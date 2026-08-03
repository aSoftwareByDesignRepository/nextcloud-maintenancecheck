<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\BackgroundJob;

use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * W6 overdue reminder cron (CORE §20 W6-R7) — hourly, idempotent per day.
 */
class OverdueReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly OverdueReminderService $reminders,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		$this->reminders->run(false);
	}
}
