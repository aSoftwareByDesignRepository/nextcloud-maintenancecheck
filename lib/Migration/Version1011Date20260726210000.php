<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Equipment QR stickers (CORE §2.3 steal #7, COMPANION §9.2 by-qr).
 *
 * Only the SHA-256 of the sticker token is persisted — plaintext is shown
 * once at issue/rotate time for printing. Rotating invalidates prior stickers.
 */
class Version1011Date20260726210000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('mn_equipment')) {
			return null;
		}

		$t = $schema->getTable('mn_equipment');
		$changed = false;
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
		if (!$t->hasIndex('mn_equip_qr_uq')) {
			$t->addUniqueIndex(['qr_token_hash'], 'mn_equip_qr_uq');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
