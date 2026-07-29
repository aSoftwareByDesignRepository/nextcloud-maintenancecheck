<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MeterReading>
 */
class MeterReadingMapper extends QBMapper
{
	public const TABLE = 'mn_meter_readings';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, MeterReading::class);
	}

	/**
	 * Latest reading by insertion order (id), which under the meter row lock
	 * is also the chronological order of accepted readings.
	 */
	public function findLatest(int $meterId): ?MeterReading
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, \PDO::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return array{data: list<MeterReading>, total: int}
	 */
	public function findByMeter(int $meterId, int $limit, int $offset): array
	{
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($countQb->expr()->eq('meter_id', $countQb->createNamedParameter($meterId, \PDO::PARAM_INT)));
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, \PDO::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	public function deleteForMeter(int $meterId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
