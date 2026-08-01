<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WoComment>
 */
class WoCommentMapper extends QBMapper
{
	public const TABLE = 'mn_wo_comments';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WoComment::class);
	}

	/**
	 * Chronological ascending (AC-W6-7).
	 *
	 * @return list<WoComment>
	 */
	public function findByWorkOrder(int $woId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('wo_id', $qb->createNamedParameter($woId, \PDO::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForWorkOrder(int $woId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('wo_id', $qb->createNamedParameter($woId, \PDO::PARAM_INT)));
		return $qb->executeStatement();
	}
}
