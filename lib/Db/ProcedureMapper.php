<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Procedure>
 */
class ProcedureMapper extends QBMapper
{
	public const TABLE = 'mn_procedures';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Procedure::class);
	}

	public function findById(int $id): Procedure
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

	public function findByCode(string $code): ?Procedure
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
	 * @return array{data: list<Procedure>, total: int}
	 */
	public function listAll(int $limit, int $offset, ?string $vertical = null, ?bool $activeOnly = null): array
	{
		$apply = function ($qb) use ($vertical, $activeOnly): void {
			$qb->from($this->getTableName());
			$qb->where($qb->expr()->neq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			if ($vertical !== null && $vertical !== '') {
				$qb->andWhere($qb->expr()->eq('vertical', $qb->createNamedParameter($vertical)));
			}
			if ($activeOnly === true) {
				$qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
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
		$qb->orderBy('title', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * @return list<Procedure>
	 */
	public function findBySourcePack(string $packCode): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('source_pack', $qb->createNamedParameter($packCode)))
			->orderBy('code', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
