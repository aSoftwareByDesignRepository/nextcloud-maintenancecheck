<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Exception;

/**
 * Unknown entity id (after access check). Maps to HTTP 404 `not_found`.
 */
class NotFoundException extends \Exception
{
	public function __construct(string $message = 'not_found')
	{
		parent::__construct($message);
	}
}
