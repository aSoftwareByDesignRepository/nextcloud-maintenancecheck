<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<UserCapacity>
 */
class UserCapacityMapper extends QBMapper
{
	public const TABLE = 'mn_user_capacity';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, UserCapacity::class);
	}

	public function findByUid(string $uid): ?UserCapacity
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Write-lock the capacity row for `$uid` for the duration of the
	 * surrounding transaction. Callers must already hold an open TX.
	 *
	 * Used by the W4 assign gate so two concurrent assigns to the same
	 * technician cannot both read load=N and both pass under `block`.
	 */
	public function lockRowByUid(string $uid): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Ensure a capacity row exists (default minutes), then FOR UPDATE lock it.
	 * Safe under concurrent first-assign races (unique uid → loser re-locks).
	 */
	public function ensureAndLock(string $uid, string $updatedBy, int $now): void
	{
		if ($this->lockRowByUid($uid)) {
			return;
		}
		$row = new UserCapacity();
		$row->setUid($uid);
		$row->setDailyMinutes(UserCapacity::DEFAULT_DAILY_MINUTES);
		$row->setUpdatedAt($now);
		$row->setUpdatedBy($updatedBy);
		try {
			$this->insert($row);
		} catch (\OCP\DB\Exception $e) {
			$reasons = [
				\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
				\OCP\DB\Exception::REASON_CONSTRAINT_VIOLATION,
			];
			if (!in_array($e->getReason(), $reasons, true)) {
				throw $e;
			}
			// Concurrent insert won — fall through to lock the winner.
		}
		if (!$this->lockRowByUid($uid)) {
			throw new \RuntimeException('Failed to lock capacity row for uid ' . $uid);
		}
	}

	/**
	 * @return list<UserCapacity>
	 */
	public function listAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('uid', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $uids
	 * @return array<string, int> uid => daily minutes (only stored rows)
	 */
	public function minutesForUsers(array $uids): array
	{
		if ($uids === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk($uids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uid', 'daily_minutes')->from($this->getTableName())
				->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$out[(string)$row['uid']] = (int)$row['daily_minutes'];
			}
			$result->closeCursor();
		}
		return $out;
	}

	public function deleteForUid(string $uid): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
