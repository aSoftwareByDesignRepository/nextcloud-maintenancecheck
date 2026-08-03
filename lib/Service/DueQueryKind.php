<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * Resolves due-board scope query params (CORE §21 / COMP §9.2).
 *
 * Primary: `kind=inspection|all`. Alias: `filter=inspection|all` when `kind`
 * is omitted — companions and clients that already use filter chips can share
 * one query shape without breaking older `kind=` callers.
 *
 * When both are present and non-empty, `kind` wins so explicit contracts stay
 * stable under accidental dual params.
 */
final class DueQueryKind
{
	public static function resolve(?string $kind, ?string $filter = null): ?string
	{
		$primary = self::normalize($kind);
		if ($primary !== null) {
			return $primary;
		}
		return self::normalize($filter);
	}

	private static function normalize(?string $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$trimmed = trim($raw);
		return $trimmed === '' ? null : $trimmed;
	}
}
