<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY AC-C-18 — mn_soft_link_ui off hides link UI; core CRUD never gates on the flag.
 */
final class SoftLinkUiFlagOffCrudContractTest extends TestCase
{
	public function testWorkOrderCreateIgnoresSoftLinkUiFlag(): void
	{
		$wo = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkOrderService.php');
		$this->assertStringNotContainsString('mn_soft_link_ui', $wo);
		$this->assertStringNotContainsString('softLinkUiEnabled', $wo);
		$this->assertStringContainsString('public function create(', $wo);
	}

	public function testCustomerCreateDoesNotGateOnSoftLinkFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CustomerService.php');
		$start = strpos($src, 'public function create(string $uid, array $body): array');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function update(', $start);
		$this->assertNotFalse($end);
		$create = substr($src, $start, $end - $start);
		$this->assertStringNotContainsString('softLinkUiEnabled', $create);
		$this->assertStringNotContainsString('mn_soft_link_ui', $create);
		// Flag is exposed for UI only (detail payload), never as a create gate.
		$this->assertStringContainsString('softLinkUiEnabled', $src);
	}

	public function testUiHidesSoftLinkControlsWhenFlagOff(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/app.js');
		$this->assertStringContainsString('softLinkUi !== false', $js);
	}
}
