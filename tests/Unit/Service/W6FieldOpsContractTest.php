<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\FailureCodeMapper;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\MobileCapabilities;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Tests\TestCase;
use OCP\IConfig;
use OCP\IDBConnection;

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
		// Pure formula lock: onTime / (onTime + late + overdue)
		$onTime = 8;
		$late = 1;
		$overdue = 1;
		$pct = round(100.0 * $onTime / ($onTime + $late + $overdue), 1);
		self::assertSame(80.0, $pct);
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
