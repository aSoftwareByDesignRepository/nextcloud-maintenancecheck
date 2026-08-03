<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\ProcedureMapper;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Db\WoPhotoMapper;
use OCA\MaintenanceCheck\Db\WoSignatureMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Db\InspectionObligationMapper;
use OCA\MaintenanceCheck\Event\WorkOrderClosedEvent;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * W1 work order lifecycle engine (CORE §8, §14.2).
 *
 * Concurrency model: every transition runs inside a transaction that first
 * takes the WO row lock, then re-reads status and validates against the
 * state machine; the final conditional UPDATE (`status IN (from)`) is the
 * belt-and-braces arbiter (AC-W1-4: concurrent dual-done → exactly one
 * success). Numbers (`WO-YYYY-#####`) are allocated under the unique index
 * with bounded retry.
 *
 * Gate wiring (§8.1): planned needs equipment-per-policy + procedure or an
 * explicit skip reason; ready needs the kit gate + skills per policy; done
 * needs the checklist policy and completes a linked visit in the same
 * transaction (AC-W1-2); cancelled optionally cancels the linked visit and
 * always removes tour stops (§12.5).
 */
class WorkOrderService
{
	private const NUMBER_RETRIES = 5;
	private const MAX_DESCRIPTION = 20000;
	private const MAX_ESTIMATED_MINUTES = 1440;
	private const MIN_SKIP_REASON = 10;
	private const MIN_OVERRIDE_REASON = 10;
	/** CORE assumption A4 — crew 1–4 (primary + ≤3 helpers). */
	private const MAX_HELPERS = 3;

