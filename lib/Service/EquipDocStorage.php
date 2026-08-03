<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Materialised equipment-document binaries (W6-R2 / AC-W6-4 / AC-C16).
 *
 * Office attaches a Files fileId they can read; we copy bytes into appdata so
 * any seated technician authorised for the equipment API can download without
 * needing a Files share (confused-deputy-safe: attach still requires actor ACL).
 *
 * Layout: appdata_<instance>/maintenancecheck/equip-docs/<docId>/blob
 */
class EquipDocStorage
{
	public const MAX_BYTES = 20 * 1024 * 1024;
	private const ROOT = 'equip-docs';
	private const BLOB = 'blob';
	private const META = 'meta.json';

	private IAppData $appData;

	public function __construct(IAppDataFactory $appDataFactory)
	{
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * @return array{name: string, mime: string, sizeBytes: int}
	 */
	public function storeFromFile(int $docId, File $file): array
	{
		if ($docId <= 0) {
			throw new ValidationException('validation_failed', 'Document id is required before storing the file.', [
				['field' => 'id', 'code' => 'invalid_value'],
			]);
		}
		$content = $file->getContent();
		if ($content === '') {
			throw new ValidationException('validation_failed', 'The attached file is empty.', [
				['field' => 'fileId', 'code' => 'empty_file'],
			]);
		}
		if (strlen($content) > self::MAX_BYTES) {
			throw new ValidationException('validation_failed', 'Equipment documents may be at most 20 MB.', [
				['field' => 'fileId', 'code' => 'file_too_large'],
			]);
		}
		$name = $file->getName();
		if ($name === '') {
			$name = 'document';
		}
		$mime = $file->getMimeType() ?: 'application/octet-stream';
		$folder = $this->folder(self::ROOT . '/' . $docId);
		try {
			$folder->getFile(self::BLOB)->putContent($content);
		} catch (FilesNotFoundException) {
			$folder->newFile(self::BLOB, $content);
		}
		$meta = json_encode(['name' => $name, 'mime' => $mime], JSON_THROW_ON_ERROR);
		try {
			$folder->getFile(self::META)->putContent($meta);
		} catch (FilesNotFoundException) {
			$folder->newFile(self::META, $meta);
		}
		return ['name' => $name, 'mime' => $mime, 'sizeBytes' => strlen($content)];
	}

	/**
	 * @return array{content: string, name: string, mime: string}|null
	 */
	public function tryRead(int $docId): ?array
	{
		if ($docId <= 0) {
			return null;
		}
		try {
			$folder = $this->appData->getFolder(self::ROOT . '/' . $docId);
			$content = $folder->getFile(self::BLOB)->getContent();
		} catch (FilesNotFoundException) {
			return null;
		}
		$name = 'document';
		$mime = 'application/octet-stream';
		try {
			$raw = $folder->getFile(self::META)->getContent();
			/** @var array{name?: string, mime?: string} $decoded */
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			if (is_string($decoded['name'] ?? null) && $decoded['name'] !== '') {
				$name = $decoded['name'];
			}
			if (is_string($decoded['mime'] ?? null) && $decoded['mime'] !== '') {
				$mime = $decoded['mime'];
			}
		} catch (\Throwable) {
			// Meta optional — blob alone is enough.
		}
		return ['content' => $content, 'name' => $name, 'mime' => $mime];
	}

	public function delete(int $docId): void
	{
		if ($docId <= 0) {
			return;
		}
		try {
			$this->appData->getFolder(self::ROOT . '/' . $docId)->delete();
		} catch (FilesNotFoundException) {
			// already gone
		}
	}

	private function folder(string $path): ISimpleFolder
	{
		try {
			return $this->appData->getFolder($path);
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder($path);
		}
	}
}
