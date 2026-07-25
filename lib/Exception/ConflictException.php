<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Exception;

/**
 * State conflict (HTTP 409). `code` is the stable machine-readable
 * error code from the SPEC §7.2 catalog, e.g. `visit_not_open`.
 *
 * Optional `details` carry structured context for the UI (e.g. openVisitId
 * on `visit_already_open`) without changing the code catalog.
 */
class ConflictException extends \Exception
{
	/**
	 * @param array<string, mixed> $details
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
	 * @return array<string, mixed>
	 */
	public function getDetails(): array
	{
		return $this->details;
	}
}
