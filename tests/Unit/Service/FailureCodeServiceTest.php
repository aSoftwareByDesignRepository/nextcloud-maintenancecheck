<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\CatalogType;
use OCA\MaintenanceCheck\Db\FailureCodeMapper;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use PHPUnit\Framework\TestCase;

final class FailureCodeServiceTest extends TestCase
{
	private function validator(): InputValidator
	{
		return new InputValidator($this->createMock(IntervalCalculator::class));
	}

	public function testSeedInsertsMissingCodesOnly(): void
	{
		$existing = new CatalogType();
		$existing->setCode('unknown');
		$existing->setName('Unknown / other');
		$existing->setSortOrder(999);
		$existing->setActive(true);

		$mapper = $this->createMock(FailureCodeMapper::class);
		$mapper->method('findByCode')->willReturnCallback(static function (string $code) use ($existing): ?CatalogType {
			return $code === 'unknown' ? $existing : null;
		});
		$inserted = [];
		$mapper->method('insert')->willReturnCallback(function (CatalogType $type) use (&$inserted): CatalogType {
			$inserted[] = $type->getCode();
			return $type;
		});

		$service = new FailureCodeService($mapper, $this->validator());
		$count = $service->seedIfEmpty();
		$this->assertSame(count(FailureCodeService::SEED) - 1, $count);
		$this->assertNotContains('unknown', $inserted);
		$this->assertContains('sensor_fault', $inserted);
	}

	public function testSeedCatalogHasAtLeastEightCodes(): void
	{
		$this->assertGreaterThanOrEqual(8, count(FailureCodeService::SEED));
		$codes = array_column(FailureCodeService::SEED, 'code');
		$this->assertSame($codes, array_unique($codes));
	}
}
