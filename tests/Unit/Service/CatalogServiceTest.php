<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\CatalogType;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

final class CatalogServiceTest extends TestCase
{
	public function testCreateMapsUniqueViolationToCodeExists(): void
	{
		$mapper = $this->createMock(EquipTypeMapper::class);
		$mapper->expects($this->exactly(2))->method('findByCode')
			->with('pump')
			->willReturnOnConsecutiveCalls(null, $this->createMock(CatalogType::class));
		$mapper->method('insert')->willThrowException($this->createMock(DbException::class));

		$service = new CatalogService(
			$mapper,
			$this->createMock(MaintTypeMapper::class),
			new InputValidator(new IntervalCalculator()),
		);

		try {
			$service->create('equip', ['code' => 'pump', 'name' => 'Pump']);
			$this->fail('expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('code_exists', $e->getErrorCode());
		}
	}
}
