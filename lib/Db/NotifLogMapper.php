<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * W6 notification dedupe log (CORE §20 AC-W6-9) — at most one NC notify
 * per entity per calendar day via unique dedupe_key.
 */
class NotifLogMapper
{
	public const TABLE = 'mn_notif_log';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function wasSent(string $dedupeKey): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('sent')))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Reserve a dedupe slot. Returns true if this caller owns the send;
	 * false if another row already holds the key (idempotent skip).
	 */
	public function reserve(
		string $type,
		string $recipient,
		string $entityType,
		int $entityId,
		string $dedupeKey,
		int $now,
	): bool {
		if ($this->wasSent($dedupeKey)) {
			return false;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'type' => $qb->createNamedParameter($type),
				'recipient' => $qb->createNamedParameter($recipient),
				'entity_type' => $qb->createNamedParameter($entityType),
				'entity_id' => $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
				'dedupe_key' => $qb->createNamedParameter($dedupeKey),
				'status' => $qb->createNamedParameter('sent'),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);
			$qb->executeStatement();
			return true;
		} catch (\OCP\DB\Exception $e) {
			// Unique violation = concurrent reserve won — treat as already sent.
			if ($this->wasSent($dedupeKey)) {
				return false;
			}
			throw $e;
		}
	}
}
