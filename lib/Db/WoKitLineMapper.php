<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WoKitLine>
 */
class WoKitLineMapper extends QBMapper
{
	public const TABLE = 'mn_wo_kit_lines';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, WoKitLine::class);
	}

	public function findById(int $id): WoKitLine
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
	 * Write-lock a kit line for the duration of the transaction (pack races).
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
	 * @return list<WoKitLine>
	 */
	public function findByKit(int $woKitId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('wo_kit_id', $qb->createNamedParameter($woKitId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForKit(int $woKitId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('wo_kit_id', $qb->createNamedParameter($woKitId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
