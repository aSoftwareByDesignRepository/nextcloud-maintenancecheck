<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<UserSkill>
 */
class UserSkillMapper extends QBMapper
{
	public const TABLE = 'mn_user_skills';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, UserSkill::class);
	}

	/**
	 * @return list<UserSkill>
	 */
	public function findByUid(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('skill_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<int> skill ids held by the user
	 */
	public function skillIdsFor(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('skill_id')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['skill_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	public function has(string $uid, int $skillId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('skill_id', $qb->createNamedParameter($skillId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	public function remove(string $uid, int $skillId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('skill_id', $qb->createNamedParameter($skillId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForSkill(int $skillId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('skill_id', $qb->createNamedParameter($skillId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * All grants for a set of users, for dispatch-board skill chips.
	 *
	 * @param list<string> $uids
	 * @return array<string, list<int>> uid => skill ids
	 */
	public function skillIdsForUsers(array $uids): array
	{
		if ($uids === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk($uids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uid', 'skill_id')->from($this->getTableName())
				->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$out[(string)$row['uid']][] = (int)$row['skill_id'];
			}
			$result->closeCursor();
		}
		return $out;
	}
}
