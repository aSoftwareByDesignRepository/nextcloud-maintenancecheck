<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Support;

/**
 * Race workers print one token line (OK / CONFLICT:…). PHP warnings on
 * stdout (pcov vs JIT, etc.) must not steal that line.
 */
final class WorkerStdoutToken
{
	public static function first(string $stdout): string
	{
		foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, 'Warning:') || str_starts_with($line, 'PHP Warning:')) {
				continue;
			}
			return $line;
		}
		return '';
	}
}
