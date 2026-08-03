<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<EquipmentClass>
 */
class EquipmentClassMapper extends QBMapper
{
	public const TABLE = 'mn_equipment_classes';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, EquipmentClass::class);
	}

	public function findByCode(string $code): ?EquipmentClass
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<EquipmentClass>
	 */
	public function listActive(int $limit = 100, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('code', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
