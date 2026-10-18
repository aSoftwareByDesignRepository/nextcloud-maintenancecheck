<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1070Date20261019000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// F18: cross-app draft dedupe — `ref_type`/`ref_id` (e.g.
		// 'tck_incident' + incident id). Unique when set; NULLs stay allowed
		// (web-created WOs carry no ref).
		if ($schema->hasTable('mn_work_orders')) {
			$table = $schema->getTable('mn_work_orders');
			if (!$table->hasColumn('ref_type')) {
				$table->addColumn('ref_type', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('ref_id')) {
				$table->addColumn('ref_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$table->hasIndex('mn_wo_ref_uq')) {
				$table->addUniqueIndex(['ref_type', 'ref_id'], 'mn_wo_ref_uq');
			}
		}

		return $schema;
	}
}
