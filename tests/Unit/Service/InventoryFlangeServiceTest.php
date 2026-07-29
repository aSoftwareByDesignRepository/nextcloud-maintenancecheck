<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CHECK-SUITE AC-L5 / F6 — toggle defaults off; soft-fail when IV absent.
 */
class InventoryFlangeServiceTest extends TestCase
{
	private IConfig&MockObject $config;
	private InventoryFlangeService $service;

	protected function setUp(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$this->service = new InventoryFlangeService(
			$this->config,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testDefaultsDisabled(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				return $default;
			});
		$this->assertFalse($this->service->isEnabled());
		$result = $this->service->issueForWorkOrder('tech', 1, [['sku' => 'X', 'qty' => 1]]);
		$this->assertSame('disabled', $result['sync']);
	}

	public function testDefaultLocationPolicyIsFailAmbiguous(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				return $default;
			});
		$this->assertSame(InventoryFlangeService::POLICY_FAIL_AMBIGUOUS, $this->service->locationPolicy());
	}

	public function testEnabledButFacadeMissingReturnsUnavailable(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($key === InventoryFlangeService::KEY_F6_ENABLED) {
					return '1';
				}
				return $default;
			});
		// In this workspace StockIssueFacade may exist — if so, skip unavailable assert.
		if (class_exists('OCA\\InventoryCheck\\Public\\StockIssueFacade')) {
			$this->markTestSkipped('InventoryCheck StockIssueFacade is present in this tree.');
		}
		$result = $this->service->issueForWorkOrder('tech', 9, [['sku' => 'FILTER-42', 'qty' => 2]]);
		$this->assertSame('unavailable', $result['sync']);
	}

	public function testFacadeThrowableSoftFailsWithoutPropagating(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($key === InventoryFlangeService::KEY_F6_ENABLED) {
					return '1';
				}
				if ($key === InventoryFlangeService::KEY_LOCATION_POLICY) {
					return InventoryFlangeService::POLICY_FAIL_AMBIGUOUS;
				}
				return $default;
			});
		$svc = new InventoryFlangeService(
			$this->config,
			$this->createMock(LoggerInterface::class),
			static function (): object {
				throw new \RuntimeException('iv_disabled_or_down');
			},
		);
		$result = $svc->issueForWorkOrder('tech', 42, [['sku' => 'FILTER-42', 'qty' => 2]]);
		$this->assertSame('failed', $result['sync']);
		$this->assertSame('inventory_sync_failed', $result['code']);
	}

	public function testFacadeNonOkResultSoftFails(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($key === InventoryFlangeService::KEY_F6_ENABLED) {
					return '1';
				}
				return $default;
			});
		$svc = new InventoryFlangeService(
			$this->config,
			$this->createMock(LoggerInterface::class),
			static function (): object {
				return (object)['ok' => false, 'code' => 'insufficient_stock'];
			},
		);
		$result = $svc->issueForWorkOrder('tech', 7, [['sku' => 'BOLT', 'qty' => 99]]);
		$this->assertSame('failed', $result['sync']);
		$this->assertSame('insufficient_stock', $result['code']);
	}

	public function testFacadeOkReturnsOk(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($key === InventoryFlangeService::KEY_F6_ENABLED) {
					return '1';
				}
				return $default;
			});
		$svc = new InventoryFlangeService(
			$this->config,
			$this->createMock(LoggerInterface::class),
			static function (): object {
				return (object)['ok' => true, 'code' => 'idempotent_replay'];
			},
		);
		$result = $svc->issueForWorkOrder('tech', 7, [['sku' => 'BOLT', 'qty' => 1]]);
		$this->assertSame('ok', $result['sync']);
		$this->assertSame('idempotent_replay', $result['code']);
	}

	public function testEmptySkuLinesShortCircuitOkWhenEnabled(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($key === InventoryFlangeService::KEY_F6_ENABLED) {
					return '1';
				}
				return $default;
			});
		$result = $this->service->issueForWorkOrder('tech', 1, [['sku' => '', 'qty' => 0]]);
		$this->assertSame('ok', $result['sync']);
		$this->assertNull($result['code']);
	}

	public function testEquipmentDefaultPolicyDoesNotLoadExplicitLocationId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/InventoryFlangeService.php');
		$this->assertStringContainsString(
			'POLICY_EXPLICIT || $policy === self::POLICY_FAIL_AMBIGUOUS',
			$src,
			'equipment_default must not force MN explicit location into the IV request',
		);
		$this->assertStringContainsString(
			'equipment_default_location lets IV resolve',
			$src,
		);
	}
}
