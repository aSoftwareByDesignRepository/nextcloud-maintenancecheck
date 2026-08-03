<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * W6 field-ops hardening (CORE §20): request intake, warranty/docs,
 * failure codes, labor minutes, comments, notification log.
 *
 * Additive only — W0–W5 columns/tables are never rewritten.
 */
class Version1020Date20260801180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('mn_work_orders')) {
			$t = $schema->getTable('mn_work_orders');
			foreach ([
				'requester_name' => ['type' => Types::STRING, 'opts' => ['length' => 128, 'notnull' => false]],
				'requester_phone' => ['type' => Types::STRING, 'opts' => ['length' => 64, 'notnull' => false]],
				'symptom' => ['type' => Types::STRING, 'opts' => ['length' => 512, 'notnull' => false]],
				'access_notes' => ['type' => Types::STRING, 'opts' => ['length' => 512, 'notnull' => false]],
				'failure_code' => ['type' => Types::STRING, 'opts' => ['length' => 64, 'notnull' => false]],
				'labor_minutes' => ['type' => Types::INTEGER, 'opts' => ['notnull' => false]],
			] as $col => $def) {
				if (!$t->hasColumn($col)) {
					$t->addColumn($col, $def['type'], $def['opts']);
					$changed = true;
				}
			}
			if (!$t->hasIndex('mn_wo_fail_idx') && $t->hasColumn('failure_code')) {
				$t->addIndex(['failure_code'], 'mn_wo_fail_idx');
				$changed = true;
			}
		}

		if ($schema->hasTable('mn_sites')) {
			$t = $schema->getTable('mn_sites');
			if (!$t->hasColumn('access_notes')) {
				$t->addColumn('access_notes', Types::STRING, ['length' => 1024, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('preferred_window')) {
				$t->addColumn('preferred_window', Types::STRING, ['length' => 128, 'notnull' => false]);
				$changed = true;
			}
		}

		if ($schema->hasTable('mn_equipment')) {
			$t = $schema->getTable('mn_equipment');
			if (!$t->hasColumn('warranty_end')) {
				$t->addColumn('warranty_end', Types::STRING, ['length' => 10, 'notnull' => false]);
				$changed = true;
			}
		}

		if (!$schema->hasTable('mn_equip_docs')) {
			$t = $schema->createTable('mn_equip_docs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('external_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_edoc_pk');
			$t->addIndex(['equipment_id'], 'mn_edoc_eq_idx');
			$changed = true;
		}

		if (!$schema->hasTable('mn_failure_codes')) {
			$t = $schema->createTable('mn_failure_codes');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'mn_fcode_pk');
			$t->addUniqueIndex(['code'], 'mn_fcode_code_uq');
			$changed = true;
		}

		if (!$schema->hasTable('mn_wo_comments')) {
			$t = $schema->createTable('mn_wo_comments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('wo_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('body', Types::STRING, ['length' => 4000, 'notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'mn_wocom_pk');
			$t->addIndex(['wo_id', 'created_at'], 'mn_wocom_wo_idx');
			$changed = true;
		}

		if (!$schema->hasTable('mn_notif_log')) {
			$t = $schema->createTable('mn_notif_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('recipient', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('entity_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('entity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('dedupe_key', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'sent']);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'mn_nlog_pk');
			$t->addUniqueIndex(['dedupe_key'], 'mn_nlog_dedupe_uq');
			$t->addIndex(['type', 'recipient'], 'mn_nlog_type_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
