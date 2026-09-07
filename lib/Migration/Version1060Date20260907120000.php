<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * SF-Z04 — optional client_request_id on checklist rows for PUT idempotency.
 *
 * Companion offline drain / double-submit retries send the same clientRequestId;
 * the server must return the same result without re-applying side effects.
 */
class Version1060Date20260907120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mn_wo_checklist')) {
			return $schema;
		}

		$table = $schema->getTable('mn_wo_checklist');
		if (!$table->hasColumn('client_request_id')) {
			$table->addColumn('client_request_id', Types::STRING, [
				'notnull' => false,
				'length' => 128,
				'default' => null,
			]);
		}

		return $schema;
	}
}
