<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Repair\EnsureMaintenanceCheckSchema;
use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use ReflectionClass;
use Test\TestCase;

/**
 * AC-18: explicit removal drops every mn_* table; schema ensurer restores them.
 * Uses reflection on the private drop path (same code Installer::removeApp runs).
 *
 * Critical: dropAllTablesAndMetadata() also clears appconfig (including `enabled`).
 * This suite snapshots every appconfig key and restores it in finally so later
 * HTTP/E2E suites and the live Docker instance stay functional.
 *
 * @group integration
 */
final class LiveUninstallDropIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
	}

	public function testRemovalDropClearsAllTablesAndEnsurerRestoresThem(): void
	{
		$db = Server::get(IDBConnection::class);
		$config = Server::get(IConfig::class);
		$appId = Application::APP_ID;

		$preserved = $this->snapshotAppConfig($config, $appId);
		$this->assertSame('yes', $preserved['enabled'] ?? '', 'precondition: app must be enabled before AC-18 drop');

		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table), "precondition: $table exists");
		}

		try {
			$step = Server::get(UninstallDropTables::class);
			$method = (new ReflectionClass(UninstallDropTables::class))->getMethod('dropAllTablesAndMetadata');
			$method->setAccessible(true);
			$method->invoke($step, $this->createMock(IOutput::class));

			foreach (UninstallDropTables::TABLES as $table) {
				$this->assertFalse($db->tableExists($table), "AC-18: $table must be gone after removal drop");
			}
			$this->assertSame(
				'',
				$config->getAppValue($appId, 'enabled', ''),
				'removal path clears appconfig (including enabled)',
			);

			Server::get(EnsureMaintenanceCheckSchema::class)->run($this->createMock(IOutput::class));

			foreach (UninstallDropTables::TABLES as $table) {
				$this->assertTrue($db->tableExists($table), "ensurer must recreate $table");
			}
		} finally {
			foreach ($preserved as $key => $value) {
				$config->setAppValue($appId, $key, $value);
			}
			$this->assertSame(
				'yes',
				$config->getAppValue($appId, 'enabled', ''),
				'AC-18 suite must leave the app enabled for the rest of the gauntlet',
			);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotAppConfig(IConfig $config, string $appId): array
	{
		$out = [];
		foreach ($config->getAppKeys($appId) as $key) {
			$out[$key] = $config->getAppValue($appId, $key, '');
		}
		return $out;
	}
}
