<?php

declare(strict_types=1);

/**
 * MaintenanceCheck transfer manifest — every appconfig key and every mn_*
 * table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * Access door + office roles are app-local appconfig (no platform
 * restriction mirror → 'plain' with uid/gid list refs, no mirrored keys).
 * `issue_location_id` is a sibling-app (InventoryCheck) entity id —
 * instance-bound, class 'env'. Evidence-blob tables (wo photos,
 * signatures, equipment docs) address appdata files keyed by source row
 * ids — the ids remap on import, so the rows are declared internal and
 * never move.
 */

namespace OCA\MaintenanceCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig ──
			'access_restriction_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── office vs technician role grants ──
			'office_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'roles'],
			'office_group_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'roles'],
			// ── work policies (PolicyService::save validators) ──
			'checklist_done_policy' => ['class' => 'plain', 'section' => 'policies'],
			'checklist_min_percent' => ['class' => 'plain', 'section' => 'policies'],
			'skills_enforcement' => ['class' => 'plain', 'section' => 'policies'],
			'capacity_enforcement' => ['class' => 'plain', 'section' => 'policies'],
			'capacity_warn_ratio' => ['class' => 'plain', 'section' => 'policies'],
			'require_equipment_on_wo' => ['class' => 'plain', 'section' => 'policies'],
			'failure_code_on_corrective' => ['class' => 'plain', 'section' => 'policies'],
			'defect_follow_up' => ['class' => 'plain', 'section' => 'policies'],
			'inspection_result_required' => ['class' => 'plain', 'section' => 'policies'],
			'fail_blocks_roll' => ['class' => 'plain', 'section' => 'policies'],
			// ── inventory stock-issue flange (F6) ──
			'f6_inventory_flange_enabled' => ['class' => 'plain', 'section' => 'inventory'],
			'issue_location_policy' => ['class' => 'plain', 'section' => 'inventory'],
			// ── suite soft-link UI toggle ──
			'mn_soft_link_ui' => 'plain',

			// ── instance-bound — never transferred ──
			// InventoryCheck location id — the row it names lives in the
			// sibling app's table and only exists on this instance.
			'issue_location_id' => 'env',

			// ── internal bookkeeping — never transferred ──
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ catalog / reference data ══
			'mn_equip_types' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'mn_maint_types' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'mn_equipment_classes' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'mn_failure_codes' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'mn_skills' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			// created_by stays verbatim: builtin packs are written by the
			// 'maintenancecheck-seed' system actor, never a real uid — a
			// uid_ref would skip every seeded procedure (and their items via
			// the FK cascade). The string is provenance, not a live grant.
			'mn_procedures' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'mn_proc_items' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['procedure_id', 'code'],
				'fk' => ['procedure_id' => 'mn_procedures'], 'depends_on' => ['mn_procedures']],
			'mn_kit_templates' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_kit_tpl_lines' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'sort_order'],
				'fk' => ['template_id' => 'mn_kit_templates'], 'depends_on' => ['mn_kit_templates']],

			// ══ user-data — only for users existing on both instances (D1) ══
			'mn_user_skills' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['uid', 'skill_id'],
				'fk' => ['skill_id' => 'mn_skills'], 'depends_on' => ['mn_skills'],
				'uid_ref' => ['uid', 'granted_by'], 'uid_ref_nullable' => ['granted_by']],
			'mn_user_capacity' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['uid'],
				'uid_ref' => ['uid', 'updated_by'], 'uid_ref_nullable' => ['updated_by']],

			// ══ business data ══
			'mn_customers' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'postal_code', 'city'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_sites' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['customer_id', 'name'],
				'fk' => ['customer_id' => 'mn_customers'], 'depends_on' => ['mn_customers'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_equipment' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['customer_id', 'label', 'serial_no'],
				'fk' => ['customer_id' => 'mn_customers', 'equip_type_id' => 'mn_equip_types'],
				// site link is optional — degrade to NULL rather than drop the unit.
				'fk_late' => ['site_id' => 'mn_sites'],
				'depends_on' => ['mn_customers', 'mn_equip_types'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_plans' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['equipment_id', 'maint_type_id', 'trigger_kind'],
				'fk' => ['equipment_id' => 'mn_equipment', 'maint_type_id' => 'mn_maint_types'],
				'depends_on' => ['mn_equipment', 'mn_maint_types'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_visits' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['plan_id', 'due_on'],
				'fk' => ['plan_id' => 'mn_plans', 'equipment_id' => 'mn_equipment',
					'customer_id' => 'mn_customers', 'maint_type_id' => 'mn_maint_types'],
				'depends_on' => ['mn_plans', 'mn_equipment', 'mn_customers', 'mn_maint_types'],
				// mn_visits has no created_by column (schema audit) — a
				// declared ref would be injected as NULL into every row.
				'uid_ref' => ['assigned_uid', 'done_by'],
				'uid_ref_nullable' => ['assigned_uid', 'done_by']],
			'mn_work_orders' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['number'],
				'fk' => ['customer_id' => 'mn_customers'],
				// Optional back-links (site/visit/procedure/obligation/parent WO)
				// resolve after the full load — a skipped parent nulls the link
				// instead of sinking the work order.
				'fk_late' => ['equipment_id' => 'mn_equipment', 'site_id' => 'mn_sites',
					'visit_id' => 'mn_visits', 'procedure_id' => 'mn_procedures',
					'obligation_id' => 'mn_inspection_obligations',
					'source_wo_id' => 'mn_work_orders'],
				'depends_on' => ['mn_customers'],
				'uid_ref' => ['primary_user_id', 'completed_by', 'created_by'],
				'uid_ref_nullable' => ['primary_user_id', 'completed_by', 'created_by'],
				'json_uid_list' => ['helper_uids']],
			'mn_wo_checklist' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['work_order_id', 'item_code'],
				'fk' => ['work_order_id' => 'mn_work_orders'], 'depends_on' => ['mn_work_orders'],
				'uid_ref' => ['updated_by'], 'uid_ref_nullable' => ['updated_by']],
			'mn_wo_kits' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['work_order_id'],
				'fk' => ['work_order_id' => 'mn_work_orders'],
				'fk_late' => ['template_id' => 'mn_kit_templates'],
				'depends_on' => ['mn_work_orders'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_wo_kit_lines' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['wo_kit_id', 'sort_order'],
				'fk' => ['wo_kit_id' => 'mn_wo_kits'], 'depends_on' => ['mn_wo_kits']],
			// Pure junction — every non-id column IS the natural key, so a
			// matched-row update has zero SET columns (empty UPDATE = SQL
			// error). seed-if-empty: full load on fresh targets + replace;
			// non-empty merge skips (junction rows re-link cheaply).
			'mn_wo_skills' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'natural_key' => ['work_order_id', 'skill_id'],
				'fk' => ['work_order_id' => 'mn_work_orders', 'skill_id' => 'mn_skills'],
				'depends_on' => ['mn_work_orders', 'mn_skills']],
			'mn_wo_comments' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['wo_id', 'created_at', 'created_by'],
				'fk' => ['wo_id' => 'mn_work_orders'], 'depends_on' => ['mn_work_orders'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_inspection_defects' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['wo_id', 'code'],
				'fk' => ['wo_id' => 'mn_work_orders'], 'depends_on' => ['mn_work_orders'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by'],
				'env_columns' => ['photo_file_id']],
			'mn_meters' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['equipment_id', 'code'],
				'fk' => ['equipment_id' => 'mn_equipment'], 'depends_on' => ['mn_equipment'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_meter_readings' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['meter_id', 'read_on', 'value'],
				'fk' => ['meter_id' => 'mn_meters', 'equipment_id' => 'mn_equipment'],
				'depends_on' => ['mn_meters', 'mn_equipment'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_inspection_obligations' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['equipment_id', 'class_code'],
				'fk' => ['equipment_id' => 'mn_equipment'],
				'fk_late' => ['procedure_id' => 'mn_procedures', 'plan_id' => 'mn_plans'],
				'depends_on' => ['mn_equipment'],
				'uid_ref' => ['created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_day_tours' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['tour_date', 'tech_uid'],
				'uid_ref' => ['tech_uid', 'created_by'], 'uid_ref_nullable' => ['created_by']],
			'mn_tour_stops' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['work_order_id'],
				'fk' => ['tour_id' => 'mn_day_tours', 'work_order_id' => 'mn_work_orders'],
				'depends_on' => ['mn_day_tours', 'mn_work_orders']],

			// ══ internal / derived / log — NEVER transferred ══
			// Vendor-signed license payload + named seats: instance-bound
			// commercial state (same call as mobilitycheck mc_license_state).
			'mn_license_state' => ['class' => 'internal'],
			'mn_mobile_seats' => ['class' => 'internal'],
			// Overdue-reminder dedupe journal — a log, not data.
			'mn_notif_log' => ['class' => 'log'],
			// Evidence blobs: rows name appdata files under source row ids —
			// remapped ids dead-end the reference; blobs never move.
			'mn_wo_photos' => ['class' => 'internal'],
			'mn_wo_signatures' => ['class' => 'internal'],
			'mn_equip_docs' => ['class' => 'internal'],
		]);
	}
}
