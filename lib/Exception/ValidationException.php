<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Exception;

/**
 * Input validation failure (HTTP 422).
 *
 * `code` is either `validation_failed` (with per-field `details`)
 * or one of the specific single-cause codes from SPEC §7.2
 * (`invalid_interval`, `invalid_due_date`, `invalid_done_on`, …).
 */
class ValidationException extends \Exception
{
	/**
	 * @param list<array{field: string, code: string}> $details
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		private readonly array $details = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * @return list<array{field: string, code: string}>
	 */
	public function getDetails(): array
	{
		return $this->details;
	}
}
