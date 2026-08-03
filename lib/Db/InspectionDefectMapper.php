<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<InspectionDefect>
 */
class InspectionDefectMapper extends QBMapper
{
	public const TABLE = 'mn_inspection_defects';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, InspectionDefect::class);
	}

	/**
	 * @return list<InspectionDefect>
	 */
	public function findByWorkOrder(int $woId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('wo_id', $qb->createNamedParameter($woId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countByWorkOrder(int $woId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from($this->getTableName())
			->where($qb->expr()->eq('wo_id', $qb->createNamedParameter($woId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
