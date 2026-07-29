<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCP\Server;

/**
 * WP-S2-MN-F6 / AC-S2.2 — live soft-fail against StockIssueFacade (or absence).
 *
 * @group integration
 */
final class InventoryFlangeLiveSoftFailIntegrationTest extends IntegrationTestCase
{
	private ?bool $previousEnabled = null;

	protected function tearDown(): void
	{
		if ($this->previousEnabled !== null && class_exists(\OC::class)) {
			try {
				Server::get(InventoryFlangeService::class)->setEnabled($this->previousEnabled);
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	public function testEnabledFlangeWithUnknownSkuSoftFails(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$flange = Server::get(InventoryFlangeService::class);
		$this->previousEnabled = $flange->isEnabled();
		$flange->setEnabled(true);

		$result = $flange->issueForWorkOrder(
			'admin',
			910001 + random_int(1, 9999),
			[['sku' => 'SUITE-F6-MISSING-' . bin2hex(random_bytes(3)), 'qty' => 1]],
		);

		// Soft-fail contract: never throws; unknown SKU → failed/unavailable.
		$this->assertContains($result['sync'], ['failed', 'unavailable']);
		$this->assertNotSame('', (string)($result['code'] ?? ''));
	}

	public function testDisabledFlangeNeverCallsInventory(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$flange = Server::get(InventoryFlangeService::class);
		$this->previousEnabled = $flange->isEnabled();
		$flange->setEnabled(false);
		$result = $flange->issueForWorkOrder('admin', 1, [['sku' => 'X', 'qty' => 1]]);
		$this->assertSame('disabled', $result['sync']);
		$this->assertSame('flange_disabled', $result['code']);
	}
}
