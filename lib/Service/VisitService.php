<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\Plan;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
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
	) {
	}

	// ── Queries ─────────────────────────────────────────────────────────

	/**
	 * S8 due board: single query, server-side bucketing.
	 *
	 * @return array<string, mixed>
	 */
	public function due(string $currentUid, bool $mine): array
	{
		$today = $this->clock->today();
		$rows = $this->visits->findDue($this->dueBoard->maxDueOn($today), $mine ? $currentUid : null);
		$buckets = [
			DueBoard::BUCKET_OVERDUE => [],
			DueBoard::BUCKET_TODAY => [],
			DueBoard::BUCKET_NEXT7 => [],
			DueBoard::BUCKET_LATER => [],
		];
		foreach ($this->enrich($rows) as $row) {
			$bucket = $this->dueBoard->bucketFor($row['dueOn'], $today);
			if ($bucket !== null) {
				$buckets[$bucket][] = $row;
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
	 * plan is inactive (S18).
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
		$notes = array_key_exists('notes', $body) ? $this->validator->visitNotes($body) : false;
		$now = $this->clock->now();

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

		$this->db->beginTransaction();
		try {
			if (!$this->visits->closeScheduled($visitId, $set)) {
				$this->db->rollBack();
				$this->throwNotOpenOrNotFound($visitId);
			}
			$visit = $this->visits->findById($visitId);
			$planId = $visit->getPlanId();

			// Always lock before deciding follow-up: S9 force-delete can remove
			// the plan mid-flight, and S18 deactivate must win under the same lock.
			if (!$this->plans->lockRow($planId)) {
				$plan = null;
			} else {
				$plan = $this->plans->findById($planId);
				if ($plan->getActive() && $this->visits->findOpenByPlan($planId) === null) {
					$dueOn = $this->intervals->addInterval(
						$anchor($doneOn),
						$plan->getIntervalUnit(),
						$plan->getIntervalCount(),
					);
					$nextVisit = $this->insertNextVisit($visit, $plan, $dueOn, $now);
				}
			}
			$this->db->commit();
		} catch (ConflictException | NotFoundException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'visit' => $this->visits->findById($visitId)->toApi(),
			'nextVisit' => $nextVisit?->toApi(),
			'planActive' => $plan !== null && $plan->getActive(),
		];
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
	 * name) and plan interval metadata to visit rows for the UI.
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

	/**
	 * @param list<int> $ids
	 * @return array<int, array{unit: string, count: int, active: bool}>
	 */
	private function planMetaMap(array $ids): array
	{
		$map = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'interval_unit', 'interval_count', 'active')->from(PlanMapper::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$map[(int)$row['id']] = [
					'unit' => (string)$row['interval_unit'],
					'count' => (int)$row['interval_count'],
					'active' => self::dbBool($row['active']),
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