	/** @var array{uid: string, sourceId: int, title: string, customerId: int, equipmentId: ?int, siteId: ?int, description: string, symptom: string, primaryUserId: ?string}|null */
	private ?array $pendingInspectionFollowUp = null;
	private ?string $lastDefectFollowUpMode = null;
	private ?int $lastCorrectiveWoId = null;
	private bool $lastDefectFollowUpFailed = false;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkOrderMapper $workOrders,
		private readonly VisitMapper $visits,
		private readonly CustomerMapper $customers,
		private readonly EquipmentMapper $equipment,
		private readonly SiteMapper $sites,
		private readonly ProcedureMapper $procedures,
		private readonly WoPhotoMapper $photos,
		private readonly WoSignatureMapper $signatures,
		private readonly TourStopMapper $tourStops,
		private readonly WorkOrderStateMachine $stateMachine,
		private readonly WoChecklistService $checklist,
		private readonly KitService $kits,
		private readonly SkillService $skills,
		private readonly SkillsAssignPolicy $skillsAssign,
		private readonly CapacityService $capacity,
		private readonly PolicyService $policies,
		private readonly VisitService $visitService,
		private readonly AccessControlService $access,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly InventoryFlangeService $inventoryFlange,
		private readonly ProjectCheckHoursDeepLinkService $hoursDeepLink,
		private readonly ArbeitszeitCheckDeepLinkService $recordTimeDeepLink,
		private readonly MeterService $meters,
		private readonly WorkOrderAccessPolicy $woAccess,
		private readonly FailureCodeService $failureCodes,
		private readonly InspectionClosePolicy $inspectionClose,
		private readonly \OCA\MaintenanceCheck\Db\InspectionDefectMapper $inspectionDefects,
		private readonly InspectionObligationMapper $inspectionObligations,
	) {
	}

	// ── Queries ─────────────────────────────────────────────────────────

	/**
	 * Filtered list with S7 envelope.
	 *
	 * @param array<string, ?string> $query
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $currentUid, array $query): array
	{
		$page = $this->validator->pagination($query['limit'] ?? null, $query['offset'] ?? null);
		$filters = [];

		$status = trim((string)($query['status'] ?? ''));
		if ($status !== '') {
			if (!in_array($status, WorkOrder::STATUSES, true)) {
				throw new ValidationException('invalid_query', 'status must be one of ' . implode(', ', WorkOrder::STATUSES) . '.');
			}
			$filters['status'] = $status;
		}
		if (($query['open'] ?? '') === '1') {
			$filters['open'] = true;
		}
		$kind = trim((string)($query['kind'] ?? ''));
		if ($kind !== '') {
			if (!in_array($kind, WorkOrder::KINDS, true)) {
				throw new ValidationException('invalid_query', 'kind must be one of ' . implode(', ', WorkOrder::KINDS) . '.');
			}
			$filters['kind'] = $kind;
		}
		$priority = trim((string)($query['priority'] ?? ''));
		if ($priority !== '') {
			if (!in_array($priority, WorkOrder::PRIORITIES, true)) {
				throw new ValidationException('invalid_query', 'priority must be one of ' . implode(', ', WorkOrder::PRIORITIES) . '.');
			}
			$filters['priority'] = $priority;
		}
		foreach (['customerId', 'equipmentId'] as $param) {
			$raw = trim((string)($query[$param] ?? ''));
			if ($raw !== '') {
				if (!preg_match('/^\d+$/', $raw)) {
					throw new ValidationException('invalid_query', $param . ' must be a positive integer.');
				}
				$filters[$param] = (int)$raw;
			}
		}
		// CORE §7: technicians only see assigned + unassigned pool WOs.
		if (!$this->access->isOffice($currentUid) || ($query['mine'] ?? '') === '1') {
			$filters['mineUid'] = $currentUid;
		}
		foreach (['from', 'to'] as $param) {
			$raw = trim((string)($query[$param] ?? ''));
			if ($raw !== '') {
				if (!$this->isValidYmd($raw)) {
					throw new ValidationException('invalid_query', $param . ' must be a valid Y-m-d date.');
				}
				$filters[$param] = $raw;
			}
		}
		if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
			throw new ValidationException('invalid_query', 'from must not be after to.');
		}
		$q = $this->validator->searchTerm($query['q'] ?? null);
		if ($q !== '') {
			$filters['q'] = $q;
		}

		$result = $this->workOrders->search($filters, $page['limit'], $page['offset']);
		return [
			'data' => $this->enrich($result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * Full detail payload for the WO page.
	 *
	 * @return array<string, mixed>
	 */
	public function get(int $id, ?string $viewerUid = null): array
	{
		$wo = $this->workOrders->findById($id);
		if ($viewerUid !== null) {
			$this->woAccess->assertCanExecute($viewerUid, $wo);
		}
		$row = $this->enrich([$wo])[0];

		$checklistDetail = $this->checklist->detail($id);
		$row['checklist'] = $checklistDetail['items'];
		$row['checklistAssessment'] = $checklistDetail['assessment'];
		$row['photos'] = array_map(static fn ($p) => $p->toApi(), $this->photos->findByWorkOrder($id));
		$row['kit'] = $this->kits->kitFor($id);
		$row['kitReadiness'] = $this->kits->readinessFor($id);
		$row['requiredSkills'] = $this->skills->woSkillsDetail($id);
		$signature = $this->signatures->findByWorkOrder($id);
		$row['signature'] = $signature?->toApi();
		$row['defects'] = array_map(
			static fn ($d) => $d->toApi(),
			$this->inspectionDefects->findByWorkOrder($id),
		);
		$stop = $this->tourStops->findByWorkOrder($id);
		$row['tourStop'] = $stop?->toApi();

		$visitId = $wo->getVisitId();
		if ($visitId !== null) {
			try {
				$row['visit'] = $this->visits->findById($visitId)->toApi();
			} catch (NotFoundException) {
				$row['visit'] = null;
			}
		} else {
			$row['visit'] = null;
		}

		$row['siteName'] = null;
		$row['siteAddress'] = null;
		$row['preferredWindow'] = null;
		$row['equipmentSerialNo'] = null;
		$siteId = $wo->getSiteId();
		if ($siteId !== null) {
			try {
				$site = $this->sites->findById($siteId)->toApi();
				$row['siteName'] = $site['name'] ?? null;
				$row['siteAddress'] = $this->formatAddress($site);
				$window = trim((string)($site['preferredWindow'] ?? ''));
				$row['preferredWindow'] = $window !== '' ? $window : null;
			} catch (NotFoundException) {
				// orphaned FK — leave null
			}
		}
		if (($row['siteAddress'] ?? null) === null || $row['siteAddress'] === '') {
			try {
				$customer = $this->customers->findById($wo->getCustomerId())->toApi();
				$fallback = $this->formatAddress($customer);
				if ($fallback !== '') {
					$row['siteAddress'] = $fallback;
				}
			} catch (NotFoundException) {
				// ignore
			}
		}
		$equipmentId = $wo->getEquipmentId();
		if ($equipmentId !== null) {
			try {
				$equipment = $this->equipment->findById($equipmentId);
				$api = $equipment->toApi();
				$serial = trim((string)($api['serialNo'] ?? ''));
				$row['equipmentSerialNo'] = $serial !== '' ? $serial : null;
				$row['warrantyEnd'] = $api['warrantyEnd'] ?? null;
				$row['warrantyExpired'] = $equipment->isWarrantyExpired($this->clock->today());
			} catch (NotFoundException) {
				// ignore
			}
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $place site or customer address fields
	 */
	private function formatAddress(array $place): string
	{
		$parts = [];
		foreach (['street', 'postalCode', 'city', 'country'] as $key) {
			$value = trim((string)($place[$key] ?? ''));
			if ($value !== '') {
				$parts[] = $value;
			}
		}
		return implode(', ', $parts);
	}

	// ── Create ──────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body, bool $isOffice = true): array
	{
		$kind = $this->validatedKind($body);
		// CORE §7: technicians may create corrective WOs only (always draft).
		if (!$isOffice) {
			if ($kind !== WorkOrder::KIND_CORRECTIVE) {
				throw new PermissionDeniedException('Technicians may only create corrective work orders.');
			}
			$body['status'] = WorkOrder::STATUS_DRAFT;
		}
		$customerId = $this->validator->intOrThrow($body, 'customerId');
		$this->customers->findById($customerId);

		$fields = $this->validatedEditableFields($body, $customerId, required: true);
		$procedureId = $this->validatedProcedureId($body);
		$skip = $this->validatedProcedureSkip($body);

		$wantPlanned = $isOffice && ($body['status'] ?? null) === WorkOrder::STATUS_PLANNED;
		if (($body['status'] ?? WorkOrder::STATUS_DRAFT) !== WorkOrder::STATUS_DRAFT && !$wantPlanned) {
			throw new ValidationException('validation_failed', 'A new work order starts as draft or planned.', [
				['field' => 'status', 'code' => 'invalid_value'],
			]);
		}

		$now = $this->clock->now();
		$wo = new WorkOrder();
		$wo->setKind($kind);
		$wo->setStatus(WorkOrder::STATUS_DRAFT);
		$wo->setPriority($fields['priority'] ?? WorkOrder::PRIORITY_NORMAL);
		$wo->setCustomerId($customerId);
		$wo->setEquipmentId($fields['equipmentId']);
		$wo->setSiteId($fields['siteId']);
		$wo->setProcedureId($procedureId);
		$wo->setTitle($fields['title'] ?? '');
		if ($wo->getTitle() === '') {
			throw new ValidationException('validation_failed', 'Required field missing.', [
				['field' => 'title', 'code' => 'title_required'],
			]);
		}
		$wo->setDescription($fields['description']);
		$wo->setDueOn($fields['dueOn']);
		$wo->setEstimatedMinutes($fields['estimatedMinutes']);
		$wo->setProcedureSkipped($skip['skipped']);
		$wo->setProcedureSkipReason($skip['reason']);
		$wo->setCreatedAt($now);
		$wo->setUpdatedAt($now);
		$wo->setCreatedBy($uid);

		$intake = $this->validatedIntakeFields($body);
		$wo->setRequesterName($intake['requesterName']);
		$wo->setRequesterPhone($intake['requesterPhone']);
		$wo->setSymptom($intake['symptom']);
		$wo->setAccessNotes($intake['accessNotes']);
		if (array_key_exists('sourceWoId', $body) && is_int($body['sourceWoId']) && $body['sourceWoId'] > 0) {
			$wo->setSourceWoId($body['sourceWoId']);
		}
		if (array_key_exists('obligationId', $body) && is_int($body['obligationId']) && $body['obligationId'] > 0) {
			$wo->setObligationId($body['obligationId']);
		}
		if (array_key_exists('visitId', $body) && is_int($body['visitId']) && $body['visitId'] > 0) {
			$wo->setVisitId($body['visitId']);
		}
		// AC-W6-2: copy site defaults when WO access_notes not explicitly set.
		// Preferred window is enriched on read from the site (not duplicated on WO).
		if ($wo->getAccessNotes() === null && $fields['siteId'] !== null) {
			try {
				$site = $this->sites->findById($fields['siteId']);
				$siteNotes = $site->getAccessNotes();
				if ($siteNotes !== null && $siteNotes !== '') {
					$wo->setAccessNotes($siteNotes);
				}
			} catch (NotFoundException) {
				// ignore
			}
		}

		$warnings = [];
		if ($kind === WorkOrder::KIND_CORRECTIVE && $fields['equipmentId'] !== null) {
			try {
				$equipment = $this->equipment->findById($fields['equipmentId']);
				if ($equipment->isWarrantyExpired($this->clock->today())) {
					$warnings[] = [
						'code' => 'warranty_expired',
						'message' => 'Equipment warranty ended on ' . (string)$equipment->getWarrantyEnd() . '.',
						'warrantyEnd' => $equipment->getWarrantyEnd(),
					];
				}
			} catch (NotFoundException) {
				// ignore
			}
		}

		if ($wantPlanned) {
			$this->assertPlannedGate($wo);
			$wo->setStatus(WorkOrder::STATUS_PLANNED);
		}

		$wo = $this->insertWithNumber($wo, $procedureId);
		$detail = $this->get((int)$wo->getId());
		$detail['warnings'] = $warnings;
		return $detail;
	}

	/**
	 * Field / companion path (UC-PRUEF): open the linked WO or create one.
	 * Idempotent under concurrent taps — visit_already_linked returns the winner.
	 * When the caller omits procedure fields and the obligation has none, skip
	 * the procedure so the WO lands Planned (tech can execute immediately).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function openOrCreateFromVisit(string $uid, int $visitId, array $body): array
	{
		if (!array_key_exists('procedureId', $body) && !array_key_exists('procedureSkipped', $body)) {
			try {
				$visit = $this->visits->findById($visitId);
				$obligation = $this->inspectionObligations->findByPlanId($visit->getPlanId());
				if ($obligation !== null && $obligation->getProcedureId() === null) {
					$body['procedureSkipped'] = true;
					$body['procedureSkipReason'] = 'Opened in the field without a procedure template.';
				}
			} catch (NotFoundException) {
				// createFromVisit will raise NotFound after the visit lock.
			}
		}
		try {
			return $this->createFromVisit($uid, $visitId, $body);
		} catch (ConflictException $e) {
			if ($e->getErrorCode() === 'visit_already_linked') {
				$woId = (int)($e->getDetails()['workOrderId'] ?? 0);
				if ($woId > 0) {
					return $this->get($woId, $uid);
				}
			}
			throw $e;
		}
	}

	/**
	 * W1 create-WO-from-visit (AC-W1-1). The visit row lock is taken first
	 * so the REPEATABLE READ snapshot forms after it — a concurrently
	 * committed WO on the same visit is then visible to the guard.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function createFromVisit(string $uid, int $visitId, array $body): array
	{
		$procedureId = $this->validatedProcedureId($body);
		$skip = $this->validatedProcedureSkip($body);
		$title = $this->validator->boundedOptionalString($body, 'title', 255, 'title_too_long');
		$priority = $this->validatedPriority($body) ?? WorkOrder::PRIORITY_NORMAL;
		$estimatedMinutes = $this->validatedEstimatedMinutes($body);
		$now = $this->clock->now();

		$attempt = 0;
		while (true) {
			$attempt++;
			$this->db->beginTransaction();
			try {
				if (!$this->visits->lockRow($visitId)) {
					$this->db->rollBack();
					throw new NotFoundException();
				}
				$visit = $this->visits->findById($visitId);
				if ($visit->getStatus() !== Visit::STATUS_SCHEDULED) {
					$this->db->rollBack();
					throw new ConflictException('visit_not_open', 'This visit was already closed.');
				}
				$existing = $this->workOrders->findNonCancelledByVisit($visitId);
				if ($existing !== null) {
					$this->db->rollBack();
					throw new ConflictException('visit_already_linked', 'A work order already fulfils this visit.', [
						'workOrderId' => (int)$existing->getId(),
						'workOrderNumber' => $existing->getNumber(),
					]);
				}

				$obligation = $this->inspectionObligations->findByPlanId($visit->getPlanId());
				$wo = new WorkOrder();
				$wo->setKind($obligation !== null ? WorkOrder::KIND_INSPECTION : WorkOrder::KIND_PREVENTIVE);
				$wo->setStatus(WorkOrder::STATUS_DRAFT);
				$wo->setPriority($priority);
				$wo->setCustomerId($visit->getCustomerId());
				$wo->setEquipmentId($visit->getEquipmentId());
				$wo->setVisitId($visitId);
				$wo->setProcedureId($procedureId);
				$wo->setTitle($title ?? $this->defaultVisitTitle($visit));
				$wo->setDueOn($visit->getDueOn());
				$wo->setEstimatedMinutes($estimatedMinutes);
				$wo->setPrimaryUserId($visit->getAssignedUid());
				$wo->setProcedureSkipped($skip['skipped']);
				$wo->setProcedureSkipReason($skip['reason']);
				if ($obligation !== null) {
					$wo->setObligationId((int)$obligation->getId());
					if ($obligation->getProcedureId() !== null && $procedureId === null) {
						$wo->setProcedureId($obligation->getProcedureId());
						$procedureId = $obligation->getProcedureId();
					}
				}
				$wo->setCreatedAt($now);
				$wo->setUpdatedAt($now);
				$wo->setCreatedBy($uid);

				// ≤ 5 clicks: auto-plan when the planned gate already passes.
				if ($procedureId !== null || $skip['skipped']) {
					$this->assertPlannedGate($wo);
					$wo->setStatus(WorkOrder::STATUS_PLANNED);
				}

				$wo->setNumber($this->nextNumber());
				$wo = $this->workOrders->insert($wo);
				if ($procedureId !== null) {
					$this->checklist->snapshotProcedure((int)$wo->getId(), $procedureId);
				}
				$this->attachInspectionSkillsIfNeeded($wo, $obligation?->getClassCode());
				$this->db->commit();
				return $this->get((int)$wo->getId());
			} catch (ConflictException | NotFoundException | ValidationException | PermissionDeniedException $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			} catch (\OCP\DB\Exception $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				if ($attempt < self::NUMBER_RETRIES && $this->isUniqueViolation($e)) {
					continue;
				}
				throw $e;
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		}
	}

	// ── Update / assign ─────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(string $uid, int $id, array $body): array
	{
		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			if (!$this->workOrders->lockRow($id)) {
				$this->db->rollBack();
				throw new NotFoundException();
			}
			$wo = $this->workOrders->findById($id);
			if ($wo->isTerminal()) {
				$this->db->rollBack();
				throw new ConflictException('invalid_status', 'This work order is closed.');
			}

			if (array_key_exists('title', $body)) {
				$wo->setTitle($this->validator->requiredString($body, 'title', 'title_required', 255, 'title_too_long'));
			}
			if (array_key_exists('description', $body)) {
				$wo->setDescription($this->validator->boundedOptionalString($body, 'description', self::MAX_DESCRIPTION, 'description_too_long'));
			}
			if (array_key_exists('priority', $body)) {
				$wo->setPriority($this->validatedPriority($body) ?? $wo->getPriority());
			}
			if (array_key_exists('dueOn', $body)) {
				$dueOn = $this->validator->optionalString($body, 'dueOn');
				$wo->setDueOn(($dueOn === null || $dueOn === '') ? null : $this->validator->dueOn($dueOn, $this->clock->today()));
			}
			if (array_key_exists('estimatedMinutes', $body)) {
				$wo->setEstimatedMinutes($this->validatedEstimatedMinutes($body));
			}
			if (array_key_exists('siteId', $body)) {
				$wo->setSiteId($this->validatedSiteId($body, $wo->getCustomerId()));
			}
			if (array_key_exists('equipmentId', $body)) {
				$this->assertStructuralEditsAllowed($wo);
				$wo->setEquipmentId($this->validatedEquipmentId($body, $wo->getCustomerId()));
			}
			if (array_key_exists('requesterName', $body)) {
				$wo->setRequesterName($this->validator->boundedOptionalString($body, 'requesterName', 128, 'requester_name_too_long'));
			}
			if (array_key_exists('requesterPhone', $body)) {
				$wo->setRequesterPhone($this->validator->boundedOptionalString($body, 'requesterPhone', 64, 'requester_phone_too_long'));
			}
			if (array_key_exists('symptom', $body)) {
				$wo->setSymptom($this->validator->boundedOptionalString($body, 'symptom', 512, 'symptom_too_long'));
			}
			if (array_key_exists('accessNotes', $body)) {
				$wo->setAccessNotes($this->validator->boundedOptionalString($body, 'accessNotes', 512, 'access_notes_too_long'));
			}

			$resnapshot = null;
			if (array_key_exists('procedureId', $body) || array_key_exists('procedureSkipped', $body)) {
				$this->assertStructuralEditsAllowed($wo);
				if ($this->checklist->hasAnyResult($id)) {
					$this->db->rollBack();
					throw new ConflictException('checklist_started', 'Checklist results exist; the procedure can no longer be changed.');
				}
				if (array_key_exists('procedureId', $body)) {
					$procedureId = $this->validatedProcedureId($body);
					$wo->setProcedureId($procedureId);
					$resnapshot = $procedureId;
				}
				$skip = $this->validatedProcedureSkip($body);
				if (array_key_exists('procedureSkipped', $body)) {
					$wo->setProcedureSkipped($skip['skipped']);
					$wo->setProcedureSkipReason($skip['reason']);
				}
			}

			// planned+ must keep satisfying the planned gate after edits.
			if ($wo->getStatus() !== WorkOrder::STATUS_DRAFT) {
				$this->assertPlannedGate($wo);
			}

			$wo->setUpdatedAt($now);
			$this->workOrders->update($wo);
			if ($resnapshot !== null) {
				$this->checklist->snapshotProcedure($id, $resnapshot);
			} elseif (array_key_exists('procedureId', $body) && $wo->getProcedureId() === null) {
				$this->checklist->clear($id);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->get($id);
	}

	/**
	 * Assign primary tech + helpers with the W2 skills gate and the W4
	 * capacity gate (§10.5, UC-SKILL). `force=true` acknowledges warn-level
	 * findings; block-level enforcement cannot be forced through.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function assign(string $uid, int $id, array $body): array
	{
		if (!array_key_exists('primaryUserId', $body) && !array_key_exists('helperUids', $body)) {
			throw new ValidationException('validation_failed', 'primaryUserId or helperUids is required.', [
				['field' => 'primaryUserId', 'code' => 'required'],
			]);
		}
		$force = $this->validator->boolOrDefault($body, 'force', false);
		$warnings = [];

		$this->db->beginTransaction();
		try {
			if (!$this->workOrders->lockRow($id)) {
				$this->db->rollBack();
				throw new NotFoundException();
			}
			$wo = $this->workOrders->findById($id);
			if ($wo->isTerminal()) {
				$this->db->rollBack();
				throw new ConflictException('invalid_status', 'This work order is closed.');
			}

			if (array_key_exists('primaryUserId', $body)) {
				$primary = $body['primaryUserId'];
				if ($primary !== null) {
					if (!is_string($primary) || trim($primary) === '' || !$this->userManager->userExists(trim($primary))) {
						$this->db->rollBack();
						throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
					}
					$primary = trim($primary);
					// W4 TOCTOU fix: serialise capacity reads under a per-tech
					// FOR UPDATE lock (after WO lock — fixed lock order).
					if (
						$this->policies->capacityEnforcement() !== PolicyService::ENFORCEMENT_OFF
						&& $wo->getEstimatedMinutes() !== null
					) {
						$this->capacity->lockAssignGate($primary);
					}
					$warnings = $this->runAssignGates($wo, $primary, $force);
				}
				$wo->setPrimaryUserId($primary);
			}
			if (array_key_exists('helperUids', $body)) {
				$helpers = $this->validatedHelperUids($body);
				// R6: helpers must satisfy skills; capacity is assessed for the primary only.
				foreach ($helpers as $helperUid) {
					foreach ($this->skillsAssign->evaluate(
						$this->policies->skillsEnforcement(),
						$this->skills->missingSkillsFor((int)$wo->getId(), $helperUid),
						$force,
					) as $warning) {
						$warnings[] = $warning;
					}
				}
				$wo->setHelperUids($helpers);
			}

			$wo->setUpdatedAt($this->clock->now());
			$this->workOrders->update($wo);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$detail = $this->get($id);
		$detail['warnings'] = $warnings;
		return $detail;
	}

	/**
	 * Equipment/procedure are structural and may only change while the WO
	 * has not entered execution (draft/planned).
	 */
	private function assertStructuralEditsAllowed(WorkOrder $wo): void
	{
		if (!in_array($wo->getStatus(), [WorkOrder::STATUS_DRAFT, WorkOrder::STATUS_PLANNED], true)) {
			throw new ConflictException('invalid_status', 'Equipment and procedure can only change while the work order is draft or planned.');
		}
	}

	// ── Transitions ─────────────────────────────────────────────────────

	/**
	 * Single transition endpoint (§8.1). `$isOffice` gates office-only
	 * targets and force-close.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function transition(string $uid, int $id, array $body, bool $isOffice): array
	{
		$to = $this->validator->optionalString($body, 'to') ?? '';
		if (!in_array($to, WorkOrder::STATUSES, true)) {
			throw new ValidationException('validation_failed', 'to must be a valid work order status.', [
				['field' => 'to', 'code' => 'invalid_value'],
			]);
		}
		$this->assertTransitionPermission($to, $body, $isOffice);

		$this->pendingInspectionFollowUp = null;
		$this->lastDefectFollowUpMode = null;
		$this->lastCorrectiveWoId = null;
		$this->lastDefectFollowUpFailed = false;

		$now = $this->clock->now();
		$flange = null;

		$this->db->beginTransaction();
		try {
			if (!$this->workOrders->lockRow($id)) {
				$this->db->rollBack();
				throw new NotFoundException();
			}
			$wo = $this->workOrders->findById($id);
			$this->woAccess->assertCanExecute($uid, $wo, $isOffice);
			$from = $wo->getStatus();
			$this->stateMachine->assertTransition($from, $to);

			$set = ['status' => $to, 'updated_at' => $now];
			switch ($to) {
				case WorkOrder::STATUS_PLANNED:
					$this->applyPlannedFields($wo, $body, $set);
					$this->assertPlannedGateFromSet($wo, $set);
					// Unblock path: clear block fields.
					$set['block_reason_code'] = null;
					$set['block_note'] = null;
					break;

				case WorkOrder::STATUS_READY:
					$this->applyReadyGate($wo, $body, $set);
					$set['block_reason_code'] = null;
					$set['block_note'] = null;
					break;

				case WorkOrder::STATUS_IN_PROGRESS:
					if ($wo->getStartedAt() === null) {
						$set['started_at'] = $now;
					}
					$set['block_reason_code'] = null;
					$set['block_note'] = null;
					break;

				case WorkOrder::STATUS_BLOCKED:
					$this->applyBlockedFields($body, $set);
					break;

				case WorkOrder::STATUS_DONE:
					$flange = $this->applyDone($uid, $wo, $body, $set, $isOffice, $now);
					break;

				case WorkOrder::STATUS_CANCELLED:
					$this->applyCancelled($uid, $wo, $body, $now);
					break;

				case WorkOrder::STATUS_DRAFT:
					// Unreachable: no transition targets draft.
					break;
			}

			if (!$this->workOrders->transition($id, [$from], $set)) {
				// We hold the row lock, so this only fires if the row
				// vanished mid-flight — treat as conflict.
				$this->db->rollBack();
				throw new ConflictException('invalid_status', 'The work order changed concurrently.');
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		// AC-F1 / §4.4.1: flange runs strictly after commit; soft-fail only.
		if ($flange !== null) {
			$this->dispatchInventoryFlange($uid, $id, $flange);
		}
		if ($this->pendingInspectionFollowUp !== null) {
			$job = $this->pendingInspectionFollowUp;
			$this->pendingInspectionFollowUp = null;
			$this->runPendingInspectionFollowUp($job);
		}
		$detail = $this->get($id);
		if ($this->lastDefectFollowUpMode !== null) {
			$detail['defectFollowUp'] = $this->lastDefectFollowUpMode;
			if ($this->lastCorrectiveWoId !== null) {
				$detail['correctiveWorkOrderId'] = $this->lastCorrectiveWoId;
			}
			$this->lastDefectFollowUpMode = null;
			$this->lastCorrectiveWoId = null;
		}
		if ($this->lastDefectFollowUpFailed) {
			$detail['defectFollowUpFailed'] = true;
			$this->lastDefectFollowUpFailed = false;
		}
		return $detail;
	}

	// ── Transition internals ────────────────────────────────────────────

	private function assertTransitionPermission(string $to, array $body, bool $isOffice): void
	{
		$officeOnly = [WorkOrder::STATUS_PLANNED, WorkOrder::STATUS_READY, WorkOrder::STATUS_CANCELLED];
		if (in_array($to, $officeOnly, true) && !$isOffice) {
			throw new PermissionDeniedException('Only office users can perform this transition.');
		}
		if ($to === WorkOrder::STATUS_DONE && array_key_exists('forceClose', $body) && !$isOffice) {
			throw new PermissionDeniedException('Only office users can force-close a work order.');
		}
	}

	/**
	 * draft→planned may carry procedure fields in the same call.
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 */
	private function applyPlannedFields(WorkOrder $wo, array $body, array &$set): void
	{
		if (array_key_exists('procedureId', $body)) {
			if ($this->checklist->hasAnyResult((int)$wo->getId())) {
				throw new ConflictException('checklist_started', 'Checklist results exist; the procedure can no longer be changed.');
			}
			$procedureId = $this->validatedProcedureId($body);
			$set['procedure_id'] = $procedureId;
			if ($procedureId !== null) {
				$this->checklist->snapshotProcedure((int)$wo->getId(), $procedureId);
			} else {
				$this->checklist->clear((int)$wo->getId());
			}
		}
		if (array_key_exists('procedureSkipped', $body)) {
			$skip = $this->validatedProcedureSkip($body);
			$set['procedure_skipped'] = $skip['skipped'];
			$set['procedure_skip_reason'] = $skip['reason'];
		}
	}

	/**
	 * §8.1 draft→planned gate on the *resulting* field values.
	 *
	 * @param array<string, string|int|bool|null> $set
	 */
	private function assertPlannedGateFromSet(WorkOrder $wo, array $set): void
	{
		$probe = clone $wo;
		if (array_key_exists('procedure_id', $set)) {
			$probe->setProcedureId($set['procedure_id'] === null ? null : (int)$set['procedure_id']);
		}
		if (array_key_exists('procedure_skipped', $set)) {
			$probe->setProcedureSkipped((bool)$set['procedure_skipped']);
			$probe->setProcedureSkipReason($set['procedure_skip_reason'] === null ? null : (string)$set['procedure_skip_reason']);
		}
		$this->assertPlannedGate($probe);
	}

	private function assertPlannedGate(WorkOrder $wo): void
	{
		if ($this->policies->requireEquipmentOnWo() && $wo->getEquipmentId() === null) {
			throw new ValidationException('equipment_required', 'An equipment must be selected before planning this work order.');
		}
		if ($wo->getProcedureId() === null && !$wo->getProcedureSkipped()) {
			throw new ValidationException('procedure_required', 'Attach a procedure or skip it with a reason of at least ' . self::MIN_SKIP_REASON . ' characters.');
		}
		if ($wo->getProcedureSkipped()) {
			$reason = trim((string)$wo->getProcedureSkipReason());
			if (mb_strlen($reason) < self::MIN_SKIP_REASON) {
				throw new ValidationException('procedure_required', 'The skip reason must be at least ' . self::MIN_SKIP_REASON . ' characters.');
			}
		}
	}

	/**
	 * §10.2 planned→ready: kit satisfied OR override with reason; skills OK
	 * per policy for the assigned primary tech.
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 */
	private function applyReadyGate(WorkOrder $wo, array $body, array &$set): void
	{
		$override = $this->validator->boolOrDefault($body, 'kitOverride', false);
		$readiness = $this->kits->readinessFor((int)$wo->getId());
		if (!$readiness['ready']) {
			if (!$override) {
				throw new ConflictException('kit_incomplete', 'The kit is not fully packed.', [
					'missing' => $readiness['missing'],
				]);
			}
			$reason = trim((string)$this->validator->boundedOptionalString($body, 'kitOverrideReason', 255, 'reason_too_long'));
			if (mb_strlen($reason) < self::MIN_OVERRIDE_REASON) {
				throw new ValidationException('validation_failed', 'The kit override reason must be at least ' . self::MIN_OVERRIDE_REASON . ' characters.', [
					['field' => 'kitOverrideReason', 'code' => 'too_short'],
				]);
			}
			$set['kit_override'] = true;
			$set['kit_override_reason'] = $reason;
		}

		$primary = $wo->getPrimaryUserId();
		if ($primary !== null && $this->policies->skillsEnforcement() === PolicyService::ENFORCEMENT_BLOCK) {
			$this->skillsAssign->evaluate(
				PolicyService::ENFORCEMENT_BLOCK,
				$this->skills->missingSkillsFor((int)$wo->getId(), $primary),
				false,
			);
		}
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 */
	private function applyBlockedFields(array $body, array &$set): void
	{
		$code = trim((string)$this->validator->boundedOptionalString($body, 'blockReasonCode', 64, 'code_too_long'));
		if ($code === '' || !preg_match('/^[a-z0-9_]+$/', $code)) {
			throw new ValidationException('validation_failed', 'blockReasonCode is required (a–z, 0–9, underscore).', [
				['field' => 'blockReasonCode', 'code' => 'required'],
			]);
		}
		$note = trim((string)$this->validator->boundedOptionalString($body, 'blockNote', 512, 'note_too_long'));
		if ($note === '') {
			throw new ValidationException('validation_failed', 'A block note is required.', [
				['field' => 'blockNote', 'code' => 'required'],
			]);
		}
		$set['block_reason_code'] = $code;
		$set['block_note'] = $note;
	}

	/**
	 * in_progress→done (§10.3, AC-W1-2/3/4).
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 * @return list<array{sku: string, label: string, qty: int}>|null sku
	 *         lines for the post-commit inventory flange
	 */
	private function applyDone(string $uid, WorkOrder $wo, array $body, array &$set, bool $isOffice, int $now): ?array
	{
		$today = $this->clock->today();
		$doneOn = $this->validator->doneOn($this->validator->optionalString($body, 'doneOn'), $today);

		$assessment = $this->checklist->assessDone((int)$wo->getId());
		if (!$assessment['allowed']) {
			$forceReason = trim((string)$this->validator->boundedOptionalString($body, 'forceClose', 255, 'reason_too_long'));
			if ($forceReason === '') {
				throw new ConflictException('checklist_incomplete', 'The checklist does not allow completion yet.', [
					'failedItems' => $assessment['failedItems'],
					'incompleteItems' => $assessment['incompleteItems'],
				]);
			}
			if (!$isOffice) {
				throw new PermissionDeniedException('Only office users can force-close a work order.');
			}
			if (mb_strlen($forceReason) < ChecklistPolicy::FORCE_CLOSE_MIN_REASON) {
				throw new ValidationException('validation_failed', 'The force-close reason must be at least ' . ChecklistPolicy::FORCE_CLOSE_MIN_REASON . ' characters.', [
					['field' => 'forceClose', 'code' => 'too_short'],
				]);
			}
			$set['force_close_reason'] = $forceReason;
		}

		$set['completed_at'] = $now;
		$set['completed_by'] = $uid;

		// W6 close-rich: failure code + labor minutes (evidence, not ArbZG).
		$this->applyCloseRichFields($wo, $body, $set);
		// W7 inspection close: result / inspector / defects (+ optional auto corrective).
		$this->applyInspectionCloseFields($uid, $wo, $body, $set, $now);

		// AC-W1-2 / invariant D2: only a *linked* visit is completed; the
		// roll is identical to visit-complete (same code path) unless CORE I2
		// fail_blocks_roll applies to a fail inspection.
		$visitId = $wo->getVisitId();
		if ($visitId !== null) {
			$rollNext = true;
			if (
				$wo->getKind() === WorkOrder::KIND_INSPECTION
				&& $this->policies->failBlocksRoll()
				&& ($set['result'] ?? $wo->getResult()) === WorkOrder::RESULT_FAIL
			) {
				$rollNext = false;
			}
			$rolled = $this->visitService->completeWithinTransaction($uid, $visitId, $doneOn, $now, $rollNext);
			if (!$rolled['closed']) {
				$visit = $this->visits->findById($visitId);
				if ($visit->getStatus() !== Visit::STATUS_DONE) {
					throw new ConflictException('visit_not_open', 'The linked visit could not be completed.');
				}
			}
		}

		// W5 exit: optional closing meter reading on the equipment (no due
		// re-evaluation — see MeterService::recordClosingWithinTransaction).
		if (array_key_exists('closingReading', $body) && $body['closingReading'] !== null) {
			if (!is_array($body['closingReading'])) {
				throw new ValidationException('validation_failed', 'closingReading must be an object.', [
					['field' => 'closingReading', 'code' => 'invalid_type'],
				]);
			}
			$equipmentId = $wo->getEquipmentId();
			if ($equipmentId === null) {
				throw new ValidationException('validation_failed', 'A closing reading requires equipment on the work order.', [
					['field' => 'closingReading', 'code' => 'equipment_required'],
				]);
			}
			$this->meters->recordClosingWithinTransaction(
				$uid,
				$equipmentId,
				$body['closingReading'],
				$today,
				$now,
			);
		}

		$skuLines = $this->kits->skuLinesFor((int)$wo->getId());
		return $skuLines === [] ? null : $skuLines;
	}

	/**
	 * W6 Done payload: failure_code policy + labor_minutes (CORE §20).
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 */
	private function applyCloseRichFields(WorkOrder $wo, array $body, array &$set): void
	{
		$code = null;
		if (array_key_exists('failureCode', $body)) {
			$raw = $this->validator->boundedOptionalString($body, 'failureCode', 64, 'failure_code_too_long');
			$code = ($raw === null || $raw === '') ? null : $raw;
		}
		$policy = $this->policies->failureCodeOnCorrective();
		$needsCode = $wo->getKind() === WorkOrder::KIND_CORRECTIVE
			&& $policy !== PolicyService::FAILURE_CODE_OFF;
		if ($needsCode && $code === null && $policy === PolicyService::FAILURE_CODE_REQUIRED) {
			throw new ValidationException('failure_code_required', 'A failure code is required to close this corrective work order.', [
				['field' => 'failureCode', 'code' => 'failure_code_required'],
			]);
		}
		if ($code !== null) {
			$this->failureCodes->assertActiveCode($code);
			$set['failure_code'] = $code;
		}

		if (array_key_exists('laborMinutes', $body) && $body['laborMinutes'] !== null) {
			$minutes = $body['laborMinutes'];
			if (!is_int($minutes) || $minutes < 0 || $minutes > WorkOrder::MAX_LABOR_MINUTES) {
				throw new ValidationException('validation_failed', 'laborMinutes must be an integer between 0 and ' . WorkOrder::MAX_LABOR_MINUTES . '.', [
					['field' => 'laborMinutes', 'code' => 'out_of_range'],
				]);
			}
			$set['labor_minutes'] = $minutes;
		}
	}

	/**
	 * AC-W7-12: portable electrical inspections require electro_portable skill.
	 */
	private function attachInspectionSkillsIfNeeded(WorkOrder $wo, ?string $classCode): void
	{
		if ($wo->getKind() !== WorkOrder::KIND_INSPECTION) {
			return;
		}
		$code = $classCode;
		if (($code === null || $code === '') && $wo->getEquipmentId() !== null) {
			try {
				$code = $this->equipment->findById($wo->getEquipmentId())->getEquipmentClass();
			} catch (NotFoundException) {
				$code = null;
			}
		}
		if ($code !== 'portable_electrical') {
			return;
		}
		$skillId = $this->skills->ensureByCode('electro_portable', 'Portable electrical inspection');
		$this->skills->setWoSkills((int)$wo->getId(), ['skillIds' => [$skillId]]);
	}

	/**
	 * W7 Done payload for kind=inspection (CORE §21 AC-W7-3…5).
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, string|int|bool|null> $set
	 */
	private function applyInspectionCloseFields(string $uid, WorkOrder $wo, array $body, array &$set, int $now): void
	{
		if ($wo->getKind() !== WorkOrder::KIND_INSPECTION) {
			return;
		}
		$normalized = $this->inspectionClose->validateAndNormalize(
			$wo,
			$body,
			$this->policies->inspectionResultRequired(),
		);
		if ($normalized['result'] !== '') {
			$set['result'] = $normalized['result'];
		}
		if ($normalized['inspectorName'] !== '') {
			$set['inspector_name'] = $normalized['inspectorName'];
		}
		if ($normalized['inspectorNote'] !== null) {
			$set['inspector_note'] = $normalized['inspectorNote'];
		}

		$this->assertDefectPhotosBelongToWorkOrder((int)$wo->getId(), $normalized['defects']);
		foreach ($normalized['defects'] as $defect) {
			$row = new \OCA\MaintenanceCheck\Db\InspectionDefect();
			$row->setWoId((int)$wo->getId());
			$row->setCode($defect['code']);
			$row->setBody($defect['body']);
			$row->setPhotoFileId($defect['photoFileId']);
			$row->setCreatedAt($now);
			$row->setCreatedBy($uid);
			$this->inspectionDefects->insert($row);
		}

		$follow = $this->policies->defectFollowUp();
		if (
			$follow === PolicyService::DEFECT_FOLLOW_AUTO
			&& $normalized['result'] !== ''
			&& $normalized['result'] !== WorkOrder::RESULT_PASS
		) {
			// Deferred until after the Done transaction commits (same pattern as inventory flange).
			$this->lastDefectFollowUpMode = PolicyService::DEFECT_FOLLOW_AUTO;
			$firstDefect = (string)($normalized['defects'][0]['body'] ?? $wo->getTitle());
			$this->pendingInspectionFollowUp = [
				'uid' => $uid,
				'sourceId' => (int)$wo->getId(),
				'title' => 'Follow-up · ' . $wo->getNumber(),
				'customerId' => $wo->getCustomerId(),
				'equipmentId' => $wo->getEquipmentId(),
				'siteId' => $wo->getSiteId(),
				'description' => 'Auto-opened from inspection ' . $wo->getNumber() . ': ' . $firstDefect,
				'symptom' => mb_substr($firstDefect, 0, 512),
				'primaryUserId' => $wo->getPrimaryUserId(),
			];
		} elseif (
			$follow === PolicyService::DEFECT_FOLLOW_WARN
			&& $normalized['result'] !== ''
			&& $normalized['result'] !== WorkOrder::RESULT_PASS
		) {
			$this->lastDefectFollowUpMode = PolicyService::DEFECT_FOLLOW_WARN;
		}
	}

	/**
	 * Idempotent auto-corrective WO linked via source_wo_id (AC-W7-5).
	 * UNIQUE(source_wo_id) is the concurrency arbiter; losers re-read the winner.
	 *
	 * @param array{uid: string, sourceId: int, title: string, customerId: int, equipmentId: ?int, siteId: ?int, description: string, symptom: string, primaryUserId: ?string} $job
	 */
	private function runPendingInspectionFollowUp(array $job): void
	{
		$existing = $this->workOrders->findBySourceWoId($job['sourceId']);
		$existingId = $existing !== null ? (int)$existing->getId() : null;
		if (InspectionFollowUpGuard::decide($existingId) === 'reuse') {
			$this->lastCorrectiveWoId = $existingId;
			return;
		}
		try {
			$created = $this->create($job['uid'], [
				'title' => $job['title'],
				'kind' => WorkOrder::KIND_CORRECTIVE,
				'customerId' => $job['customerId'],
				'equipmentId' => $job['equipmentId'],
				'siteId' => $job['siteId'],
				'description' => $job['description'],
				'symptom' => $job['symptom'],
				'sourceWoId' => $job['sourceId'],
				'status' => WorkOrder::STATUS_PLANNED,
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Auto follow-up from inspection defect',
			], true);
			$correctiveId = (int)$created['id'];
			$this->lastCorrectiveWoId = $correctiveId;
			$assignee = $job['primaryUserId'] ?? null;
			if (is_string($assignee) && $assignee !== '') {
				try {
					$this->assign($job['uid'], $correctiveId, [
						'primaryUserId' => $assignee,
						'force' => true,
					]);
				} catch (\Throwable $e) {
					$this->logger->warning('Inspection follow-up assignee copy failed for WO ' . $correctiveId, [
						'exception' => $e,
					]);
				}
			}
		} catch (\Throwable $e) {
			// Unique race: another request inserted the follow-up first.
			$winner = $this->workOrders->findBySourceWoId($job['sourceId']);
			if ($winner !== null && InspectionFollowUpGuard::decide((int)$winner->getId()) === 'reuse') {
				$this->lastCorrectiveWoId = (int)$winner->getId();
				return;
			}
			if ($e instanceof \OCP\DB\Exception && $this->isUniqueViolation($e)) {
				$this->logger->warning('Inspection auto-corrective unique race without readable winner for WO ' . $job['sourceId'], [
					'exception' => $e,
				]);
				$this->lastDefectFollowUpFailed = true;
				return;
			}
			// Non-unique failures must not look like success — surface on Done payload.
			$this->logger->error('Inspection auto-corrective follow-up failed for WO ' . $job['sourceId'], [
				'exception' => $e,
			]);
			$this->lastDefectFollowUpFailed = true;
		}
	}

	/**
	 * photoFileId on defects is the mn_wo_photos.id for THIS work order (W7-R6).
	 *
	 * @param list<array{code: string, body: string, photoFileId: ?int}> $defects
	 */
	private function assertDefectPhotosBelongToWorkOrder(int $woId, array $defects): void
	{
		foreach ($defects as $i => $defect) {
			$photoId = $defect['photoFileId'] ?? null;
			if ($photoId === null) {
				continue;
			}
			try {
				$photo = $this->photos->findById($photoId);
			} catch (NotFoundException) {
				throw new ValidationException('validation_failed', 'Defect photo was not found on this work order.', [
					['field' => 'defects[' . $i . '].photoFileId', 'code' => 'photo_not_found'],
				]);
			}
			if ($photo->getWorkOrderId() !== $woId) {
				throw new ValidationException('validation_failed', 'Defect photo must belong to this work order.', [
					['field' => 'defects[' . $i . '].photoFileId', 'code' => 'photo_wrong_work_order'],
				]);
			}
		}
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{requesterName: ?string, requesterPhone: ?string, symptom: ?string, accessNotes: ?string}
	 */
	private function validatedIntakeFields(array $body): array
	{
		return [
			'requesterName' => $this->validator->boundedOptionalString($body, 'requesterName', 128, 'requester_name_too_long'),
			'requesterPhone' => $this->validator->boundedOptionalString($body, 'requesterPhone', 64, 'requester_phone_too_long'),
			'symptom' => $this->validator->boundedOptionalString($body, 'symptom', 512, 'symptom_too_long'),
			'accessNotes' => $this->validator->boundedOptionalString($body, 'accessNotes', 512, 'access_notes_too_long'),
		];
	}

	/**
	 * *→cancelled (§8.1, §12.5): optional visit cancel, tour stop
	 * auto-remove + renumber.
	 *
	 * @param array<string, mixed> $body
	 */
	private function applyCancelled(string $uid, WorkOrder $wo, array $body, int $now): void
	{
		$visitId = $wo->getVisitId();
		if ($visitId !== null && $this->validator->boolOrDefault($body, 'cancelVisit', false)) {
			// Conditional close — if the visit is no longer open this is a
			// no-op, matching "linked visit stays scheduled unless…".
			$this->visits->closeScheduled($visitId, [
				'status' => Visit::STATUS_CANCELLED,
				'updated_at' => $now,
			]);
		}

		$stop = $this->tourStops->findByWorkOrder((int)$wo->getId());
		if ($stop !== null) {
			$tourId = $stop->getTourId();
			$this->tourStops->delete($stop);
			$this->renumberTourStops($tourId);
		}
	}

	private function renumberTourStops(int $tourId): void
	{
		$position = 0;
		foreach ($this->tourStops->findByTour($tourId) as $stop) {
			if ($stop->getPosition() !== $position) {
				$stop->setPosition($position);
				$this->tourStops->update($stop);
			}
			$position++;
		}
	}

	/**
	 * @param list<array{sku: string, label: string, qty: int}> $skuLines
	 */
	private function dispatchInventoryFlange(string $actorUid, int $id, array $skuLines): void
	{
		$result = $this->inventoryFlange->issueForWorkOrder($actorUid, $id, $skuLines);
		$sync = $result['sync'];
		$code = $result['code'] ?? null;
		if ($sync === 'unavailable') {
			$sync = 'failed';
			$code = $code ?? 'sibling_unavailable';
		}
		if ($sync === 'disabled') {
			// Toggle off is not a soft-fail — leave code for diagnostics only.
			$code = $code ?? 'flange_disabled';
		}
		try {
			$wo = $this->workOrders->findById($id);
			$this->eventDispatcher->dispatchTyped(new WorkOrderClosedEvent(
				$id,
				$wo->getNumber(),
				$wo->getCustomerId(),
				$wo->getEquipmentId(),
				$skuLines,
			));
		} catch (\Throwable $e) {
			$this->logger->warning('WorkOrderClosedEvent listener failed for WO ' . $id, ['exception' => $e]);
			if ($sync === 'ok') {
				$sync = 'failed';
				$code = 'event_listener_failed';
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(WorkOrderMapper::TABLE)
			->set('inventory_sync', $qb->createNamedParameter($sync))
			->set('inventory_sync_code', $qb->createNamedParameter($code))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	// ── Gates for assign ────────────────────────────────────────────────

	/**
	 * @return list<array<string, mixed>> warn-level findings acknowledged by force
	 */
	private function runAssignGates(WorkOrder $wo, string $primary, bool $force): array
	{
		$warnings = [];

		$missing = $this->skills->missingSkillsFor((int)$wo->getId(), $primary);
		foreach ($this->skillsAssign->evaluate($this->policies->skillsEnforcement(), $missing, $force) as $warning) {
			$warnings[] = $warning;
		}

		$capacityPolicy = $this->policies->capacityEnforcement();
		if ($capacityPolicy !== PolicyService::ENFORCEMENT_OFF && $wo->getEstimatedMinutes() !== null) {
			$assessment = $this->capacity->assessAssign(
				$primary,
				$wo->getDueOn(),
				$wo->getEstimatedMinutes(),
				(int)$wo->getId(),
			);
			if ($assessment['exceeds']) {
				if ($capacityPolicy === PolicyService::ENFORCEMENT_BLOCK) {
					throw new ValidationException('capacity_exceeded', 'This assignment would exceed the technician’s daily capacity.', [
						['field' => 'primaryUserId', 'code' => 'capacity_exceeded'],
					]);
				}
				if (!$force) {
					throw new ConflictException('capacity_warning', 'This assignment would exceed the technician’s daily capacity. Confirm to assign anyway.', $assessment);
				}
				$warnings[] = ['code' => 'capacity_exceeded'] + $assessment;
			}
		}
		return $warnings;
	}

	// ── Create internals ────────────────────────────────────────────────

	private function insertWithNumber(WorkOrder $wo, ?int $procedureId): WorkOrder
	{
		$attempt = 0;
		while (true) {
			$attempt++;
			$this->db->beginTransaction();
			try {
				$wo->setNumber($this->nextNumber());
				$inserted = $this->workOrders->insert(clone $wo);
				if ($procedureId !== null) {
					$this->checklist->snapshotProcedure((int)$inserted->getId(), $procedureId);
				}
				$this->db->commit();
				return $inserted;
			} catch (\OCP\DB\Exception $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				if ($attempt < self::NUMBER_RETRIES && $this->isUniqueViolation($e)) {
					continue;
				}
				throw $e;
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		}
	}

	/**
	 * `WO-YYYY-#####` (§14.2) — the unique index on `number` is the final
	 * arbiter; callers retry on violation.
	 */
	private function nextNumber(): string
	{
		$year = substr($this->clock->today(), 0, 4);
		$next = $this->workOrders->maxNumberForYear($year) + 1;
		return sprintf('WO-%s-%05d', $year, $next);
	}

	private function isUniqueViolation(\OCP\DB\Exception $e): bool
	{
		return in_array($e->getReason(), [
			\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
			\OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
		], true);
	}

	// ── Validation helpers ──────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedKind(array $body): string
	{
		$kind = $this->validator->boundedOptionalString($body, 'kind', 16, 'kind_too_long') ?? WorkOrder::KIND_CORRECTIVE;
		if (!in_array($kind, WorkOrder::KINDS, true)) {
			throw new ValidationException('validation_failed', 'kind must be one of ' . implode(', ', WorkOrder::KINDS) . '.', [
				['field' => 'kind', 'code' => 'invalid_value'],
			]);
		}
		return $kind;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedPriority(array $body): ?string
	{
		$priority = $this->validator->boundedOptionalString($body, 'priority', 16, 'priority_too_long');
		if ($priority === null) {
			return null;
		}
		if (!in_array($priority, WorkOrder::PRIORITIES, true)) {
			throw new ValidationException('validation_failed', 'priority must be one of ' . implode(', ', WorkOrder::PRIORITIES) . '.', [
				['field' => 'priority', 'code' => 'invalid_value'],
			]);
		}
		return $priority;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{title: ?string, description: ?string, dueOn: ?string,
	 *               estimatedMinutes: ?int, priority: ?string,
	 *               equipmentId: ?int, siteId: ?int}
	 */
	private function validatedEditableFields(array $body, int $customerId, bool $required): array
	{
		$dueOn = $this->validator->optionalString($body, 'dueOn');
		return [
			'title' => $required
				? $this->validator->requiredString($body, 'title', 'title_required', 255, 'title_too_long')
				: $this->validator->boundedOptionalString($body, 'title', 255, 'title_too_long'),
			'description' => $this->validator->boundedOptionalString($body, 'description', self::MAX_DESCRIPTION, 'description_too_long'),
			'dueOn' => ($dueOn === null || $dueOn === '') ? null : $this->validator->dueOn($dueOn, $this->clock->today()),
			'estimatedMinutes' => $this->validatedEstimatedMinutes($body),
			'priority' => $this->validatedPriority($body),
			'equipmentId' => $this->validatedEquipmentId($body, $customerId),
			'siteId' => $this->validatedSiteId($body, $customerId),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedEstimatedMinutes(array $body): ?int
	{
		if (!array_key_exists('estimatedMinutes', $body) || $body['estimatedMinutes'] === null) {
			return null;
		}
		$minutes = $body['estimatedMinutes'];
		if (!is_int($minutes) || $minutes < 1 || $minutes > self::MAX_ESTIMATED_MINUTES) {
			throw new ValidationException('validation_failed', 'estimatedMinutes must be between 1 and ' . self::MAX_ESTIMATED_MINUTES . '.', [
				['field' => 'estimatedMinutes', 'code' => 'out_of_range'],
			]);
		}
		return $minutes;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedEquipmentId(array $body, int $customerId): ?int
	{
		if (!array_key_exists('equipmentId', $body) || $body['equipmentId'] === null) {
			return null;
		}
		$equipmentId = $this->validator->intOrThrow($body, 'equipmentId');
		$equipment = $this->equipment->findById($equipmentId);
		if ($equipment->getCustomerId() !== $customerId) {
			throw new ValidationException('validation_failed', 'The equipment belongs to a different customer.', [
				['field' => 'equipmentId', 'code' => 'customer_mismatch'],
			]);
		}
		return $equipmentId;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedSiteId(array $body, int $customerId): ?int
	{
		if (!array_key_exists('siteId', $body) || $body['siteId'] === null) {
			return null;
		}
		$siteId = $this->validator->intOrThrow($body, 'siteId');
		$site = $this->sites->findById($siteId);
		if ($site->getCustomerId() !== $customerId) {
			throw new ValidationException('validation_failed', 'The site belongs to a different customer.', [
				['field' => 'siteId', 'code' => 'customer_mismatch'],
			]);
		}
		return $siteId;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedProcedureId(array $body): ?int
	{
		if (!array_key_exists('procedureId', $body) || $body['procedureId'] === null) {
			return null;
		}
		$procedureId = $this->validator->intOrThrow($body, 'procedureId');
		$procedure = $this->procedures->findById($procedureId);
		if (!$procedure->getActive()) {
			throw new ValidationException('validation_failed', 'This procedure is deactivated.', [
				['field' => 'procedureId', 'code' => 'inactive'],
			]);
		}
		return $procedureId;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{skipped: bool, reason: ?string}
	 */
	private function validatedProcedureSkip(array $body): array
	{
		$skipped = $this->validator->boolOrDefault($body, 'procedureSkipped', false);
		if (!$skipped) {
			return ['skipped' => false, 'reason' => null];
		}
		$reason = trim((string)$this->validator->boundedOptionalString($body, 'procedureSkipReason', 255, 'reason_too_long'));
		if (mb_strlen($reason) < self::MIN_SKIP_REASON) {
			throw new ValidationException('procedure_required', 'The skip reason must be at least ' . self::MIN_SKIP_REASON . ' characters.');
		}
		return ['skipped' => true, 'reason' => $reason];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedHelperUids(array $body): ?string
	{
		$raw = $body['helperUids'];
		if ($raw === null) {
			return null;
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new ValidationException('validation_failed', 'helperUids must be a list of user ids.', [
				['field' => 'helperUids', 'code' => 'invalid_type'],
			]);
		}
		if (count($raw) > self::MAX_HELPERS) {
			throw new ValidationException('validation_failed', 'At most ' . self::MAX_HELPERS . ' helpers are allowed.', [
				['field' => 'helperUids', 'code' => 'too_many'],
			]);
		}
		$uids = [];
		foreach ($raw as $uid) {
			if (!is_string($uid) || trim($uid) === '' || !$this->userManager->userExists(trim($uid))) {
				throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
			}
			$uids[] = trim($uid);
		}
		$uids = array_values(array_unique($uids));
		return $uids === [] ? null : json_encode($uids, JSON_UNESCAPED_UNICODE);
	}

	private function defaultVisitTitle(Visit $visit): string
	{
		$parts = [];
		try {
			$parts[] = $this->equipment->findById($visit->getEquipmentId())->getLabel();
		} catch (NotFoundException) {
			// keep going with what we have
		}
		$title = trim(implode(' — ', array_filter($parts, static fn (string $p) => $p !== '')));
		return $title !== '' ? mb_substr('PM: ' . $title, 0, 255) : 'Preventive maintenance';
	}

	private function isValidYmd(string $value): bool
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return false;
		}
		[$y, $m, $d] = array_map('intval', explode('-', $value));
		return checkdate($m, $d, $y);
	}

	// ── Enrichment ──────────────────────────────────────────────────────

	/**
	 * @param list<WorkOrder> $rows
	 * @return list<array<string, mixed>>
	 */
	public function enrich(array $rows): array
	{
		$customerIds = $equipmentIds = [];
		foreach ($rows as $wo) {
			$customerIds[$wo->getCustomerId()] = true;
			if ($wo->getEquipmentId() !== null) {
				$equipmentIds[$wo->getEquipmentId()] = true;
			}
		}
		$customerNames = $this->nameMap(CustomerMapper::TABLE, 'name', array_keys($customerIds));
		$equipmentLabels = $this->nameMap(EquipmentMapper::TABLE, 'label', array_keys($equipmentIds));

		$result = [];
		foreach ($rows as $wo) {
			$row = $wo->toApi();
			$row['customerName'] = $customerNames[$wo->getCustomerId()] ?? '';
			$row['equipmentLabel'] = $wo->getEquipmentId() !== null ? ($equipmentLabels[$wo->getEquipmentId()] ?? '') : '';
			$row['logHoursUrl'] = $this->hoursDeepLink->buildLogHoursUrl((string)$wo->getNumber());
			$row['recordTimeUrl'] = $this->recordTimeDeepLink->buildRecordTimeUrl((string)$wo->getNumber());
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	private function nameMap(string $table, string $column, array $ids): array
	{
		$map = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', $column)->from($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$map[(int)$row['id']] = (string)$row[$column];
			}
			$result->closeCursor();
		}
		return $map;
	}
}
