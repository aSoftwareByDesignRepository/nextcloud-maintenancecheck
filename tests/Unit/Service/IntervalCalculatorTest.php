<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §6.1 vectors — clamp-to-month-end semantics (S2), leap years,
 * year rollovers, and S19 bounds. Primary mutation target.
 */
final class IntervalCalculatorTest extends TestCase
{
	private IntervalCalculator $calc;

	protected function setUp(): void
	{
		$this->calc = new IntervalCalculator();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static function specVectors(): array
	{
		return [
			// Days
			'day simple' => ['2026-03-15', 'day', 10, '2026-03-25'],
			'day month rollover' => ['2026-01-31', 'day', 1, '2026-02-01'],
			'day year rollover' => ['2026-12-31', 'day', 1, '2027-01-01'],
			'day across leap day' => ['2028-02-28', 'day', 1, '2028-02-29'],
			'day across non-leap' => ['2027-02-28', 'day', 1, '2027-03-01'],
			'day max count' => ['2026-01-01', 'day', 3650, '2035-12-30'],
			// Weeks
			'week simple' => ['2026-03-02', 'week', 1, '2026-03-09'],
			'week four' => ['2026-01-05', 'week', 4, '2026-02-02'],
			'week year rollover' => ['2026-12-28', 'week', 1, '2027-01-04'],
			// Months — clamp semantics
			'month simple' => ['2026-03-15', 'month', 1, '2026-04-15'],
			'month jan31 clamps to feb28' => ['2026-01-31', 'month', 1, '2026-02-28'],
			'month jan31 leap clamps to feb29' => ['2028-01-31', 'month', 1, '2028-02-29'],
			'month jan30 clamps to feb28' => ['2026-01-30', 'month', 1, '2026-02-28'],
			'month mar31 clamps to apr30' => ['2026-03-31', 'month', 1, '2026-04-30'],
			'month aug31 clamps to sep30' => ['2026-08-31', 'month', 1, '2026-09-30'],
			'month clamp does not stick' => ['2026-01-31', 'month', 2, '2026-03-31'],
			'month year rollover' => ['2026-11-15', 'month', 2, '2027-01-15'],
			'month dec to jan' => ['2026-12-31', 'month', 1, '2027-01-31'],
			'month 12 equals year' => ['2026-05-14', 'month', 12, '2027-05-14'],
			'month multi-year' => ['2026-01-15', 'month', 25, '2028-02-15'],
			'month max count' => ['2026-02-28', 'month', 120, '2036-02-28'],
			// SPEC §6.1 additional normative vectors
			'month mar31 times six' => ['2026-03-31', 'month', 6, '2026-09-30'],
			'month nov30 times three' => ['2026-11-30', 'month', 3, '2027-02-28'],
			'week july24 times two' => ['2026-07-24', 'week', 2, '2026-08-07'],
			// Years
			'year simple' => ['2026-06-10', 'year', 1, '2027-06-10'],
			'year feb29 clamps to feb28' => ['2028-02-29', 'year', 1, '2029-02-28'],
			'year feb29 to next leap' => ['2028-02-29', 'year', 4, '2032-02-29'],
			'year max count' => ['2026-01-01', 'year', 10, '2036-01-01'],
		];
	}

	/** @dataProvider specVectors */
	public function testAddInterval(string $date, string $unit, int $count, string $expected): void
	{
		$this->assertSame($expected, $this->calc->addInterval($date, $unit, $count));
	}

	public function testAddIntervalNeverUsesPhpMonthOverflow(): void
	{
		// PHP native modify('+1 month') would return 2026-03-03 here.
		$this->assertSame('2026-02-28', $this->calc->addInterval('2026-01-31', 'month', 1));
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function invalidIntervals(): array
	{
		return [
			'unknown unit' => ['fortnight', 1],
			'empty unit' => ['', 1],
			'case-sensitive unit' => ['Day', 1],
			'zero count' => ['day', 0],
			'negative count' => ['week', -1],
			'day above max' => ['day', 3651],
			'week above max' => ['week', 521],
			'month above max' => ['month', 121],
			'year above max' => ['year', 11],
		];
	}

	/** @dataProvider invalidIntervals */
	public function testAssertValidIntervalRejects(string $unit, int $count): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->calc->assertValidInterval($unit, $count);
		} catch (ValidationException $e) {
			$this->assertSame('invalid_interval', $e->getErrorCode());
			throw $e;
		}
	}

	/**
	 * Unknown units must fail the unit allow-list itself (with the unit
	 * message), not fall through to the count-bounds branch.
	 */
	public function testUnknownUnitFailsTheAllowListCheck(): void
	{
		foreach (['fortnight', 'Day', ''] as $unit) {
			try {
				$this->calc->assertValidInterval($unit, 1);
				$this->fail('Expected ValidationException for unit ' . $unit);
			} catch (ValidationException $e) {
				$this->assertSame('invalid_interval', $e->getErrorCode());
				$this->assertStringContainsString('unit must be day, week, month, or year', $e->getMessage());
			}
		}
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function boundaryValidIntervals(): array
	{
		return [
			'day min' => ['day', 1],
			'day max' => ['day', 3650],
			'week max' => ['week', 520],
			'month max' => ['month', 120],
			'year max' => ['year', 10],
		];
	}

	/** @dataProvider boundaryValidIntervals */
	public function testAssertValidIntervalAcceptsBoundaries(string $unit, int $count): void
	{
		$this->calc->assertValidInterval($unit, $count);
		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalidDates(): array
	{
		return [
			'not a date' => ['banana'],
			'wrong shape' => ['2026-1-05'],
			'slash separators' => ['2026/01/05'],
			'month 13' => ['2026-13-01'],
			'day zero' => ['2026-01-00'],
			'feb 30' => ['2026-02-30'],
			'feb 29 non-leap' => ['2027-02-29'],
			'trailing junk' => ['2026-01-05x'],
			'empty' => [''],
		];
	}

	/** @dataProvider invalidDates */
	public function testAddIntervalRejectsInvalidDate(string $date): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->calc->addInterval($date, 'day', 1);
		} catch (ValidationException $e) {
			$this->assertSame('invalid_due_date', $e->getErrorCode());
			throw $e;
		}
	}

	/** @dataProvider invalidDates */
	public function testIsValidYmdRejects(string $date): void
	{
		$this->assertFalse($this->calc->isValidYmd($date));
	}

	public function testIsValidYmdAccepts(): void
	{
		$this->assertTrue($this->calc->isValidYmd('2026-01-05'));
		$this->assertTrue($this->calc->isValidYmd('2028-02-29'));
		$this->assertTrue($this->calc->isValidYmd('2000-01-01'));
	}

	public function testAddIntervalIsDeterministic(): void
	{
		$a = $this->calc->addInterval('2026-01-31', 'month', 1);
		$b = $this->calc->addInterval('2026-01-31', 'month', 1);
		$this->assertSame($a, $b);
	}

	public function testResultNeverMovesBackwardForPositiveCount(): void
	{
		foreach (['day', 'week', 'month', 'year'] as $unit) {
			$result = $this->calc->addInterval('2026-07-24', $unit, 1);
			$this->assertGreaterThanOrEqual('2026-07-24', $result, $unit);
		}
	}
}
