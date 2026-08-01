<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\SiteService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\IConfig;
use OCP\Server;

/**
 * AC-W6-1…12 behavioural integration (MySQL via Docker).
 */
final class W6FieldOpsIntegrationTest extends IntegrationTestCase
{
	private WorkOrderService $workOrders;
	private SiteService $sites;
	private EquipDocService $docs;
	private WoCommentService $comments;
	private FailureCodeService $failureCodes;
	private PolicyService $policies;
	private KpiService $kpi;
	private ExceptionBoardService $exceptions;
	private OverdueReminderService $reminders;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$this->workOrders = Server::get(WorkOrderService::class);
		$this->sites = Server::get(SiteService::class);
		$this->docs = Server::get(EquipDocService::class);
		$this->comments = Server::get(WoCommentService::class);
		$this->failureCodes = Server::get(FailureCodeService::class);
		$this->policies = Server::get(PolicyService::class);
		$this->kpi = Server::get(KpiService::class);
		$this->exceptions = Server::get(ExceptionBoardService::class);
		$this->reminders = Server::get(OverdueReminderService::class);
		$this->config = Server::get(IConfig::class);
		$this->failureCodes->seedIfEmpty();
	}

	public function testCorrectiveIntakePersistsAndCopiesSiteAccessNotes(): void
	{
		$customerId = $this->seedCustomer('W6 Intake Co');
		$site = $this->sites->create('admin', $customerId, [
			'name' => 'Boiler room',
			'accessNotes' => 'Gate 1234 · dog in yard',
			'preferredWindow' => 'Mo–Fr 08–12',
		]);
		$equipmentId = $this->seedEquipment($customerId, 'Boiler A');

		$wo = $this->workOrders->create('admin', [
			'title' => 'No heat',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'siteId' => (int)$site['id'],
			'requesterName' => 'Hausmeister Müller',
			'symptom' => 'Boiler cold since morning',
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Emergency intake without pack',
		], true);

		self::assertSame('Hausmeister Müller', $wo['requesterName']);
		self::assertSame('Boiler cold since morning', $wo['symptom']);
		self::assertSame('Gate 1234 · dog in yard', $wo['accessNotes']);
	}

	public function testWarrantyWarnOnCorrectiveCreate(): void
	{
		$customerId = $this->seedCustomer('W6 Warranty Co');
		$equipmentId = $this->seedEquipment($customerId, 'Old panel', [
			'warrantyEnd' => '2020-01-01',
		]);
		$wo = $this->workOrders->create('admin', [
			'title' => 'Alarm fault',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Quick corrective without pack',
		], true);
		self::assertNotEmpty($wo['warnings'] ?? []);
		self::assertSame('warranty_expired', $wo['warnings'][0]['code']);
	}

	public function testEquipmentDocumentListAndLimit(): void
	{
		$customerId = $this->seedCustomer('W6 Docs Co');
		$equipmentId = $this->seedEquipment($customerId, 'Unit Docs');
		$doc = $this->docs->create('admin', $equipmentId, [
			'title' => 'Manual',
			'externalUrl' => 'https://example.com/manual.pdf',
		]);
		self::assertSame('Manual', $doc['title']);
		$list = $this->docs->listForEquipment($equipmentId);
		self::assertCount(1, $list['data']);
	}

	public function testFailureCodeRequiredBlocksDone(): void
	{
		$this->policies->save(['failureCodeOnCorrective' => PolicyService::FAILURE_CODE_REQUIRED]);
		try {
			$customerId = $this->seedCustomer('W6 Fail Co');
			$equipmentId = $this->seedEquipment($customerId, 'Unit Fail');
			$wo = $this->workOrders->create('admin', [
				'title' => 'Fail close',
				'kind' => WorkOrder::KIND_CORRECTIVE,
				'customerId' => $customerId,
				'equipmentId' => $equipmentId,
				'status' => WorkOrder::STATUS_PLANNED,
				'procedureSkipped' => true,
				'procedureSkipReason' => 'No procedure for this fixture',
			], true);
			$id = (int)$wo['id'];
			$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			try {
				$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_DONE], true);
				self::fail('Expected failure_code_required');
			} catch (ValidationException $e) {
				self::assertSame('failure_code_required', $e->getErrorCode());
			}
			$done = $this->workOrders->transition('admin', $id, [
				'to' => WorkOrder::STATUS_DONE,
				'failureCode' => 'sensor_fault',
				'laborMinutes' => 45,
			], true);
			self::assertSame(WorkOrder::STATUS_DONE, $done['status']);
			self::assertSame('sensor_fault', $done['failureCode']);
			self::assertSame(45, $done['laborMinutes']);
		} finally {
			$this->policies->save(['failureCodeOnCorrective' => PolicyService::FAILURE_CODE_WARN]);
		}
	}

	public function testCommentsAppendChronological(): void
	{
		$customerId = $this->seedCustomer('W6 Comment Co');
		$equipmentId = $this->seedEquipment($customerId, 'Unit Comment');
		$wo = $this->workOrders->create('admin', [
			'title' => 'Comment WO',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Comment fixture without pack',
		], true);
		$id = (int)$wo['id'];
		$this->comments->create('admin', $id, ['body' => 'First note'], true);
		$this->comments->create('admin', $id, ['body' => 'Second note'], true);
		$list = $this->comments->list($id);
		self::assertCount(2, $list['data']);
		self::assertSame('First note', $list['data'][0]['body']);
		self::assertSame('Second note', $list['data'][1]['body']);
	}

	public function testKpiSnapshotAndCsv(): void
	{
		$snap = $this->kpi->snapshot(30);
		self::assertSame(30, $snap['windowDays']);
		self::assertArrayHasKey('pmCompliancePercent', $snap);
		self::assertArrayHasKey('openWorkOrdersByStatus', $snap);
		$csv = $this->kpi->toCsv(30);
		self::assertStringContainsString('pm_compliance_percent', $csv);
		self::assertStringContainsString('overdue_visit_count', $csv);
	}

	public function testExceptionBoardListsBlocked(): void
	{
		$customerId = $this->seedCustomer('W6 Exc Co');
		$equipmentId = $this->seedEquipment($customerId, 'Unit Exc');
		$wo = $this->workOrders->create('admin', [
			'title' => 'Blocked WO',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'status' => WorkOrder::STATUS_PLANNED,
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Exception fixture without pack',
			'dueOn' => '2000-01-01',
		], true);
		$id = (int)$wo['id'];
		$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
		$this->workOrders->transition('admin', $id, [
			'to' => WorkOrder::STATUS_BLOCKED,
			'blockReasonCode' => 'parts',
			'blockNote' => 'Awaiting spare part delivery',
		], true);
		$board = $this->exceptions->list('50', '0', 'blocked');
		$ids = array_column($board['data'], 'id');
		self::assertContains($id, $ids);
	}

	public function testReminderDryRunIsIdempotentPerDay(): void
	{
		$first = $this->reminders->run(true);
		self::assertArrayHasKey('visits', $first);
		self::assertArrayHasKey('workOrders', $first);
		self::assertArrayHasKey('skipped', $first);
		$second = $this->reminders->run(true);
		self::assertSame($first['visits'] + $first['workOrders'], $second['visits'] + $second['workOrders']);
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function seedCustomer(string $name): int
	{
		$customers = Server::get(\OCA\MaintenanceCheck\Service\CustomerService::class);
		$row = $customers->create('admin', ['name' => $name, 'active' => true]);
		return (int)$row['id'];
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function seedEquipment(int $customerId, string $label, array $extra = []): int
	{
		$equipment = Server::get(\OCA\MaintenanceCheck\Service\EquipmentService::class);
		$catalog = Server::get(\OCA\MaintenanceCheck\Service\CatalogService::class);
		$types = $catalog->list('equip', '10', '0');
		$typeId = (int)($types['data'][0]['id'] ?? 0);
		self::assertGreaterThan(0, $typeId);
		$body = array_merge([
			'customerId' => $customerId,
			'equipTypeId' => $typeId,
			'label' => $label,
			'active' => true,
		], $extra);
		$row = $equipment->create('admin', $body);
		return (int)$row['id'];
	}
}
