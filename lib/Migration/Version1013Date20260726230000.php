<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Persist F6 soft-fail machine codes (FC-IV-ISSUE / AC-S2.2).
 *
 * inventory_sync alone cannot distinguish insufficient_stock vs
 * location_unresolved vs flange_disabled — operators need the code.
 */
class Version1013Date20260726230000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('mn_work_orders')) {
			return null;
		}

		$t = $schema->getTable('mn_work_orders');
		if ($t->hasColumn('inventory_sync_code')) {
			return null;
		}

		$t->addColumn('inventory_sync_code', Types::STRING, [
			'length' => 64,
			'notnull' => false,
		]);

		return $schema;
	}
}
