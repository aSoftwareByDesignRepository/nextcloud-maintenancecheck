<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\MeterMath;
use PHPUnit\Framework\TestCase;

/** W5 decimal meter arithmetic + AC-W5-3 comparison. */
final class MeterMathTest extends TestCase
{
	private MeterMath $math;

	protected function setUp(): void
	{
		$this->math = new MeterMath();
	}

	public function testNormalizeIntAndString(): void
	{
		$this->assertSame('12.000', $this->math->normalizeValue(12));
		$this->assertSame('12.500', $this->math->normalizeValue('12.5'));
		$this->assertSame('0.000', $this->math->normalizeValue('-0'));
	}

	public function testNormalizeFloatUsesThreeDecimals(): void
	{
		$this->assertSame('0.100', $this->math->normalizeValue(0.1));
	}

	public function testRejectsInvalidValues(): void
	{
		foreach (['', 'abc', '1.2345', '1234567890', [], null, true] as $raw) {
			try {
				$this->math->normalizeValue($raw);
				$this->fail('expected ValidationException for ' . var_export($raw, true));
			} catch (ValidationException $e) {
				$this->assertSame('invalid_meter_value', $e->getErrorCode());
			}
		}
	}

	public function testCompareOrdering(): void
	{
		$this->assertSame(-1, $this->math->compare('9.000', '10.000'));
		$this->assertSame(0, $this->math->compare('10.0', '10.000'));
		$this->assertSame(1, $this->math->compare('10.001', '10.000'));
	}

	public function testDecreasingComparisonIsNegative(): void
	{
		// AC-W5-3: monotonic gate uses compare(new, previous) < 0.
		$this->assertSame(-1, $this->math->compare('99.000', '100.000'));
	}

	public function testNegativeNumbers(): void
	{
		$this->assertSame(-1, $this->math->compare('-5.000', '-1.000'));
		$this->assertSame(1, $this->math->compare('-1.000', '-5.000'));
		$this->assertSame('-1.000', $this->math->normalizeValue('-1'));
	}
}
