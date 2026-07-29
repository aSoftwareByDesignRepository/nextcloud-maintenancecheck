<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * AC-S2.2 / AC-L5 — F6 must never roll back WO done; soft-fail contract in source.
 */
final class InventoryFlangeSoftFailContractTest extends TestCase
{
	public function testWorkOrderTransitionCommitsBeforeFlange(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkOrderService.php');
		$commitPos = strpos($src, '$this->db->commit();');
		$flangePos = strpos($src, '$this->dispatchInventoryFlange(');
		$this->assertNotFalse($commitPos);
		$this->assertNotFalse($flangePos);
		$this->assertGreaterThan($commitPos, $flangePos, 'F6 must run after WO commit');
		$this->assertStringContainsString('soft-fail only', $src);
	}

	public function testUnavailableMapsToFailedInventorySyncColumn(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkOrderService.php');
		$this->assertStringContainsString("if (\$sync === 'unavailable')", $src);
		$this->assertStringContainsString("\$sync = 'failed';", $src);
		$this->assertStringContainsString("set('inventory_sync'", $src);
		$this->assertStringContainsString("set('inventory_sync_code'", $src);
	}

	public function testFlangeCatchesThrowableAsFailedAndDefaultsOff(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/InventoryFlangeService.php');
		$this->assertStringContainsString('catch (\\Throwable $e)', $src);
		$this->assertStringContainsString("'sync' => 'failed'", $src);
		$this->assertStringContainsString("'sync' => 'unavailable'", $src);
		$this->assertStringContainsString("self::KEY_F6_ENABLED, '0'", $src);
	}

	public function testEventDocsSoftFailNeverRollsBackWo(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Event/WorkOrderClosedEvent.php');
		$this->assertMatchesRegularExpression('/inventory_sync\s*=\s*failed|soft.?fail/i', $src);
	}
}
