<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Customer>
 */
class CustomerMapper extends QBMapper
{
	public const TABLE = 'mn_customers';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Customer::class);
	}

	public function findById(int $id): Customer
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

	public function exists(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * S9: write-lock the customer row so concurrent force-deletes serialise
	 * and a second caller observes not_found after the first commits.
	 */
	public function lockRow(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * S13 search: case-insensitive substring across name, customer_no, city.
	 *
	 * @return array{data: list<Customer>, total: int}
	 */
	public function search(string $q, int $limit, int $offset): array
	{
		$apply = function ($qb) use ($q): void {
			$qb->from($this->getTableName());
			if ($q !== '') {
				$needle = '%' . $this->db->escapeLikeParameter(mb_strtolower($q)) . '%';
				$qb->where($qb->expr()->orX(
					$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('customer_no'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('city'), $qb->createNamedParameter($needle)),
				));
			}
		};

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'));
		$apply($countQb);
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*');
		$apply($qb);
		$qb->orderBy('name', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}
}
