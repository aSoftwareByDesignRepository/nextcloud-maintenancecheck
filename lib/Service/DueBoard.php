<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * S8 bucket rules — pure and independently unit-tested (mutation target).
 *
 * All buckets apply to `status = scheduled` visits only:
 *   overdue: due_on < today
 *   today:   due_on = today
 *   next7:   today+1 ≤ due_on ≤ today+7
 *   later:   today+8 ≤ due_on ≤ today+30
 * Visits with due_on > today+30 are not on the board.
 */
class DueBoard
{
	public const BUCKET_OVERDUE = 'overdue';
	public const BUCKET_TODAY = 'today';
	public const BUCKET_NEXT7 = 'next7';
	public const BUCKET_LATER = 'later';

	public const HORIZON_DAYS = 30;

	public function __construct(
		private readonly IntervalCalculator $intervals,
	) {
	}

	/**
	 * Board horizon: latest due_on shown (today + 30).
	 */
	public function maxDueOn(string $today): string
	{
		return $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, self::HORIZON_DAYS);
	}

	/**
	 * Returns the bucket name for a scheduled visit, or null when the
	 * visit is beyond the 30-day horizon.
	 */
	public function bucketFor(string $dueOn, string $today): ?string
	{
		if ($dueOn < $today) {
			return self::BUCKET_OVERDUE;
		}
		if ($dueOn === $today) {
			return self::BUCKET_TODAY;
		}
		if ($dueOn <= $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 7)) {
			return self::BUCKET_NEXT7;
		}
		if ($dueOn <= $this->maxDueOn($today)) {
			return self::BUCKET_LATER;
		}
		return null;
	}
}
