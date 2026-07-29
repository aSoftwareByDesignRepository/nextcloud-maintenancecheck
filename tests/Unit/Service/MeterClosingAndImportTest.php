<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\Meter;
use OCA\MaintenanceCheck\Db\MeterMapper;
use OCA\MaintenanceCheck\Db\MeterReading;
use OCA\MaintenanceCheck\Db\MeterReadingMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\MeterMath;
use OCA\MaintenanceCheck\Service\MeterService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W5 exit: closing reading must not re-evaluate due; CSV import uses SOURCE_IMPORT.
 */
final class MeterClosingAndImportTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private MeterMapper&MockObject $meters;
	private MeterReadingMapper&MockObject $readings;
	private EquipmentMapper&MockObject $equipment;
	private PlanMapper&MockObject $plans;
	private VisitMapper&MockObject $visits;
	private MeterService $service;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->meters = $this->createMock(MeterMapper::class);
		$this->readings = $this->createMock(MeterReadingMapper::class);
		$this->equipment = $this->createMock(EquipmentMapper::class);
		$this->plans = $this->createMock(PlanMapper::class);
		$this->visits = $this->createMock(VisitMapper::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('today')->willReturn('2026-07-26');
		$clock->method('now')->willReturn(1_722_000_000);

		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');
		$this->db->method('inTransaction')->willReturn(false);

		$this->service = new MeterService(
			$this->db,
			$this->meters,
			$this->readings,
			$this->equipment,
			$this->plans,
			$this->visits,
			new MeterMath(),
			new InputValidator(new IntervalCalculator()),
			$clock,
		);
	}

	public function testClosingReadingDoesNotEvaluateDuePlans(): void
	{
		$meter = $this->meter(7, 11, 'runtime_h', true);
		$this->meters->expects($this->once())->method('findByEquipmentAndCode')
			->with(11, 'runtime_h')->willReturn($meter);
		$this->meters->expects($this->once())->method('lockRow')->with(7)->willReturn(true);
		$this->meters->expects($this->once())->method('findById')->with(7)->willReturn($meter);
		$this->readings->expects($this->once())->method('findLatest')->with(7)->willReturn(null);
		$this->readings->expects($this->once())->method('insert')
			->willReturnCallback(static function (MeterReading $reading): MeterReading {
				self::assertSame(MeterReading::SOURCE_MANUAL, $reading->getSource());
				self::assertSame('2005.000', $reading->getValue());
				$reading->setId(99);
				return $reading;
			});
		// Closing path must never ask plans / visits (no due re-open).
		$this->plans->expects($this->never())->method('findActiveMeterPlans');
		$this->visits->expects($this->never())->method('findOpenByPlan');
		$this->equipment->expects($this->never())->method('findById');

		$api = $this->service->recordClosingWithinTransaction(
			'tech1',
			11,
			['meterCode' => 'runtime_h', 'value' => '2005'],
			'2026-07-26',
			1_722_000_000,
		);
		$this->assertSame(99, $api['id']);
		$this->assertSame('2005.000', $api['value']);
	}

	public function testClosingReadingRejectsForeignMeterId(): void
	{
		$meter = $this->meter(7, 99, 'runtime_h', true);
		$this->meters->expects($this->once())->method('findById')->with(7)->willReturn($meter);

		$this->expectException(ValidationException::class);
		$this->service->recordClosingWithinTransaction(
			'tech1',
			11,
			['meterId' => 7, 'value' => '10'],
			'2026-07-26',
			1_722_000_000,
		);
	}

	public function testImportCsvUsesImportSourceAndTriggersDue(): void
	{
		$equipment = new Equipment();
		$equipment->setId(11);
		$equipment->setCustomerId(3);
		$equipment->setActive(true);
		$this->equipment->expects($this->exactly(2))->method('findById')->with(11)->willReturn($equipment);

		$meter = $this->meter(7, 11, 'runtime_h', true);
		$this->meters->expects($this->once())->method('findByEquipmentAndCode')
			->with(11, 'runtime_h')->willReturn($meter);
		$this->meters->expects($this->once())->method('lockRow')->with(7)->willReturn(true);
		$this->meters->expects($this->once())->method('findById')->with(7)->willReturn($meter);
		$this->readings->expects($this->once())->method('findLatest')->with(7)->willReturn(null);
		$this->readings->expects($this->once())->method('insert')
			->willReturnCallback(static function (MeterReading $reading): MeterReading {
				self::assertSame(MeterReading::SOURCE_IMPORT, $reading->getSource());
				$reading->setId(55);
				return $reading;
			});
		$this->plans->expects($this->once())->method('findActiveMeterPlans')
			->with(11, 'runtime_h')->willReturn([]);

		$result = $this->service->importCsv('office1', 11, "meter_code,value\nruntime_h,2005\n");
		$this->assertSame(1, $result['imported']);
		$this->assertSame(MeterReading::SOURCE_IMPORT, $result['readings'][0]['source']);
	}

	public function testImportCsvRejectsUnknownMeterCodeWithRowHint(): void
	{
		$equipment = new Equipment();
		$equipment->setId(11);
		$this->equipment->method('findById')->willReturn($equipment);
		$this->meters->method('findByEquipmentAndCode')->willReturn(null);

		try {
			$this->service->importCsv('office1', 11, "runtime_h,10\n");
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertStringContainsString('Row 1', $e->getMessage());
		}
	}

	private function meter(int $id, int $equipmentId, string $code, bool $active): Meter
	{
		$meter = new Meter();
		$meter->setId($id);
		$meter->setEquipmentId($equipmentId);
		$meter->setCode($code);
		$meter->setName($code);
		$meter->setMonotonic(true);
		$meter->setActive($active);
		return $meter;
	}
}
