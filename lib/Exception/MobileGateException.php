<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Exception;

/**
 * Mobile gate ladder failure (SPEC §9.1) — HTTP 402 with one of:
 * `license_missing`, `license_expired`, `seat_required`, `seat_limit_exceeded`.
 */
class MobileGateException extends \Exception
{
	public function __construct(
		private readonly string $errorCode,
	) {
		parent::__construct($errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
