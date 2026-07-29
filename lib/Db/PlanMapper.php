<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Plan>
 */
class PlanMapper extends QBMapper
{
	public const TABLE = 'mn_plans';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Plan::class);
	}

	public function findById(int $id): Plan
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

	/**
	 * @return list<Plan>
	 */
	public function findByEquipment(int $equipmentId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
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
	 * @param list<int> $equipmentIds
	 */
	public function countForEquipmentIds(array $equipmentIds): int
	{
		if ($equipmentIds === []) {
			return 0;
		}
		$count = 0;
		foreach (array_chunk($equipmentIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
				->where($qb->expr()->in('equipment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			$count += (int)($result->fetchOne() ?: 0);
			$result->closeCursor();
		}
		return $count;
	}

	/**
	 * @param list<int> $equipmentIds
	 */
	public function deleteForEquipmentIds(array $equipmentIds): void
	{
		foreach (array_chunk($equipmentIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('equipment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * W5 due engine: active plans on this equipment whose meter trigger
	 * matches the given meter code and that carry a threshold.
	 *
	 * @return list<Plan>
	 */
	public function findActiveMeterPlans(int $equipmentId, string $meterCode): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('trigger_kind', $qb->createNamedParameter(
				[Plan::TRIGGER_METER, Plan::TRIGGER_EITHER],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->eq('meter_code', $qb->createNamedParameter($meterCode)))
			->andWhere($qb->expr()->isNotNull('meter_threshold'))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Serialises concurrent open-visit creation for one plan (SPEC §6.3.2).
	 *
	 * `SELECT … FOR UPDATE` takes a write lock on the plan row in
	 * MySQL/InnoDB and PostgreSQL until the surrounding transaction
	 * commits. Returns false when the plan does not exist.
	 */
	public function lockRow(int $planId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($planId, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * S9 force-delete: write-lock every plan under the customer's equipment so
	 * a concurrent complete/skip cannot INSERT a follow-up visit after we have
	 * already counted/deleted the visit set (orphan race).
	 *
	 * @param list<int> $equipmentIds
	 */
	public function lockForEquipmentIds(array $equipmentIds): void
	{
		if ($equipmentIds === []) {
			return;
		}
		foreach (array_chunk($equipmentIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from($this->getTableName())
				->where($qb->expr()->in(
					'equipment_id',
					$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->orderBy('id', 'ASC')
				->forUpdate();
			$result = $qb->executeQuery();
			// Drain the cursor — the lock is the side effect we need.
			while ($result->fetch() !== false) {
			}
			$result->closeCursor();
		}
	}
}
