<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WoSkill>
 */
class WoSkillMapper extends QBMapper
{
	public const TABLE = 'mn_wo_skills';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WoSkill::class);
	}

	/**
	 * @return list<WoSkill>
	 */
	public function findByWorkOrder(int $workOrderId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)))
			->orderBy('skill_id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForWorkOrder(int $workOrderId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('work_order_id', $qb->createNamedParameter($workOrderId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function countForSkill(int $skillId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('skill_id', $qb->createNamedParameter($skillId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
