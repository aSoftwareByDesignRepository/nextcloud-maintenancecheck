<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=maintenancecheck
 */
namespace OCA\MaintenanceCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'maintenancecheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/maintenancecheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [
		'wo-photos',
		'wo-signatures',
	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'mn_customers',
		'mn_day_tours',
		'mn_equip_types',
		'mn_equipment',
		'mn_kit_templates',
		'mn_kit_tpl_lines',
		'mn_license_state',
		'mn_maint_types',
		'mn_meter_readings',
		'mn_meters',
		'mn_mobile_seats',
		'mn_plans',
		'mn_proc_items',
		'mn_procedures',
		'mn_sites',
		'mn_skills',
		'mn_tour_stops',
		'mn_user_capacity',
		'mn_user_skills',
		'mn_visits',
		'mn_wo_checklist',
		'mn_wo_kit_lines',
		'mn_wo_kits',
		'mn_wo_photos',
		'mn_wo_signatures',
		'mn_wo_skills',
		'mn_work_orders',
	];

	/** @var list<string> Parents before children so restores never orphan rows. */
	public const RESTORE_TABLE_ORDER = [
		'mn_customers',
		'mn_sites',
		'mn_equip_types',
		'mn_maint_types',
		'mn_equipment',
		'mn_plans',
		'mn_visits',
		'mn_procedures',
		'mn_proc_items',
		'mn_work_orders',
		'mn_wo_checklist',
		'mn_wo_photos',
		'mn_wo_signatures',
		'mn_kit_templates',
		'mn_kit_tpl_lines',
		'mn_wo_kits',
		'mn_wo_kit_lines',
		'mn_skills',
		'mn_user_skills',
		'mn_wo_skills',
		'mn_day_tours',
		'mn_tour_stops',
		'mn_user_capacity',
		'mn_meters',
		'mn_meter_readings',
		'mn_license_state',
		'mn_mobile_seats',
	];

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
