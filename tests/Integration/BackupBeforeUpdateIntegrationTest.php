<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Repair\BackupBeforeUpdate;
use OCP\Migration\IOutput;
use Test\TestCase;

final class BackupBeforeUpdateIntegrationTest extends TestCase
{
	public function testPreMigrationRepairStepRunsInContainer(): void
	{
		/** @var BackupBeforeUpdate $step */
		$step = \OC::$server->get(BackupBeforeUpdate::class);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::atLeastOnce())->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}
}
