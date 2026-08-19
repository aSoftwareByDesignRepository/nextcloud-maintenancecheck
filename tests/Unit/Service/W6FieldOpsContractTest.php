<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\MobileCapabilities;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * W6 unit contracts — capabilities, failure-code policy, KPI algebra edge cases.
 */
final class W6FieldOpsContractTest extends TestCase
{
	public function testCapabilitiesAdvertiseW6Flags(): void
	{
		$caps = MobileCapabilities::current();
		foreach ([
			'requestIntake',
			'failureCodes',
			'laborMinutes',
			'woComments',
			'equipmentDocs',
			'opsAlerts',
		] as $flag) {
			self::assertTrue($caps[$flag], $flag . ' must be true for W6 companion');
		}
		self::assertTrue($caps['meters']);
		self::assertTrue($caps['workOrders']);
	}

	public function testFailureCodePolicyDefaultsToWarn(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return $default;
			}
		);
		$policy = new PolicyService($config);
		self::assertSame(PolicyService::FAILURE_CODE_WARN, $policy->failureCodeOnCorrective());
	}

	public function testFailureCodePolicyRejectsInvalidWrite(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return $default;
			}
		);
		$policy = new PolicyService($config);
		$this->expectException(\OCA\MaintenanceCheck\Exception\ValidationException::class);
		$policy->save(['failureCodeOnCorrective' => 'block']);
	}

	public function testFailureCodeSeedHasAtLeastEight(): void
	{
		self::assertGreaterThanOrEqual(8, count(FailureCodeService::SEED));
		$codes = array_column(FailureCodeService::SEED, 'code');
		self::assertContains('unknown', $codes);
		self::assertSame(count($codes), count(array_unique($codes)));
	}

	public function testKpiComplianceFormulaOnTimeOnly(): void
	{
		self::assertSame(80.0, \OCA\MaintenanceCheck\Service\KpiService::ratioPercent(8, 1, 1));
		self::assertNull(\OCA\MaintenanceCheck\Service\KpiService::ratioPercent(0, 0, 0));
		self::assertSame(100.0, \OCA\MaintenanceCheck\Service\KpiService::ratioPercent(3, 0, 0));
		self::assertSame(0.0, \OCA\MaintenanceCheck\Service\KpiService::ratioPercent(0, 2, 2));
	}

	public function testLaborMinutesBoundConstant(): void
	{
		self::assertSame(1440, \OCA\MaintenanceCheck\Db\WorkOrder::MAX_LABOR_MINUTES);
	}

	public function testEquipDocMaxIsTwenty(): void
	{
		self::assertSame(20, \OCA\MaintenanceCheck\Db\EquipDoc::MAX_PER_EQUIPMENT);
	}
}
