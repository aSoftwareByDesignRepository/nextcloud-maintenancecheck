<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\ProcedureMapper;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** AC-W1-8 — seeder discovers ≥3 vertical packs and is idempotent-safe. */
final class BuiltinProcedurePackSeederTest extends TestCase
{
	public function testPackFilesConstantListsThreeVerticals(): void
	{
		$this->assertCount(15, BuiltinProcedurePackSeeder::PACK_FILES);
		$joined = implode(',', BuiltinProcedurePackSeeder::PACK_FILES);
		$this->assertStringContainsString('shk', $joined);
		$this->assertStringContainsString('security', $joined);
		$this->assertStringContainsString('electro', $joined);
		$this->assertStringContainsString('facility', $joined);
		$this->assertStringContainsString('hvac', $joined);
		$this->assertStringContainsString('industrial', $joined);
		$this->assertStringContainsString('shk-de', $joined);
		$this->assertStringContainsString('security-de', $joined);
		$this->assertStringContainsString('electro-de', $joined);
		$this->assertStringContainsString('de-portable-electrical', $joined);
		$this->assertStringContainsString('de-ladders', $joined);
		$this->assertStringContainsString('de-fire-extinguisher', $joined);
		$this->assertStringContainsString('en-portable-electrical', $joined);
		$this->assertStringContainsString('en-ladders', $joined);
		$this->assertStringContainsString('en-fire-extinguisher', $joined);
		foreach (BuiltinProcedurePackSeeder::PACK_FILES as $file) {
			$this->assertStringEndsWith('.json', $file);
		}
	}

	public function testPacksDirectoryContainsReadableFixtures(): void
	{
		$seeder = new BuiltinProcedurePackSeeder(
			$this->createMock(ProcedureService::class),
			$this->createMock(ProcedureMapper::class),
			new PackSchema(new ShowIfEvaluator()),
			new NullLogger(),
		);
		$dir = $seeder->packsDirectory();
		$this->assertDirectoryExists($dir);
		foreach (BuiltinProcedurePackSeeder::PACK_FILES as $file) {
			$this->assertFileIsReadable($dir . '/' . $file, $file);
		}
	}

	public function testEnsureInstalledSkipsWhenAlreadyPresent(): void
	{
		$procedures = $this->createMock(ProcedureService::class);
		$procedures->expects($this->never())->method('importPack');

		$mapper = $this->createMock(ProcedureMapper::class);
		$mapper->method('findBySourcePack')->willReturn([['id' => 1]]);

		$seeder = new BuiltinProcedurePackSeeder(
			$procedures,
			$mapper,
			new PackSchema(new ShowIfEvaluator()),
			new NullLogger(),
		);
		$result = $seeder->ensureInstalled();
		$this->assertSame([], $result['installed']);
		$this->assertCount(15, $result['skipped']);
		$this->assertSame([], $result['failed']);
	}

	public function testEnsureInstalledImportsMissingPacks(): void
	{
		$procedures = $this->createMock(ProcedureService::class);
		$procedures->expects($this->exactly(15))->method('importPack')->willReturn([
			'packCode' => 'x',
			'imported' => 1,
			'replaced' => 0,
			'forkedAside' => 0,
		]);

		$mapper = $this->createMock(ProcedureMapper::class);
		$mapper->method('findBySourcePack')->willReturn([]);

		$seeder = new BuiltinProcedurePackSeeder(
			$procedures,
			$mapper,
			new PackSchema(new ShowIfEvaluator()),
			new NullLogger(),
		);
		$result = $seeder->ensureInstalled();
		$this->assertCount(15, $result['installed']);
		$this->assertSame([], $result['skipped']);
		$this->assertSame([], $result['failed']);
	}
}
