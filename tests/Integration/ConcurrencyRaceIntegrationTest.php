<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Tests\Support\WorkerStdoutToken;
use OCP\IDBConnection;
use OCP\Server;

/**
 * SPEC §14.2-I5 / AC-6 / N3: true parallel races via independent PHP processes
 * (fresh DB connections). Sequential tests are not enough — auditors require
 * two overlapping completes / schedules under InnoDB row locks.
 *
 * @group integration
 * @group concurrency
 */
final class ConcurrencyRaceIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_race_office';
	private const MARKER = 'mn_race_';

	private CustomerService $customers;
	private EquipmentService $equipment;
	private CatalogService $catalogs;
	private PlanService $plans;
	private VisitService $visits;
	private IDBConnection $db;

	/** @var list<int> */
	private array $customerIds = [];

	private int $equipTypeId;
	private int $maintTypeId;
	private string $today;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$this->customers = Server::get(CustomerService::class);
		$this->equipment = Server::get(EquipmentService::class);
		$this->catalogs = Server::get(CatalogService::class);
		$this->plans = Server::get(PlanService::class);
		$this->visits = Server::get(VisitService::class);
		$this->db = Server::get(IDBConnection::class);
		$this->today = Server::get(\OCA\MaintenanceCheck\Service\Clock::class)->today();
		$this->equipTypeId = $this->ensureCatalog('equip', self::MARKER . 'et');
		$this->maintTypeId = $this->ensureCatalog('maint', self::MARKER . 'mt');
	}

	protected function tearDown(): void
	{
		if (!class_exists(\OC::class)) {
			return;
		}
		foreach ($this->customerIds as $id) {
			try {
				$this->customers->delete($id, true);
			} catch (NotFoundException) {
			}
		}
		$this->customerIds = [];
		foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->like('code', $qb->createNamedParameter(self::MARKER . '%')));
			$qb->executeStatement();
		}
	}

	public function testAc6ParallelCompletesExactlyOneWinnerAndOneFollowUp(): void
	{
		$seed = $this->seedPlan();
		$visitId = (int)$seed['openVisit']['id'];
		$planId = (int)$seed['id'];

		[$a, $b] = $this->raceTwo([
			[(string)$visitId, self::UID],
			[(string)$visitId, self::UID],
		]);

		$tokens = [$a['token'], $b['token']];
		$this->assertContains('OK', $tokens, 'exactly one worker must win the complete');
		$conflicts = array_values(array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'CONFLICT:')));
		$this->assertCount(1, $conflicts, 'loser must report a conflict');
		$this->assertSame('CONFLICT:visit_not_open', $conflicts[0]);

		$open = Server::get(VisitMapper::class)->findOpenByPlan($planId);
		$this->assertNotNull(
			$open,
			'winner must schedule exactly one follow-up; tokens=' . implode(',', $tokens),
		);
		$list = $this->visits->list(self::UID, ['planId' => (string)$planId, 'status' => 'scheduled']);
		$this->assertSame(1, $list['total'], 'D6: never more than one open visit after parallel complete');
	}

	public function testI5ParallelScheduleCreatesSingleOpenVisit(): void
	{
		$seed = $this->seedPlan();
		$planId = (int)$seed['id'];
		$visitId = (int)$seed['openVisit']['id'];

		// Cancel the open visit so both workers race S14 schedule.
		$this->visits->cancel($visitId);
		$this->assertNull(Server::get(VisitMapper::class)->findOpenByPlan($planId));

		[$a, $b] = $this->raceTwo([
			['--schedule', (string)$planId, $this->today],
			['--schedule', (string)$planId, $this->today],
		]);

		$tokens = [$a['token'], $b['token']];
		$this->assertContains('OK', $tokens, 'exactly one schedule must win');
		$conflicts = array_values(array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'CONFLICT:')));
		$this->assertCount(1, $conflicts);
		$this->assertSame('CONFLICT:visit_already_open', $conflicts[0]);

		$list = $this->visits->list(self::UID, ['planId' => (string)$planId, 'status' => 'scheduled']);
		$this->assertSame(1, $list['total'], 'parallel schedule must leave a single open visit');
	}

	/**
	 * S9 × S6: force-delete racing a concurrent complete must not leave an
	 * orphan follow-up visit after the customer cascade commits.
	 */
	public function testForceDeleteVsCompleteLeavesNoOrphanVisits(): void
	{
		$seed = $this->seedPlan();
		$customerId = $this->customerIds[array_key_last($this->customerIds)];
		$visitId = (int)$seed['openVisit']['id'];
		$planId = (int)$seed['id'];

		[$a, $b] = $this->raceTwo([
			[(string)$visitId, self::UID],
			['--force-delete', (string)$customerId],
		]);

		$tokens = [$a['token'], $b['token']];
		$this->assertTrue(
			in_array('OK', $tokens, true),
			'at least one worker must finish cleanly; got ' . implode(',', $tokens),
		);

		// Customer must be gone (force-delete won) OR still present with a
		// consistent visit set (complete won first). Either way: zero orphans
		// referencing a deleted plan, and ≤ 1 open visit when the plan remains.
		$customerGone = false;
		try {
			$this->customers->get($customerId);
		} catch (NotFoundException) {
			$customerGone = true;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from(VisitMapper::TABLE)
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$visitsForPlan = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		if ($customerGone) {
			$this->assertSame(0, $visitsForPlan, 'force-delete must remove every visit of the plan');
			// Stop tearDown from trying to delete an already-removed customer.
			$this->customerIds = array_values(array_filter(
				$this->customerIds,
				static fn (int $id): bool => $id !== $customerId,
			));
		} else {
			$open = Server::get(VisitMapper::class)->findOpenByPlan($planId);
			$this->assertNotNull($open, 'if the customer survived, complete must have left exactly one open visit');
			$list = $this->visits->list(self::UID, ['planId' => (string)$planId, 'status' => 'scheduled']);
			$this->assertSame(1, $list['total']);
		}
	}

	/**
	 * AC-15: two parallel seat assigns at limit=1 → exactly one created, one conflict.
	 */
	public function testParallelSeatAssignRespectsLicenseLimit(): void
	{
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . \OCA\MaintenanceCheck\Tests\Support\Mn2TestSigning::publicKeyB64());
		$license = Server::get(\OCA\MaintenanceCheck\Service\LicenseService::class);
		$seats = Server::get(\OCA\MaintenanceCheck\Db\MobileSeatMapper::class);
		$states = Server::get(\OCA\MaintenanceCheck\Db\LicenseStateMapper::class);
		$states->deleteAll();
		foreach ($seats->findAllRanked() as $seat) {
			$seats->delete($seat);
		}

		$key = \OCA\MaintenanceCheck\Tests\Support\Mn2TestSigning::signPayload([
			'v' => 2,
			'product' => 'maintenancecheck',
			'customerId' => 'race-seats',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 1,
		]);
		$license->apply(self::UID, $key);

		$um = Server::get(\OCP\IUserManager::class);
		$u1 = 'mn_race_s1_' . bin2hex(random_bytes(3));
		$u2 = 'mn_race_s2_' . bin2hex(random_bytes(3));
		foreach ([$u1, $u2] as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
			$um->createUser($uid, 'Mn-Race-Seat-9xK!' . bin2hex(random_bytes(2)));
		}

		try {
			[$a, $b] = $this->raceSeatAssign([
				[self::UID, $u1],
				[self::UID, $u2],
			]);
			$tokens = [$a['token'], $b['token']];
			$created = array_values(array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'CREATED:')));
			$conflicts = array_values(array_filter($tokens, static fn (string $t): bool => $t === 'CONFLICT:seat_limit_reached'));
			$this->assertCount(1, $created, 'exactly one seat must be created under limit=1');
			$this->assertCount(1, $conflicts, 'loser must hit seat_limit_reached');
			$this->assertSame(1, $license->status()['seats']['assigned']);
		} finally {
			foreach ([$u1, $u2] as $uid) {
				if ($um->userExists($uid)) {
					$um->get($uid)?->delete();
				}
			}
			$states->deleteAll();
			foreach ($seats->findAllRanked() as $seat) {
				$seats->delete($seat);
			}
			putenv('MN_VENDOR_PUBLIC_KEY_B64');
		}
	}

	/**
	 * @param list<list<string>> $argSets
	 * @return list<array{token: string, code: int}>
	 */
	private function raceSeatAssign(array $argSets): array
	{
		$worker = __DIR__ . '/workers/seat-assign-worker.php';
		$this->assertFileExists($worker);

		$php = PHP_BINARY;
		$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$procs = [];
		$pipes = [];
		foreach ($argSets as $i => $args) {
			$cmd = array_merge([$php, $worker], $args);
			$env = array_merge($_ENV, $_SERVER, [
				'NEXTCLOUD_ROOT' => $root,
				'MN_VENDOR_PUBLIC_KEY_B64' => \OCA\MaintenanceCheck\Tests\Support\Mn2TestSigning::publicKeyB64(),
			]);
			$proc = proc_open($cmd, $descriptors, $pipeSet, null, $env);
			$this->assertIsResource($proc, 'failed to spawn seat worker ' . $i);
			$procs[$i] = $proc;
			$pipes[$i] = $pipeSet;
			fclose($pipeSet[0]);
		}
		usleep(50_000);

		$results = [];
		foreach ($procs as $i => $proc) {
			$stdout = stream_get_contents($pipes[$i][1]) ?: '';
			$stderr = stream_get_contents($pipes[$i][2]) ?: '';
			fclose($pipes[$i][1]);
			fclose($pipes[$i][2]);
			$code = proc_close($proc);
			$token = WorkerStdoutToken::first($stdout);
			if ($token === '') {
				$this->fail("Seat worker $i produced empty stdout (exit=$code stderr=$stderr)");
			}
			$results[] = ['token' => $token, 'code' => $code];
		}
		return $results;
	}

	/**
	 * AC-W1-4: concurrent dual-done → exactly one success.
	 */
	public function testAcW14ParallelWoDoneExactlyOneWinner(): void
	{
		$workOrders = Server::get(\OCA\MaintenanceCheck\Service\WorkOrderService::class);
		$seed = $this->seedPlan();
		$visitId = (int)$seed['openVisit']['id'];
		Server::get(\OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder::class)->ensureInstalled();
		$wo = $workOrders->createFromVisit(self::UID, $visitId, [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Race test skips checklist template',
		]);
		$woId = (int)$wo['id'];
		$workOrders->transition(self::UID, $woId, ['to' => 'ready'], true);
		$workOrders->transition(self::UID, $woId, ['to' => 'in_progress'], true);

		[$a, $b] = $this->raceWoDone([
			[(string)$woId, self::UID],
			[(string)$woId, self::UID],
		]);
		$tokens = [$a['token'], $b['token']];
		$this->assertContains('OK', $tokens, 'exactly one worker must win done');
		$conflicts = array_values(array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'CONFLICT:')));
		$this->assertCount(1, $conflicts, 'loser must report a conflict; tokens=' . implode(',', $tokens));
		$this->assertSame('CONFLICT:invalid_status', $conflicts[0]);
		$detail = $workOrders->get($woId);
		$this->assertSame('done', $detail['status']);
	}

	/**
	 * W4 capacity TOCTOU: two concurrent assigns to the same tech under
	 * capacity_enforcement=block must yield exactly one OK and one
	 * CONFLICT:capacity_exceeded (FOR UPDATE on mn_user_capacity).
	 */
	public function testW4ParallelCapacityAssignExactlyOneWinner(): void
	{
		$workOrders = Server::get(\OCA\MaintenanceCheck\Service\WorkOrderService::class);
		$policies = Server::get(\OCA\MaintenanceCheck\Service\PolicyService::class);
		$capacity = Server::get(\OCA\MaintenanceCheck\Service\CapacityService::class);
		$userManager = Server::get(\OCP\IUserManager::class);
		$previous = $policies->snapshot();

		$tech = 'mn_race_cap_' . bin2hex(random_bytes(3));
		if ($userManager->userExists($tech)) {
			$userManager->get($tech)?->delete();
		}
		$userManager->createUser($tech, 'Mn-Race-Cap-9xK!' . bin2hex(random_bytes(2)));

		try {
			$policies->save([
				'capacityEnforcement' => \OCA\MaintenanceCheck\Service\PolicyService::ENFORCEMENT_BLOCK,
			]);
			$capacity->set(self::UID, $tech, ['dailyMinutes' => 60]);

			$seedA = $this->seedPlan();
			$seedB = $this->seedPlan();
			Server::get(\OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder::class)->ensureInstalled();

			$woA = $workOrders->createFromVisit(self::UID, (int)$seedA['openVisit']['id'], [
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Capacity race A',
				'estimatedMinutes' => 60,
			]);
			$woB = $workOrders->createFromVisit(self::UID, (int)$seedB['openVisit']['id'], [
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Capacity race B',
				'estimatedMinutes' => 60,
			]);
			// Ensure due_on is today so both land on the same capacity day.
			$workOrders->update(self::UID, (int)$woA['id'], ['dueOn' => $this->today]);
			$workOrders->update(self::UID, (int)$woB['id'], ['dueOn' => $this->today]);

			[$a, $b] = $this->raceCapacityAssign([
				[(string)$woA['id'], self::UID, $tech],
				[(string)$woB['id'], self::UID, $tech],
			]);
			$tokens = [$a['token'], $b['token']];
			$ok = array_values(array_filter($tokens, static fn (string $t): bool => $t === 'OK'));
			$conflicts = array_values(array_filter(
				$tokens,
				static fn (string $t): bool => $t === 'CONFLICT:capacity_exceeded',
			));
			$this->assertCount(1, $ok, 'exactly one assign must succeed; tokens=' . implode(',', $tokens));
			$this->assertCount(1, $conflicts, 'loser must hit capacity_exceeded; tokens=' . implode(',', $tokens));

			$load = Server::get(\OCA\MaintenanceCheck\Db\WorkOrderMapper::class)
				->loadMinutesFor($tech, $this->today, null);
			$this->assertSame(60, $load, 'winner alone must consume the full 60-minute capacity');
		} finally {
			$policies->save([
				'capacityEnforcement' => $previous['capacityEnforcement'],
			]);
			if ($userManager->userExists($tech)) {
				$userManager->get($tech)?->delete();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('mn_user_capacity')->where($qb->expr()->eq('uid', $qb->createNamedParameter($tech)));
			$qb->executeStatement();
		}
	}

	/**
	 * @param list<list<string>> $argSets
	 * @return list<array{token: string, code: int}>
	 */
	private function raceCapacityAssign(array $argSets): array
	{
		$worker = __DIR__ . '/workers/capacity-assign-worker.php';
		$this->assertFileExists($worker);

		$php = PHP_BINARY;
		$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$procs = [];
		$pipes = [];
		foreach ($argSets as $i => $args) {
			$cmd = array_merge([$php, $worker], $args);
			$env = array_merge($_ENV, $_SERVER, [
				'NEXTCLOUD_ROOT' => $root,
			]);
			$proc = proc_open($cmd, $descriptors, $pipeSet, null, $env);
			$this->assertIsResource($proc, 'failed to spawn capacity-assign worker ' . $i);
			$procs[$i] = $proc;
			$pipes[$i] = $pipeSet;
			fclose($pipeSet[0]);
		}
		usleep(50_000);

		$results = [];
		foreach ($procs as $i => $proc) {
			$stdout = stream_get_contents($pipes[$i][1]) ?: '';
			$stderr = stream_get_contents($pipes[$i][2]) ?: '';
			fclose($pipes[$i][1]);
			fclose($pipes[$i][2]);
			$code = proc_close($proc);
			$token = WorkerStdoutToken::first($stdout);
			if ($token === '') {
				$this->fail("Capacity assign worker $i produced empty stdout (exit=$code stderr=$stderr)");
			}
			$results[] = ['token' => $token, 'code' => $code];
		}
		return $results;
	}

	/**
	 * @param list<list<string>> $argSets
	 * @return list<array{token: string, code: int}>
	 */
	private function raceWoDone(array $argSets): array
	{
		$worker = __DIR__ . '/workers/wo-done-worker.php';
		$this->assertFileExists($worker);

		$php = PHP_BINARY;
		$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$procs = [];
		$pipes = [];
		foreach ($argSets as $i => $args) {
			$cmd = array_merge([$php, $worker], $args);
			$env = array_merge($_ENV, $_SERVER, [
				'NEXTCLOUD_ROOT' => $root,
			]);
			$proc = proc_open($cmd, $descriptors, $pipeSet, null, $env);
			$this->assertIsResource($proc, 'failed to spawn wo-done worker ' . $i);
			$procs[$i] = $proc;
			$pipes[$i] = $pipeSet;
			fclose($pipeSet[0]);
		}
		usleep(50_000);

		$results = [];
		foreach ($procs as $i => $proc) {
			$stdout = stream_get_contents($pipes[$i][1]) ?: '';
			$stderr = stream_get_contents($pipes[$i][2]) ?: '';
			fclose($pipes[$i][1]);
			fclose($pipes[$i][2]);
			$code = proc_close($proc);
			$token = WorkerStdoutToken::first($stdout);
			if ($token === '') {
				$this->fail("WO done worker $i produced empty stdout (exit=$code stderr=$stderr)");
			}
			$results[] = ['token' => $token, 'code' => $code];
		}
		return $results;
	}

	/**
	 * @param list<list<string>> $argSets
	 * @return list<array{token: string, code: int}>
	 */
	private function raceTwo(array $argSets): array
	{
		$worker = __DIR__ . '/workers/race-worker.php';
		$this->assertFileExists($worker);

		$php = PHP_BINARY;
		$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$procs = [];
		$pipes = [];
		foreach ($argSets as $i => $args) {
			$cmd = array_merge(
				[$php, $worker],
				$args,
			);
			$env = array_merge($_ENV, $_SERVER, [
				'NEXTCLOUD_ROOT' => $root,
			]);
			$proc = proc_open($cmd, $descriptors, $pipeSet, null, $env);
			$this->assertIsResource($proc, 'failed to spawn worker ' . $i);
			$procs[$i] = $proc;
			$pipes[$i] = $pipeSet;
			fclose($pipeSet[0]);
		}

		// Tiny stagger so both are overlapping under InnoDB, not purely sequential.
		usleep(50_000);

		$results = [];
		foreach ($procs as $i => $proc) {
			$stdout = stream_get_contents($pipes[$i][1]) ?: '';
			$stderr = stream_get_contents($pipes[$i][2]) ?: '';
			fclose($pipes[$i][1]);
			fclose($pipes[$i][2]);
			$code = proc_close($proc);
			$token = WorkerStdoutToken::first($stdout);
			if ($token === '') {
				$this->fail("Worker $i produced empty stdout (exit=$code stderr=$stderr)");
			}
			$results[] = ['token' => $token, 'code' => $code];
		}
		return $results;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seedPlan(): array
	{
		$customer = $this->customers->create(self::UID, [
			'name' => self::MARKER . 'Race ' . bin2hex(random_bytes(3)),
		]);
		$this->customerIds[] = (int)$customer['id'];
		$equipment = $this->equipment->create(self::UID, [
			'label' => self::MARKER . 'Unit',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => $this->equipTypeId,
		]);
		return $this->plans->create(self::UID, (int)$equipment['id'], [
			'maintTypeId' => $this->maintTypeId,
			'intervalUnit' => 'month',
			'intervalCount' => 1,
			'firstDueOn' => $this->today,
		]);
	}

	private function ensureCatalog(string $kind, string $code): int
	{
		try {
			$row = $this->catalogs->create($kind, ['code' => $code, 'name' => 'Race ' . $code]);
		} catch (ConflictException) {
			foreach ($this->catalogs->list($kind, '200', '0')['data'] as $entry) {
				if ($entry['code'] === $code) {
					return (int)$entry['id'];
				}
			}
			$this->fail('Catalog vanished: ' . $code);
		}
		return (int)$row['id'];
	}
}
