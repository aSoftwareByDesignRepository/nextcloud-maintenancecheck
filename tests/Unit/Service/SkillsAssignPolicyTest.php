<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\SkillsAssignPolicy;
use PHPUnit\Framework\TestCase;

/** AC-W2-2 — skills block / warn / force on assign. */
final class SkillsAssignPolicyTest extends TestCase
{
	private SkillsAssignPolicy $policy;

	protected function setUp(): void
	{
		$this->policy = new SkillsAssignPolicy();
	}

	public function testOffAllowsMissingSkills(): void
	{
		$warnings = $this->policy->evaluate(PolicyService::ENFORCEMENT_OFF, [
			['id' => 1, 'code' => 'gas', 'name' => 'Gas'],
		], false);
		$this->assertSame([], $warnings);
	}

	public function testEmptyMissingAlwaysOk(): void
	{
		$this->assertSame([], $this->policy->evaluate(PolicyService::ENFORCEMENT_BLOCK, [], false));
		$this->assertSame([], $this->policy->evaluate(PolicyService::ENFORCEMENT_WARN, [], false));
	}

	public function testBlockRejectsWithSkillsMissing(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->policy->evaluate(PolicyService::ENFORCEMENT_BLOCK, [
				['id' => 7, 'code' => 'f-gas', 'name' => 'F-Gas'],
			], true);
		} catch (ValidationException $e) {
			$this->assertSame('skills_missing', $e->getErrorCode());
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('missing_skill:f-gas', $codes);
			throw $e;
		}
	}

	public function testWarnWithoutForceConflicts(): void
	{
		$this->expectException(ConflictException::class);
		try {
			$this->policy->evaluate(PolicyService::ENFORCEMENT_WARN, [
				['id' => 2, 'code' => 'electro', 'name' => 'Electro'],
			], false);
		} catch (ConflictException $e) {
			$this->assertSame('skills_warning', $e->getErrorCode());
			$this->assertArrayHasKey('missing', $e->getDetails());
			throw $e;
		}
	}

	public function testWarnWithForceReturnsWarningPayload(): void
	{
		$missing = [['id' => 3, 'code' => 'shk', 'name' => 'SHK']];
		$warnings = $this->policy->evaluate(PolicyService::ENFORCEMENT_WARN, $missing, true);
		$this->assertCount(1, $warnings);
		$this->assertSame('skills_missing', $warnings[0]['code']);
		$this->assertSame($missing, $warnings[0]['missing']);
	}
}
