<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceCheck\Tests\Unit\BackgroundJob;

use OCA\MaintenanceCheck\BackgroundJob\OverdueReminderJob;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OverdueReminderJobTest extends TestCase
{
	private OverdueReminderService&MockObject $reminders;
	private ILockingProvider&MockObject $locking;
	private LoggerInterface&MockObject $logger;
	private OverdueReminderJob $job;

	protected function setUp(): void
	{
		$this->reminders = $this->createMock(OverdueReminderService::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new OverdueReminderJob(
			$this->createMock(ITimeFactory::class),
			$this->reminders,
			$this->locking,
			$this->logger,
		);
	}

	private function runJob(): void
	{
		$m = new \ReflectionMethod($this->job, 'run');
		$m->invoke($this->job, null);
	}

	public function testRunsRemindersUnderSharedLock(): void
	{
		$this->locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->anything());
		$this->reminders->expects($this->once())->method('run')->with(false);
		$this->runJob();
	}

	public function testSkipsScanWhileRestoreHoldsExclusiveLock(): void
	{
		// A restore holds the exclusive backup lock — the scan must skip,
		// not write notif_log rows into tables being truncated+reinserted.
		$this->locking->method('acquireLock')
			->willThrowException(new LockedException('restore running'));
		$this->reminders->expects($this->never())->method('run');
		$this->logger->expects($this->once())->method('warning');
		$this->runJob(); // must not throw
	}

	public function testReleasesSharedLockAfterRun(): void
	{
		$this->locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		$this->reminders->method('run')->willReturn(['visits' => 0, 'workOrders' => 0, 'skipped' => 0]);
		$this->runJob();
	}

	public function testReleasesLockEvenWhenRunThrows(): void
	{
		$this->reminders->method('run')->willThrowException(new \RuntimeException('db down'));
		$this->locking->expects($this->once())->method('releaseLock');
		$this->expectException(\RuntimeException::class);
		$this->runJob();
	}
}
