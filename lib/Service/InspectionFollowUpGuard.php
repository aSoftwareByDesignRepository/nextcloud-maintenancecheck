<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * Pure AC-W7-5 idempotency gate for auto-corrective follow-up.
 * Mutation target — keep free of I/O.
 */
final class InspectionFollowUpGuard
{
	/**
	 * @return 'reuse'|'create'
	 */
	public static function decide(?int $existingCorrectiveId): string
	{
		if ($existingCorrectiveId !== null && $existingCorrectiveId > 0) {
			return 'reuse';
		}
		return 'create';
	}
}
