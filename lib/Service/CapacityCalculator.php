<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * CORE §10.5 (W4) capacity hints.
 *
 *   load(tech, day) = Σ estimated_minutes of non-terminal WOs that day
 *   warn  if projected load > daily_capacity_minutes × capacity_warn_ratio
 *   block if capacity_enforcement = block AND projected load would exceed
 *
 * This class does the maths; the enforcement decision (off/warn/block)
 * belongs to the caller.
 *
 * Pure service — no I/O, mutation-test target.
 */
class CapacityCalculator
{
	public const DEFAULT_WARN_RATIO = 1.0;

	/**
	 * @param int   $dailyMinutes the tech's capacity for the day (> 0)
	 * @param float $warnRatio    org setting `capacity_warn_ratio`
	 * @param int   $loadMinutes  already-assigned estimated minutes
	 * @param int   $addMinutes   minutes about to be assigned (0 = status only)
	 * @return array{exceeds: bool, capacityMinutes: int, thresholdMinutes: int,
	 *               loadMinutes: int, projectedMinutes: int, utilisation: float}
	 */
	public function assess(int $dailyMinutes, float $warnRatio, int $loadMinutes, int $addMinutes): array
	{
		$capacity = max(1, $dailyMinutes);
		$ratio = ($warnRatio > 0.0 && $warnRatio <= 10.0) ? $warnRatio : self::DEFAULT_WARN_RATIO;
		$threshold = (int)floor($capacity * $ratio);
		$projected = max(0, $loadMinutes) + max(0, $addMinutes);

		return [
			'exceeds' => $projected > $threshold,
			'capacityMinutes' => $capacity,
			'thresholdMinutes' => $threshold,
			'loadMinutes' => max(0, $loadMinutes),
			'projectedMinutes' => $projected,
			'utilisation' => round($projected / $capacity, 4),
		];
	}
}
