<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WoSignatureMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Service\EvidenceStorage;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/** AC-W3-3: Servicebericht must render checklist `fail` (not legacy `not_ok`). */
final class WoPdfServiceTest extends TestCase
{
	private WoPdfService $pdf;

	protected function setUp(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []) {
			if ($params === []) {
				return $text;
			}
			return vsprintf(str_replace(['%1$s', '%2$s', '%s'], ['%s', '%s', '%s'], $text), $params);
		});
		$this->pdf = new WoPdfService(
			$this->createMock(WorkOrderMapper::class),
			$this->createMock(WorkOrderService::class),
			$this->createMock(WoSignatureMapper::class),
			$this->createMock(EvidenceStorage::class),
			$l10n,
		);
	}

	public function testServiceberichtMapsFailResult(): void
	{
		$html = $this->invokeHtml([
			'id' => 1,
			'number' => 'WO-1',
			'title' => 'Boiler service',
			'kind' => 'preventive',
			'status' => 'done',
			'customerName' => 'Acme',
			'equipmentLabel' => 'Boiler',
			'doneOn' => '2026-07-01',
			'actualMinutes' => 60,
			'checklist' => [
				[
					'code' => 'leak',
					'label' => 'Leak found?',
					'required' => true,
					'requiredEffective' => true,
					'visible' => true,
					'result' => 'fail',
					'note' => 'Under valve',
				],
				[
					'code' => 'ok_item',
					'label' => 'Filters OK',
					'required' => true,
					'requiredEffective' => true,
					'visible' => true,
					'result' => 'ok',
					'note' => '',
				],
			],
			'signature' => null,
		], true);

		$this->assertStringContainsString('Fail', $html);
		$this->assertStringContainsString('Under valve', $html);
		$this->assertStringContainsString('class="r-fail"', $html);
		$this->assertStringNotContainsString('not_ok', $html);
		$this->assertStringContainsString('OK', $html);
		$this->assertStringContainsString('WO-1', $html);
		$this->assertStringContainsString('Acme', $html);
		$this->assertStringContainsString('Leak found?', $html);
	}

	public function testHiddenChecklistItemsAreOmitted(): void
	{
		$html = $this->invokeHtml([
			'id' => 2,
			'number' => 'WO-2',
			'title' => 'Hidden',
			'kind' => 'corrective',
			'status' => 'done',
			'customerName' => 'Acme',
			'equipmentLabel' => null,
			'doneOn' => '2026-07-01',
			'actualMinutes' => null,
			'checklist' => [
				[
					'code' => 'hidden',
					'label' => 'Should not appear',
					'required' => true,
					'visible' => false,
					'result' => 'fail',
				],
			],
			'signature' => null,
		], true);

		$this->assertStringNotContainsString('Should not appear', $html);
	}

	public function testServiceberichtIncludesAddressSerialAndPhotoCap(): void
	{
		$storage = $this->createMock(EvidenceStorage::class);
		$storage->method('readPhoto')->willReturn('fakepng');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []) {
			if ($params === []) {
				return $text;
			}
			return vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $params);
		});
		$pdf = new WoPdfService(
			$this->createMock(WorkOrderMapper::class),
			$this->createMock(WorkOrderService::class),
			$this->createMock(WoSignatureMapper::class),
			$storage,
			$l10n,
		);
		$ref = new \ReflectionClass($pdf);
		$method = $ref->getMethod('buildHtml');
		$method->setAccessible(true);

		$photos = [];
		for ($i = 1; $i <= 13; $i++) {
			$photos[] = [
				'id' => $i,
				'fileName' => 'p-' . $i . '.png',
				'mime' => 'image/png',
			];
		}
		$html = (string)$method->invoke($pdf, [
			'id' => 9,
			'number' => 'WO-9',
			'title' => 'Annual',
			'kind' => 'preventive',
			'status' => 'done',
			'customerName' => 'Acme GmbH',
			'siteName' => 'Plant North',
			'siteAddress' => 'Berliner Str. 1, 10115, Berlin, DE',
			'equipmentLabel' => 'Heat pump',
			'equipmentSerialNo' => 'SN-42',
			'doneOn' => '2026-07-01',
			'actualMinutes' => 45,
			'checklist' => [[
				'code' => 'filters',
				'label' => 'Filters',
				'required' => true,
				'visible' => true,
				'result' => 'ok',
			]],
			'photos' => $photos,
			'signature' => null,
		], true);

		$this->assertStringContainsString('Acme GmbH', $html);
		$this->assertStringContainsString('Berliner Str. 1', $html);
		$this->assertStringContainsString('SN-42', $html);
		$this->assertStringContainsString('Plant North', $html);
		$this->assertStringContainsString('Photos', $html);
		$this->assertStringContainsString('data:image/png;base64,', $html);
		$this->assertStringContainsString('Showing 12 of 13 photos.', $html);
		$this->assertSame(12, substr_count($html, 'class="thumb"'));
	}

	public function testServiceberichtMapsCompletedAtAndDuration(): void
	{
		$html = $this->invokeHtml([
			'id' => 3,
			'number' => 'WO-3',
			'title' => 'Done job',
			'kind' => 'corrective',
			'status' => 'done',
			'customerName' => 'Acme',
			'equipmentLabel' => 'Pump',
			'startedAt' => 1_720_000_000,
			'completedAt' => 1_720_000_000 + 5400,
			'checklist' => [[
				'code' => 'a',
				'label' => 'Step',
				'required' => true,
				'visible' => true,
				'result' => 'ok',
			]],
			'photos' => [],
			'signature' => null,
		], true);

		$this->assertStringContainsString(gmdate('Y-m-d', 1_720_000_000), $html);
		$this->assertStringContainsString('90', $html); // 5400s → 90 min
	}

	/**
	 * @param array<string, mixed> $detail
	 */
	private function invokeHtml(array $detail, bool $isReport): string
	{
		$ref = new \ReflectionClass($this->pdf);
		$method = $ref->getMethod('buildHtml');
		$method->setAccessible(true);
		return (string)$method->invoke($this->pdf, $detail, $isReport);
	}
}
