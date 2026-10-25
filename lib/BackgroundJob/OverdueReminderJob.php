<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\BackgroundJob;

use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * W6 overdue reminder cron (CORE §20 W6-R7) — hourly, idempotent per day.
 */
class OverdueReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly OverdueReminderService $reminders,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		// Shared lock on the backup key: an admin running
		// `maintenancecheck:upgrade-backup restore` holds it exclusively —
		// the scan skips rather than writing mn_notif_log rows into tables
		// mid-restore (writes between truncate and insert would be lost).
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'MaintenanceCheck overdue reminder scan',
			);
		} catch (LockedException) {
			$this->logger->warning('MaintenanceCheck: skipping overdue reminder scan — upgrade backup/restore in progress.');
			return;
		}

		try {
			$this->reminders->run(false);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
