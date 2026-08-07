<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\EquipDocStorage;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

final class EquipDocStorageTest extends TestCase
{
	public function testStoreFromFileRejectsEmpty(): void
	{
		$storage = $this->storageWithAppData($this->createMock(IAppData::class));
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('');
		try {
			$storage->storeFromFile(1, $file);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('empty_file', $codes);
		}
	}

	public function testStoreFromFileRejectsInvalidDocId(): void
	{
		$storage = $this->storageWithAppData($this->createMock(IAppData::class));
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('x');
		try {
			$storage->storeFromFile(0, $file);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$codes = array_column($e->getDetails(), 'code');
			$this->assertContains('invalid_value', $codes);
		}
	}

	public function testTryReadReturnsNullWhenMissing(): void
	{
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new FilesNotFoundException());
		$storage = $this->storageWithAppData($appData);
		$this->assertNull($storage->tryRead(9));
		$this->assertNull($storage->tryRead(0));
	}

	public function testStoreAndTryReadRoundTrip(): void
	{
		$mem = new class {
			/** @var array<string, string> */
			public array $files = [];
		};
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($mem) {
			if (!isset($mem->files[$name])) {
				throw new FilesNotFoundException();
			}
			$f = $this->createMock(ISimpleFile::class);
			$f->method('getContent')->willReturnCallback(static fn () => $mem->files[$name]);
			$f->method('putContent')->willReturnCallback(static function (string $c) use ($mem, $name): void {
				$mem->files[$name] = $c;
			});
			return $f;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, string $content) use ($mem) {
			$mem->files[$name] = $content;
			$f = $this->createMock(ISimpleFile::class);
			$f->method('getContent')->willReturnCallback(static fn () => $mem->files[$name]);
			return $f;
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appData->method('newFolder')->willReturn($folder);
		$storage = $this->storageWithAppData($appData);

		$ncFile = $this->createMock(File::class);
		$ncFile->method('getContent')->willReturn('%PDF-x');
		$ncFile->method('getName')->willReturn('a.pdf');
		$ncFile->method('getMimeType')->willReturn('application/pdf');

		$metaOut = $storage->storeFromFile(3, $ncFile);
		$this->assertSame('a.pdf', $metaOut['name']);
		$this->assertSame(6, $metaOut['sizeBytes']);
		$read = $storage->tryRead(3);
		$this->assertNotNull($read);
		$this->assertSame('%PDF-x', $read['content']);
		$this->assertSame('application/pdf', $read['mime']);
		$this->assertSame('a.pdf', $read['name']);
	}

	public function testDeleteIsIdempotentWhenMissing(): void
	{
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new FilesNotFoundException());
		$storage = $this->storageWithAppData($appData);
		$storage->delete(0);
		$storage->delete(42);
		$this->addToAssertionCount(1);
	}

	private function storageWithAppData(IAppData $appData): EquipDocStorage
	{
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		return new EquipDocStorage($factory);
	}
}
