<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial MaintenanceCheck schema (mn_*). Identifier lengths stay Oracle-safe:
 * logical table names ≤ 27 chars, explicit PK/index names ≤ 30 chars.
 */
class Version1000Date20260724160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('mn_customers')) {
			$t = $schema->createTable('mn_customers');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('customer_no', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('street', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('postal_code', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('city', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('country', Types::STRING, ['length' => 2, 'notnull' => false]);
			$t->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('phone', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_cust_pk');
			$t->addIndex(['name'], 'mn_cust_name_idx');
			$t->addIndex(['customer_no'], 'mn_cust_no_idx');
			$t->addIndex(['city'], 'mn_cust_city_idx');
		}

		if (!$schema->hasTable('mn_equip_types')) {
			$t = $schema->createTable('mn_equip_types');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'mn_etype_pk');
			$t->addUniqueIndex(['code'], 'mn_etype_code_uq');
		}

		if (!$schema->hasTable('mn_maint_types')) {
			$t = $schema->createTable('mn_maint_types');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'mn_mtype_pk');
			$t->addUniqueIndex(['code'], 'mn_mtype_code_uq');
		}

		if (!$schema->hasTable('mn_equipment')) {
			$t = $schema->createTable('mn_equipment');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('equip_type_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('manufacturer', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('model', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('serial_no', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('location_text', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_equip_pk');
			$t->addIndex(['customer_id'], 'mn_equip_cust_idx');
			$t->addIndex(['equip_type_id'], 'mn_equip_type_idx');
			$t->addIndex(['label'], 'mn_equip_label_idx');
		}

		if (!$schema->hasTable('mn_plans')) {
			$t = $schema->createTable('mn_plans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('maint_type_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('interval_unit', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'month']);
			$t->addColumn('interval_count', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('has_contract', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('contract_notes', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_plan_pk');
			$t->addIndex(['equipment_id'], 'mn_plan_equip_idx');
			$t->addIndex(['maint_type_id'], 'mn_plan_mtype_idx');
		}

		if (!$schema->hasTable('mn_visits')) {
			$t = $schema->createTable('mn_visits');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('plan_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('equipment_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('customer_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('maint_type_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('due_on', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'scheduled']);
			$t->addColumn('assigned_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('done_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('done_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('done_on', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'mn_visit_pk');
			$t->addIndex(['status', 'due_on'], 'mn_visit_due_idx');
			$t->addIndex(['plan_id'], 'mn_visit_plan_idx');
			$t->addIndex(['equipment_id'], 'mn_visit_equip_idx');
			$t->addIndex(['assigned_uid'], 'mn_visit_assign_idx');
			$t->addIndex(['customer_id'], 'mn_visit_cust_idx');
		}

		if (!$schema->hasTable('mn_license_state')) {
			$t = $schema->createTable('mn_license_state');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('issued_at', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('mobile_seats', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_b64', Types::TEXT, ['notnull' => true]);
			$t->addColumn('signature_b64', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('applied_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('applied_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_lic_pk');
		}

		if (!$schema->hasTable('mn_mobile_seats')) {
			$t = $schema->createTable('mn_mobile_seats');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'mn_seat_pk');
			$t->addUniqueIndex(['uid'], 'mn_seat_uid_uq');
		}

		return $schema;
	}
}
