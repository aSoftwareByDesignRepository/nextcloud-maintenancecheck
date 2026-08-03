<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<InspectionObligation>
 */
class InspectionObligationMapper extends QBMapper
{
	public const TABLE = 'mn_inspection_obligations';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, InspectionObligation::class);
	}

	/**
	 * @return list<InspectionObligation>
	 */
	public function findByEquipment(int $equipmentId, bool $activeOnly = true): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('equipment_id', $qb->createNamedParameter($equipmentId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}
		return $this->findEntities($qb);
	}

	public function findByPlanId(int $planId): ?InspectionObligation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findById(int $id): InspectionObligation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}
}
