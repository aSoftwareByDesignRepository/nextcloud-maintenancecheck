<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<EquipDoc>
 */
class EquipDocMapper extends QBMapper
{
	public const TABLE = 'mn_equip_docs';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, EquipDoc::class);
	}

	public function findById(int $id): EquipDoc
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
	 * @return list<EquipDoc>
	 */
	public function findByEquipment(int $equipmentId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
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

	public function deleteForEquipment(int $equipmentId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)));
		return $qb->executeStatement();
	}
}
