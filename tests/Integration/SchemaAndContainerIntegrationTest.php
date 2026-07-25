<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Repair\EnsureMaintenanceCheckSchema;
use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * @group integration
 */
class SchemaAndContainerIntegrationTest extends IntegrationTestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
	}

	public function testAppIdConstant(): void
	{
		$this->assertSame('maintenancecheck', Application::APP_ID);
	}

	public function testAllEightTablesExistAfterEnsure(): void
	{
		$step = Server::get(EnsureMaintenanceCheckSchema::class);
		$step->run($this->createMock(IOutput::class));

		$db = Server::get(\OCP\IDBConnection::class);
		$this->assertCount(8, UninstallDropTables::TABLES);
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table), "missing $table");
		}
	}

	public function testEnsureStepIsIdempotent(): void
	{
		$step = Server::get(EnsureMaintenanceCheckSchema::class);
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));
		$db = Server::get(\OCP\IDBConnection::class);
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table));
		}
	}

	public function testRepairStepsResolveFromContainer(): void
	{
		$this->assertInstanceOf(EnsureMaintenanceCheckSchema::class, Server::get(EnsureMaintenanceCheckSchema::class));
		$this->assertInstanceOf(UninstallDropTables::class, Server::get(UninstallDropTables::class));
	}

	public function testDefaultCatalogSeedsArePresent(): void
	{
		$db = Server::get(\OCP\IDBConnection::class);
		foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('id', 'cnt'))->from($table);
			$result = $qb->executeQuery();
			$count = (int)($result->fetchOne() ?: 0);
			$result->closeCursor();
			$this->assertGreaterThan(0, $count, "$table has no seed rows");
		}
	}
}
