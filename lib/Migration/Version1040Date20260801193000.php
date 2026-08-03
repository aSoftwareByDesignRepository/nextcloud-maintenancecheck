<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * AC-W7-5 / EXEC-2 R9: make inspection auto-corrective follow-up idempotent
 * under concurrency via UNIQUE(source_wo_id). NULLs remain allowed (MySQL /
 * PostgreSQL unique semantics).
 */
class Version1040Date20260801193000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Keep the lowest id per source_wo_id; clear the rest so UNIQUE can apply.
		$qb = $this->db->getQueryBuilder();
		$qb->select('source_wo_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from('mn_work_orders')
			->where($qb->expr()->isNotNull('source_wo_id'))
			->groupBy('source_wo_id')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$dupes = $result->fetchAll();
		$result->closeCursor();
		foreach ($dupes as $row) {
			$sourceId = (int)$row['source_wo_id'];
			$keepQb = $this->db->getQueryBuilder();
			$keepQb->select('id')->from('mn_work_orders')
				->where($keepQb->expr()->eq('source_wo_id', $keepQb->createNamedParameter($sourceId, \PDO::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults(1);
			$keepRes = $keepQb->executeQuery();
			$keepId = (int)($keepRes->fetchOne() ?: 0);
			$keepRes->closeCursor();
			if ($keepId <= 0) {
				continue;
			}
			$clear = $this->db->getQueryBuilder();
			$clear->update('mn_work_orders')
				->set('source_wo_id', $clear->createNamedParameter(null, \PDO::PARAM_NULL))
				->where($clear->expr()->eq('source_wo_id', $clear->createNamedParameter($sourceId, \PDO::PARAM_INT)))
				->andWhere($clear->expr()->neq('id', $clear->createNamedParameter($keepId, \PDO::PARAM_INT)))
				->executeStatement();
			$output->info('Deduped source_wo_id=' . $sourceId . ' keeping wo id=' . $keepId);
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('mn_work_orders')) {
			return null;
		}
		$t = $schema->getTable('mn_work_orders');
		if (!$t->hasColumn('source_wo_id')) {
			return null;
		}
		$changed = false;
		if ($t->hasIndex('mn_wo_src_idx')) {
			$t->dropIndex('mn_wo_src_idx');
			$changed = true;
		}
		if (!$t->hasIndex('mn_wo_src_uq')) {
			$t->addUniqueIndex(['source_wo_id'], 'mn_wo_src_uq');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
