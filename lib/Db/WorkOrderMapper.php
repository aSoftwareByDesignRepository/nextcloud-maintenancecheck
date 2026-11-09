<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WorkOrder>
 */
class WorkOrderMapper extends QBMapper
{
	public const TABLE = 'mn_work_orders';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WorkOrder::class);
	}

	public function findById(int $id): WorkOrder
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	public function findBySourceWoId(int $sourceWoId): ?WorkOrder
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('source_wo_id', $qb->createNamedParameter($sourceWoId, \PDO::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * F18 dedupe lookup — one draft per (ref_type, ref_id).
	 */
	public function findByRef(string $refType, string $refId): ?WorkOrder
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('ref_type', $qb->createNamedParameter($refType)))
			->andWhere($qb->expr()->eq('ref_id', $qb->createNamedParameter($refId)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function exists(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Conditional status transition (mirror of the visits S6 mechanism,
	 * CORE R7): UPDATE … WHERE id AND status IN ($fromStatuses). Returns
	 * true iff exactly this call performed the transition.
	 *
	 * @param list<string> $fromStatuses
	 * @param array<string, string|int|bool|null> $set snake_case columns
	 */
	public function transition(int $id, array $fromStatuses, array $set): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach ($set as $column => $value) {
			if ($value === null) {
				$qb->set($column, $qb->createNamedParameter(null, \PDO::PARAM_NULL));
			} elseif (is_bool($value)) {
				$qb->set($column, $qb->createNamedParameter($value, \PDO::PARAM_BOOL));
			} elseif (is_int($value)) {
				$qb->set($column, $qb->createNamedParameter($value, \PDO::PARAM_INT));
			} else {
				$qb->set($column, $qb->createNamedParameter($value));
			}
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($fromStatuses, IQueryBuilder::PARAM_STR_ARRAY)));
		return $qb->executeStatement() > 0;
	}

	/**
	 * Write-lock a work order row for the duration of the transaction.
	 */
	public function lockRow(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Invariant D1 / §14.2: `visit_id` is unique among **non-cancelled** WOs.
	 * Must be called inside a transaction that holds the visit row lock.
	 */
	public function findNonCancelledByVisit(int $visitId): ?WorkOrder
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('visit_id', $qb->createNamedParameter($visitId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(WorkOrder::STATUS_CANCELLED)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<WorkOrder>
	 */
	public function findOpenByVisitIds(array $visitIds): array
	{
		if ($visitIds === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk($visitIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('visit_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($this->findEntities($qb) as $wo) {
				$out[] = $wo;
			}
		}
		return $out;
	}

	/**
	 * Highest existing sequence for `WO-{year}-#####` allocation. Runs inside
	 * the insert transaction; the unique index on `number` is the final
	 * arbiter (caller retries on constraint violation).
	 */
	public function maxNumberForYear(string $year): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('number')->from($this->getTableName())
			->where($qb->expr()->like('number', $qb->createNamedParameter('WO-' . $year . '-%')))
			->orderBy('number', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return 0;
		}
		$parts = explode('-', (string)$row['number']);
		return (int)end($parts);
	}

	/**
	 * Filtered list with S7 pagination envelope inputs.
	 *
	 * @param array{status?: string, kind?: string, priority?: string, customerId?: int,
	 *              equipmentId?: int, mineUid?: string, q?: string, from?: string, to?: string,
	 *              open?: bool, dueOn?: string, primaryUid?: string} $filters
	 * @return array{data: list<WorkOrder>, total: int}
	 */
	public function search(array $filters, int $limit, int $offset): array
	{
		$apply = function ($qb) use ($filters): void {
			$qb->from($this->getTableName());
			$qb->where($qb->expr()->neq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			if (isset($filters['status'])) {
				$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
			}
			if (($filters['open'] ?? false) === true) {
				$qb->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
			}
			if (isset($filters['kind'])) {
				$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($filters['kind'])));
			}
			if (isset($filters['priority'])) {
				$qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter($filters['priority'])));
			}
			if (isset($filters['customerId'])) {
				$qb->andWhere($qb->expr()->eq('customer_id', $qb->createNamedParameter($filters['customerId'], \PDO::PARAM_INT)));
			}
			if (isset($filters['equipmentId'])) {
				$qb->andWhere($qb->expr()->eq('equipment_id', $qb->createNamedParameter($filters['equipmentId'], \PDO::PARAM_INT)));
			}
			if (isset($filters['mineUid'])) {
				$this->constrainToMine($qb, $filters['mineUid']);
			}
			if (isset($filters['primaryUid'])) {
				$qb->andWhere($qb->expr()->eq('primary_user_id', $qb->createNamedParameter($filters['primaryUid'])));
			}
			if (isset($filters['from'])) {
				// FA-1 overlap semantics: a span touches [from,to] when it starts
				// on/before `to` and ends on/after `from` (plain comparisons stay
				// index-usable — never COALESCE here, see spec F-1).
				$qb->andWhere($qb->expr()->gte('due_until', $qb->createNamedParameter($filters['from'])));
			}
			if (isset($filters['to'])) {
				$qb->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($filters['to'])));
			}
			if (isset($filters['dueOn'])) {
				$this->constrainToDay($qb, $filters['dueOn']);
			}
			if (isset($filters['q']) && $filters['q'] !== '') {
				$needle = '%' . $this->db->escapeLikeParameter(mb_strtolower($filters['q'])) . '%';
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->like($qb->func()->lower('title'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('number'), $qb->createNamedParameter($needle)),
				));
			}
		};

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'));
		$apply($countQb);
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*');
		$apply($qb);
		$this->applyMineAwareSort($qb, isset($filters['mineUid']) ? (string)$filters['mineUid'] : null);
		$qb->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * Dispatch board window: non-terminal WOs whose span overlaps [from, to]
	 * (FA-1 overlap semantics — a multi-day WO appears on every covered day).
	 *
	 * @return list<WorkOrder>
	 */
	public function findForDispatch(string $from, string $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($to)))
			->andWhere($qb->expr()->gte('due_until', $qb->createNamedParameter($from)))
			->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(1000);
		return $this->findEntities($qb);
	}

	/**
	 * Shared span overlap predicate: `due_on <= :to AND due_until >= :from`.
	 * Undated rows (due_on IS NULL → due_until IS NULL) never match — the
	 * dispatch "no date" tray lists them separately.
	 */
	private function constrainToWindow(IQueryBuilder $qb, string $from, string $to): void
	{
		$qb->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($to)))
			->andWhere($qb->expr()->gte('due_until', $qb->createNamedParameter($from)));
	}

	/**
	 * Shared single-day membership predicate: `due_on <= :day AND due_until >= :day`.
	 */
	private function constrainToDay(IQueryBuilder $qb, string $day): void
	{
		$qb->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->gte('due_until', $qb->createNamedParameter($day)));
	}

	/**
	 * Open WOs without a due date — the dispatch board's "no date" tray so
	 * nothing silently disappears from planning (§8 A2).
	 *
	 * @return list<WorkOrder>
	 */
	public function findOpenWithoutDueDate(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('due_on'))
			->orderBy('priority', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(500);
		return $this->findEntities($qb);
	}

	/**
	 * W4 capacity load: non-terminal WOs with this primary tech whose span
	 * covers the day. FA-1: each WO charges CEIL(estimated_minutes/spanDays)
	 * per covered day (even distribution heuristic, spec §9) — computed in
	 * PHP because portable SQL cannot express the day-diff division.
	 */
	public function loadMinutesFor(string $uid, string $dueOn, ?int $excludeWoId = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'estimated_minutes', 'due_on', 'due_until')->from($this->getTableName())
			->where($qb->expr()->eq('primary_user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($dueOn)))
			->andWhere($qb->expr()->gte('due_until', $qb->createNamedParameter($dueOn)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($excludeWoId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeWoId, \PDO::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$sum = 0;
		while ($row = $result->fetch()) {
			$estimated = (int)($row['estimated_minutes'] ?? 0);
			if ($estimated <= 0) {
				continue;
			}
			$span = \OCA\MaintenanceCheck\Service\WorkOrderSpan::spanDays(
				$row['due_on'] !== null ? (string)$row['due_on'] : null,
				$row['due_until'] !== null ? (string)$row['due_until'] : null,
			);
			$sum += \OCA\MaintenanceCheck\Service\WorkOrderSpan::chargePerDay($estimated, $span);
		}
		$result->closeCursor();
		return $sum;
	}

	/**
	 * Due board (CORE §13.1 + W7): open preventive/inspection WOs with due date in range.
	 * Excludes cancelled/done; optional mine filter matches list semantics.
	 *
	 * @return list<WorkOrder>
	 */
	public function findOpenPreventiveDue(string $maxDueOn, ?string $mineUid = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('kind', $qb->createNamedParameter(
				[WorkOrder::KIND_PREVENTIVE, WorkOrder::KIND_INSPECTION],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('due_on'))
			->andWhere($qb->expr()->lte('due_until', $qb->createNamedParameter($maxDueOn)));
		if ($mineUid !== null) {
			$this->constrainToMine($qb, $mineUid);
		}
		$this->applyMineAwareSort($qb, $mineUid);
		$qb->setMaxResults(1000);
		return $this->findEntities($qb);
	}

	/**
	 * CORE §7 list/due-board scope: primary assignee, unassigned pool, or
	 * JSON helper list. Must stay aligned with WorkOrder::isAssigneeOrPool().
	 */
	private function constrainToMine(IQueryBuilder $qb, string $mineUid): void
	{
		$helperNeedle = '%' . $this->db->escapeLikeParameter('"' . $mineUid . '"') . '%';
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->eq('primary_user_id', $qb->createNamedParameter($mineUid)),
			$qb->expr()->isNull('primary_user_id'),
			$qb->expr()->eq('primary_user_id', $qb->createNamedParameter('')),
			$qb->expr()->like('helper_uids', $qb->createNamedParameter($helperNeedle)),
		));
	}

	/**
	 * When listing "mine", assigned + helper rows must sort before the
	 * unassigned pool so pagination cannot hide the job the technician is
	 * actually on. Pool remains visible (CORE §7); it just comes after.
	 */
	private function applyMineAwareSort(IQueryBuilder $qb, ?string $mineUid): void
	{
		if ($mineUid !== null && $mineUid !== '') {
			$uidParam = $qb->createNamedParameter($mineUid);
			$helperNeedle = '%' . $this->db->escapeLikeParameter('"' . $mineUid . '"') . '%';
			$helperParam = $qb->createNamedParameter($helperNeedle);
			$rank = 'CASE WHEN '
				. $qb->expr()->eq('primary_user_id', $uidParam)
				. ' OR ' . $qb->expr()->like('helper_uids', $helperParam)
				. ' THEN 0 ELSE 1 END';
			$qb->orderBy($qb->createFunction($rank), 'ASC');
		}
		$qb->addOrderBy('due_on', 'ASC')->addOrderBy('id', 'ASC');
	}

	/**
	 * FA-2 calendar read model: non-terminal WOs whose span overlaps
	 * [from,to]. Scope mirrors the work-order list exactly:
	 *  - $mineUid set → primary/helper/pool (constrainToMine, CORE §7)
	 *  - $techUids non-empty → office narrowing to those primary assignees
	 *
	 * @param list<string>|null $techUids
	 * @param list<string>|null $kinds
	 * @param list<string>|null $statuses
	 * @return list<WorkOrder>
	 */
	public function findForCalendar(
		string $from,
		string $to,
		?string $mineUid = null,
		?array $techUids = null,
		?array $kinds = null,
		?array $statuses = null,
		int $limit = 1001,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
		$this->constrainToWindow($qb, $from, $to);
		if ($mineUid !== null) {
			$this->constrainToMine($qb, $mineUid);
		}
		if (is_array($techUids) && $techUids !== []) {
			// Office narrowing mirrors the mine scope minus pool: a tech "owns"
			// a job as primary OR helper — otherwise filtering to a tech would
			// hide the jobs they are booked on as helper.
			$techOr = $qb->expr()->orX(
				$qb->expr()->in('primary_user_id', $qb->createNamedParameter($techUids, IQueryBuilder::PARAM_STR_ARRAY)),
			);
			foreach ($techUids as $techUid) {
				$helperNeedle = '%' . $this->db->escapeLikeParameter('"' . $techUid . '"') . '%';
				$techOr->add($qb->expr()->like('helper_uids', $qb->createNamedParameter($helperNeedle)));
			}
			$qb->andWhere($techOr);
		}
		if (is_array($kinds) && $kinds !== []) {
			$qb->andWhere($qb->expr()->in('kind', $qb->createNamedParameter($kinds, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if (is_array($statuses) && $statuses !== []) {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$qb->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));
		return $this->findEntities($qb);
	}

	public function countForProcedure(int $procedureId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('procedure_id', $qb->createNamedParameter($procedureId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
