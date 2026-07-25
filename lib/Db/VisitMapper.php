<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Visit>
 */
class VisitMapper extends QBMapper
{
	public const TABLE = 'mn_visits';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Visit::class);
	}

	public function findById(int $id): Visit
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

	public function findOpenByPlan(int $planId): ?Visit
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Visit::STATUS_SCHEDULED)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * S6: conditional terminal transition. Returns true iff exactly this call
	 * moved the visit out of `scheduled`. All `$set` values must already be
	 * validated; keys are snake_case column names.
	 *
	 * @param array<string, string|int|null> $set
	 */
	public function closeScheduled(int $id, array $set): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach ($set as $column => $value) {
			if ($value === null) {
				$qb->set($column, $qb->createNamedParameter(null, \PDO::PARAM_NULL));
			} elseif (is_int($value)) {
				$qb->set($column, $qb->createNamedParameter($value, \PDO::PARAM_INT));
			} else {
				$qb->set($column, $qb->createNamedParameter($value));
			}
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Visit::STATUS_SCHEDULED)));
		return $qb->executeStatement() > 0;
	}

	/**
	 * S15 / S12: mutate a still-open visit (reschedule, notes, assignment).
	 *
	 * @param array<string, string|int|null> $set
	 */
	public function updateScheduled(int $id, array $set): bool
	{
		return $this->closeScheduled($id, $set);
	}

	/**
	 * Due-board query (SPEC §6.2): scheduled visits up to $maxDueOn,
	 * ordered due_on ASC, id ASC.
	 *
	 * @return list<Visit>
	 */
	public function findDue(string $maxDueOn, ?string $mineUid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Visit::STATUS_SCHEDULED)))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($maxDueOn)));
		if ($mineUid !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->eq('assigned_uid', $qb->createNamedParameter($mineUid)),
				$qb->expr()->isNull('assigned_uid'),
			));
		}
		$qb->orderBy('due_on', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Filtered visit list with S7 pagination envelope inputs.
	 *
	 * @param array{from?: string, to?: string, status?: string, mineUid?: string,
	 *              customerId?: int, equipmentId?: int, planId?: int} $filters
	 * @return array{data: list<Visit>, total: int}
	 */
	public function searchVisits(array $filters, int $limit, int $offset): array
	{
		$apply = function ($qb) use ($filters): void {
			$qb->from($this->getTableName());
			$qb->where($qb->expr()->neq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			if (isset($filters['from'])) {
				$qb->andWhere($qb->expr()->gte('due_on', $qb->createNamedParameter($filters['from'])));
			}
			if (isset($filters['to'])) {
				$qb->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($filters['to'])));
			}
			if (isset($filters['status'])) {
				$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
			}
			if (isset($filters['mineUid'])) {
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->eq('assigned_uid', $qb->createNamedParameter($filters['mineUid'])),
					$qb->expr()->isNull('assigned_uid'),
				));
			}
			if (isset($filters['customerId'])) {
				$qb->andWhere($qb->expr()->eq('customer_id', $qb->createNamedParameter($filters['customerId'], \PDO::PARAM_INT)));
			}
			if (isset($filters['equipmentId'])) {
				$qb->andWhere($qb->expr()->eq('equipment_id', $qb->createNamedParameter($filters['equipmentId'], \PDO::PARAM_INT)));
			}
			if (isset($filters['planId'])) {
				$qb->andWhere($qb->expr()->eq('plan_id', $qb->createNamedParameter($filters['planId'], \PDO::PARAM_INT)));
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
		$qb->orderBy('due_on', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	public function countForEquipment(int $equipmentId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}

	/**
	 * Most recent visits for an equipment (SPEC §9.2 mobile equipment summary).
	 *
	 * @return list<Visit>
	 */
	public function findRecentForEquipment(int $equipmentId, int $limit = 5): array
	{
		$limit = max(1, min(50, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)))
			->orderBy('due_on', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countForCustomer(int $customerId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}

	public function deleteForCustomer(int $customerId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
