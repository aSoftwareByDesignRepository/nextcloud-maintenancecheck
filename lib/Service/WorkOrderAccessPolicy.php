<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;

/**
 * CORE §7 execute scope: office may act on any WO; technicians may execute
 * only assigned WOs or the unassigned pool (null primary = pool).
 *
 * Kept free of WorkOrderService so checklist / kit / evidence can enforce
 * the same rule without a circular DI dependency.
 */
final class WorkOrderAccessPolicy
{
	public function __construct(
		private readonly AccessControlService $access,
	) {
	}

	public function canExecute(string $uid, WorkOrder $wo, ?bool $isOffice = null): bool
	{
		if ($isOffice ?? $this->access->isOffice($uid)) {
			return true;
		}
		return $wo->isAssigneeOrPool($uid);
	}

	public function assertCanExecute(string $uid, WorkOrder $wo, ?bool $isOffice = null): void
	{
		if (!$this->canExecute($uid, $wo, $isOffice)) {
			throw new PermissionDeniedException('You are not assigned to this work order.');
		}
	}
}
