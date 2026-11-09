<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * FA-1 multi-day work-order span rules — pure, no I/O (mutation-test target).
 *
 * Contract: `due_until` is NULL iff `due_on` is NULL; otherwise
 * due_until >= due_on and the span is at most MAX_SPAN_DAYS days.
 * Longer engagements are split into several work orders (spec AS-03).
 */
final class WorkOrderSpan
{
	/** Spec §9: bounds overlap queries + render; longer jobs must be split. */
	public const MAX_SPAN_DAYS = 31;

	/**
	 * Inclusive span length in days: [dueOn, dueUntil] = dueUntil - dueOn + 1.
	 * Returns null for undated work orders.
	 */
	public static function spanDays(?string $dueOn, ?string $dueUntil): ?int
	{
		if ($dueOn === null || $dueOn === '') {
			return null;
		}
		$end = ($dueUntil === null || $dueUntil === '') ? $dueOn : $dueUntil;
		$startTs = strtotime($dueOn . 'T00:00:00Z');
		$endTs = strtotime($end . 'T00:00:00Z');
		if ($startTs === false || $endTs === false || $endTs < $startTs) {
			return null;
		}
		return intdiv($endTs - $startTs, 86400) + 1;
	}

	/**
	 * Effective span end — single-day jobs carry due_until = due_on.
	 */
	public static function effectiveEnd(?string $dueOn, ?string $dueUntil): ?string
	{
		if ($dueOn === null || $dueOn === '') {
			return null;
		}
		return ($dueUntil === null || $dueUntil === '') ? $dueOn : $dueUntil;
	}

	/**
	 * True when the span covers $day (single-day membership predicate).
	 */
	public static function covers(?string $dueOn, ?string $dueUntil, string $day): bool
	{
		if ($dueOn === null || $dueOn === '') {
			return false;
		}
		$end = self::effectiveEnd($dueOn, $dueUntil);
		return $dueOn <= $day && $end !== null && $end >= $day;
	}

	/**
	 * Per-day capacity charge rule (spec §9): even distribution
	 * CEIL(estimatedMinutes / spanDays). Single-day → the full estimate.
	 */
	public static function chargePerDay(int $estimatedMinutes, ?int $spanDays): int
	{
		if ($estimatedMinutes <= 0) {
			return 0;
		}
		if ($spanDays === null || $spanDays < 1) {
			return $estimatedMinutes;
		}
		return (int)ceil($estimatedMinutes / $spanDays);
	}

	/**
	 * Service-layer invariant for create/update (spec §9 edge cases):
	 *  - dueUntil without dueOn → 422 (span requires the anchor date)
	 *  - dueUntil < dueOn      → 422 on field dueUntil (never auto-swapped)
	 *  - span > MAX_SPAN_DAYS   → 422 span_too_long
	 *
	 * Both values must already be validated Y-m-d dates (or null/'' treated
	 * as unset). Returns the normalized pair [dueOn, dueUntil].
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	public static function assertValid(?string $dueOn, ?string $dueUntil): array
	{
		$dueOn = ($dueOn === null || $dueOn === '') ? null : $dueOn;
		$dueUntil = ($dueUntil === null || $dueUntil === '') ? null : $dueUntil;
		if ($dueUntil !== null && $dueOn === null) {
			throw new ValidationException('validation_failed', 'An end date needs a start (due) date first.', [
				['field' => 'dueOn', 'code' => 'required_for_due_until'],
			]);
		}
		if ($dueOn === null) {
			return [null, null];
		}
		if ($dueUntil !== null && $dueUntil < $dueOn) {
			throw new ValidationException('validation_failed', 'The end date must not be before the start date.', [
				['field' => 'dueUntil', 'code' => 'before_due_on'],
			]);
		}
		$end = $dueUntil ?? $dueOn;
		$days = self::spanDays($dueOn, $end);
		if ($days !== null && $days > self::MAX_SPAN_DAYS) {
			throw new ValidationException('span_too_long', 'Jobs may span at most ' . self::MAX_SPAN_DAYS . ' days.', [
				['field' => 'dueUntil', 'code' => 'span_too_long'],
			]);
		}
		return [$dueOn, $end];
	}
}
