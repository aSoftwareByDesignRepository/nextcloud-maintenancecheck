<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCP\IDBConnection;
use OCP\Server;
use Test\TestCase;

/**
 * Shared integration base — aborts any dangling DB transaction left by a
 * prior test (or a crashed worker) so MariaDB REPEATABLE READ cannot hide
 * commits from sibling PHP processes (AC-6 / I5 flake class).
 */
abstract class IntegrationTestCase extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->abortDanglingTransaction();
	}

	protected function tearDown(): void
	{
		$this->abortDanglingTransaction();
		parent::tearDown();
	}

	protected function abortDanglingTransaction(): void
	{
		if (!class_exists(\OC::class)) {
			return;
		}
		try {
			$db = Server::get(IDBConnection::class);
		} catch (\Throwable) {
			return;
		}
		while ($db->inTransaction()) {
			$db->rollBack();
		}
	}
}
