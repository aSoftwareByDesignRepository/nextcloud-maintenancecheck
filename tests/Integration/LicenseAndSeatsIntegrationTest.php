<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\LicenseStateMapper;
use OCA\MaintenanceCheck\Db\MobileSeatMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Tests\Support\Mn2TestSigning;
use OCP\IUserManager;
use OCP\Server;

/**
 * Track L against the live database: apply/replace/remove MN2 keys,
 * named seats with §8.4 downgrade ranking, and the §9.1 mobile gate.
 *
 * Uses the deterministic test signing key via MN_VENDOR_PUBLIC_KEY_B64.
 *
 * @group integration
 */
final class LicenseAndSeatsIntegrationTest extends IntegrationTestCase
{
	private const ADMIN = 'mn_lic_admin';

	private LicenseService $license;
	private MobileGateService $gate;

	/** @var list<string> */
	private array $testUsers = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . Mn2TestSigning::publicKeyB64());
		$this->license = Server::get(LicenseService::class);
		$this->gate = Server::get(MobileGateService::class);
		$this->wipeLicenseTables();
	}

	protected function tearDown(): void
	{
		if (!class_exists(\OC::class)) {
			return;
		}
		$this->wipeLicenseTables();
		putenv('MN_VENDOR_PUBLIC_KEY_B64');
		$userManager = Server::get(IUserManager::class);
		foreach ($this->testUsers as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
		$this->testUsers = [];
	}

	private function wipeLicenseTables(): void
	{
		Server::get(LicenseStateMapper::class)->deleteAll();
		$seats = Server::get(MobileSeatMapper::class);
		foreach ($seats->findAllRanked() as $seat) {
			$seats->delete($seat);
		}
	}

	private function makeUser(string $prefix = 'mn_lic_u'): string
	{
		$uid = $prefix . bin2hex(random_bytes(4));
		Server::get(IUserManager::class)->createUser($uid, 'Mn-Lic-Pass-5!z' . bin2hex(random_bytes(4)));
		$this->testUsers[] = $uid;
		return $uid;
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function signedKey(array $overrides = []): string
	{
		return Mn2TestSigning::signPayload(array_merge([
			'v' => 2,
			'product' => 'maintenancecheck',
			'customerId' => 'integration-test',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 2,
		], $overrides));
	}

	// ── Key lifecycle ───────────────────────────────────────────────────

	public function testStatusWithoutKey(): void
	{
		$status = $this->license->status();
		$this->assertNull($status['state']);
		$this->assertSame(0, $status['seats']['limit']);
		$this->assertSame('coming_soon', $status['mobileAppStatus']);
	}

	public function testApplyValidKeyStoresSingleton(): void
	{
		$status = $this->license->apply(self::ADMIN, $this->signedKey());

		$this->assertSame('integration-test', $status['state']['customerId']);
		$this->assertSame(2, $status['state']['mobileSeats']);
		$this->assertTrue($status['state']['valid']);
		$this->assertSame(self::ADMIN, $status['state']['appliedBy']);
		$this->assertSame(2, $status['seats']['limit']);

		// Replacement stays a singleton (delete-then-insert).
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 5, 'customerId' => 'second-key']));
		$status = $this->license->status();
		$this->assertSame('second-key', $status['state']['customerId']);
		$this->assertSame(5, $status['seats']['limit']);
	}

	public function testApplyRejectsInvalidKeys(): void
	{
		foreach (['garbage', 'MN2.a.b', Mn2TestSigning::signPayload([
			'v' => 2,
			'product' => 'maintenancecheck',
			'customerId' => 'x-tampered',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 2,
		]) . 'X'] as $bad) {
			try {
				$this->license->apply(self::ADMIN, $bad);
				$this->fail('Invalid key must be rejected: ' . substr($bad, 0, 24));
			} catch (ValidationException $e) {
				$this->assertSame('license_invalid', $e->getErrorCode());
			}
		}
		$this->assertNull($this->license->status()['state'], 'no partial state after rejects');
	}

	public function testExpiredKeyIsAcceptedButReportedInvalid(): void
	{
		// S16: acceptance never blocks on dates; validity is gate-time.
		$status = $this->license->apply(self::ADMIN, $this->signedKey([
			'issuedAt' => '2020-01-01',
			'validUntil' => '2020-12-31',
		]));
		$this->assertFalse($status['state']['valid']);
	}

	public function testRemoveKeyRetainsSeats(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey());
		$uid = $this->makeUser();
		$this->license->assignSeat(self::ADMIN, $uid);

		$status = $this->license->remove();
		$this->assertNull($status['state']);
		$this->assertSame(1, $status['seats']['assigned'], 'seats survive key removal (SPEC §5.3)');
		$this->assertSame(0, $status['seats']['limit']);
	}

	// ── Seats ───────────────────────────────────────────────────────────

	public function testAssignSeatIsIdempotentAndEnforcesLimit(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 2]));
		$u1 = $this->makeUser();
		$u2 = $this->makeUser();
		$u3 = $this->makeUser();

		$row = $this->license->assignSeat(self::ADMIN, $u1);
		$this->assertSame($u1, $row['seat']['uid']);
		$this->assertTrue($row['created']);
		$this->assertTrue($row['seat']['withinLimit']);

		// Idempotent re-assign — no duplicate, no error, created=false (HTTP 200).
		$again = $this->license->assignSeat(self::ADMIN, $u1);
		$this->assertFalse($again['created']);
		$this->assertSame($u1, $again['seat']['uid']);
		$this->assertSame(1, $this->license->status()['seats']['assigned']);

		$this->license->assignSeat(self::ADMIN, $u2);
		try {
			$this->license->assignSeat(self::ADMIN, $u3);
			$this->fail('Third seat on a 2-seat license must conflict');
		} catch (ConflictException $e) {
			$this->assertSame('seat_limit_reached', $e->getErrorCode());
		}
	}

	public function testAssignSeatRejectsUnknownUser(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey());
		foreach (['mn_lic_ghost', '', null, 42] as $bad) {
			try {
				$this->license->assignSeat(self::ADMIN, $bad);
				$this->fail('Unknown/invalid user must be rejected');
			} catch (ValidationException $e) {
				$this->assertSame('unknown_user', $e->getErrorCode());
			}
		}
	}

	public function testDowngradeMarksNewestSeatsOverLimitWithoutDeleting(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 2]));
		$u1 = $this->makeUser();
		$u2 = $this->makeUser();
		$this->license->assignSeat(self::ADMIN, $u1);
		$this->license->assignSeat(self::ADMIN, $u2);

		// Downgrade 2 → 1: both stay listed; only the older seat is within limit.
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));
		$seats = $this->license->listSeats(null, null);
		$this->assertSame(2, $seats['total'], 'downgrade never auto-deletes seats');

		$byUid = array_column($seats['data'], null, 'uid');
		$this->assertTrue($byUid[$u1]['withinLimit'], 'older seat survives the downgrade');
		$this->assertFalse($byUid[$u2]['withinLimit'], 'newer seat is over the limit');
	}

	public function testRemoveSeat(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey());
		$uid = $this->makeUser();
		$this->license->assignSeat(self::ADMIN, $uid);
		$this->license->removeSeat($uid);
		$this->assertSame(0, $this->license->status()['seats']['assigned']);

		$this->expectException(NotFoundException::class);
		$this->license->removeSeat($uid);
	}

	public function testSeatListPagination(): void
	{
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 3]));
		for ($i = 0; $i < 3; $i++) {
			$this->license->assignSeat(self::ADMIN, $this->makeUser());
		}
		$page = $this->license->listSeats('2', '0');
		$this->assertSame(3, $page['total']);
		$this->assertCount(2, $page['data']);
		$page2 = $this->license->listSeats('2', '2');
		$this->assertCount(1, $page2['data']);
	}

	// ── Mobile gate ladder (SPEC §9.1) ──────────────────────────────────

	public function testGateLadderFailsRungByRung(): void
	{
		$uid = $this->makeUser();

		// Rung 3: no license at all.
		$this->assertGateFails($uid, 'license_missing');

		// Rung 4: expired license.
		$this->license->apply(self::ADMIN, $this->signedKey([
			'issuedAt' => '2020-01-01',
			'validUntil' => '2020-12-31',
		]));
		$this->assertGateFails($uid, 'license_expired');

		// Rung 5: valid license, no seat.
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));
		$this->assertGateFails($uid, 'seat_required');

		// All rungs pass with a seat.
		$this->license->assignSeat(self::ADMIN, $uid);
		$this->gate->assertGatePassed($uid);
		$this->addToAssertionCount(1);

		// Rung 6: downgrade pushes a newer seat over the limit.
		$u2 = $this->makeUser();
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 2]));
		$this->license->assignSeat(self::ADMIN, $u2);
		$this->license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));
		$this->assertGateFails($u2, 'seat_limit_exceeded');
		$this->gate->assertGatePassed($uid);
		$this->addToAssertionCount(1);
	}

	public function testBootstrapReportsWithoutGating(): void
	{
		$uid = $this->makeUser();
		$payload = $this->gate->bootstrapPayload($uid, 'Display Name', false);
		$this->assertNull($payload['licensing']);
		$this->assertFalse($payload['seatAssigned']);

		$this->license->apply(self::ADMIN, $this->signedKey());
		$this->license->assignSeat(self::ADMIN, $uid);
		$payload = $this->gate->bootstrapPayload($uid, 'Display Name', true);
		$this->assertSame('MN2', $payload['licensing']['format']);
		$this->assertTrue($payload['seatAssigned']);
		$this->assertTrue($payload['seatWithinLimit']);
	}

	public function testGateAcceptsValidUntilTodayAndRejectsYesterday(): void
	{
		$uid = $this->makeUser();
		$today = Server::get(\OCA\MaintenanceCheck\Service\Clock::class)->today();
		$yesterday = (new \DateTimeImmutable($today . ' 12:00:00 UTC'))
			->modify('-1 day')
			->format('Y-m-d');

		$this->license->apply(self::ADMIN, $this->signedKey([
			'issuedAt' => '2020-01-01',
			'validUntil' => $today,
			'mobileSeats' => 1,
		]));
		$this->license->assignSeat(self::ADMIN, $uid);
		$this->gate->assertGatePassed($uid);
		$this->assertTrue($this->license->status()['state']['valid']);

		$this->license->apply(self::ADMIN, $this->signedKey([
			'issuedAt' => '2020-01-01',
			'validUntil' => $yesterday,
			'mobileSeats' => 1,
		]));
		$this->assertGateFails($uid, 'license_expired');
		$this->assertFalse($this->license->status()['state']['valid']);
	}

	private function assertGateFails(string $uid, string $expectedCode): void
	{
		try {
			$this->gate->assertGatePassed($uid);
			$this->fail('Expected gate failure ' . $expectedCode);
		} catch (MobileGateException $e) {
			$this->assertSame($expectedCode, $e->getErrorCode());
		}
	}
}
