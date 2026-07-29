<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\CapacityCalculator;
use PHPUnit\Framework\TestCase;

/** AC-W4-1 / CORE §10.5 capacity maths. */
final class CapacityCalculatorTest extends TestCase
{
	private CapacityCalculator $calc;

	protected function setUp(): void
	{
		$this->calc = new CapacityCalculator();
	}

	public function testDoesNotExceedAtExactThreshold(): void
	{
		$result = $this->calc->assess(480, 1.0, 400, 80);
		$this->assertFalse($result['exceeds']);
		$this->assertSame(480, $result['thresholdMinutes']);
		$this->assertSame(480, $result['projectedMinutes']);
	}

	public function testExceedsWhenProjectedAboveThreshold(): void
	{
		$result = $this->calc->assess(480, 1.0, 400, 81);
		$this->assertTrue($result['exceeds']);
		$this->assertSame(481, $result['projectedMinutes']);
	}

	public function testWarnRatioRaisesThreshold(): void
	{
		// 480 * 1.25 = 600 → 400+100 = 500 does not exceed.
		$result = $this->calc->assess(480, 1.25, 400, 100);
		$this->assertFalse($result['exceeds']);
		$this->assertSame(600, $result['thresholdMinutes']);
	}

	public function testInvalidWarnRatioFallsBackToDefault(): void
	{
		$result = $this->calc->assess(100, 0.0, 50, 60);
		$this->assertTrue($result['exceeds']);
		$this->assertSame(100, $result['thresholdMinutes']);
	}

	public function testDailyMinutesClampedToAtLeastOne(): void
	{
		$result = $this->calc->assess(0, 1.0, 0, 2);
		$this->assertSame(1, $result['capacityMinutes']);
		$this->assertTrue($result['exceeds']);
	}

	public function testNegativeLoadTreatedAsZero(): void
	{
		$result = $this->calc->assess(100, 1.0, -50, 10);
		$this->assertSame(0, $result['loadMinutes']);
		$this->assertSame(10, $result['projectedMinutes']);
		$this->assertFalse($result['exceeds']);
	}

	public function testUtilisationRounded(): void
	{
		$result = $this->calc->assess(100, 1.0, 33, 0);
		$this->assertSame(0.33, $result['utilisation']);
	}
}
