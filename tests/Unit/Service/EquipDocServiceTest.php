<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\EquipDocMapper;
use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCA\MaintenanceCheck\Service\EquipDocStorage;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W6-R2: equipment document fileId must be readable by the attaching actor;
 * downloads prefer materialised appdata so techs are not stuck without a share.
 */
final class EquipDocServiceTest extends TestCase
{
	private EquipDocMapper&MockObject $docs;
	private EquipmentMapper&MockObject $equipment;
	private IRootFolder&MockObject $root;
	private Folder&MockObject $userFolder;
	private EquipDocStorage&MockObject $storage;
	private EquipDocService $service;

	protected function setUp(): void
	{
		$this->docs = $this->createMock(EquipDocMapper::class);
		$this->equipment = $this->createMock(EquipmentMapper::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->storage = $this->createMock(EquipDocStorage::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_700_000_000);
		$equip = new Equipment();
		$equip->setId(5);
		$this->equipment->method('findById')->with(5)->willReturn($equip);
		$this->docs->method('countForEquipment')->willReturn(0);
		$this->root->method('getUserFolder')->with('tech')->willReturn($this->userFolder);

		$this->service = new EquipDocService(
			$this->docs,
			$this->equipment,
			new InputValidator(new IntervalCalculator()),
			$clock,
			$this->root,
			$this->storage,
		);
	}

	public function testCreateRejectsUnreadableFileId(): void
	{
		$this->userFolder->method('getById')->with(999)->willReturn([]);
		$this->storage->expects($this->never())->method('storeFromFile');
		try {
			$this->service->create('tech', 5, [
				'title' => 'Manual',
				'fileId' => 999,
			]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('file_not_readable', $codes);
		}
	}

	public function testCreateRejectsFolderFileId(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
		$this->userFolder->method('getById')->with(42)->willReturn([$folder]);
		try {
			$this->service->create('tech', 5, [
				'title' => 'Folder',
				'fileId' => 42,
			]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('not_a_file', $codes);
		}
	}

	public function testCreateAcceptsExternalUrlWithoutFilesLookup(): void
	{
		$this->userFolder->expects($this->never())->method('getById');
		$this->storage->expects($this->never())->method('storeFromFile');
		$this->docs->expects($this->once())->method('insert')->willReturnCallback(
			static function ($doc) {
				$doc->setId(7);
				return $doc;
			},
		);
		$row = $this->service->create('tech', 5, [
			'title' => 'Vendor PDF',
			'externalUrl' => 'https://example.com/manual.pdf',
		]);
		$this->assertSame(7, $row['id']);
		$this->assertSame('https://example.com/manual.pdf', $row['externalUrl']);
	}

	public function testCreateMaterialisesReadableFileIntoAppData(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getContent')->willReturn('%PDF-office');
		$file->method('getName')->willReturn('Manual.pdf');
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->userFolder->method('getById')->with(11)->willReturn([$file]);
		$this->docs->expects($this->once())->method('insert')->willReturnCallback(
			static function ($doc) {
				$doc->setId(8);
				return $doc;
			},
		);
		$this->storage->expects($this->once())->method('storeFromFile')->with(8, $file);
		$row = $this->service->create('tech', 5, [
			'title' => 'Stored manual',
			'fileId' => 11,
		]);
		$this->assertSame(8, $row['id']);
		$this->assertSame(11, $row['fileId']);
	}

	public function testReadFileForDownloadUsesMaterialisedBlobWithoutActorAcl(): void
	{
		$doc = new \OCA\MaintenanceCheck\Db\EquipDoc();
		$doc->setId(3);
		$doc->setTitle('Manual.pdf');
		$doc->setFileId(11);
		$this->docs->method('findById')->with(3)->willReturn($doc);
		$this->userFolder->expects($this->never())->method('getById');
		$this->storage->method('tryRead')->with(3)->willReturn([
			'content' => '%PDF-cached',
			'name' => 'Manual.pdf',
			'mime' => 'application/pdf',
		]);

		$out = $this->service->readFileForDownload('other-tech', 3);
		$this->assertSame('%PDF-cached', $out['content']);
		$this->assertSame('Manual.pdf', $out['name']);
		$this->assertSame('application/pdf', $out['mime']);
	}

	public function testReadFileForDownloadLegacyMaterialisesWhenActorCanRead(): void
	{
		$doc = new \OCA\MaintenanceCheck\Db\EquipDoc();
		$doc->setId(3);
		$doc->setTitle('Manual.pdf');
		$doc->setFileId(11);
		$this->docs->method('findById')->with(3)->willReturn($doc);

		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getContent')->willReturn('%PDF-1.4 mock');
		$file->method('getName')->willReturn('Manual.pdf');
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->userFolder->method('getById')->with(11)->willReturn([$file]);

		$this->storage->method('tryRead')->willReturnOnConsecutiveCalls(
			null,
			[
				'content' => '%PDF-1.4 mock',
				'name' => 'Manual.pdf',
				'mime' => 'application/pdf',
			],
		);
		$this->storage->expects($this->once())->method('storeFromFile')->with(3, $file);

		$out = $this->service->readFileForDownload('tech', 3);
		$this->assertSame('%PDF-1.4 mock', $out['content']);
	}

	public function testReadFileForDownloadRejectsExternalOnlyDoc(): void
	{
		$doc = new \OCA\MaintenanceCheck\Db\EquipDoc();
		$doc->setId(4);
		$doc->setTitle('Vendor link');
		$doc->setFileId(null);
		$doc->setExternalUrl('https://example.com/x.pdf');
		$this->docs->method('findById')->with(4)->willReturn($doc);
		$this->userFolder->expects($this->never())->method('getById');
		try {
			$this->service->readFileForDownload('tech', 4);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('no_file', $codes);
		}
	}

	public function testReadFileForDownloadRejectsUnreadableLegacyWithoutCache(): void
	{
		$doc = new \OCA\MaintenanceCheck\Db\EquipDoc();
		$doc->setId(5);
		$doc->setTitle('Secret');
		$doc->setFileId(999);
		$this->docs->method('findById')->with(5)->willReturn($doc);
		$this->storage->method('tryRead')->with(5)->willReturn(null);
		$this->userFolder->method('getById')->with(999)->willReturn([]);
		try {
			$this->service->readFileForDownload('tech', 5);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('file_not_readable', $codes);
			$this->assertStringContainsString('Ask office to re-attach', $e->getMessage());
		}
	}
}
