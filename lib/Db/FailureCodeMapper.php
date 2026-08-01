<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\IDBConnection;

/**
 * W6 failure / cause codes catalog (CORE §20 W6-R3).
 */
class FailureCodeMapper extends CatalogTypeMapper
{
	public const TABLE = 'mn_failure_codes';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE);
	}

	/**
	 * @return list<CatalogType>
	 */
	public function listActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
