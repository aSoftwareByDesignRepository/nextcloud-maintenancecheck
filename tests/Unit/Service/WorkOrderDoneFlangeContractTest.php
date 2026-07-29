<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * WO done → F6 dispatch contract (WP-S2-MN-F6 / AC-S2.2).
 */
final class WorkOrderDoneFlangeContractTest extends TestCase
{
	public function testApplyDoneReturnsSkuLinesForFlange(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkOrderService.php');
		$this->assertStringContainsString('function applyDone(', $src);
		$this->assertStringContainsString('skuLinesFor', $src);
		$this->assertMatchesRegularExpression(
			'/return \$skuLines === \[\] \? null : \$skuLines;/',
			$src,
		);
	}

	public function testTransitionInvokesFlangeOnlyAfterCommit(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkOrderService.php');
		$commit = strpos($src, '$this->db->commit();');
		$dispatch = strpos($src, '$this->dispatchInventoryFlange(');
		$this->assertNotFalse($commit);
		$this->assertNotFalse($dispatch);
		$this->assertGreaterThan($commit, $dispatch);
		$this->assertStringContainsString("if (\$flange !== null)", $src);
	}
}
