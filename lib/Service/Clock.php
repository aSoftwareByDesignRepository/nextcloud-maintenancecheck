<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * S1: "today" is the current calendar date in the PHP default timezone of the
 * Nextcloud server process. Single seam for all date/time reads so tests can
 * substitute a fixed clock.
 */
class Clock
{
	public function today(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
	}

	public function now(): int
	{
		return time();
	}
}
