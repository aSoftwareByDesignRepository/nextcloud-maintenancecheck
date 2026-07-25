<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Base mapper for the two catalog tables (equipment types / maintenance types).
 *
 * @extends QBMapper<CatalogType>
 */
abstract class CatalogTypeMapper extends QBMapper
{
	public function __construct(IDBConnection $db, string $table)
	{
		parent::__construct($db, $table, CatalogType::class);
	}

	public function findById(int $id): CatalogType
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

	public function findByCode(string $code): ?CatalogType
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return array{data: list<CatalogType>, total: int}
	 */
	public function listAll(int $limit, int $offset): array
	{
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'))->from($this->getTableName());
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}
}
