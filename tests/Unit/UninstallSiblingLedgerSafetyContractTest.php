<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

/** SHARED-IDENTITY AC-C-11 — uninstall drops only MN-owned tables. */
final class UninstallSiblingLedgerSafetyContractTest extends TestCase
{
	public function testUninstallTablesAreMnPrefixedOnly(): void
	{
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertStringStartsWith('mn_', $table, $table);
			$this->assertStringNotContainsString('pc_', $table);
			$this->assertStringNotContainsString('ic_', $table);
			$this->assertStringNotContainsString('crm_', $table);
		}
		$this->assertContains('mn_customers', UninstallDropTables::TABLES);
	}
}
