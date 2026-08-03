<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\InspectionObligationMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\Plan;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Visit lifecycle engine — owns every state transition and the D6 invariant
 * (≤ 1 open visit per plan). All terminal transitions follow S6: conditional
 * UPDATE + optional next-visit INSERT inside one transaction.
 *
 * W5: pure `meter` plans never call IntervalCalculator on close (AC-W5-2).
 * W1: due-board rows may carry an open work-order badge + soft deep links.
 */
class VisitService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly VisitMapper $visits,
		private readonly PlanMapper $plans,
		private readonly CustomerMapper $customers,
		private readonly EquipmentMapper $equipment,
		private readonly MaintTypeMapper $maintTypes,
		private readonly IntervalCalculator $intervals,
		private readonly DueBoard $dueBoard,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
		private readonly WorkOrderMapper $workOrders,
		private readonly ProjectCheckHoursDeepLinkService $hoursDeepLink,
		private readonly ArbeitszeitCheckDeepLinkService $recordTimeDeepLink,
		private readonly MeterService $meters,
		private readonly InspectionObligationMapper $inspectionObligations,
	) {
	}

	// ── Queries ─────────────────────────────────────────────────────────

	/**
	 * Single visit by id with due-board enrichment (AC-C companion VisitDetail).
	 * Does not depend on the due-horizon bucket — open or closed visits resolve.
	 *
	 * @return array<string, mixed>
	 */
	public function get(string $currentUid, int $id): array
	{
		// All authenticated app users may read visit detail (same as due board reads).
		$enriched = $this->enrich([$this->visits->findById($id)]);
		return $enriched[0];
	}

	/**
	 * S8 due board: single query, server-side bucketing.
	 *
	 * @return array<string, mixed>
	 */
	public function due(string $currentUid, bool $mine, ?string $kind = null): array
	{
		if ($kind !== null && $kind !== '' && $kind !== 'inspection' && $kind !== 'all') {
			throw new ValidationException('invalid_query', 'kind must be inspection or all.');
		}
		$inspectionOnly = $kind === 'inspection';
		$today = $this->clock->today();
		$maxDue = $this->dueBoard->maxDueOn($today);
		$rows = $this->visits->findDue($maxDue, $mine ? $currentUid : null);
		$buckets = [
			DueBoard::BUCKET_OVERDUE => [],
			DueBoard::BUCKET_TODAY => [],
			DueBoard::BUCKET_NEXT7 => [],
			DueBoard::BUCKET_LATER => [],
		];
		$visitIdsOnBoard = [];
		foreach ($this->enrich($rows) as $row) {
			$row['rowKind'] = 'visit';
			$row['isInspection'] = $this->rowLooksLikeInspection($row);
			if ($inspectionOnly && !$row['isInspection']) {
				continue;
			}
			$bucket = $this->dueBoard->bucketFor($row['dueOn'], $today);
			if ($bucket !== null) {
				$buckets[$bucket][] = $row;
				$visitIdsOnBoard[(int)$row['id']] = true;
			}
		}
		// CORE §13.1 + W7: open preventive/inspection WOs. Skip WOs whose linked
		// visit is already on the board (badge already covers that job).
		foreach ($this->enrichOpenPreventiveWorkOrders($maxDue, $mine ? $currentUid : null, $visitIdsOnBoard) as $woRow) {
			$woRow['isInspection'] = (($woRow['kind'] ?? '') === WorkOrder::KIND_INSPECTION);
			if ($inspectionOnly && !$woRow['isInspection']) {
				continue;
			}
			$bucket = $this->dueBoard->bucketFor($woRow['dueOn'], $today);
			if ($bucket !== null) {
				$buckets[$bucket][] = $woRow;
			}
		}
		return [
			'overdue' => $buckets[DueBoard::BUCKET_OVERDUE],
			'today' => $buckets[DueBoard::BUCKET_TODAY],
			'next7' => $buckets[DueBoard::BUCKET_NEXT7],
			'later' => $buckets[DueBoard::BUCKET_LATER],
			'counts' => [
				'overdue' => count($buckets[DueBoard::BUCKET_OVERDUE]),
				'today' => count($buckets[DueBoard::BUCKET_TODAY]),
				'next7' => count($buckets[DueBoard::BUCKET_NEXT7]),
				'later' => count($buckets[DueBoard::BUCKET_LATER]),
			],
			'today_date' => $today,
			'kind' => $inspectionOnly ? 'inspection' : 'all',
		];
	}

	/**
	 * Filtered list with S7 envelope.
	 *
	 * @param array<string, ?string> $query raw query params
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $currentUid, array $query): array
	{
		$page = $this->validator->pagination($query['limit'] ?? null, $query['offset'] ?? null);
		$filters = [];

		$from = trim((string)($query['from'] ?? ''));
		$to = trim((string)($query['to'] ?? ''));
		if ($from !== '') {
			if (!$this->intervals->isValidYmd($from)) {
				throw new ValidationException('invalid_query', 'from must be a valid Y-m-d date.');
			}
			$filters['from'] = $from;
		}
		if ($to !== '') {
			if (!$this->intervals->isValidYmd($to)) {
				throw new ValidationException('invalid_query', 'to must be a valid Y-m-d date.');
			}
			$filters['to'] = $to;
		}
		if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
			throw new ValidationException('invalid_query', 'from must not be after to.');
		}

		$status = trim((string)($query['status'] ?? ''));
		if ($status !== '') {
			if (!in_array($status, Visit::STATUSES, true)) {
				throw new ValidationException('invalid_query', 'status must be scheduled, done, skipped, or cancelled.');
			}
			$filters['status'] = $status;
		}

		if (($query['mine'] ?? '') === '1') {
			$filters['mineUid'] = $currentUid;
		}

		foreach (['customerId' => 'customerId', 'equipmentId' => 'equipmentId', 'planId' => 'planId'] as $param => $key) {
			$raw = trim((string)($query[$param] ?? ''));
			if ($raw !== '') {
				if (!preg_match('/^\d+$/', $raw)) {
					throw new ValidationException('invalid_query', $param . ' must be a positive integer.');
				}
				$filters[$key] = (int)$raw;
			}
		}

		$result = $this->visits->searchVisits($filters, $page['limit'], $page['offset']);
		return [
			'data' => $this->enrich($result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	// ── Terminal transitions (S6) ───────────────────────────────────────

	/**
	 * Complete: close visit, roll next due from done_on (D5), unless the
	 * plan is inactive (S18) or pure meter (AC-W5-2).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function complete(string $uid, int $visitId, array $body): array
	{
		$today = $this->clock->today();
		$doneOn = $this->validator->doneOn($this->validator->optionalString($body, 'doneOn'), $today);
		return $this->close($uid, $visitId, Visit::STATUS_DONE, $doneOn, $body, static fn (string $done): string => $done);
	}

	/**
	 * Skip: close visit, roll next due from *today* (S4) — even when skipped
	 * before due_on. Backlog clears deterministically.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function skip(string $uid, int $visitId, array $body): array
	{
		$today = $this->clock->today();
		return $this->close($uid, $visitId, Visit::STATUS_SKIPPED, $today, $body, fn (): string => $this->clock->today());
	}

	/**
	 * Cancel (office): terminal, never creates a follow-up (recovery = S14).
	 *
	 * @return array<string, mixed>
	 */
	public function cancel(int $visitId): array
	{
		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			$closed = $this->visits->closeScheduled($visitId, [
				'status' => Visit::STATUS_CANCELLED,
				'updated_at' => $now,
			]);
			if (!$closed) {
				$this->db->rollBack();
				$this->throwNotOpenOrNotFound($visitId);
			}
			$this->db->commit();
		} catch (ConflictException | NotFoundException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['visit' => $this->visits->findById($visitId)->toApi(), 'nextVisit' => null];
	}

	/**
	 * S15 reschedule / notes edit (office) — allowed only while scheduled.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function reschedule(int $visitId, array $body): array
	{
		$set = ['updated_at' => $this->clock->now()];
		if (array_key_exists('dueOn', $body)) {
			$set['due_on'] = $this->validator->dueOn($this->validator->optionalString($body, 'dueOn'), $this->clock->today());
		}
		if (array_key_exists('notes', $body)) {
			$set['notes'] = $this->validator->visitNotes($body);
		}
		if (!$this->visits->updateScheduled($visitId, $set)) {
			$this->throwNotOpenOrNotFound($visitId);
		}
		return $this->visits->findById($visitId)->toApi();
	}

	/**
	 * S12 assign (office): `userId` string assigns, null clears; allowed only
	 * while scheduled. No app-access check on the assignee by design.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function assign(int $visitId, array $body): array
	{
		if (!array_key_exists('userId', $body)) {
			throw new ValidationException('validation_failed', 'userId is required (string or null).', [
				['field' => 'userId', 'code' => 'required'],
			]);
		}
		$userId = $body['userId'];
		if ($userId !== null) {
			if (!is_string($userId) || trim($userId) === '') {
				throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
			}
			$userId = trim($userId);
			if (!$this->userManager->userExists($userId)) {
				throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
			}
		}
		$updated = $this->visits->updateScheduled($visitId, [
			'assigned_uid' => $userId,
			'updated_at' => $this->clock->now(),
		]);
		if (!$updated) {
			$this->throwNotOpenOrNotFound($visitId);
		}
		return $this->visits->findById($visitId)->toApi();
	}

	/**
	 * W1 (AC-W1-2): complete a visit as part of a work-order `done` — the
	 * caller owns the transaction. Behaviour matches complete()'s roll path
	 * unless `$rollNext` is false (CORE I2 fail_blocks_roll).
	 *
	 * @return array{closed: bool, visit: ?Visit, nextVisit: ?Visit}
	 */
	public function completeWithinTransaction(string $uid, int $visitId, string $doneOn, int $now, bool $rollNext = true): array
	{
		$closed = $this->visits->closeScheduled($visitId, [
			'status' => Visit::STATUS_DONE,
			'done_by' => $uid,
			'done_at' => $now,
			'done_on' => $doneOn,
			'updated_at' => $now,
		]);
		if (!$closed) {
			return ['closed' => false, 'visit' => null, 'nextVisit' => null];
		}
		$visit = $this->visits->findById($visitId);
		if (!$rollNext) {
			return ['closed' => true, 'visit' => $visit, 'nextVisit' => null];
		}
		$rolled = $this->rollNextVisit($visit, $doneOn, $now);
		return ['closed' => true, 'visit' => $visit, 'nextVisit' => $rolled['nextVisit']];
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * Shared complete/skip path. `$anchor` computes the next-due anchor date
	 * from done_on (complete → done_on itself, skip → today).
	 *
	 * @param array<string, mixed> $body
	 * @param callable(string): string $anchor
	 * @return array<string, mixed>
	 */
	private function close(string $uid, int $visitId, string $status, string $doneOn, array $body, callable $anchor): array
	{
		// CORE §21 I3 / AC-W7-3…5: obligation visits must close via inspection WO
		// (result, inspector, defects, failBlocksRoll). Plain complete/skip would
		// roll next due without evidence — fail closed here.
		$openVisit = $this->visits->findById($visitId);
		$this->assertCloseableWithoutInspectionWorkOrder($openVisit);

		$notes = array_key_exists('notes', $body) ? $this->validator->visitNotes($body) : false;
		$now = $this->clock->now();
		$today = $this->clock->today();
		$closingReading = $this->optionalClosingReading($body);

		$set = [
			'status' => $status,
			'done_by' => $uid,
			'done_at' => $now,
			'done_on' => $doneOn,
			'updated_at' => $now,
		];
		if ($notes !== false) {
			$set['notes'] = $notes;
		}

		$nextVisit = null;
		$plan = null;
		$closingReadingApi = null;

		$this->db->beginTransaction();
		try {
			if (!$this->visits->closeScheduled($visitId, $set)) {
				$this->db->rollBack();
				$this->throwNotOpenOrNotFound($visitId);
			}
			$visit = $this->visits->findById($visitId);
			if ($closingReading !== null) {
				$closingReadingApi = $this->meters->recordClosingWithinTransaction(
					$uid,
					$visit->getEquipmentId(),
					$closingReading,
					$today,
					$now,
				);
			}
			$rolled = $this->rollNextVisit($visit, $anchor($doneOn), $now);
			$plan = $rolled['plan'];
			$nextVisit = $rolled['nextVisit'];
			$this->db->commit();
		} catch (ConflictException | NotFoundException | ValidationException $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'visit' => $this->visits->findById($visitId)->toApi(),
			'nextVisit' => $nextVisit?->toApi(),
			'planActive' => $plan !== null && $plan->getActive(),
			'closingReading' => $closingReadingApi,
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return ?array<string, mixed>
	 */
	private function optionalClosingReading(array $body): ?array
	{
		if (!array_key_exists('closingReading', $body) || $body['closingReading'] === null) {
			return null;
		}
		if (!is_array($body['closingReading'])) {
			throw new ValidationException('validation_failed', 'closingReading must be an object.', [
				['field' => 'closingReading', 'code' => 'invalid_type'],
			]);
		}
		return $body['closingReading'];
	}

	/**
	 * Shared follow-up decision for every terminal close. Runs inside the
	 * caller's transaction.
	 *
	 * W5 invariants M1/M2: interval math runs only for `interval`/`either`
	 * plans — pure `meter` plans never touch the IntervalCalculator.
	 *
	 * @return array{plan: ?Plan, nextVisit: ?Visit}
	 */
	private function rollNextVisit(Visit $visit, string $anchorDate, int $now): array
	{
		$planId = $visit->getPlanId();
		if (!$this->plans->lockRow($planId)) {
			return ['plan' => null, 'nextVisit' => null];
		}
		$plan = $this->plans->findById($planId);
		$nextVisit = null;
		if ($plan->getActive()
			&& $plan->usesIntervalTrigger()
			&& $this->visits->findOpenByPlan($planId) === null
		) {
			$dueOn = $this->intervals->addInterval(
				$anchorDate,
				$plan->getIntervalUnit(),
				$plan->getIntervalCount(),
			);
			$nextVisit = $this->insertNextVisit($visit, $plan, $dueOn, $now);
		}
		return ['plan' => $plan, 'nextVisit' => $nextVisit];
	}

	private function insertNextVisit(Visit $closed, Plan $plan, string $dueOn, int $now): Visit
	{
		$next = new Visit();
		$next->setPlanId((int)$plan->getId());
		$next->setEquipmentId($closed->getEquipmentId());
		$next->setCustomerId($closed->getCustomerId());
		$next->setMaintTypeId($plan->getMaintTypeId());
		$next->setDueOn($dueOn);
		$next->setStatus(Visit::STATUS_SCHEDULED);
		$next->setAssignedUid($closed->getAssignedUid());
		$next->setCreatedAt($now);
		$next->setUpdatedAt($now);
		return $this->visits->insert($next);
	}

	/**
	 * @return never
	 */
	private function throwNotOpenOrNotFound(int $visitId): void
	{
		if ($this->visits->exists($visitId)) {
			throw new ConflictException('visit_not_open', 'This visit was already closed.');
		}
		throw new NotFoundException();
	}

	/**
	 * Adds display fields (customer name, equipment label, maintenance type
	 * name), plan interval metadata, and optional open work-order badge.
	 *
	 * @param list<Visit> $rows
	 * @return list<array<string, mixed>>
	 */
	private function enrich(array $rows): array
	{
		$customerIds = $equipmentIds = $maintTypeIds = $planIds = [];
		foreach ($rows as $visit) {
			$customerIds[$visit->getCustomerId()] = true;
			$equipmentIds[$visit->getEquipmentId()] = true;
			$maintTypeIds[$visit->getMaintTypeId()] = true;
			$planIds[$visit->getPlanId()] = true;
		}

		$customerNames = $this->nameMap(CustomerMapper::TABLE, 'name', array_keys($customerIds));
		$equipmentLabels = $this->nameMap(EquipmentMapper::TABLE, 'label', array_keys($equipmentIds));
		$maintTypeNames = $this->nameMap(MaintTypeMapper::TABLE, 'name', array_keys($maintTypeIds));
		$planMeta = $this->planMetaMap(array_keys($planIds));
		$openWoByVisit = $this->openWorkOrderMap($rows);

		$result = [];
		foreach ($rows as $visit) {
			$row = $visit->toApi();
			$row['customerName'] = $customerNames[$visit->getCustomerId()] ?? '';
			$row['equipmentLabel'] = $equipmentLabels[$visit->getEquipmentId()] ?? '';
			$row['maintTypeName'] = $maintTypeNames[$visit->getMaintTypeId()] ?? '';
			$meta = $planMeta[$visit->getPlanId()] ?? null;
			$row['intervalUnit'] = $meta['unit'] ?? null;
			$row['intervalCount'] = $meta['count'] ?? null;
			$row['planActive'] = $meta['active'] ?? false;
			$row['triggerKind'] = $meta['triggerKind'] ?? 'interval';
			$row['meterCode'] = $meta['meterCode'] ?? null;
			$row['meterThreshold'] = $meta['meterThreshold'] ?? null;
			$row['contractNotes'] = $meta['contractNotes'] ?? null;
			$row['openWorkOrder'] = $openWoByVisit[(int)$visit->getId()] ?? null;
			$row['isInspection'] = $this->rowLooksLikeInspection($row);
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * Standalone preventive WO cards for the due board (CORE §13.1).
	 * Skips WOs whose linked visit is already listed (avoids duplicate jobs).
	 *
	 * @param array<int, true> $visitIdsOnBoard
	 * @return list<array<string, mixed>>
	 */
	private function enrichOpenPreventiveWorkOrders(string $maxDueOn, ?string $mineUid, array $visitIdsOnBoard): array
	{
		$workOrders = $this->workOrders->findOpenPreventiveDue($maxDueOn, $mineUid);
		if ($workOrders === []) {
			return [];
		}

		$customerIds = $equipmentIds = [];
		$filtered = [];
		foreach ($workOrders as $wo) {
			$visitId = $wo->getVisitId();
			if ($visitId !== null && isset($visitIdsOnBoard[$visitId])) {
				continue;
			}
			$filtered[] = $wo;
			$customerIds[$wo->getCustomerId()] = true;
			if ($wo->getEquipmentId() !== null) {
				$equipmentIds[$wo->getEquipmentId()] = true;
			}
		}
		if ($filtered === []) {
			return [];
		}

		$customerNames = $this->nameMap(CustomerMapper::TABLE, 'name', array_keys($customerIds));
		$equipmentLabels = $this->nameMap(EquipmentMapper::TABLE, 'label', array_keys($equipmentIds));

		$result = [];
		foreach ($filtered as $wo) {
			$number = (string)$wo->getNumber();
			$dueOn = (string)$wo->getDueOn();
			$result[] = [
				'rowKind' => 'workOrder',
				'id' => (int)$wo->getId(),
				'number' => $number,
				'title' => $wo->getTitle(),
				'status' => $wo->getStatus(),
				'kind' => $wo->getKind(),
				'priority' => $wo->getPriority(),
				'dueOn' => $dueOn,
				'customerId' => $wo->getCustomerId(),
				'customerName' => $customerNames[$wo->getCustomerId()] ?? '',
				'equipmentId' => $wo->getEquipmentId(),
				'equipmentLabel' => $wo->getEquipmentId() !== null
					? ($equipmentLabels[$wo->getEquipmentId()] ?? '')
					: '',
				'visitId' => $wo->getVisitId(),
				'primaryUserId' => $wo->getPrimaryUserId(),
				'logHoursUrl' => $this->hoursDeepLink->buildLogHoursUrl($number),
				'recordTimeUrl' => $this->recordTimeDeepLink->buildRecordTimeUrl($number),
			];
		}
		return $result;
	}

	/**
	 * @param list<Visit> $rows
	 * @return array<int, array{id: int, number: string, status: string, logHoursUrl: ?string, recordTimeUrl: ?string}>
	 */
	private function openWorkOrderMap(array $rows): array
	{
		$visitIds = [];
		foreach ($rows as $visit) {
			$visitIds[] = (int)$visit->getId();
		}
		$map = [];
		foreach ($this->workOrders->findOpenByVisitIds($visitIds) as $wo) {
			$visitId = $wo->getVisitId();
			if ($visitId !== null && !isset($map[$visitId])) {
				$number = (string)$wo->getNumber();
				$map[$visitId] = [
					'id' => (int)$wo->getId(),
					'number' => $number,
					'status' => $wo->getStatus(),
					'kind' => $wo->getKind(),
					'logHoursUrl' => $this->hoursDeepLink->buildLogHoursUrl($number),
					'recordTimeUrl' => $this->recordTimeDeepLink->buildRecordTimeUrl($number),
				];
			}
		}
		return $map;
	}

	/**
	 * W7: obligation-linked plans carry a stable contractNotes prefix.
	 *
	 * @param array<string, mixed> $row
	 */
	private function rowLooksLikeInspection(array $row): bool
	{
		if (($row['kind'] ?? '') === WorkOrder::KIND_INSPECTION) {
			return true;
		}
		$notes = (string)($row['contractNotes'] ?? '');
		if (str_starts_with($notes, 'W7 inspection obligation:')) {
			return true;
		}
		$open = $row['openWorkOrder'] ?? null;
		if (is_array($open) && ($open['kind'] ?? '') === WorkOrder::KIND_INSPECTION) {
			return true;
		}
		return false;
	}

	/**
	 * Plain visit complete/skip must not close Prüfpflichten visits (CORE I3).
	 * WO Done still uses {@see completeWithinTransaction()} and is unaffected.
	 */
	private function assertCloseableWithoutInspectionWorkOrder(Visit $visit): void
	{
		$obligation = $this->inspectionObligations->findByPlanId($visit->getPlanId());
		if ($obligation === null) {
			return;
		}
		throw new ConflictException(
			'inspection_requires_work_order',
			'Inspection visits must be closed via an inspection work order (result and inspector required).',
			[
				'planId' => $visit->getPlanId(),
				'obligationId' => (int)$obligation->getId(),
			],
		);
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

	/**
	 * @param list<int> $ids
	 * @return array<int, array{unit: string, count: int, active: bool, triggerKind: string, meterCode: ?string, meterThreshold: ?string}>
	 */
	private function planMetaMap(array $ids): array
	{
		$map = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'interval_unit', 'interval_count', 'active', 'trigger_kind', 'meter_code', 'meter_threshold', 'contract_notes')
				->from(PlanMapper::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$map[(int)$row['id']] = [
					'unit' => (string)$row['interval_unit'],
					'count' => (int)$row['interval_count'],
					'active' => self::dbBool($row['active']),
					'triggerKind' => (string)($row['trigger_kind'] ?? 'interval'),
					'meterCode' => $row['meter_code'] !== null && $row['meter_code'] !== ''
						? (string)$row['meter_code']
						: null,
					'meterThreshold' => $row['meter_threshold'] !== null && $row['meter_threshold'] !== ''
						? (string)$row['meter_threshold']
						: null,
					'contractNotes' => $row['contract_notes'] !== null && $row['contract_notes'] !== ''
						? (string)$row['contract_notes']
						: null,
				];
			}
			$result->closeCursor();
		}
		return $map;
	}

	/**
	 * Portable boolean decode for raw SQL rows (MySQL 0/1, PostgreSQL t/f).
	 * Never cast with `(bool)$string` — `(bool)'f' === true` in PHP.
	 */
	private static function dbBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int)$value !== 0;
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			return $normalized === '1'
				|| $normalized === 't'
				|| $normalized === 'true'
				|| $normalized === 'yes'
				|| $normalized === 'on';
		}
		return false;
	}
}
