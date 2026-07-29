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
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->eq('primary_user_id', $qb->createNamedParameter($filters['mineUid'])),
					$qb->expr()->isNull('primary_user_id'),
				));
			}
			if (isset($filters['primaryUid'])) {
				$qb->andWhere($qb->expr()->eq('primary_user_id', $qb->createNamedParameter($filters['primaryUid'])));
			}
			if (isset($filters['from'])) {
				$qb->andWhere($qb->expr()->gte('due_on', $qb->createNamedParameter($filters['from'])));
			}
			if (isset($filters['to'])) {
				$qb->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($filters['to'])));
			}
			if (isset($filters['dueOn'])) {
				$qb->andWhere($qb->expr()->eq('due_on', $qb->createNamedParameter($filters['dueOn'])));
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
		$qb->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * Dispatch board window: non-terminal WOs with due_on inside [from, to].
	 *
	 * @return list<WorkOrder>
	 */
	public function findForDispatch(string $from, string $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('due_on', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($to)))
			->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(1000);
		return $this->findEntities($qb);
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
	 * W4 capacity load: sum of estimated_minutes of non-terminal WOs with
	 * this primary tech on this day.
	 */
	public function loadMinutesFor(string $uid, string $dueOn, ?int $excludeWoId = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('estimated_minutes'))->from($this->getTableName())
			->where($qb->expr()->eq('primary_user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('due_on', $qb->createNamedParameter($dueOn)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($excludeWoId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeWoId, \PDO::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();
		return (int)($sum ?: 0);
	}

	/**
	 * Due board (CORE §13.1): open preventive WOs with a due date in range.
	 * Excludes cancelled/done; optional mine filter matches list semantics.
	 *
	 * @return list<WorkOrder>
	 */
	public function findOpenPreventiveDue(string $maxDueOn, ?string $mineUid = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter(WorkOrder::KIND_PREVENTIVE)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('due_on'))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($maxDueOn)));
		if ($mineUid !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->eq('primary_user_id', $qb->createNamedParameter($mineUid)),
				$qb->expr()->isNull('primary_user_id'),
			));
		}
		$qb->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC')->setMaxResults(1000);
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
