<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ProcItem>
 */
class ProcItemMapper extends QBMapper
{
	public const TABLE = 'mn_proc_items';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ProcItem::class);
	}

	/**
	 * @return list<ProcItem>
	 */
	public function findByProcedure(int $procedureId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('procedure_id', $qb->createNamedParameter($procedureId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForProcedure(int $procedureId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('procedure_id', $qb->createNamedParameter($procedureId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
