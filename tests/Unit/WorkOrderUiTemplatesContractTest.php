<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** WP-S2-MN-W1 — phone UI templates must exist for PageController routes. */
final class WorkOrderUiTemplatesContractTest extends TestCase
{
	public function testWorkOrderTemplatesExist(): void
	{
		$root = dirname(__DIR__, 2) . '/templates';
		foreach (['work-orders.php', 'work-order-detail.php', 'dispatch.php', 'tours.php'] as $file) {
			$this->assertFileExists($root . '/' . $file, "Missing template $file");
			$src = (string)file_get_contents($root . '/' . $file);
			$this->assertStringContainsString('page-start.php', $src);
		}
		$list = (string)file_get_contents($root . '/work-orders.php');
		$this->assertStringContainsString('mn-wo-list', $list);
		$this->assertStringContainsString('mn-filter-panel', $list);
		$detail = (string)file_get_contents($root . '/work-order-detail.php');
		$this->assertStringContainsString('mn-wo-detail', $detail);
	}

	public function testWorkOrderPagesScriptExists(): void
	{
		$js = dirname(__DIR__, 2) . '/js/work-order-pages.js';
		$this->assertFileExists($js);
		$src = (string)file_get_contents($js);
		$this->assertStringContainsString("registerPage('work-orders'", $src);
		$this->assertStringContainsString("registerPage('work-order-detail'", $src);
		$this->assertStringContainsString('checklist', $src);
		$this->assertStringContainsString('apiUpload', $src);
		$this->assertStringContainsString('showBootFailure', $src);
		$this->assertStringContainsString('Work orders could not start', $src);
		$this->assertStringContainsString("apiUrl('customers')", $src);
		$this->assertStringContainsString('Select a customer', $src);
		$this->assertStringNotContainsString('Numeric customer id from the register', $src);
	}

	public function testAppJsExposesDomBridge(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/app.js');
		$this->assertStringContainsString('MnApp.__dom', $src);
		$this->assertStringContainsString('registerPage', $src);
		$this->assertStringContainsString("case 'in_progress'", $src);
	}
}
