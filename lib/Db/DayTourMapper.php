<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DayTour>
 */
class DayTourMapper extends QBMapper
{
	public const TABLE = 'mn_day_tours';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, DayTour::class);
	}

	public function findById(int $id): DayTour
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

	public function findByDateAndTech(string $tourDate, string $techUid): ?DayTour
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tour_date', $qb->createNamedParameter($tourDate)))
			->andWhere($qb->expr()->eq('tech_uid', $qb->createNamedParameter($techUid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<DayTour>
	 */
	public function findByDate(string $tourDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tour_date', $qb->createNamedParameter($tourDate)))
			->orderBy('tech_uid', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Write-lock the tour row while its stops are re-ordered.
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
}
