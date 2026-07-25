<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Full domain lifecycle against the live database (SPEC §5, §6):
 * customer → equipment → plan → visit transitions, D6 invariant,
 * cascade rules S9/S10, catalog rules S11, recovery S14, edits S15/S3/S18.
 *
 * @group integration
 */
class MaintenanceLifecycleIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_itest_office';
	private const MARKER = 'mn_itest_';

	private CustomerService $customers;
	private EquipmentService $equipment;
	private CatalogService $catalogs;
	private PlanService $plans;
	private VisitService $visits;
	private IntervalCalculator $intervals;
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
		$this->intervals = Server::get(IntervalCalculator::class);
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
				// already gone
			}
		}
		$this->customerIds = [];
		foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->like('code', $qb->createNamedParameter(self::MARKER . '%')));
			$qb->executeStatement();
		}
	}

	private function ensureCatalog(string $kind, string $code): int
	{
		try {
			$row = $this->catalogs->create($kind, ['code' => $code, 'name' => 'ITest ' . $code]);
		} catch (ConflictException) {
			foreach ($this->catalogs->list($kind, '200', '0')['data'] as $entry) {
				if ($entry['code'] === $code) {
					return (int)$entry['id'];
				}
			}
			$this->fail('Catalog entry vanished: ' . $code);
		}
		return (int)$row['id'];
	}

	/**
	 * @return array{customer: array<string, mixed>, equipment: array<string, mixed>}
	 */
	private function seedCustomerWithEquipment(): array
	{
		$customer = $this->customers->create(self::UID, [
			'name' => self::MARKER . 'ACME ' . bin2hex(random_bytes(4)),
			'city' => 'Stuttgart',
			'country' => 'de',
		]);
		$this->customerIds[] = (int)$customer['id'];

		$equipment = $this->equipment->create(self::UID, [
			'label' => self::MARKER . 'Heat pump',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => $this->equipTypeId,
			'serialNo' => 'SN-' . bin2hex(random_bytes(3)),
		]);
		return ['customer' => $customer, 'equipment' => $equipment];
	}

	/**
	 * @return array<string, mixed> plan with openVisit
	 */
	private function seedPlan(int $equipmentId, string $unit = 'month', int $count = 3, ?string $firstDueOn = null): array
	{
		return $this->plans->create(self::UID, $equipmentId, [
			'maintTypeId' => $this->maintTypeId,
			'intervalUnit' => $unit,
			'intervalCount' => $count,
			'firstDueOn' => $firstDueOn ?? $this->today,
		]);
	}

	// ── AC-4: plan creation schedules exactly one visit ─────────────────

	public function testPlanCreationCreatesExactlyOneScheduledVisit(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);

		$this->assertNotNull($plan['openVisit']);
		$this->assertSame('scheduled', $plan['openVisit']['status']);
		$this->assertSame($this->today, $plan['openVisit']['dueOn']);

		$list = $this->visits->list(self::UID, ['planId' => (string)$plan['id']]);
		$this->assertSame(1, $list['total']);
	}

	// ── D5: complete rolls next due from done_on ────────────────────────

	public function testCompleteClosesVisitAndSchedulesNext(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id'], 'month', 3);
		$visitId = (int)$plan['openVisit']['id'];

		$result = $this->visits->complete(self::UID, $visitId, ['notes' => 'Filter replaced']);

		$this->assertSame('done', $result['visit']['status']);
		$this->assertSame($this->today, $result['visit']['doneOn']);
		$this->assertSame(self::UID, $result['visit']['doneBy']);
		$this->assertSame('Filter replaced', $result['visit']['notes']);

		$this->assertNotNull($result['nextVisit'], 'active plan must get a follow-up visit');
		$this->assertSame('scheduled', $result['nextVisit']['status']);
		$this->assertSame(
			$this->intervals->addInterval($this->today, 'month', 3),
			$result['nextVisit']['dueOn'],
			'next due must roll from done_on by the plan interval',
		);
	}

	// ── S4: skip rolls from today ───────────────────────────────────────

	public function testSkipRollsNextDueFromToday(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		// Overdue visit: first due far in the past.
		$plan = $this->seedPlan((int)$seed['equipment']['id'], 'week', 2, '2020-01-01');
		$visitId = (int)$plan['openVisit']['id'];

		$result = $this->visits->skip(self::UID, $visitId, []);

		$this->assertSame('skipped', $result['visit']['status']);
		$this->assertSame(
			$this->intervals->addInterval($this->today, 'week', 2),
			$result['nextVisit']['dueOn'],
			'skip must clear the backlog by rolling from today, not from the old due date',
		);
	}

	// ── Cancel: terminal without follow-up ──────────────────────────────

	public function testCancelIsTerminalWithoutFollowUp(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$visitId = (int)$plan['openVisit']['id'];

		$result = $this->visits->cancel($visitId);
		$this->assertSame('cancelled', $result['visit']['status']);
		$this->assertNull($result['nextVisit']);

		$open = Server::get(VisitMapper::class)->findOpenByPlan((int)$plan['id']);
		$this->assertNull($open, 'cancel must not create a follow-up visit');
	}

	// ── D6 invariant + S6 race: double close resolves to one winner ─────

	public function testDoubleCloseSecondCallerGetsConflict(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$visitId = (int)$plan['openVisit']['id'];

		$this->visits->complete(self::UID, $visitId, []);

		try {
			$this->visits->skip(self::UID, $visitId, []);
			$this->fail('Second terminal transition must fail');
		} catch (ConflictException $e) {
			$this->assertSame('visit_not_open', $e->getErrorCode());
		}

		// D6: still exactly one open visit for the plan.
		$list = $this->visits->list(self::UID, ['planId' => (string)$plan['id'], 'status' => 'scheduled']);
		$this->assertSame(1, $list['total']);
	}

	public function testConditionalCloseIsAtomicAtTheMapperLevel(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$visitId = (int)$plan['openVisit']['id'];

		$mapper = Server::get(VisitMapper::class);
		$set = ['status' => 'cancelled', 'updated_at' => time()];
		$this->assertTrue($mapper->closeScheduled($visitId, $set), 'first conditional close wins');
		$this->assertFalse($mapper->closeScheduled($visitId, $set), 'second conditional close must be a no-op');
	}

	// ── S18: inactive plan gets no follow-up ────────────────────────────

	public function testCompleteOnInactivePlanCreatesNoFollowUp(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$this->plans->deactivate((int)$plan['id']);

		$result = $this->visits->complete(self::UID, (int)$plan['openVisit']['id'], []);
		$this->assertSame('done', $result['visit']['status']);
		$this->assertNull($result['nextVisit']);
		$this->assertFalse($result['planActive']);
	}

	// ── S14: manual schedule (recovery) + guard rails ───────────────────

	public function testManualScheduleAfterCancelRecoversThePlan(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$this->visits->cancel((int)$plan['openVisit']['id']);

		$dueOn = $this->intervals->addInterval($this->today, 'day', 14);
		$visit = $this->plans->schedule((int)$plan['id'], ['dueOn' => $dueOn]);
		$this->assertSame('scheduled', $visit['status']);
		$this->assertSame($dueOn, $visit['dueOn']);
	}

	public function testManualScheduleRejectsSecondOpenVisit(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);

		try {
			$this->plans->schedule((int)$plan['id'], ['dueOn' => $this->today]);
			$this->fail('Open visit already exists — schedule must conflict');
		} catch (ConflictException $e) {
			$this->assertSame('visit_already_open', $e->getErrorCode());
		}
	}

	public function testManualScheduleRejectsInactivePlan(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$this->visits->cancel((int)$plan['openVisit']['id']);
		$this->plans->deactivate((int)$plan['id']);

		try {
			$this->plans->schedule((int)$plan['id'], ['dueOn' => $this->today]);
			$this->fail('Inactive plan must not be schedulable');
		} catch (ValidationException $e) {
			$this->assertSame('plan_inactive', $e->getErrorCode());
		}
	}

	// ── S15: reschedule only while open ─────────────────────────────────

	public function testRescheduleOpenVisitAndRejectClosed(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$visitId = (int)$plan['openVisit']['id'];

		$newDue = $this->intervals->addInterval($this->today, 'day', 5);
		$updated = $this->visits->reschedule($visitId, ['dueOn' => $newDue, 'notes' => 'moved']);
		$this->assertSame($newDue, $updated['dueOn']);
		$this->assertSame('moved', $updated['notes']);

		$this->visits->complete(self::UID, $visitId, []);
		try {
			$this->visits->reschedule($visitId, ['dueOn' => $newDue]);
			$this->fail('Closed visit must not be reschedulable');
		} catch (ConflictException $e) {
			$this->assertSame('visit_not_open', $e->getErrorCode());
		}
	}

	// ── S3: interval change with optional recalculation ─────────────────

	public function testPlanUpdateRecalculatesOpenVisitOnlyWhenRequested(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id'], 'month', 3);
		$originalDue = $plan['openVisit']['dueOn'];

		$updated = $this->plans->update((int)$plan['id'], ['intervalCount' => 6]);
		$this->assertSame(6, $updated['intervalCount']);
		$this->assertSame($originalDue, $updated['openVisit']['dueOn'], 'without recalculate the open visit is untouched');

		$updated = $this->plans->update((int)$plan['id'], ['intervalCount' => 6, 'recalculateOpenVisit' => true]);
		$this->assertSame(
			$this->intervals->addInterval($this->today, 'month', 6),
			$updated['openVisit']['dueOn'],
			'recalculate moves due_on to today + new interval',
		);
	}

	// ── Due board S8 ────────────────────────────────────────────────────

	public function testDueBoardBucketsAndMineFilter(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$overduePlan = $this->seedPlan((int)$seed['equipment']['id'], 'month', 1, '2020-06-15');
		$todayPlan = $this->seedPlan((int)$seed['equipment']['id'], 'week', 1, $this->today);
		$next7Plan = $this->seedPlan(
			(int)$seed['equipment']['id'],
			'week',
			1,
			$this->intervals->addInterval($this->today, 'day', 7),
		);
		$laterPlan = $this->seedPlan(
			(int)$seed['equipment']['id'],
			'week',
			1,
			$this->intervals->addInterval($this->today, 'day', 8),
		);
		$horizonPlan = $this->seedPlan(
			(int)$seed['equipment']['id'],
			'week',
			1,
			$this->intervals->addInterval($this->today, 'day', 30),
		);
		$beyondPlan = $this->seedPlan(
			(int)$seed['equipment']['id'],
			'week',
			1,
			$this->intervals->addInterval($this->today, 'day', 31),
		);

		$board = $this->visits->due(self::UID, false);
		$this->assertSame($this->today, $board['today_date']);

		$overdueIds = array_column($board['overdue'], 'id');
		$todayIds = array_column($board['today'], 'id');
		$next7Ids = array_column($board['next7'], 'id');
		$laterIds = array_column($board['later'], 'id');
		$this->assertContains((int)$overduePlan['openVisit']['id'], $overdueIds);
		$this->assertContains((int)$todayPlan['openVisit']['id'], $todayIds);
		$this->assertContains((int)$next7Plan['openVisit']['id'], $next7Ids);
		$this->assertContains((int)$laterPlan['openVisit']['id'], $laterIds);
		$this->assertContains((int)$horizonPlan['openVisit']['id'], $laterIds);
		$this->assertNotContains((int)$beyondPlan['openVisit']['id'], $laterIds);
		$this->assertNotContains((int)$beyondPlan['openVisit']['id'], $next7Ids);
		$this->assertSame(count($board['overdue']), $board['counts']['overdue']);

		// "Mine" keeps unassigned visits and those assigned to me,
		// hides visits assigned to someone else.
		$otherUid = $this->createTestUser();
		try {
			$this->visits->assign((int)$todayPlan['openVisit']['id'], ['userId' => $otherUid]);
			$mine = $this->visits->due(self::UID, true);
			$this->assertNotContains((int)$todayPlan['openVisit']['id'], array_column($mine['today'], 'id'));
			$this->assertContains((int)$overduePlan['openVisit']['id'], array_column($mine['overdue'], 'id'));
		} finally {
			$this->deleteTestUser($otherUid);
		}
	}

	// ── S12 assign ──────────────────────────────────────────────────────

	public function testAssignRequiresExistingUserAndNullClears(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$plan = $this->seedPlan((int)$seed['equipment']['id']);
		$visitId = (int)$plan['openVisit']['id'];

		try {
			$this->visits->assign($visitId, ['userId' => 'mn_itest_ghost_user']);
			$this->fail('Unknown user must be rejected');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_user', $e->getErrorCode());
		}

		$uid = $this->createTestUser();
		try {
			$assigned = $this->visits->assign($visitId, ['userId' => $uid]);
			$this->assertSame($uid, $assigned['assignedUid']);

			$cleared = $this->visits->assign($visitId, ['userId' => null]);
			$this->assertNull($cleared['assignedUid']);
		} finally {
			$this->deleteTestUser($uid);
		}
	}

	// ── S9 / S10 delete rules ───────────────────────────────────────────

	public function testCustomerDeleteRequiresForceWhenEquipmentExists(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$customerId = (int)$seed['customer']['id'];
		$this->seedPlan((int)$seed['equipment']['id']);

		try {
			$this->customers->delete($customerId, false);
			$this->fail('Customer with equipment must not delete without force');
		} catch (ConflictException $e) {
			$this->assertSame('customer_has_equipment', $e->getErrorCode());
		}

		$result = $this->customers->delete($customerId, true);
		$this->assertTrue($result['deleted']);
		$this->assertSame(1, $result['counts']['equipment']);
		$this->assertSame(1, $result['counts']['plans']);
		$this->assertGreaterThanOrEqual(1, $result['counts']['visits']);

		try {
			$this->customers->get($customerId);
			$this->fail('Customer must be gone after cascade');
		} catch (NotFoundException) {
			$this->addToAssertionCount(1);
		}
		$this->assertSame(0, $this->visits->list(self::UID, ['customerId' => (string)$customerId])['total']);
	}

	public function testEquipmentDeleteBlockedWhileInUse(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$equipmentId = (int)$seed['equipment']['id'];
		$this->seedPlan($equipmentId);

		try {
			$this->equipment->delete($equipmentId);
			$this->fail('Equipment with plans must not be deletable');
		} catch (ConflictException $e) {
			$this->assertSame('equipment_in_use', $e->getErrorCode());
		}
	}

	public function testUnusedEquipmentCanBeDeleted(): void
	{
		$seed = $this->seedCustomerWithEquipment();
		$this->equipment->delete((int)$seed['equipment']['id']);
		try {
			$this->equipment->get((int)$seed['equipment']['id']);
			$this->fail('Deleted equipment must be gone');
		} catch (NotFoundException) {
			$this->addToAssertionCount(1);
		}
	}

	// ── S11 catalog rules ───────────────────────────────────────────────

	public function testCatalogCodeIsUniqueAndInactiveTypeRejected(): void
	{
		try {
			$this->catalogs->create('maint', ['code' => self::MARKER . 'mt', 'name' => 'Duplicate']);
			$this->fail('Duplicate catalog code must conflict');
		} catch (ConflictException $e) {
			$this->assertSame('code_exists', $e->getErrorCode());
		}

		$seed = $this->seedCustomerWithEquipment();
		$this->catalogs->update('maint', $this->maintTypeId, ['active' => false]);
		try {
			$this->seedPlan((int)$seed['equipment']['id']);
			$this->fail('Plan with inactive maintenance type must be rejected');
		} catch (ValidationException $e) {
			$this->assertSame('inactive_maint_type', $e->getErrorCode());
		} finally {
			$this->catalogs->update('maint', $this->maintTypeId, ['active' => true]);
		}
	}

	// ── S7 pagination envelope ──────────────────────────────────────────

	public function testCustomerListPaginationEnvelopeAndSearch(): void
	{
		$marker = self::MARKER . 'page_' . bin2hex(random_bytes(3));
		for ($i = 1; $i <= 3; $i++) {
			$c = $this->customers->create(self::UID, ['name' => $marker . ' nr ' . $i]);
			$this->customerIds[] = (int)$c['id'];
		}

		$page = $this->customers->list($marker, '2', '0');
		$this->assertSame(3, $page['total']);
		$this->assertCount(2, $page['data']);
		$this->assertSame(2, $page['limit']);
		$this->assertSame(0, $page['offset']);

		$page2 = $this->customers->list($marker, '2', '2');
		$this->assertCount(1, $page2['data']);
		$this->assertSame(3, $page2['total']);
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	private function createTestUser(): string
	{
		$uid = 'mn_itest_u' . bin2hex(random_bytes(4));
		$userManager = Server::get(\OCP\IUserManager::class);
		$userManager->createUser($uid, 'Mn-Itest-Pass-7!x' . bin2hex(random_bytes(4)));
		return $uid;
	}

	private function deleteTestUser(string $uid): void
	{
		$userManager = Server::get(\OCP\IUserManager::class);
		if ($userManager->userExists($uid)) {
			$userManager->get($uid)?->delete();
		}
	}
}
