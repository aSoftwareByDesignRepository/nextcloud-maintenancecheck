<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WoSignature>
 */
class WoSignatureMapper extends QBMapper
{
	public const TABLE = 'mn_wo_signatures';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WoSignature::class);
	}

	public function findByWorkOrder(int $workOrderId): ?WoSignature
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

	public function deleteForWorkOrder(int $workOrderId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
