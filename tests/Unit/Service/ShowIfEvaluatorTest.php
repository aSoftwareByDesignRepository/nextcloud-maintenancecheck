<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use PHPUnit\Framework\TestCase;

/** AC-W1-6 / AC-W1-7 — show_if authoring + runtime visibility. */
final class ShowIfEvaluatorTest extends TestCase
{
	private ShowIfEvaluator $eval;

	protected function setUp(): void
	{
		$this->eval = new ShowIfEvaluator();
	}

	public function testAlwaysVisibleWhenNoRule(): void
	{
		$items = [
			['code' => 'a', 'showIfItemCode' => null, 'showIfResult' => null],
		];
		$vis = $this->eval->visibility($items, []);
		$this->assertTrue($vis['a']);
	}

	public function testShowsWhenParentMatchesResult(): void
	{
		$items = [
			['code' => 'parent', 'showIfItemCode' => null, 'showIfResult' => null],
			['code' => 'child', 'showIfItemCode' => 'parent', 'showIfResult' => 'fail'],
		];
		$this->assertFalse($this->eval->visibility($items, ['parent' => 'ok'])['child']);
		$this->assertTrue($this->eval->visibility($items, ['parent' => 'fail'])['child']);
	}

	public function testAnyAnsweredRequiresOkFailOrNa(): void
	{
		$items = [
			['code' => 'parent', 'showIfItemCode' => null, 'showIfResult' => null],
			['code' => 'child', 'showIfItemCode' => 'parent', 'showIfResult' => 'any_answered'],
		];
		$this->assertFalse($this->eval->visibility($items, ['parent' => null])['child']);
		$this->assertTrue($this->eval->visibility($items, ['parent' => 'ok'])['child']);
		$this->assertFalse($this->eval->visibility($items, ['parent' => 'garbage'])['child']);
	}

	public function testHiddenParentHidesChild(): void
	{
		$items = [
			['code' => 'root', 'showIfItemCode' => null, 'showIfResult' => null],
			['code' => 'mid', 'showIfItemCode' => 'root', 'showIfResult' => 'ok'],
			['code' => 'leaf', 'showIfItemCode' => 'mid', 'showIfResult' => 'ok'],
		];
		$vis = $this->eval->visibility($items, ['root' => 'fail', 'mid' => null]);
		$this->assertFalse($vis['mid']);
		$this->assertFalse($vis['leaf']);
	}

	public function testValidateRejectsIncompleteRuleResultOnly(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->eval->validateRules([
				['code' => 'a', 'showIfItemCode' => null, 'showIfResult' => 'ok'],
			]);
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('incomplete_rule', $codes);
			throw $e;
		}
	}

	public function testValidateRejectsIncompleteRuleCodeOnly(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->eval->validateRules([
				['code' => 'a', 'showIfItemCode' => 'b', 'showIfResult' => null],
				['code' => 'b', 'showIfItemCode' => null, 'showIfResult' => null],
			]);
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('incomplete_rule', $codes);
			throw $e;
		}
	}

	public function testValidateRejectsSelfReference(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->eval->validateRules([
				['code' => 'a', 'showIfItemCode' => 'a', 'showIfResult' => 'ok'],
			]);
		} catch (ValidationException $e) {
			$this->assertSame('show_if_cycle', $e->getErrorCode());
			$this->assertStringContainsString('itself', strtolower($e->getMessage()));
			throw $e;
		}
	}

	public function testValidateRejectsUnknownReference(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->eval->validateRules([
				['code' => 'a', 'showIfItemCode' => null, 'showIfResult' => null],
				['code' => 'b', 'showIfItemCode' => 'missing', 'showIfResult' => 'ok'],
			]);
		} catch (ValidationException $e) {
			$this->assertSame('show_if_unknown', $e->getErrorCode());
			throw $e;
		}
	}

	public function testValidateRejectsCycle(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->eval->validateRules([
				['code' => 'a', 'showIfItemCode' => 'b', 'showIfResult' => 'ok'],
				['code' => 'b', 'showIfItemCode' => 'a', 'showIfResult' => 'ok'],
			]);
		} catch (ValidationException $e) {
			$this->assertSame('show_if_cycle', $e->getErrorCode());
			$this->assertStringContainsString('cycle', strtolower($e->getMessage()));
			throw $e;
		}
	}

	public function testPersistedCycleDegradesToHidden(): void
	{
		// Runtime must not recurse forever if bad data slipped past validation.
		$items = [
			['code' => 'a', 'showIfItemCode' => 'b', 'showIfResult' => 'ok'],
			['code' => 'b', 'showIfItemCode' => 'a', 'showIfResult' => 'ok'],
		];
		$vis = $this->eval->visibility($items, ['a' => 'ok', 'b' => 'ok']);
		$this->assertFalse($vis['a']);
		$this->assertFalse($vis['b']);
	}
}
