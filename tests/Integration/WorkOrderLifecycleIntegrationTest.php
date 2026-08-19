<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\TourService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;

/**
 * Critical W1–W3 acceptance paths against the live DB.
 *
 * @group integration
 */
class WorkOrderLifecycleIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_wo_itest';
	private const MARKER = 'mn_wo_';

	private CustomerService $customers;
	private EquipmentService $equipment;
	private CatalogService $catalogs;
	private PlanService $plans;
	private WorkOrderService $workOrders;
	private ProcedureService $procedures;
	private KitService $kits;
	private TourService $tours;
	private WoPdfService $pdf;
	private IDBConnection $db;
	private string $today;

	/** @var list<int> */
	private array $customerIds = [];

	/** @var list<string> */
	private array $tempUsers = [];

	private int $equipTypeId;
	private int $maintTypeId;
	private int $procedureId;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		parent::setUp();
		$this->customers = Server::get(CustomerService::class);
		$this->equipment = Server::get(EquipmentService::class);
		$this->catalogs = Server::get(CatalogService::class);
		$this->plans = Server::get(PlanService::class);
		$this->workOrders = Server::get(WorkOrderService::class);
		$this->procedures = Server::get(ProcedureService::class);
		$this->kits = Server::get(KitService::class);
		$this->tours = Server::get(TourService::class);
		$this->pdf = Server::get(WoPdfService::class);
		$this->db = Server::get(IDBConnection::class);
		$this->today = Server::get(\OCA\MaintenanceCheck\Service\Clock::class)->today();

		$this->equipTypeId = $this->ensureCatalog('equip', self::MARKER . 'et');
		$this->maintTypeId = $this->ensureCatalog('maint', self::MARKER . 'mt');
		Server::get(BuiltinProcedurePackSeeder::class)->ensureInstalled();
		$this->procedureId = $this->firstActiveProcedureId();
	}

	protected function tearDown(): void
	{
		if (class_exists(\OC::class)) {
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
			$userManager = Server::get(IUserManager::class);
			foreach ($this->tempUsers as $uid) {
				$userManager->get($uid)?->delete();
			}
			$this->tempUsers = [];
		}
		parent::tearDown();
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

	private function firstActiveProcedureId(): int
	{
		$page = $this->procedures->list('50', '0', null, '1');
		foreach ($page['data'] as $row) {
			if (!empty($row['active'])) {
				return (int)$row['id'];
			}
		}
		$this->fail('No active procedure after builtin seed');
	}

	private function createTempUser(): string
	{
		$uid = self::MARKER . substr(bin2hex(random_bytes(4)), 0, 8);
		$userManager = Server::get(IUserManager::class);
		$user = $userManager->createUser($uid, bin2hex(random_bytes(12)));
		$this->assertNotFalse($user);
		$this->tempUsers[] = $uid;
		return $uid;
	}

	/**
	 * @return array{customerId: int, equipmentId: int, visitId: int}
	 */
	private function seedVisit(): array
	{
		$customer = $this->customers->create(self::UID, [
			'name' => self::MARKER . 'cust ' . uniqid('', true),
		]);
		$customerId = (int)$customer['id'];
		$this->customerIds[] = $customerId;
		$equipment = $this->equipment->create(self::UID, [
			'label' => self::MARKER . 'eq',
			'customerId' => $customerId,
			'equipTypeId' => $this->equipTypeId,
		]);
		$plan = $this->plans->create(self::UID, (int)$equipment['id'], [
			'maintTypeId' => $this->maintTypeId,
			'intervalUnit' => 'month',
			'intervalCount' => 6,
			'firstDueOn' => $this->today,
		]);
		return [
			'customerId' => $customerId,
			'equipmentId' => (int)$equipment['id'],
			'visitId' => (int)$plan['openVisit']['id'],
		];
	}

	public function testCreateFromVisitIsIdempotentAgainstSecondOpen(): void
	{
		$seed = $this->seedVisit();
		$first = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureId' => $this->procedureId,
		]);
		$this->assertSame('planned', $first['status']);
		$this->assertNotEmpty($first['checklist']);

		try {
			$this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
				'procedureId' => $this->procedureId,
			]);
			$this->fail('Expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('visit_already_linked', $e->getErrorCode());
		}
	}

	public function testOpenOrCreateFromVisitReturnsExistingWithoutConflict(): void
	{
		$seed = $this->seedVisit();
		$first = $this->workOrders->openOrCreateFromVisit(self::UID, $seed['visitId'], [
			'procedureId' => $this->procedureId,
		]);
		$second = $this->workOrders->openOrCreateFromVisit(self::UID, $seed['visitId'], [
			'procedureId' => $this->procedureId,
		]);
		$this->assertSame((int)$first['id'], (int)$second['id']);
		$this->assertSame($first['number'], $second['number']);
	}

	/**
	 * AC-W1-2: Done on a preventive WO completes the linked visit and rolls
	 * the next due identically to VisitService::complete for the same interval.
	 */
	public function testDoneOnPreventiveWoRollsNextDueLikeVisitComplete(): void
	{
		$visits = Server::get(\OCA\MaintenanceCheck\Service\VisitService::class);
		$interval = Server::get(\OCA\MaintenanceCheck\Service\IntervalCalculator::class);

		$seed = $this->seedVisit();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'AC-W1-2 roll parity fixture',
		]);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'ready'], true);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'in_progress'], true);
		$done = $this->workOrders->transition(self::UID, (int)$wo['id'], [
			'to' => 'done',
			'doneOn' => $this->today,
		], true);
		$this->assertSame('done', $done['status']);

		$closedVisit = $visits->list(self::UID, [
			'status' => 'done',
			'equipmentId' => (string)$seed['equipmentId'],
			'limit' => '50',
			'offset' => '0',
		]);
		$matched = null;
		foreach ($closedVisit['data'] as $row) {
			if ((int)$row['id'] === $seed['visitId']) {
				$matched = $row;
				break;
			}
		}
		$this->assertNotNull($matched, 'Linked visit must be done');
		$this->assertSame($this->today, $matched['doneOn']);

		$expectedNext = $interval->addInterval($this->today, 'month', 6);
		$open = $visits->list(self::UID, [
			'status' => 'scheduled',
			'equipmentId' => (string)$seed['equipmentId'],
			'limit' => '50',
			'offset' => '0',
		]);
		$this->assertGreaterThanOrEqual(1, $open['total']);
		$this->assertSame($expectedNext, $open['data'][0]['dueOn']);
	}

	/** CORE §7: unrelated tech cannot transition an assigned WO. */
	public function testUnrelatedTechCannotExecuteAssignedWorkOrder(): void
	{
		$seed = $this->seedVisit();
		$assignee = $this->createTempUser();
		$intruder = $this->createTempUser();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Execute ACL fixture reason',
		]);
		$this->workOrders->assign(self::UID, (int)$wo['id'], [
			'primaryUserId' => $assignee,
			'force' => true,
		]);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'ready'], true);

		$this->expectException(\OCA\MaintenanceCheck\Exception\PermissionDeniedException::class);
		$this->workOrders->transition($intruder, (int)$wo['id'], ['to' => 'in_progress'], false);
	}

	/** CORE §7: sequential WO id is not a free read of someone else's assigned job. */
	public function testUnrelatedTechCannotReadAssignedWorkOrderById(): void
	{
		$seed = $this->seedVisit();
		$assignee = $this->createTempUser();
		$intruder = $this->createTempUser();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Read ACL fixture reason',
		]);
		$this->workOrders->assign(self::UID, (int)$wo['id'], [
			'primaryUserId' => $assignee,
			'force' => true,
		]);

		$this->expectException(\OCA\MaintenanceCheck\Exception\PermissionDeniedException::class);
		$this->workOrders->get((int)$wo['id'], $intruder);
	}

	/** CORE §7: helpers can execute and must appear on their own "mine" list. */
	public function testHelperSeesAssignedWorkOrderOnMineList(): void
	{
		$seed = $this->seedVisit();
		$primary = $this->createTempUser();
		$helper = $this->createTempUser();
		$stranger = $this->createTempUser();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Helper mine-list fixture reason',
		]);
		$this->workOrders->assign(self::UID, (int)$wo['id'], [
			'primaryUserId' => $primary,
			'helperUids' => [$helper],
			'force' => true,
		]);

		$detail = $this->workOrders->get((int)$wo['id']);
		$this->assertContains($helper, $detail['helperUids'], 'assign must persist helperUids as a list');

		$mine = $this->workOrders->list($helper, [
			'mine' => '1',
			'equipmentId' => (string)$seed['equipmentId'],
			'limit' => '50',
			'offset' => '0',
		]);
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $mine['data']);
		$this->assertContains((int)$wo['id'], $ids, 'helper must see the job on mine=1 for this equipment');

		$other = $this->workOrders->list($stranger, ['mine' => '1', 'limit' => '50', 'offset' => '0']);
		$otherIds = array_map(static fn (array $row): int => (int)$row['id'], $other['data']);
		$this->assertNotContains((int)$wo['id'], $otherIds, 'unrelated tech must not see an assigned job');
	}

	/**
	 * Assigned/helper rows must sort ahead of the unassigned pool so a helper
	 * job cannot vanish behind 50 older pool drafts on page 1.
	 */
	public function testHelperMineListRanksAssignedAheadOfPoolOnFirstPage(): void
	{
		$seed = $this->seedVisit();
		$primary = $this->createTempUser();
		$helper = $this->createTempUser();
		$mapper = Server::get(\OCA\MaintenanceCheck\Db\WorkOrderMapper::class);
		$now = time();
		for ($i = 0; $i < 50; $i++) {
			$pool = new \OCA\MaintenanceCheck\Db\WorkOrder();
			$pool->setNumber('MNQA' . substr(bin2hex(random_bytes(8)), 0, 16) . sprintf('%02d', $i));
			$pool->setKind(\OCA\MaintenanceCheck\Db\WorkOrder::KIND_CORRECTIVE);
			$pool->setStatus(\OCA\MaintenanceCheck\Db\WorkOrder::STATUS_DRAFT);
			$pool->setPriority(\OCA\MaintenanceCheck\Db\WorkOrder::PRIORITY_NORMAL);
			$pool->setCustomerId($seed['customerId']);
			$pool->setTitle('Pool filler ' . $i);
			$pool->setDueOn('2000-01-01');
			$pool->setProcedureSkipped(false);
			$pool->setCreatedAt($now);
			$pool->setUpdatedAt($now);
			$pool->setCreatedBy(self::UID);
			$mapper->insert($pool);
		}

		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Helper pagination fixture reason',
		]);
		$this->workOrders->assign(self::UID, (int)$wo['id'], [
			'primaryUserId' => $primary,
			'helperUids' => [$helper],
			'force' => true,
		]);

		$mine = $this->workOrders->list($helper, [
			'mine' => '1',
			'limit' => '50',
			'offset' => '0',
		]);
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $mine['data']);
		$this->assertContains(
			(int)$wo['id'],
			$ids,
			'helper WO must be on page 1 even when 50 older pool jobs exist',
		);
		$this->assertSame(
			(int)$wo['id'],
			$ids[0],
			'assigned/helper jobs must sort before the unassigned pool',
		);
	}

	public function testReadyBlockedUntilKitPackedOrOverridden(): void
	{
		$seed = $this->seedVisit();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureId' => $this->procedureId,
		]);
		$tpl = $this->kits->createTemplate(self::UID, [
			'code' => self::MARKER . 'kit' . substr(uniqid(), -4),
			'name' => 'ITest kit',
			'lines' => [[
				'lineType' => 'part',
				'label' => 'Filter',
				'qtyRequired' => 1,
				'optional' => false,
				'sortOrder' => 1,
			]],
		]);
		$this->kits->attachKit(self::UID, (int)$wo['id'], ['templateId' => (int)$tpl['id']]);

		try {
			$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'ready'], true);
			$this->fail('Expected kit_incomplete');
		} catch (ConflictException $e) {
			$this->assertSame('kit_incomplete', $e->getErrorCode());
		}

		$ready = $this->workOrders->transition(self::UID, (int)$wo['id'], [
			'to' => 'ready',
			'kitOverride' => true,
			'kitOverrideReason' => 'Parts already on the van today',
		], true);
		$this->assertSame('ready', $ready['status']);
	}

	public function testLockedTourIgnoresSuggestOrder(): void
	{
		$tech = $this->createTempUser();
		$seed = $this->seedVisit();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureId' => $this->procedureId,
		]);
		$tour = $this->tours->create(self::UID, [
			'tourDate' => $this->today,
			'techUid' => $tech,
		]);
		$this->assertNotSame('', (string)($tour['techDisplayName'] ?? ''));
		$this->tours->addStop((int)$tour['id'], ['workOrderId' => (int)$wo['id']]);
		$detail = $this->tours->get((int)$tour['id']);
		$this->assertNotSame('', (string)($detail['stops'][0]['workOrder']['customerName'] ?? ''));
		$this->tours->update((int)$tour['id'], ['orderLocked' => true]);
		try {
			$this->tours->suggestOrder((int)$tour['id']);
			$this->fail('Expected tour_locked');
		} catch (ConflictException $e) {
			$this->assertSame('tour_locked', $e->getErrorCode());
		}
	}

	public function testSuggestOrderThenReorderAppliesPermutation(): void
	{
		$tech = $this->createTempUser();
		$seedA = $this->seedVisit();
		$woA = $this->workOrders->createFromVisit(self::UID, $seedA['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Tour suggest A needs no checklist',
		]);
		$seedB = $this->seedVisit();
		$woB = $this->workOrders->createFromVisit(self::UID, $seedB['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Tour suggest B needs no checklist',
		]);
		$tour = $this->tours->create(self::UID, [
			'tourDate' => $this->today,
			'techUid' => $tech,
		]);
		$this->tours->addStop((int)$tour['id'], ['workOrderId' => (int)$woA['id']]);
		$this->tours->addStop((int)$tour['id'], ['workOrderId' => (int)$woB['id']]);

		$suggestion = $this->tours->suggestOrder((int)$tour['id']);
		$this->assertFalse($suggestion['applied']);
		$this->assertCount(2, $suggestion['suggestedWorkOrderIds']);

		$reordered = $this->tours->reorder((int)$tour['id'], [
			'workOrderIds' => $suggestion['suggestedWorkOrderIds'],
		]);
		$stopWoIds = array_map(
			static fn (array $stop): int => (int)$stop['workOrderId'],
			$reordered['stops'] ?? [],
		);
		$this->assertSame($suggestion['suggestedWorkOrderIds'], $stopWoIds);
	}

	public function testServiceberichtRequiresDoneThenSucceeds(): void
	{
		$seed = $this->seedVisit();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'No template needed for this PDF path',
		]);
		try {
			$this->pdf->servicebericht((int)$wo['id']);
			$this->fail('Expected ConflictException while open');
		} catch (ConflictException $e) {
			$this->assertSame('wo_not_done', $e->getErrorCode());
		}

		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'ready'], true);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'in_progress'], true);
		$done = $this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'done'], true);
		$this->assertSame('done', $done['status']);

		$pdf = $this->pdf->servicebericht((int)$wo['id']);
		$this->assertSame('application/pdf', $pdf['mime']);
		$this->assertNotSame('', $pdf['content']);
		$this->assertStringStartsWith('%PDF', $pdf['content']);
		$this->assertStringContainsString((string)$done['number'], (string)($pdf['filename'] ?? ''));
	}

	/** W3 exit: Servicebericht generates in ≤ 5 s on reference Docker host. */
	public function testServiceberichtGeneratesWithinFiveSeconds(): void
	{
		$seed = $this->seedVisit();
		$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Perf fixture skips checklist for PDF timing',
		]);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'ready'], true);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'in_progress'], true);
		$this->workOrders->transition(self::UID, (int)$wo['id'], ['to' => 'done'], true);

		$started = hrtime(true);
		$pdf = $this->pdf->servicebericht((int)$wo['id']);
		$elapsedMs = (hrtime(true) - $started) / 1e6;
		$this->assertSame('application/pdf', $pdf['mime']);
		$this->assertLessThan(
			5000.0,
			$elapsedMs,
			sprintf('Servicebericht took %.1f ms (W3 exit requires ≤ 5000 ms)', $elapsedMs),
		);
	}

	public function testShkPackExportIsValidMnProcedurePack(): void
	{
		$export = $this->procedures->exportPack('builtin-shk-v1', null);
		$this->assertSame('mn_procedure_pack_v1', $export['format']);
		$this->assertSame('builtin-shk-v1', $export['pack_code']);
		$this->assertSame('shk', $export['vertical']);
		$this->assertNotEmpty($export['procedures']);

		Server::get(\OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder::class)->ensureInstalled();
		$de = $this->procedures->exportPack('builtin-shk-de-v1', null);
		$this->assertSame('mn_procedure_pack_v1', $de['format']);
		$this->assertSame('builtin-shk-de-v1', $de['pack_code']);
		$this->assertSame('de', $de['locale']);
		$this->assertNotEmpty($de['procedures']);
	}

	/** AC-W2-2: skills block rejects assign with skills_missing. */
	public function testSkillsBlockRejectsAssignWithSkillsMissing(): void
	{
		$policies = Server::get(\OCA\MaintenanceCheck\Service\PolicyService::class);
		$skills = Server::get(\OCA\MaintenanceCheck\Service\SkillService::class);
		$previous = $policies->snapshot();
		try {
			$policies->save(['skillsEnforcement' => \OCA\MaintenanceCheck\Service\PolicyService::ENFORCEMENT_BLOCK]);
			$skill = $skills->create([
				'code' => self::MARKER . 'sk' . substr(uniqid(), -5),
				'name' => 'ITest skill',
			]);
			$tech = $this->createTempUser();
			$seed = $this->seedVisit();
			$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
				'procedureId' => $this->procedureId,
			]);
			$skills->setWoSkills((int)$wo['id'], ['skillIds' => [(int)$skill['id']]]);

			try {
				$this->workOrders->assign(self::UID, (int)$wo['id'], [
					'primaryUserId' => $tech,
				]);
				$this->fail('Expected skills_missing');
			} catch (\OCA\MaintenanceCheck\Exception\ValidationException $e) {
				$this->assertSame('skills_missing', $e->getErrorCode());
			}

			$skills->setUserSkills(self::UID, $tech, ['skillIds' => [(int)$skill['id']]]);
			$assigned = $this->workOrders->assign(self::UID, (int)$wo['id'], [
				'primaryUserId' => $tech,
			]);
			$this->assertSame($tech, $assigned['primaryUserId']);
		} finally {
			$policies->save([
				'skillsEnforcement' => $previous['skillsEnforcement'],
			]);
		}
	}

	/** AC-W4-1: capacity block rejects assign when load would exceed. */
	public function testCapacityBlockRejectsAssignWhenExceeded(): void
	{
		$policies = Server::get(\OCA\MaintenanceCheck\Service\PolicyService::class);
		$capacity = Server::get(\OCA\MaintenanceCheck\Service\CapacityService::class);
		$previous = $policies->snapshot();
		try {
			$policies->save(['capacityEnforcement' => \OCA\MaintenanceCheck\Service\PolicyService::ENFORCEMENT_BLOCK]);
			$tech = $this->createTempUser();
			$capacity->set(self::UID, $tech, ['dailyMinutes' => 60]);
			$seed = $this->seedVisit();
			$wo = $this->workOrders->createFromVisit(self::UID, $seed['visitId'], [
				'procedureId' => $this->procedureId,
				'estimatedMinutes' => 120,
			]);
			try {
				$this->workOrders->assign(self::UID, (int)$wo['id'], [
					'primaryUserId' => $tech,
				]);
				$this->fail('Expected capacity_exceeded');
			} catch (\OCA\MaintenanceCheck\Exception\ValidationException $e) {
				$this->assertSame('capacity_exceeded', $e->getErrorCode());
			}
		} finally {
			$policies->save([
				'capacityEnforcement' => $previous['capacityEnforcement'],
			]);
		}
	}

	/** AC-W3-4: overwrite=0 → pack_exists; overwrite=1 succeeds. */
	public function testPackImportOverwriteGate(): void
	{
		$packCode = self::MARKER . 'pack_' . substr(uniqid(), -6);
		$procCode = self::MARKER . 'proc_' . substr(uniqid(), -6);
		$payload = [
			'format' => 'mn_procedure_pack_v1',
			'pack_code' => $packCode,
			'vertical' => 'shk',
			'locale' => 'en',
			'version' => 1,
			'procedures' => [[
				'code' => $procCode,
				'title' => 'ITest pack procedure',
				'items' => [[
					'code' => 'step_a',
					'label' => 'Step A',
					'required' => true,
					'sort_order' => 10,
				]],
			]],
		];
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		$first = $this->procedures->importPack(self::UID, $json, false);
		$this->assertSame($packCode, $first['packCode']);
		$this->assertSame(1, $first['imported']);

		try {
			$this->procedures->importPack(self::UID, $json, false);
			$this->fail('Expected pack_exists');
		} catch (ConflictException $e) {
			$this->assertSame('pack_exists', $e->getErrorCode());
		}

		$second = $this->procedures->importPack(self::UID, $json, true);
		$this->assertSame($packCode, $second['packCode']);
		$this->assertGreaterThanOrEqual(1, $second['imported'] + $second['replaced']);
	}
}
