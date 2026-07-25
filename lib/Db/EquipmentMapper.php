<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Equipment>
 */
class EquipmentMapper extends QBMapper
{
	public const TABLE = 'mn_equipment';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Equipment::class);
	}

	public function findById(int $id): Equipment
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
	 * S13 search across label, manufacturer, model, serial_no, optional customer filter.
	 *
	 * @return array{data: list<Equipment>, total: int}
	 */
	public function search(?int $customerId, string $q, int $limit, int $offset): array
	{
		$apply = function ($qb) use ($customerId, $q): void {
			$qb->from($this->getTableName());
			$qb->where($qb->expr()->neq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			if ($customerId !== null) {
				$qb->andWhere($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
			}
			if ($q !== '') {
				$needle = '%' . $this->db->escapeLikeParameter(mb_strtolower($q)) . '%';
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->like($qb->func()->lower('label'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('manufacturer'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('model'), $qb->createNamedParameter($needle)),
					$qb->expr()->like($qb->func()->lower('serial_no'), $qb->createNamedParameter($needle)),
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
		$qb->orderBy('label', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * @return list<int>
	 */
	public function idsForCustomer(int $customerId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	public function countForCustomer(int $customerId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('customer_id', $qb->createNamedParameter($customerId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
