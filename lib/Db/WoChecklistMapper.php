<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WoChecklistItem>
 */
class WoChecklistMapper extends QBMapper
{
	public const TABLE = 'mn_wo_checklist';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WoChecklistItem::class);
	}

	/**
	 * @return list<WoChecklistItem>
	 */
	public function findByWorkOrder(int $workOrderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForWorkOrder(int $workOrderId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
