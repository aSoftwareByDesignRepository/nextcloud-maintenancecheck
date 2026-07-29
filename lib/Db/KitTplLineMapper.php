<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<KitTplLine>
 */
class KitTplLineMapper extends QBMapper
{
	public const TABLE = 'mn_kit_tpl_lines';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, KitTplLine::class);
	}

	/**
	 * @return list<KitTplLine>
	 */
	public function findByTemplate(int $templateId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForTemplate(int $templateId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
