<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Public;

use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Service\Clock;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * F18 — defect-on-round intake: sibling field apps (TourCheck today) file a
 * DRAFT work order that lands in triage. The facade never plans, assigns,
 * or publishes — a human dispatcher still owns the WO lifecycle.
 *
 * Contract: {refType:'tck_incident', refId:string, siteLabel?:string,
 *            title:string, description?:string, checkpointLabel?:string,
 *            photoFileId?:int, actorUid:string}
 *
 * Dedupe: UNIQUE(ref_type, ref_id) — a retried submission returns the
 * existing draft (`already:true`), never a second WO.
 *
 * Site resolution: exact-name match against active MN sites. Ambiguous or
 * absent → `site_unresolved` soft-fail (customer_id is NOT NULL; inventing
 * a customer silently is worse than refusing the draft).
 *
 * The referenced photo stays in the reporter's Files storage — file ids
 * are owner-scoped; the description carries the reference.
 */
class FieldIssueDraftFacade
{
	public const FACADE_VERSION = 1;
	public const MAX_TITLE = 255;
	public const MAX_DESCRIPTION = 4000;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkOrderMapper $workOrders,
		private readonly SiteMapper $sites,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool,woId?:int,number?:string,already?:bool,code?:string}
	 */
	public function createDraft(array $payload): array
	{
		$refType = trim((string)($payload['refType'] ?? ''));
		$refId = trim((string)($payload['refId'] ?? ''));
		$title = trim((string)($payload['title'] ?? ''));
		if ($refType === '' || strlen($refType) > 32
			|| $refId === '' || strlen($refId) > 64
			|| $title === '' || $refType === '0') {
			return ['ok' => false, 'code' => 'invalid_payload'];
		}

		try {
			$existing = $this->workOrders->findByRef($refType, $refId);
		} catch (\Throwable $e) {
			$this->logger->warning('F18 draft dedupe lookup failed', ['exception' => $e]);
			return ['ok' => false, 'code' => 'lookup_failed'];
		}
		if ($existing !== null) {
			return ['ok' => true, 'already' => true, 'woId' => (int)$existing->getId(), 'number' => $existing->getNumber()];
		}

		// Site resolution — exact name match; unique hit binds customer+site.
		$siteId = null;
		$customerId = null;
		$siteLabel = trim((string)($payload['siteLabel'] ?? ''));
		if ($siteLabel !== '') {
			try {
				$matches = $this->sites->findByName($siteLabel);
			} catch (\Throwable $e) {
				$this->logger->warning('F18 site lookup failed', ['exception' => $e]);
				return ['ok' => false, 'code' => 'lookup_failed'];
			}
			if (count($matches) === 1) {
				$siteId = (int)$matches[0]->getId();
				$customerId = (int)$matches[0]->getCustomerId();
			}
		}
		if ($customerId === null || $customerId <= 0) {
			return ['ok' => false, 'code' => 'site_unresolved'];
		}

		$desc = trim((string)($payload['description'] ?? ''));
		$meta = [];
		$checkpoint = trim((string)($payload['checkpointLabel'] ?? ''));
		if ($checkpoint !== '') {
			$meta[] = 'Checkpoint: ' . $checkpoint;
		}
		if (isset($payload['photoFileId']) && (int)$payload['photoFileId'] > 0) {
			$meta[] = 'Photo: attached to the source record (' . $refType . ' #' . $refId . ')';
		}
		$meta[] = 'Source: ' . $refType . ' #' . $refId;
		if ($meta !== []) {
			$desc = ($desc === '' ? '' : $desc . "\n\n") . implode("\n", $meta);
		}

		$now = $this->clock->now();
		$actorUid = trim((string)($payload['actorUid'] ?? ''));
		$wo = new WorkOrder();
		$wo->setKind(WorkOrder::KIND_CORRECTIVE);
		$wo->setStatus(WorkOrder::STATUS_DRAFT);
		$wo->setPriority(WorkOrder::PRIORITY_NORMAL);
		$wo->setCustomerId($customerId);
		$wo->setSiteId($siteId);
		$wo->setTitle(mb_substr($title, 0, self::MAX_TITLE));
		$wo->setDescription($desc === '' ? null : mb_substr($desc, 0, self::MAX_DESCRIPTION));
		$wo->setRefType($refType);
		$wo->setRefId($refId);
		$wo->setCreatedAt($now);
		$wo->setUpdatedAt($now);
		$wo->setCreatedBy($actorUid !== '' ? $actorUid : 'suite:' . $refType);

		// WO-YYYY-##### with the unique index as final arbiter — retry once
		// on a number race, return the existing draft on a ref race.
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$wo->setNumber($this->nextNumber());
			try {
				$this->workOrders->insert($wo);
				return ['ok' => true, 'woId' => (int)$wo->getId(), 'number' => $wo->getNumber()];
			} catch (\OCP\DB\Exception $e) {
				if (!in_array($e->getReason(), [
					\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
					\OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
				], true)) {
					throw $e;
				}
				$again = $this->workOrders->findByRef($refType, $refId);
				if ($again !== null) {
					return ['ok' => true, 'already' => true, 'woId' => (int)$again->getId(), 'number' => $again->getNumber()];
				}
				// ref was free → the race was on `number`; loop retries it.
			}
		}
		return ['ok' => false, 'code' => 'number_exhausted'];
	}

	private function nextNumber(): string
	{
		$year = substr($this->clock->today(), 0, 4);
		return sprintf('WO-%s-%05d', $year, $this->workOrders->maxNumberForYear($year) + 1);
	}
}
