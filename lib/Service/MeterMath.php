<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W5 decimal meter arithmetic. DECIMAL(12,3) values travel as strings and
 * must never pass through floats (0.1 + 0.2 …). Comparison is performed on
 * normalised (sign, integer, fraction) triples.
 *
 * Pure service — no I/O, mutation-test target.
 */
class MeterMath
{
	private const VALUE_PATTERN = '/^-?\d{1,9}(\.\d{1,3})?$/';

	/**
	 * Validate and normalise an incoming reading value (string or int/float
	 * from JSON). Bounds follow DECIMAL(12,3).
	 *
	 * @throws ValidationException `invalid_meter_value`
	 */
	public function normalizeValue(mixed $raw): string
	{
		if (is_int($raw)) {
			$raw = (string)$raw;
		} elseif (is_float($raw)) {
			// JSON numbers arrive as floats; 3 decimals is the column scale.
			$raw = number_format($raw, 3, '.', '');
		}
		if (!is_string($raw)) {
			throw new ValidationException('invalid_meter_value', 'Meter value must be a number.');
		}
		$raw = trim($raw);
		if (!preg_match(self::VALUE_PATTERN, $raw)) {
			throw new ValidationException('invalid_meter_value', 'Meter value must be a decimal with at most 9 integer and 3 fraction digits.');
		}
		return $this->normalize($raw);
	}

	/**
	 * Numeric comparison of two decimal strings: -1, 0, or 1.
	 */
	public function compare(string $a, string $b): int
	{
		[$signA, $intA, $fracA] = $this->parts($this->normalize($a));
		[$signB, $intB, $fracB] = $this->parts($this->normalize($b));

		if ($signA !== $signB) {
			return $signA < $signB ? -1 : 1;
		}
		$cmp = strlen($intA) <=> strlen($intB);
		if ($cmp === 0) {
			$cmp = strcmp($intA, $intB);
		}
		if ($cmp === 0) {
			$cmp = strcmp($fracA, $fracB);
		}
		$cmp = $cmp <=> 0;
		return $signA < 0 ? -$cmp : $cmp;
	}

	/**
	 * Canonical form: no leading zeros, exactly 3 fraction digits, `-0` → `0`.
	 */
	public function normalize(string $value): string
	{
		$negative = str_starts_with($value, '-');
		if ($negative) {
			$value = substr($value, 1);
		}
		$dot = strpos($value, '.');
		$int = $dot === false ? $value : substr($value, 0, $dot);
		$frac = $dot === false ? '' : substr($value, $dot + 1);

		$int = ltrim($int, '0');
		if ($int === '') {
			$int = '0';
		}
		$frac = str_pad(substr($frac, 0, 3), 3, '0');

		if ($int === '0' && $frac === '000') {
			$negative = false;
		}
		return ($negative ? '-' : '') . $int . '.' . $frac;
	}

	/**
	 * @return array{0: int, 1: string, 2: string} sign (-1|1), integer digits, fraction digits
	 */
	private function parts(string $normalized): array
	{
		$negative = str_starts_with($normalized, '-');
		if ($negative) {
			$normalized = substr($normalized, 1);
		}
		[$int, $frac] = explode('.', $normalized, 2);
		return [$negative ? -1 : 1, $int, $frac];
	}
}
