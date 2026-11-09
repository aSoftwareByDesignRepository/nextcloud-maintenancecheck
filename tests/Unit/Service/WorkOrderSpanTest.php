<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\WorkOrderSpan;
use PHPUnit\Framework\TestCase;

/**
 * FA-1 span math — mutation-test target (boundary matrix per spec §10).
 */
final class WorkOrderSpanTest extends TestCase
{
	public function testSpanDaysSingleDay(): void
	{
		$this->assertSame(1, WorkOrderSpan::spanDays('2026-11-16', '2026-11-16'));
		$this->assertSame(1, WorkOrderSpan::spanDays('2026-11-16', null));
	}

	public function testSpanDaysMultiDayAcrossMonth(): void
	{
		$this->assertSame(3, WorkOrderSpan::spanDays('2026-11-16', '2026-11-18'));
		$this->assertSame(3, WorkOrderSpan::spanDays('2026-11-30', '2026-12-02'));
	}

	public function testSpanDaysNullForUndatedOrInverted(): void
	{
		$this->assertNull(WorkOrderSpan::spanDays(null, '2026-11-18'));
		$this->assertNull(WorkOrderSpan::spanDays('', ''));
		$this->assertNull(WorkOrderSpan::spanDays('2026-11-18', '2026-11-16'));
	}

	public function testEffectiveEndFallsBackToDueOn(): void
	{
		$this->assertSame('2026-11-16', WorkOrderSpan::effectiveEnd('2026-11-16', null));
		$this->assertSame('2026-11-18', WorkOrderSpan::effectiveEnd('2026-11-16', '2026-11-18'));
		$this->assertNull(WorkOrderSpan::effectiveEnd(null, '2026-11-18'));
	}

	public function testCoversBoundaries(): void
	{
		$this->assertTrue(WorkOrderSpan::covers('2026-11-16', '2026-11-18', '2026-11-16'));
		$this->assertTrue(WorkOrderSpan::covers('2026-11-16', '2026-11-18', '2026-11-18'));
		$this->assertFalse(WorkOrderSpan::covers('2026-11-16', '2026-11-18', '2026-11-15'));
		$this->assertFalse(WorkOrderSpan::covers('2026-11-16', '2026-11-18', '2026-11-19'));
		$this->assertFalse(WorkOrderSpan::covers(null, null, '2026-11-16'));
		$this->assertTrue(WorkOrderSpan::covers('2026-11-16', null, '2026-11-16'));
	}

	public function testChargePerDayEvenDistribution(): void
	{
		$this->assertSame(480, WorkOrderSpan::chargePerDay(480, 1));
		$this->assertSame(160, WorkOrderSpan::chargePerDay(480, 3));
		$this->assertSame(1, WorkOrderSpan::chargePerDay(1, 2));
		$this->assertSame(480, WorkOrderSpan::chargePerDay(480, null));
		$this->assertSame(0, WorkOrderSpan::chargePerDay(-5, 2));
	}

	public function testAssertValidAcceptsAbsent(): void
	{
		$this->assertSame([null, null], WorkOrderSpan::assertValid(null, null));
		$this->assertSame(['2026-11-16', '2026-11-16'], WorkOrderSpan::assertValid('2026-11-16', null));
		$this->assertSame(['2026-11-16', '2026-11-18'], WorkOrderSpan::assertValid('2026-11-16', '2026-11-18'));
		$this->assertSame(['2026-11-16', '2026-11-16'], WorkOrderSpan::assertValid('2026-11-16', '2026-11-16'));
	}

	public function testAssertValidRejectsUntilWithoutOn(): void
	{
		$this->expectException(ValidationException::class);
		WorkOrderSpan::assertValid(null, '2026-11-18');
	}

	public function testAssertValidRejectsInvertedSpan(): void
	{
		try {
			WorkOrderSpan::assertValid('2026-11-18', '2026-11-16');
			$this->fail('expected 422');
		} catch (ValidationException $e) {
			$this->assertSame('dueUntil', $e->getDetails()[0]['field']);
		}
	}

	public function testAssertValidBoundary31Days(): void
	{
		$this->assertSame(['2026-11-01', '2026-12-01'], WorkOrderSpan::assertValid('2026-11-01', '2026-12-01'));
		try {
			WorkOrderSpan::assertValid('2026-11-01', '2026-12-02');
			$this->fail('expected span_too_long');
		} catch (ValidationException $e) {
			$this->assertSame('span_too_long', $e->getErrorCode());
		}
	}
}
