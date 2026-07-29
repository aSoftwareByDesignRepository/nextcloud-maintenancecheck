<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use PHPUnit\Framework\TestCase;

/** AC-W1-8 — mn_procedure_pack_v1 schema + builtin seeds. */
final class PackSchemaTest extends TestCase
{
	private PackSchema $schema;
	private string $packsDir;

	protected function setUp(): void
	{
		$this->schema = new PackSchema(new ShowIfEvaluator());
		$this->packsDir = dirname(__DIR__, 3) . '/data/procedure-packs';
	}

	public function testBuiltinPacksExistAndParse(): void
	{
		$files = [
			'builtin-shk-v1.json',
			'builtin-security-v1.json',
			'builtin-electro-v1.json',
			'builtin-facility-v1.json',
			'builtin-hvac-v1.json',
			'builtin-industrial-v1.json',
			'builtin-shk-de-v1.json',
			'builtin-security-de-v1.json',
			'builtin-electro-de-v1.json',
		];
		$this->assertCount(9, $files);
		$locales = [];
		$verticals = [];
		foreach ($files as $file) {
			$path = $this->packsDir . '/' . $file;
			$this->assertFileExists($path, $file);
			$raw = (string)file_get_contents($path);
			$parsed = $this->schema->parse($raw);
			$this->assertNotSame('', $parsed['packCode']);
			$this->assertNotEmpty($parsed['procedures']);
			$this->assertNotSame('', $parsed['vertical']);
			$locales[$parsed['locale']] = true;
			$verticals[$parsed['vertical']] = true;
		}
		$this->assertArrayHasKey('en', $locales);
		$this->assertArrayHasKey('de', $locales);
		foreach (['shk', 'security', 'electro', 'facility', 'hvac', 'industrial'] as $vertical) {
			$this->assertArrayHasKey($vertical, $verticals, $vertical);
		}
	}

	public function testRejectsWrongFormat(): void
	{
		$this->expectException(ValidationException::class);
		$this->schema->parse(json_encode([
			'format' => 'nope',
			'pack_code' => 'x',
			'procedures' => [['code' => 'p', 'title' => 'T', 'items' => [['code' => 'i', 'label' => 'L']]]],
		], JSON_THROW_ON_ERROR));
	}

	public function testRejectsOversizeRaw(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->schema->parse(str_repeat('x', PackSchema::MAX_RAW_BYTES + 1));
		} catch (ValidationException $e) {
			$this->assertSame('pack_too_large', $e->getErrorCode());
			throw $e;
		}
	}

	public function testBuildRoundTrip(): void
	{
		$built = $this->schema->build('demo', 'shk', 'en', 1, [[
			'code' => 'p1',
			'title' => 'Demo',
			'items' => [[
				'code' => 'i1',
				'label' => 'Step',
				'required' => true,
				'sortOrder' => 1,
				'showIfItemCode' => null,
				'showIfResult' => null,
			]],
		]]);
		$parsed = $this->schema->validate($built);
		$this->assertSame('demo', $parsed['packCode']);
		$this->assertSame('shk', $parsed['vertical']);
		$this->assertSame('p1', $parsed['procedures'][0]['code']);
	}

	public function testShowIfCycleInPackBubbles(): void
	{
		$this->expectException(ValidationException::class);
		$this->schema->parse(json_encode([
			'format' => 'mn_procedure_pack_v1',
			'pack_code' => 'bad-cycle',
			'procedures' => [[
				'code' => 'p',
				'title' => 'Bad',
				'items' => [
					['code' => 'a', 'label' => 'A', 'show_if_item_code' => 'b', 'show_if_result' => 'ok'],
					['code' => 'b', 'label' => 'B', 'show_if_item_code' => 'a', 'show_if_result' => 'ok'],
				],
			]],
		], JSON_THROW_ON_ERROR));
	}
}
