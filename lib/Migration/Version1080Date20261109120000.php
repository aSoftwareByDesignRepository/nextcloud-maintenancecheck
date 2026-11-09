<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Technician calendar (FA-1): multi-day work orders.
 *
 * `due_until` mirrors `due_on`'s NULL contract: a work order without a due
 * date keeps due_until NULL; every dated work order carries
 * due_until >= due_on (service-layer invariant — MySQL < 8.0.16 ignores
 * CHECK constraints). Backfill copies due_on so every existing row reads
 * as a single-day job (legacy-safe contract).
 *
 * Plain two-column comparisons (`due_on <= :to AND due_until >= :from`)
 * stay index-usable on every supported DB — no COALESCE expressions.
 */
class Version1080Date20261109120000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('mn_work_orders')) {
			$table = $schema->getTable('mn_work_orders');
			if (!$table->hasColumn('due_until')) {
				$table->addColumn('due_until', Types::STRING, ['length' => 10, 'notnull' => false]);
			}
			if (!$table->hasIndex('mn_wo_dueuntil_idx')) {
				$table->addIndex(['due_until'], 'mn_wo_dueuntil_idx');
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Single-day backfill: due_until = due_on for every row not yet set
		// (createFunction references the column itself — portable across
		// MySQL/MariaDB/PostgreSQL/SQLite).
		$qb = $this->db->getQueryBuilder();
		$qb->update('mn_work_orders')
			->set('due_until', $qb->createFunction('due_on'))
			->where($qb->expr()->isNull('due_until'));
		$qb->executeStatement();
	}
}
