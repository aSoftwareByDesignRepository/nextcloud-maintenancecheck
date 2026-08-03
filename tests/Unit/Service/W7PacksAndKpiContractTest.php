<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\EquipmentClassService;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use PHPUnit\Framework\TestCase;

/** AC-W7-1 / W7-R14 / pack disclaimer contract. */
final class W7PacksAndKpiContractTest extends TestCase
{
	public function testW7PackFilesParseAndCarryDisclaimer(): void
	{
		$schema = new PackSchema(new ShowIfEvaluator());
		$dir = (new BuiltinProcedurePackSeeder(
			$this->createMock(\OCA\MaintenanceCheck\Service\ProcedureService::class),
			$this->createMock(\OCA\MaintenanceCheck\Db\ProcedureMapper::class),
			$schema,
			new \Psr\Log\NullLogger(),
		))->packsDirectory();
		foreach (['de-portable-electrical-v1.json', 'de-ladders-v1.json', 'de-fire-extinguisher-v1.json', 'en-portable-electrical-v1.json', 'en-ladders-v1.json', 'en-fire-extinguisher-v1.json'] as $file) {
			$raw = (string)file_get_contents($dir . '/' . $file);
			$parsed = $schema->parse($raw);
			self::assertNotSame('', $parsed['packCode']);
			$decoded = json_decode($raw, true);
			self::assertIsArray($decoded);
			self::assertArrayHasKey('pack_legal_disclaimer', $decoded);
			self::assertArrayHasKey('pack_legal_disclaimer_de', $decoded);
			self::assertArrayHasKey('pack_legal_disclaimer_en', $decoded);
			self::assertStringNotContainsStringIgnoringCase('rechtskonform', (string)$decoded['pack_legal_disclaimer']);
			self::assertStringNotContainsStringIgnoringCase('zertifiziert', (string)$decoded['pack_legal_disclaimer']);
			self::assertStringNotContainsStringIgnoringCase('Zertifikat', (string)$decoded['pack_legal_disclaimer']);
			self::assertStringNotContainsStringIgnoringCase('rechtskonform', (string)$decoded['pack_legal_disclaimer_de']);
		}
		self::assertSame('en', json_decode((string)file_get_contents($dir . '/en-ladders-v1.json'), true)['locale']);
		self::assertSame('de', json_decode((string)file_get_contents($dir . '/de-ladders-v1.json'), true)['locale']);
	}

	public function testEquipmentClassSeedHasAtLeastSix(): void
	{
		self::assertGreaterThanOrEqual(6, count(EquipmentClassService::SEED));
		$codes = array_column(EquipmentClassService::SEED, 'code');
		self::assertContains('portable_electrical', $codes);
		self::assertContains('ladder', $codes);
		self::assertContains('fire_extinguisher', $codes);
	}

	public function testPortablePackListsElectroPortableSkill(): void
	{
		$dir = dirname(__DIR__, 3) . '/data/procedure-packs';
		$raw = (string)file_get_contents($dir . '/de-portable-electrical-v1.json');
		$decoded = json_decode($raw, true);
		self::assertContains('electro_portable', $decoded['required_skills']);
	}
}
