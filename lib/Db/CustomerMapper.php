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
	 * Batch name lookup for list enrichment (equipment register, etc.).
	 *
	 * @param list<int> $ids
	 * @return array<int, string> id → name
	 */
	public function mapNamesByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn ($id) => (int)$id, $ids),
			static fn (int $id) => $id >= 1,
		)));
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['id']] = (string)$row['name'];
		}
		$result->closeCursor();
		return $map;
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

	public function findByPcCustomerId(int $pcCustomerId): ?Customer
	{
		if ($pcCustomerId <= 0) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('pc_customer_id', $qb->createNamedParameter($pcCustomerId, \PDO::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByCrmCompanyId(int $crmCompanyId): ?Customer
	{
		if ($crmCompanyId <= 0) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('crm_company_id', $qb->createNamedParameter($crmCompanyId, \PDO::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return array{mnLinkedPc:int,mnLinkedCrm:int,mnUnlinked:int}
	 */
	public function identityLinkCounts(): array
	{
		$totalQb = $this->db->getQueryBuilder();
		$totalQb->select($totalQb->func()->count('id', 'cnt'))->from($this->getTableName());
		$tr = $totalQb->executeQuery();
		$total = (int)($tr->fetchOne() ?: 0);
		$tr->closeCursor();

		$pcQb = $this->db->getQueryBuilder();
		$pcQb->select($pcQb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($pcQb->expr()->isNotNull('pc_customer_id'));
		$pr = $pcQb->executeQuery();
		$pc = (int)($pr->fetchOne() ?: 0);
		$pr->closeCursor();

		$crmQb = $this->db->getQueryBuilder();
		$crmQb->select($crmQb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($crmQb->expr()->isNotNull('crm_company_id'));
		$cr = $crmQb->executeQuery();
		$crm = (int)($cr->fetchOne() ?: 0);
		$cr->closeCursor();

		$unlinkedQb = $this->db->getQueryBuilder();
		$unlinkedQb->select($unlinkedQb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($unlinkedQb->expr()->isNull('pc_customer_id'))
			->andWhere($unlinkedQb->expr()->isNull('crm_company_id'));
		$ur = $unlinkedQb->executeQuery();
		$unlinked = (int)($ur->fetchOne() ?: 0);
		$ur->closeCursor();

		return [
			'mnLinkedPc' => $pc,
			'mnLinkedCrm' => $crm,
			'mnUnlinked' => $unlinked > 0 ? $unlinked : max(0, $total - max($pc, $crm)),
		];
	}
}
