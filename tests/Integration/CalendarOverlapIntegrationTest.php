<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CalendarService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * FA-1/FA-2 DB-level proof (spec §10 integration tier): overlap predicates,
 * capacity proration, and mine-scope on a real database — not mocks.
 *
 * @group integration
 */
final class CalendarOverlapIntegrationTest extends TestCase
{
	private const MARKER = 'CALIT-';

	private const OFFICE = 'calit-office';
	private const TECH_A = 'calit-tech-a';
	private const TECH_B = 'calit-tech-b';

	private WorkOrderMapper $workOrders;
	private WorkOrderService $woService;
	private CalendarService $calendar;
	private IDBConnection $db;
	private IConfig $config;

	private int $customerId;
	private string $prevOffice;

	/** @var list<int> */
	private array $woIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$this->workOrders = Server::get(WorkOrderMapper::class);
		$this->woService = Server::get(WorkOrderService::class);
		$this->calendar = Server::get(CalendarService::class);
		$this->db = Server::get(IDBConnection::class);
		$this->config = Server::get(IConfig::class);
		$this->prevOffice = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '');
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, json_encode([self::OFFICE]));

		$customer = Server::get(CustomerService::class)->create(self::OFFICE, [
			'name' => self::MARKER . 'Customer ' . bin2hex(random_bytes(4)),
		]);
		$this->customerId = (int)$customer['id'];
	}

	protected function tearDown(): void
	{
		if (!class_exists(\OC::class)) {
			return;
		}
		foreach ($this->woIds as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('mn_work_orders')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
			$qb->executeStatement();
		}
		try {
			Server::get(CustomerService::class)->delete($this->customerId, true);
		} catch (\Throwable) {
		}
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, $this->prevOffice);
	}

	private function makeWo(?string $dueOn, ?string $dueUntil, ?string $tech, int $minutes = 60, string $status = WorkOrder::STATUS_PLANNED): WorkOrder
	{
		$wo = new WorkOrder();
		$wo->setNumber(self::MARKER . bin2hex(random_bytes(6)));
		$wo->setTitle('Calendar IT');
		$wo->setKind(WorkOrder::KIND_PREVENTIVE);
		$wo->setStatus($status);
		$wo->setPriority(WorkOrder::PRIORITY_NORMAL);
		$wo->setCustomerId($this->customerId);
		$wo->setDueOn($dueOn);
		$wo->setDueUntil($dueUntil ?? $dueOn);
		$wo->setEstimatedMinutes($minutes);
		$wo->setPrimaryUserId($tech);
		$wo->setCreatedBy($tech ?? 'calit-seed');
		$wo->setCreatedAt(time());
		$wo->setUpdatedAt(time());
		$this->workOrders->insert($wo);
		$this->woIds[] = (int)$wo->getId();
		return $wo;
	}

	public function testWindowOverlapSemanticsOnRealDb(): void
	{
		$spanning = $this->makeWo('2026-11-10', '2026-11-20', self::TECH_A); // crosses window start
		$inside = $this->makeWo('2026-11-16', '2026-11-18', self::TECH_A);
		$before = $this->makeWo('2026-11-01', '2026-11-05', self::TECH_A);   // ends before window
		$after = $this->makeWo('2026-12-01', '2026-12-05', self::TECH_A);   // starts after window
		$this->makeWo('2026-11-16', '2026-11-16', self::TECH_A, 60, WorkOrder::STATUS_DONE); // terminal excluded
		$undated = $this->makeWo(null, null, self::TECH_A);

		$found = array_column(
			array_map(fn (WorkOrder $wo) => ['id' => (int)$wo->getId()], $this->workOrders->findForCalendar('2026-11-15', '2026-11-30')),
			'id',
		);
		$this->assertContains((int)$spanning->getId(), $found, 'span touching window start must match');
		$this->assertContains((int)$inside->getId(), $found);
		$this->assertNotContains((int)$before->getId(), $found, 'span ending before window must not match');
		$this->assertNotContains((int)$after->getId(), $found);
		$this->assertNotContains((int)$undated->getId(), $found, 'undated rows never match a day window');
	}

	public function testLoadMinutesProrationOnRealDb(): void
	{
		$this->makeWo('2026-11-16', '2026-11-18', self::TECH_A, 480); // 3-day span, 480 min
		$this->makeWo('2026-11-17', '2026-11-17', self::TECH_A, 60);  // single day inside
		$this->makeWo('2026-11-19', '2026-11-19', self::TECH_A, 60);  // outside

		// CEIL(480/3) = 160 per covered day.
		$this->assertSame(160, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-16'));
		$this->assertSame(220, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-17')); // 160 + 60
		$this->assertSame(160, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-18'));
		$this->assertSame(60, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-19'));
		$this->assertSame(0, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-20'));
	}

	public function testMineScopeParityCalendarVsList(): void
	{
		$own = $this->makeWo('2026-11-16', '2026-11-16', self::TECH_A);
		$pool = $this->makeWo('2026-11-16', '2026-11-16', null);            // unassigned pool — visible to tech
		$other = $this->makeWo('2026-11-16', '2026-11-16', self::TECH_B);   // another tech — invisible (NN-01)

		$out = $this->calendar->events(self::TECH_A, ['from' => '2026-11-01', 'to' => '2026-11-30']);
		$ids = array_column(array_filter($out['events'], fn ($e) => $e['entity'] === 'work_order'), 'id');
		$this->assertContains((int)$own->getId(), $ids);
		$this->assertContains((int)$pool->getId(), $ids);
		$this->assertNotContains((int)$other->getId(), $ids, 'another tech\'s WO must never appear in a tech payload');

		// Scope cannot widen via query params (US-CAL-05 AC-2) — same result
		// even when adversarial filters are passed on the tech session.
		$out2 = $this->calendar->events(self::TECH_A, [
			'from' => '2026-11-01', 'to' => '2026-11-30', 'tech' => self::TECH_B,
		]);
		$ids2 = array_column(array_filter($out2['events'], fn ($e) => $e['entity'] === 'work_order'), 'id');
		sort($ids);
		sort($ids2);
		$this->assertSame($ids, $ids2, 'adversarial tech param on a tech session must not change scope');
		$this->assertNotContains((int)$other->getId(), $ids2);

		// Office narrows only (US-CAL-05 AC-3).
		$office = $this->calendar->events(self::OFFICE, [
			'from' => '2026-11-01', 'to' => '2026-11-30', 'tech' => self::TECH_B,
		]);
		$officeIds = array_column(array_filter($office['events'], fn ($e) => $e['entity'] === 'work_order'), 'id');
		$this->assertContains((int)$other->getId(), $officeIds);
		$this->assertNotContains((int)$own->getId(), $officeIds);
	}

	public function testBackfilledSingleDayRowBehavesIdentically(): void
	{
		// Migration backfill contract (US-CAL-03 AC-4): a row with
		// due_until = due_on reads as a single-day job on every surface.
		$wo = $this->makeWo('2026-11-16', '2026-11-16', self::TECH_A, 480);
		$this->assertSame(1, $wo->spanDays());
		$this->assertSame('2026-11-16', $wo->effectiveDueUntil());
		$this->assertSame(480, $this->workOrders->loadMinutesFor(self::TECH_A, '2026-11-16'));

		$out = $this->calendar->events(self::TECH_A, ['from' => '2026-11-01', 'to' => '2026-11-30']);
		$ev = array_values(array_filter($out['events'], fn ($e) => $e['entity'] === 'work_order' && $e['id'] === (int)$wo->getId()))[0];
		$this->assertSame('2026-11-16', $ev['startDay']);
		$this->assertSame('2026-11-16', $ev['endDay']);
	}
}
