<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Exception;

/**
 * L3 role failure — user opened the app but lacks the role for this action.
 * Maps to HTTP 403 `permission_denied` (SPEC §7.2).
 */
class PermissionDeniedException extends \Exception
{
	public function __construct(string $message = 'permission_denied')
	{
		parent::__construct($message);
	}
}
