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
use OCA\MaintenanceCheck\Service\MeterService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCP\IDBConnection;
use OCP\Server;

/**
 * W5 AC-W5-2 / AC-W5-3: meter reading → due visit, monotonic gate, idempotency.
 *
 * @group integration
 */
class MeterDueEngineIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_meter_itest';
	private const MARKER = 'mn_meter_';

	private CustomerService $customers;
	private EquipmentService $equipment;
	private CatalogService $catalogs;
	private PlanService $plans;
	private MeterService $meters;
	private VisitMapper $visitMapper;
	private IDBConnection $db;
	private string $today;

	/** @var list<int> */
	private array $customerIds = [];

	private int $equipTypeId;
	private int $maintTypeId;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$this->customers = Server::get(CustomerService::class);
		$this->equipment = Server::get(EquipmentService::class);
		$this->catalogs = Server::get(CatalogService::class);
		$this->plans = Server::get(PlanService::class);
		$this->meters = Server::get(MeterService::class);
		$this->visitMapper = Server::get(VisitMapper::class);
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
	 * @return array{customerId: int, equipmentId: int, meterId: int, planId: int}
	 */
	private function seedMeterPlan(string $threshold = '100.000'): array
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
		$equipmentId = (int)$equipment['id'];

		$meter = $this->meters->create(self::UID, $equipmentId, [
			'code' => 'hours',
			'name' => 'Operating hours',
			'unit' => 'h',
			'monotonic' => true,
		]);
		$meterId = (int)$meter['id'];

		$plan = $this->plans->create(self::UID, $equipmentId, [
			'maintTypeId' => $this->maintTypeId,
			'triggerKind' => 'meter',
			'meterCode' => 'hours',
			'meterThreshold' => $threshold,
		]);
		$this->assertNull($plan['openVisit'] ?? null, 'Pure meter plans must not create an interval visit');
		$this->assertNull($this->visitMapper->findOpenByPlan((int)$plan['id']));

		return [
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'meterId' => $meterId,
			'planId' => (int)$plan['id'],
		];
	}

	public function testReadingBelowThresholdDoesNotOpenVisit(): void
	{
		$seed = $this->seedMeterPlan('100.000');
		$result = $this->meters->addReading(self::UID, $seed['meterId'], [
			'value' => '99.999',
			'readOn' => $this->today,
		]);
		$this->assertSame([], $result['triggered']);
		$this->assertNull($this->visitMapper->findOpenByPlan($seed['planId']));
	}

	public function testReadingAtThresholdCreatesDueVisitIdempotently(): void
	{
		$seed = $this->seedMeterPlan('100.000');
		$first = $this->meters->addReading(self::UID, $seed['meterId'], [
			'value' => '100',
			'readOn' => $this->today,
		]);
		$this->assertCount(1, $first['triggered']);
		$this->assertSame('created', $first['triggered'][0]['action']);
		$visitId = (int)$first['triggered'][0]['visitId'];
		$this->assertSame($this->today, $first['triggered'][0]['dueOn']);

		$open = $this->visitMapper->findOpenByPlan($seed['planId']);
		$this->assertNotNull($open);
		$this->assertSame($visitId, (int)$open->getId());
		$this->assertSame($this->today, $open->getDueOn());

		$second = $this->meters->addReading(self::UID, $seed['meterId'], [
			'value' => '105',
			'readOn' => $this->today,
		]);
		$this->assertSame([], $second['triggered'], 'Open visit already due today must not duplicate');
		$this->assertSame($visitId, (int)$this->visitMapper->findOpenByPlan($seed['planId'])->getId());
	}

	public function testMonotonicMeterRejectsDecrease(): void
	{
		$seed = $this->seedMeterPlan('9999');
		$this->meters->addReading(self::UID, $seed['meterId'], ['value' => '50', 'readOn' => $this->today]);
		try {
			$this->meters->addReading(self::UID, $seed['meterId'], ['value' => '49.999', 'readOn' => $this->today]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('meter_not_monotonic', $e->getErrorCode());
		}
	}

	public function testMeterInUseBlocksDelete(): void
	{
		$seed = $this->seedMeterPlan('10');
		try {
			$this->meters->delete($seed['meterId']);
			$this->fail('Expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('meter_in_use', $e->getErrorCode());
		}
	}

	public function testClosingReadingOnCompleteDoesNotReopenVisit(): void
	{
		$seed = $this->seedMeterPlan('100.000');
		$due = $this->meters->addReading(self::UID, $seed['meterId'], [
			'value' => '100',
			'readOn' => $this->today,
		]);
		$visitId = (int)$due['triggered'][0]['visitId'];
		$visits = Server::get(\OCA\MaintenanceCheck\Service\VisitService::class);
		$result = $visits->complete(self::UID, $visitId, [
			'doneOn' => $this->today,
			'closingReading' => [
				'meterCode' => 'hours',
				'value' => '101',
			],
		]);
		$this->assertNotNull($result['closingReading']);
		$this->assertSame('101.000', $result['closingReading']['value']);
		$this->assertNull($result['nextVisit'], 'Pure meter complete must not invent an interval next visit');
		$this->assertNull(
			$this->visitMapper->findOpenByPlan($seed['planId']),
			'Closing reading must not re-open a due visit for the same threshold',
		);
	}

	public function testCsvImportCreatesDueAndUsesImportSource(): void
	{
		$seed = $this->seedMeterPlan('200.000');
		$result = $this->meters->importCsv(self::UID, $seed['equipmentId'], "meter_code,value,read_on\nhours,200," . $this->today . "\n");
		$this->assertSame(1, $result['imported']);
		$this->assertSame('import', $result['readings'][0]['source']);
		$this->assertCount(1, $result['triggered']);
		$open = $this->visitMapper->findOpenByPlan($seed['planId']);
		$this->assertNotNull($open);
		$this->assertSame($this->today, $open->getDueOn());
	}
}
