<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after maintenancecheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=maintenancecheck
 */
namespace OCA\MaintenanceCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'mn_customers',
		'mn_day_tours',
		'mn_equip_docs',
		'mn_equip_types',
		'mn_equipment',
		'mn_equipment_classes',
		'mn_failure_codes',
		'mn_inspection_defects',
		'mn_inspection_obligations',
		'mn_kit_templates',
		'mn_kit_tpl_lines',
		'mn_license_state',
		'mn_maint_types',
		'mn_meter_readings',
		'mn_meters',
		'mn_mobile_seats',
		'mn_notif_log',
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
		'mn_wo_comments',
		'mn_wo_kit_lines',
		'mn_wo_kits',
		'mn_wo_photos',
		'mn_wo_signatures',
		'mn_wo_skills',
		'mn_work_orders',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
