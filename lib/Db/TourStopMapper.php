<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TourStop>
 */
class TourStopMapper extends QBMapper
{
	public const TABLE = 'mn_tour_stops';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, TourStop::class);
	}

	/**
	 * @return list<TourStop>
	 */
	public function findByTour(int $tourId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tour_id', $qb->createNamedParameter($tourId, \PDO::PARAM_INT)))
			->orderBy('position', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByWorkOrder(int $workOrderId): ?TourStop
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Bulk lookup: work_order_id → tour_id for the dispatch board.
	 *
	 * @param list<int> $workOrderIds
	 * @return array<int, int>
	 */
	public function tourIdsByWorkOrder(array $workOrderIds): array
	{
		$map = [];
		foreach (array_chunk($workOrderIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('work_order_id', 'tour_id')->from($this->getTableName())
				->where($qb->expr()->in('work_order_id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$map[(int)$row['work_order_id']] = (int)$row['tour_id'];
			}
			$result->closeCursor();
		}
		return $map;
	}

	public function deleteForTour(int $tourId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tour_id', $qb->createNamedParameter($tourId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForWorkOrder(int $workOrderId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function countForTour(int $tourId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('tour_id', $qb->createNamedParameter($tourId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
