<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repair amendment: re-apply W1/W5 equipment + plan columns that may be
 * missing when earlier migrations were recorded without a successful schema
 * diff (idempotent hasColumn guards). Also ensures QR sticker columns.
 */
class Version1012Date20260726220000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('mn_plans')) {
			$t = $schema->getTable('mn_plans');
			if (!$t->hasColumn('trigger_kind')) {
				$t->addColumn('trigger_kind', Types::STRING, [
					'length' => 16,
					'notnull' => true,
					'default' => 'interval',
				]);
				$changed = true;
			}
			if (!$t->hasColumn('meter_code')) {
				$t->addColumn('meter_code', Types::STRING, ['length' => 64, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('meter_threshold')) {
				$t->addColumn('meter_threshold', Types::DECIMAL, [
					'precision' => 12,
					'scale' => 3,
					'notnull' => false,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('mn_equipment')) {
			$t = $schema->getTable('mn_equipment');
			if (!$t->hasColumn('site_id')) {
				$t->addColumn('site_id', Types::BIGINT, ['notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('lat')) {
				$t->addColumn('lat', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('lng')) {
				$t->addColumn('lng', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('qr_token_hash')) {
				$t->addColumn('qr_token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
				$changed = true;
			}
			if (!$t->hasColumn('qr_token_rotated_at')) {
				$t->addColumn('qr_token_rotated_at', Types::INTEGER, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$changed = true;
			}
			if (!$t->hasIndex('mn_equip_qr_uq') && $t->hasColumn('qr_token_hash')) {
				$t->addUniqueIndex(['qr_token_hash'], 'mn_equip_qr_uq');
				$changed = true;
			}
			if (!$t->hasIndex('mn_equip_site_idx') && $t->hasColumn('site_id')) {
				$t->addIndex(['site_id'], 'mn_equip_site_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
