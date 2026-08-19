<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * SPEC §4.1 field validation — server-side, authoritative.
 *
 * All string inputs are trimmed before validation (S20). Overlong input is
 * rejected with 422 (never silently truncated). Wrong JSON types → 422
 * `validation_failed`. Unknown body fields are ignored by the callers.
 */
class InputValidator
{
	public const TEN_YEARS_MIN_DATE = '2000-01-01';
	/** Hard cap so OFFSET cannot be used as an unbounded table scan. */
	public const MAX_OFFSET = 10000;

	public function __construct(
		private readonly IntervalCalculator $intervals,
	) {
	}

	// ── Generic helpers ─────────────────────────────────────────────────

	/**
	 * Trimmed string from a JSON body value; null when absent/null.
	 * Non-string scalars → 422 validation_failed.
	 */
	public function optionalString(array $body, string $field): ?string
	{
		if (!array_key_exists($field, $body) || $body[$field] === null) {
			return null;
		}
		if (!is_string($body[$field])) {
			throw new ValidationException('validation_failed', 'Invalid type.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		return trim($body[$field]);
	}

	public function requiredString(array $body, string $field, string $emptyCode, int $maxLen, string $tooLongCode): string
	{
		$value = $this->optionalString($body, $field) ?? '';
		if ($value === '') {
			throw new ValidationException('validation_failed', 'Required field missing.', [
				['field' => $field, 'code' => $emptyCode],
			]);
		}
		if (mb_strlen($value) > $maxLen) {
			throw new ValidationException('validation_failed', 'Value too long.', [
				['field' => $field, 'code' => $tooLongCode],
			]);
		}
		return $value;
	}

	public function boundedOptionalString(array $body, string $field, int $maxLen, string $tooLongCode): ?string
	{
		$value = $this->optionalString($body, $field);
		if ($value === null || $value === '') {
			return null;
		}
		if (mb_strlen($value) > $maxLen) {
			throw new ValidationException('validation_failed', 'Value too long.', [
				['field' => $field, 'code' => $tooLongCode],
			]);
		}
		return $value;
	}

	public function intOrThrow(array $body, string $field): int
	{
		$value = $body[$field] ?? null;
		if (!is_int($value)) {
			throw new ValidationException('validation_failed', 'Invalid type.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		return $value;
	}

	public function boolOrDefault(array $body, string $field, bool $default): bool
	{
		if (!array_key_exists($field, $body) || $body[$field] === null) {
			return $default;
		}
		if (!is_bool($body[$field])) {
			throw new ValidationException('validation_failed', 'Invalid type.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		return $body[$field];
	}

	// ── S7 pagination ───────────────────────────────────────────────────

	/**
	 * @return array{limit: int, offset: int}
	 */
	public function pagination(?string $limit, ?string $offset): array
	{
		$limitValue = 50;
		if ($limit !== null && $limit !== '') {
			if (!preg_match('/^\d+$/', $limit)) {
				throw new ValidationException('invalid_query', 'limit must be a non-negative integer.');
			}
			$limitValue = (int)$limit;
			if ($limitValue < 1 || $limitValue > 200) {
				throw new ValidationException('invalid_query', 'limit must be between 1 and 200.');
			}
		}
		$offsetValue = 0;
		if ($offset !== null && $offset !== '') {
			if (!preg_match('/^\d+$/', $offset)) {
				throw new ValidationException('invalid_query', 'offset must be a non-negative integer.');
			}
			$offsetValue = (int)$offset;
			if ($offsetValue > self::MAX_OFFSET) {
				throw new ValidationException('invalid_query', 'offset must be at most ' . self::MAX_OFFSET . '.');
			}
		}
		return ['limit' => $limitValue, 'offset' => $offsetValue];
	}

	/**
	 * S13: search term, 1–128 chars after trim; empty allowed (no filter).
	 */
	public function searchTerm(?string $q): string
	{
		$q = trim((string)$q);
		if (mb_strlen($q) > 128) {
			throw new ValidationException('invalid_query', 'Search term must be at most 128 characters.');
		}
		return $q;
	}

	// ── Dates ───────────────────────────────────────────────────────────

	/**
	 * S5: done_on — Y-m-d, real date, 2000-01-01 ≤ x ≤ today.
	 */
	public function doneOn(?string $value, string $today): string
	{
		if ($value === null || trim($value) === '') {
			return $today;
		}
		$value = trim($value);
		if (!$this->intervals->isValidYmd($value) || $value < self::TEN_YEARS_MIN_DATE || $value > $today) {
			throw new ValidationException('invalid_done_on', 'Completion date must be a real date between 2000-01-01 and today.');
		}
		return $value;
	}

	/**
	 * S14/S15: due date — Y-m-d, real date, 2000-01-01 ≤ x ≤ today + 10 years.
	 */
	public function dueOn(?string $value, string $today): string
	{
		$value = trim((string)$value);
		$max = $this->intervals->addInterval($today, IntervalCalculator::UNIT_YEAR, 10);
		if ($value === '' || !$this->intervals->isValidYmd($value) || $value < self::TEN_YEARS_MIN_DATE || $value > $max) {
			throw new ValidationException('invalid_due_date', 'Due date must be a real date between 2000-01-01 and ten years from today.');
		}
		return $value;
	}

	// ── Entity contracts (§4.1) ─────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> normalized customer fields
	 */
	public function customer(array $body): array
	{
		$name = $this->requiredString($body, 'name', 'name_required', 255, 'name_too_long');
		$customerNo = $this->boundedOptionalString($body, 'customerNo', 64, 'customer_no_too_long');
		$street = $this->boundedOptionalString($body, 'street', 255, 'street_too_long');
		$postalCode = $this->boundedOptionalString($body, 'postalCode', 32, 'postal_code_too_long');
		$city = $this->boundedOptionalString($body, 'city', 128, 'city_too_long');
		$phone = $this->boundedOptionalString($body, 'phone', 64, 'phone_too_long');
		$notes = $this->boundedOptionalString($body, 'notes', 10000, 'notes_too_long');

		$country = $this->optionalString($body, 'country');
		if ($country !== null && $country !== '') {
			$country = strtoupper($country);
			if (!preg_match('/^[A-Z]{2}$/', $country)) {
				throw new ValidationException('validation_failed', 'Country must be a two-letter ISO code.', [
					['field' => 'country', 'code' => 'invalid_country'],
				]);
			}
		} else {
			$country = null;
		}

		$email = $this->optionalString($body, 'email');
		if ($email !== null && $email !== '') {
			if (mb_strlen($email) > 255 || !preg_match('/^[^@\s]+@[^@\s]+$/', $email)) {
				throw new ValidationException('validation_failed', 'E-mail address looks invalid.', [
					['field' => 'email', 'code' => 'invalid_email'],
				]);
			}
		} else {
			$email = null;
		}

		return [
			'name' => $name,
			'customerNo' => $customerNo,
			'street' => $street,
			'postalCode' => $postalCode,
			'city' => $city,
			'country' => $country,
			'email' => $email,
			'phone' => $phone,
			'notes' => $notes,
			'active' => $this->boolOrDefault($body, 'active', true),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function equipment(array $body): array
	{
		return [
			'label' => $this->requiredString($body, 'label', 'label_required', 255, 'label_too_long'),
			'manufacturer' => $this->boundedOptionalString($body, 'manufacturer', 128, 'manufacturer_too_long'),
			'model' => $this->boundedOptionalString($body, 'model', 128, 'model_too_long'),
			'serialNo' => $this->boundedOptionalString($body, 'serialNo', 128, 'serial_no_too_long'),
			'locationText' => $this->boundedOptionalString($body, 'locationText', 512, 'location_too_long'),
			'notes' => $this->boundedOptionalString($body, 'notes', 10000, 'notes_too_long'),
			'active' => $this->boolOrDefault($body, 'active', true),
			'warrantyEnd' => $this->optionalYmd($body, 'warrantyEnd'),
			'equipmentClass' => $this->boundedOptionalString($body, 'equipmentClass', 64, 'equipment_class_too_long'),
		];
	}

	/**
	 * Optional calendar date `Y-m-d` or null when absent/empty.
	 *
	 * @param array<string, mixed> $body
	 */
	public function optionalYmd(array $body, string $field): ?string
	{
		if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
			return null;
		}
		if (!is_string($body[$field]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body[$field])) {
			throw new ValidationException('validation_failed', $field . ' must be a Y-m-d date.', [
				['field' => $field, 'code' => 'invalid_date'],
			]);
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $body[$field]);
		if ($dt === false || $dt->format('Y-m-d') !== $body[$field]) {
			throw new ValidationException('validation_failed', $field . ' must be a valid calendar date.', [
				['field' => $field, 'code' => 'invalid_date'],
			]);
		}
		return $body[$field];
	}

	/**
	 * W1 geo coordinate (DECIMAL 10,7). Returns the normalised string or
	 * null when absent/empty. Never passes through floats unmodified —
	 * the string form is what gets stored.
	 */
	public function coordinate(array $body, string $field, float $min, float $max): ?string
	{
		if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
			return null;
		}
		$raw = $body[$field];
		if (is_int($raw)) {
			$raw = (string)$raw;
		} elseif (is_float($raw)) {
			$raw = number_format($raw, 7, '.', '');
		}
		if (!is_string($raw)) {
			throw new ValidationException('validation_failed', 'Invalid coordinate.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		$raw = trim($raw);
		if (!preg_match('/^-?\d{1,3}(\.\d{1,7})?$/', $raw) || (float)$raw < $min || (float)$raw > $max) {
			throw new ValidationException('validation_failed', 'Coordinate out of range.', [
				['field' => $field, 'code' => 'invalid_value'],
			]);
		}
		return $raw;
	}

	/**
	 * Catalog code: required, 1–64, ^[a-z0-9_]+$.
	 */
	public function catalogCode(array $body): string
	{
		$code = $this->optionalString($body, 'code') ?? '';
		if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[a-z0-9_]+$/', $code)) {
			throw new ValidationException('invalid_code', 'Code must be 1–64 characters of a–z, 0–9, underscore.');
		}
		return $code;
	}

	public function catalogName(array $body): string
	{
		return $this->requiredString($body, 'name', 'name_required', 255, 'name_too_long');
	}

	/**
	 * Visit / plan notes bound (10 000 chars) and contract notes bound (512).
	 */
	public function visitNotes(array $body): ?string
	{
		return $this->boundedOptionalString($body, 'notes', 10000, 'notes_too_long');
	}

	public function contractNotes(array $body): ?string
	{
		return $this->boundedOptionalString($body, 'contractNotes', 512, 'notes_too_long');
	}
}
