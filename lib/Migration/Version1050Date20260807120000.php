<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * SHARED-IDENTITY W3 — optional soft links on field customers (BQ-2c).
 *
 * Additive nullable columns + unique indexes (multiple NULLs allowed).
 */
class Version1050Date20260807120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mn_customers')) {
			return $schema;
		}

		$table = $schema->getTable('mn_customers');
		if (!$table->hasColumn('pc_customer_id')) {
			$table->addColumn('pc_customer_id', 'bigint', [
				'notnull' => false,
			]);
		}
		if (!$table->hasColumn('crm_company_id')) {
			$table->addColumn('crm_company_id', 'bigint', [
				'notnull' => false,
			]);
		}
		if (!$table->hasIndex('mn_cust_pc_uq')) {
			$table->addUniqueIndex(['pc_customer_id'], 'mn_cust_pc_uq');
		}
		if (!$table->hasIndex('mn_cust_crm_uq')) {
			$table->addUniqueIndex(['crm_company_id'], 'mn_cust_crm_uq');
		}

		return $schema;
	}
}
