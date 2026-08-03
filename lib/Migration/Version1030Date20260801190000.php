<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * W7 Prüfpflichten depth (CORE §21): equipment classes, obligations,
 * inspection results/defects, Prüfnachweis columns.
 *
 * Additive only — W0–W6 columns/tables are never rewritten.
 */
class Version1030Date20260801190000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('mn_equipment')) {
			$t = $schema->getTable('mn_equipment');
			if (!$t->hasColumn('equipment_class')) {
				$t->addColumn('equipment_class', Types::STRING, ['length' => 64, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasIndex('mn_eq_class_idx') && $t->hasColumn('equipment_class')) {
				$t->addIndex(['equipment_class'], 'mn_eq_class_idx');
				$changed = true;
			}
		}

		if ($schema->hasTable('mn_work_orders')) {
			$t = $schema->getTable('mn_work_orders');
			foreach ([
				'result' => ['type' => Types::STRING, 'opts' => ['length' => 16, 'notnull' => false]],
				'inspector_name' => ['type' => Types::STRING, 'opts' => ['length' => 128, 'notnull' => false]],
				'inspector_note' => ['type' => Types::STRING, 'opts' => ['length' => 512, 'notnull' => false]],
				'source_wo_id' => ['type' => Types::BIGINT, 'opts' => ['notnull' => false]],
				'obligation_id' => ['type' => Types::BIGINT, 'opts' => ['notnull' => false]],
			] as $col => $def) {
				if (!$t->hasColumn($col)) {
					$t->addColumn($col, $def['type'], $def['opts']);
					$changed = true;
				}
			}
			if (!$t->hasIndex('mn_wo_src_idx') && $t->hasColumn('source_wo_id')) {
				$t->addIndex(['source_wo_id'], 'mn_wo_src_idx');
				$changed = true;
			}
			if (!$t->hasIndex('mn_wo_obl_idx') && $t->hasColumn('obligation_id')) {
				$t->addIndex(['obligation_id'], 'mn_wo_obl_idx');
				$changed = true;
			}
		}

		if (!$schema->hasTable('mn_equipment_classes')) {
			$t = $schema->createTable('mn_equipment_classes');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name_de', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('name_en', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('default_interval_unit', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'year']);
			$t->addColumn('default_interval_count', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'mn_ecls_pk');
			$t->addUniqueIndex(['code'], 'mn_ecls_code_uq');
			$changed = true;
		}

		if (!$schema->hasTable('mn_inspection_obligations')) {
			$t = $schema->createTable('mn_inspection_obligations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('class_code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('interval_unit', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('interval_count', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('procedure_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('plan_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_iobl_pk');
			$t->addIndex(['equipment_id', 'active'], 'mn_iobl_eq_idx');
			$t->addIndex(['plan_id'], 'mn_iobl_plan_idx');
			$changed = true;
		}

		if (!$schema->hasTable('mn_inspection_defects')) {
			$t = $schema->createTable('mn_inspection_defects');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('wo_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('body', Types::STRING, ['length' => 2000, 'notnull' => true]);
			$t->addColumn('photo_file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_idef_pk');
			$t->addIndex(['wo_id'], 'mn_idef_wo_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
