<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * Deterministic calendar math (SPEC §6.1) with clamp-to-month-end semantics (S2).
 *
 * Pure — no clock, no DB, no timezone: calendar days in, calendar days out.
 * PHP native `modify('+1 month')` MUST NOT be used because it overflows
 * (2026-01-31 + 1 month → 2026-03-03); this class clamps to 2026-02-28.
 */
class IntervalCalculator
{
	public const UNIT_DAY = 'day';
	public const UNIT_WEEK = 'week';
	public const UNIT_MONTH = 'month';
	public const UNIT_YEAR = 'year';

	public const UNITS = [self::UNIT_DAY, self::UNIT_WEEK, self::UNIT_MONTH, self::UNIT_YEAR];

	/** S19: upper bound per unit so every computed due date stays ≤ 10 years out. */
	public const MAX_COUNT = [
		self::UNIT_DAY => 3650,
		self::UNIT_WEEK => 520,
		self::UNIT_MONTH => 120,
		self::UNIT_YEAR => 10,
	];

	/**
	 * S19: validate unit + count bounds. Throws 422 `invalid_interval`.
	 */
	public function assertValidInterval(string $unit, int $count): void
	{
		if (!in_array($unit, self::UNITS, true)) {
			throw new ValidationException('invalid_interval', 'Interval unit must be day, week, month, or year.');
		}
		if ($count < 1 || $count > self::MAX_COUNT[$unit]) {
			throw new ValidationException('invalid_interval', sprintf(
				'Interval count must be between 1 and %d for unit "%s".',
				self::MAX_COUNT[$unit],
				$unit,
			));
		}
	}

	/**
	 * addInterval(date, unit, count) exactly per SPEC §6.1.
	 */
	public function addInterval(string $date, string $unit, int $count): string
	{
		$this->assertValidInterval($unit, $count);
		[$y, $m, $d] = $this->parseYmd($date);

		switch ($unit) {
			case self::UNIT_DAY:
				return $this->addDays($y, $m, $d, $count);
			case self::UNIT_WEEK:
				return $this->addDays($y, $m, $d, $count * 7);
			case self::UNIT_YEAR:
				$count *= 12;
				// fall through to month arithmetic
			case self::UNIT_MONTH:
			default:
				$monthIndex = $m - 1 + $count;
				$targetYear = $y + intdiv($monthIndex, 12);
				$targetMonth = ($monthIndex % 12) + 1;
				$targetDay = min($d, $this->daysInMonth($targetYear, $targetMonth));
				return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
		}
	}

	/**
	 * Strict Y-m-d check: correct shape AND a real calendar date.
	 */
	public function isValidYmd(string $value): bool
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
			return false;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	/**
	 * @return array{0: int, 1: int, 2: int} [year, month, day]
	 */
	private function parseYmd(string $date): array
	{
		if (!$this->isValidYmd($date)) {
			throw new ValidationException('invalid_due_date', sprintf('"%s" is not a valid calendar date.', $date));
		}
		return [(int)substr($date, 0, 4), (int)substr($date, 5, 2), (int)substr($date, 8, 2)];
	}

	private function addDays(int $y, int $m, int $d, int $days): string
	{
		$dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), new \DateTimeZone('UTC'));
		return $dt->modify(sprintf('+%d days', $days))->format('Y-m-d');
	}

	private function daysInMonth(int $year, int $month): int
	{
		return (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->format('t');
	}
}
