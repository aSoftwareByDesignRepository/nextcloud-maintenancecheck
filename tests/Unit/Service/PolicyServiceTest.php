<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Org policy snapshot + validated writes (W3–W4). */
final class PolicyServiceTest extends TestCase
{
	private IConfig $config;
	private PolicyService $policies;

	protected function setUp(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$this->policies = new PolicyService($this->config);
	}

	public function testSnapshotFallsBackOnCorruptStoredValues(): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				self::assertSame(Application::APP_ID, $app);
				return match ($key) {
					PolicyService::KEY_CHECKLIST_DONE_POLICY => 'bogus',
					PolicyService::KEY_CHECKLIST_MIN_PERCENT => 'x',
					PolicyService::KEY_SKILLS_ENFORCEMENT => 'loud',
					PolicyService::KEY_CAPACITY_ENFORCEMENT => 'loud',
					PolicyService::KEY_CAPACITY_WARN_RATIO => 'nope',
					PolicyService::KEY_REQUIRE_EQUIPMENT_ON_WO => '0',
					default => $default,
				};
			}
		);

		$snap = $this->policies->snapshot();
		$this->assertSame(ChecklistPolicy::POLICY_ALL_REQUIRED, $snap['checklistDonePolicy']);
		$this->assertSame(ChecklistPolicy::DEFAULT_MIN_PERCENT, $snap['checklistMinPercent']);
		$this->assertSame(PolicyService::ENFORCEMENT_WARN, $snap['skillsEnforcement']);
		$this->assertSame(PolicyService::ENFORCEMENT_WARN, $snap['capacityEnforcement']);
		$this->assertSame(1.0, $snap['capacityWarnRatio']);
		$this->assertFalse($snap['requireEquipmentOnWo']);
	}

	public function testSavePersistsValidatedPartialUpdate(): void
	{
		$written = [];
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$written) {
				return $written[$key] ?? $default;
			}
		);
		$this->config->expects($this->exactly(3))->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): void {
				self::assertSame(Application::APP_ID, $app);
				$written[$key] = $value;
			}
		);

		$snap = $this->policies->save([
			'checklistDonePolicy' => ChecklistPolicy::POLICY_PERCENT,
			'checklistMinPercent' => 80,
			'skillsEnforcement' => PolicyService::ENFORCEMENT_BLOCK,
		]);

		$this->assertSame(ChecklistPolicy::POLICY_PERCENT, $snap['checklistDonePolicy']);
		$this->assertSame(80, $snap['checklistMinPercent']);
		$this->assertSame(PolicyService::ENFORCEMENT_BLOCK, $snap['skillsEnforcement']);
	}

	public function testSaveRejectsInvalidEnforcement(): void
	{
		$this->expectException(ValidationException::class);
		$this->policies->save(['capacityEnforcement' => 'maybe']);
	}

	public function testSaveRejectsBadWarnRatio(): void
	{
		$this->expectException(ValidationException::class);
		$this->policies->save(['capacityWarnRatio' => 0]);
	}

	public function testSaveRejectsNonBoolEquipmentFlag(): void
	{
		$this->expectException(ValidationException::class);
		$this->policies->save(['requireEquipmentOnWo' => 1]);
	}
}
