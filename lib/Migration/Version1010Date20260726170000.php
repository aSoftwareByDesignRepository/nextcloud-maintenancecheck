<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * CORE-APP (MN-CORE-1.1) phases W1–W5 schema — additive only (R9).
 *
 * W1: sites, procedures + items (show_if), work orders, checklist instances,
 *     photos.
 * W2: kit templates + lines, WO kits + lines, skills, user skills, WO skills.
 * W3: day tours + stops, WO signatures.
 * W4: per-user capacity.
 * W5: meters + readings; plan trigger amendment (trigger_kind / meter_code /
 *     meter_threshold).
 *
 * Identifier lengths stay Oracle-safe: logical table names ≤ 27 chars,
 * explicit PK/index names ≤ 30 chars. Every block is guarded by
 * hasTable/hasColumn so re-running is a no-op.
 */
class Version1010Date20260726170000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createSites($schema);
		$this->createProcedures($schema);
		$this->createWorkOrders($schema);
		$this->createKits($schema);
		$this->createSkills($schema);
		$this->createTours($schema);
		$this->createCapacity($schema);
		$this->createMeters($schema);
		$this->amendPlans($schema);
		$this->amendEquipment($schema);

		return $schema;
	}

	private function createSites(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_sites')) {
			$t = $schema->createTable('mn_sites');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('street', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('postal_code', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('city', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('country', Types::STRING, ['length' => 2, 'notnull' => false]);
			$t->addColumn('lat', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
			$t->addColumn('lng', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_site_pk');
			$t->addIndex(['customer_id'], 'mn_site_cust_idx');
		}
	}

	private function createProcedures(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_procedures')) {
			$t = $schema->createTable('mn_procedures');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('vertical', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('locale', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'en']);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('source_pack', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_proc_pk');
			$t->addUniqueIndex(['code'], 'mn_proc_code_uq');
		}

		if (!$schema->hasTable('mn_proc_items')) {
			$t = $schema->createTable('mn_proc_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('procedure_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('required', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('show_if_item_code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('show_if_result', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'mn_pitem_pk');
			$t->addUniqueIndex(['procedure_id', 'code'], 'mn_pitem_proc_code_uq');
		}
	}

	private function createWorkOrders(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_work_orders')) {
			$t = $schema->createTable('mn_work_orders');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('number', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'corrective']);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('priority', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'normal']);
			$t->addColumn('customer_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('visit_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('procedure_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('due_on', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('estimated_minutes', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('primary_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('helper_uids', Types::TEXT, ['notnull' => false]);
			$t->addColumn('procedure_skipped', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('procedure_skip_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('block_reason_code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('block_note', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('kit_override', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('kit_override_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('force_close_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('inventory_sync', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('started_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('completed_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('completed_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_wo_pk');
			$t->addUniqueIndex(['number'], 'mn_wo_number_uq');
			$t->addIndex(['status', 'due_on'], 'mn_wo_status_due_idx');
			$t->addIndex(['customer_id'], 'mn_wo_cust_idx');
			$t->addIndex(['equipment_id'], 'mn_wo_equip_idx');
			$t->addIndex(['visit_id'], 'mn_wo_visit_idx');
			$t->addIndex(['primary_user_id', 'due_on'], 'mn_wo_primary_due_idx');
		}

		if (!$schema->hasTable('mn_wo_checklist')) {
			$t = $schema->createTable('mn_wo_checklist');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('item_code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('required', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('show_if_item_code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('show_if_result', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('result', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('note', Types::STRING, ['length' => 1024, 'notnull' => false]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'mn_wochk_pk');
			$t->addUniqueIndex(['work_order_id', 'item_code'], 'mn_wochk_wo_code_uq');
		}

		if (!$schema->hasTable('mn_wo_photos')) {
			$t = $schema->createTable('mn_wo_photos');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_name', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('original_name', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('mime', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('size_bytes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_wopho_pk');
			$t->addIndex(['work_order_id'], 'mn_wopho_wo_idx');
		}

		if (!$schema->hasTable('mn_wo_signatures')) {
			$t = $schema->createTable('mn_wo_signatures');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_name', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('size_bytes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('signer_name', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_wosig_pk');
			$t->addUniqueIndex(['work_order_id'], 'mn_wosig_wo_uq');
		}
	}

	private function createKits(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_kit_templates')) {
			$t = $schema->createTable('mn_kit_templates');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_kittpl_pk');
			$t->addUniqueIndex(['code'], 'mn_kittpl_code_uq');
		}

		if (!$schema->hasTable('mn_kit_tpl_lines')) {
			$t = $schema->createTable('mn_kit_tpl_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('template_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('line_type', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'part']);
			$t->addColumn('sku', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('qty_required', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('optional', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'mn_kitln_pk');
			$t->addIndex(['template_id'], 'mn_kitln_tpl_idx');
		}

		if (!$schema->hasTable('mn_wo_kits')) {
			$t = $schema->createTable('mn_wo_kits');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('template_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_wokit_pk');
			$t->addUniqueIndex(['work_order_id'], 'mn_wokit_wo_uq');
		}

		if (!$schema->hasTable('mn_wo_kit_lines')) {
			$t = $schema->createTable('mn_wo_kit_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('wo_kit_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('line_type', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'part']);
			$t->addColumn('sku', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('qty_required', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('qty_packed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('optional', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'mn_wokln_pk');
			$t->addIndex(['wo_kit_id'], 'mn_wokln_kit_idx');
		}
	}

	private function createSkills(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_skills')) {
			$t = $schema->createTable('mn_skills');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'mn_skill_pk');
			$t->addUniqueIndex(['code'], 'mn_skill_code_uq');
		}

		if (!$schema->hasTable('mn_user_skills')) {
			$t = $schema->createTable('mn_user_skills');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('skill_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('granted_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('granted_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_uskill_pk');
			$t->addUniqueIndex(['uid', 'skill_id'], 'mn_uskill_uid_skill_uq');
		}

		if (!$schema->hasTable('mn_wo_skills')) {
			$t = $schema->createTable('mn_wo_skills');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('skill_id', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_woskl_pk');
			$t->addUniqueIndex(['work_order_id', 'skill_id'], 'mn_woskl_wo_skill_uq');
		}
	}

	private function createTours(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_day_tours')) {
			$t = $schema->createTable('mn_day_tours');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tour_date', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('tech_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('order_locked', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('notes', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_tour_pk');
			$t->addUniqueIndex(['tour_date', 'tech_uid'], 'mn_tour_day_tech_uq');
		}

		if (!$schema->hasTable('mn_tour_stops')) {
			$t = $schema->createTable('mn_tour_stops');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tour_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('work_order_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'mn_tstop_pk');
			$t->addUniqueIndex(['work_order_id'], 'mn_tstop_wo_uq');
			$t->addIndex(['tour_id'], 'mn_tstop_tour_idx');
		}
	}

	private function createCapacity(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_user_capacity')) {
			$t = $schema->createTable('mn_user_capacity');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('daily_minutes', Types::INTEGER, ['notnull' => true, 'default' => 480]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_ucap_pk');
			$t->addUniqueIndex(['uid'], 'mn_ucap_uid_uq');
		}
	}

	private function createMeters(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_meters')) {
			$t = $schema->createTable('mn_meters');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('unit', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('monotonic', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_meter_pk');
			$t->addUniqueIndex(['equipment_id', 'code'], 'mn_meter_eq_code_uq');
		}

		if (!$schema->hasTable('mn_meter_readings')) {
			$t = $schema->createTable('mn_meter_readings');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('meter_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('meter_code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('value', Types::DECIMAL, ['precision' => 12, 'scale' => 3, 'notnull' => true]);
			$t->addColumn('read_on', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('source', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'manual']);
			$t->addColumn('note', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_mread_pk');
			$t->addIndex(['meter_id', 'id'], 'mn_mread_meter_idx');
		}
	}

	/**
	 * W5 plan amendment (§14.1b): existing plans keep interval semantics
	 * (`trigger_kind = 'interval'` default).
	 */
	private function amendPlans(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_plans')) {
			return;
		}
		$t = $schema->getTable('mn_plans');
		if (!$t->hasColumn('trigger_kind')) {
			$t->addColumn('trigger_kind', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'interval']);
		}
		if (!$t->hasColumn('meter_code')) {
			$t->addColumn('meter_code', Types::STRING, ['length' => 64, 'notnull' => false]);
		}
		if (!$t->hasColumn('meter_threshold')) {
			$t->addColumn('meter_threshold', Types::DECIMAL, ['precision' => 12, 'scale' => 3, 'notnull' => false]);
		}
	}

	/**
	 * W1/W3 equipment amendment: optional site link + optional geo for tour
	 * suggest-order (A3 — missing geo degrades to PLZ/city sort).
	 */
	private function amendEquipment(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('mn_equipment')) {
			return;
		}
		$t = $schema->getTable('mn_equipment');
		if (!$t->hasColumn('site_id')) {
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$t->hasColumn('lat')) {
			$t->addColumn('lat', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
		}
		if (!$t->hasColumn('lng')) {
			$t->addColumn('lng', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
		}
	}
}
