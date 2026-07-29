<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Site>
 */
class SiteMapper extends QBMapper
{
	public const TABLE = 'mn_sites';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Site::class);
	}

	public function findById(int $id): Site
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/**
	 * @return list<Site>
	 */
	public function findByCustomer(int $customerId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)))
			->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForCustomer(int $customerId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
