<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\ReferenceDatasetSeeder;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\Server;

/**
 * N4 smoke profile + I7 pagination cap via live services.
 *
 * @group integration
 */
final class ReferenceDatasetIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_n4_seed_office';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
	}

	protected function tearDown(): void
	{
		if (!class_exists(\OC::class)) {
			return;
		}
		Server::get(ReferenceDatasetSeeder::class)->purge();
	}

	public function testSmokeProfileMeetsMinimumCountsAndPurgesCleanly(): void
	{
		$seeder = Server::get(ReferenceDatasetSeeder::class);
		$seeder->purge();
		$result = $seeder->seed('smoke', self::UID);

		$expected = ReferenceDatasetSeeder::PROFILES['smoke'];
		$this->assertSame('smoke', $result['profile']);
		$this->assertSame($expected['customers'], $result['customers']);
		$this->assertSame($expected['equipment'], $result['equipment']);
		$this->assertSame($expected['plans'], $result['plans']);
		$this->assertSame($expected['visits'], $result['visits']);
		$this->assertSame($expected['plans'], $result['openVisits']);

		$due = Server::get(VisitService::class)->due(self::UID, false);
		$totalDue = $due['counts']['overdue'] + $due['counts']['today'] + $due['counts']['next7'] + $due['counts']['later'];
		$this->assertGreaterThan(0, $totalDue, 'smoke dataset must put visits on the due board');

		$deleted = $seeder->purge();
		$this->assertSame($expected['customers'], $deleted);
		$this->assertSame(0, $seeder->purge(), 'second purge is a no-op');
	}

	public function testI7Limit201RejectedByVisitList(): void
	{
		try {
			Server::get(VisitService::class)->list(self::UID, ['limit' => '201', 'offset' => '0']);
			$this->fail('limit=201 must 422');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_query', $e->getErrorCode());
		}
	}
}
