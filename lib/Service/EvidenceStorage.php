<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

/**
 * WO evidence binaries in appdata (photos W1, signature PNGs W3).
 *
 * File names are server-generated (S12 pattern) — original names are stored
 * as metadata only and never used as paths. Content is verified to actually
 * be the claimed image type before persisting; the client-supplied MIME is
 * advisory only.
 *
 * Layout: appdata_<instance>/maintenancecheck/wo-photos/<woId>/<name>
 *         appdata_<instance>/maintenancecheck/wo-signatures/<woId>.png
 * (both removed wholesale by UninstallDropTables).
 */
class EvidenceStorage
{
	public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
	/** CORE §12.7: signature canvas PNG ≤ 200 KB. */
	public const MAX_SIGNATURE_BYTES = 200 * 1024;
	/** Defensive cap so a WO can never become an unbounded file sink. */
	public const MAX_PHOTOS_PER_WO = 50;

	public const PHOTO_MIMES = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
	];

	private const PHOTOS_ROOT = 'wo-photos';
	private const SIGNATURES_ROOT = 'wo-signatures';

	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private readonly ISecureRandom $random,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	// ── Photos (W1) ─────────────────────────────────────────────────────

	/**
	 * @return array{fileName: string, mime: string, sizeBytes: int}
	 * @throws ValidationException `invalid_photo` / `photo_too_large`
	 */
	public function storePhoto(int $workOrderId, string $content): array
	{
		if ($content === '') {
			throw new ValidationException('invalid_photo', 'The uploaded file is empty.');
		}
		if (strlen($content) > self::MAX_PHOTO_BYTES) {
			throw new ValidationException('photo_too_large', 'Photos may be at most 10 MB.');
		}
		$mime = $this->sniffImageMime($content);
		if ($mime === null || !isset(self::PHOTO_MIMES[$mime])) {
			throw new ValidationException('invalid_photo', 'Only JPEG, PNG, or WebP images are accepted.');
		}

		$fileName = sprintf(
			'p-%d-%s.%s',
			$workOrderId,
			$this->random->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS),
			self::PHOTO_MIMES[$mime],
		);
		$this->folder(self::PHOTOS_ROOT . '/' . $workOrderId)->newFile($fileName, $content);

		return ['fileName' => $fileName, 'mime' => $mime, 'sizeBytes' => strlen($content)];
	}

	public function readPhoto(int $workOrderId, string $fileName): string
	{
		return $this->read(self::PHOTOS_ROOT . '/' . $workOrderId, $fileName);
	}

	public function deletePhoto(int $workOrderId, string $fileName): void
	{
		$this->delete(self::PHOTOS_ROOT . '/' . $workOrderId, $fileName);
	}

	// ── Signatures (W3) ─────────────────────────────────────────────────

	/**
	 * @return array{fileName: string, sizeBytes: int}
	 * @throws ValidationException `invalid_signature` / `signature_too_large`
	 */
	public function storeSignature(int $workOrderId, string $pngContent): array
	{
		if (strlen($pngContent) > self::MAX_SIGNATURE_BYTES) {
			throw new ValidationException('signature_too_large', 'The signature image may be at most 200 KB.');
		}
		if ($this->sniffImageMime($pngContent) !== 'image/png') {
			throw new ValidationException('invalid_signature', 'The signature must be a PNG image.');
		}
		$fileName = $workOrderId . '.png';
		$folder = $this->folder(self::SIGNATURES_ROOT);
		try {
			// Overwrite-safe: re-signing replaces the previous capture.
			$folder->getFile($fileName)->putContent($pngContent);
		} catch (FilesNotFoundException) {
			$folder->newFile($fileName, $pngContent);
		}
		return ['fileName' => $fileName, 'sizeBytes' => strlen($pngContent)];
	}

	public function readSignature(int $workOrderId, string $fileName): string
	{
		return $this->read(self::SIGNATURES_ROOT, $fileName);
	}

	public function deleteSignature(int $workOrderId, string $fileName): void
	{
		$this->delete(self::SIGNATURES_ROOT, $fileName);
	}

	// ── Cleanup ─────────────────────────────────────────────────────────

	/**
	 * Remove every binary belonging to a WO (photo folder + signature).
	 * Missing nodes are fine — cleanup must be idempotent.
	 */
	public function deleteAllForWorkOrder(int $workOrderId): void
	{
		try {
			$this->appData->getFolder(self::PHOTOS_ROOT . '/' . $workOrderId)->delete();
		} catch (FilesNotFoundException) {
			// nothing stored
		}
		try {
			$this->appData->getFolder(self::SIGNATURES_ROOT)->getFile($workOrderId . '.png')->delete();
		} catch (FilesNotFoundException) {
			// nothing stored
		}
	}

	// ── Internals ───────────────────────────────────────────────────────

	private function folder(string $path): ISimpleFolder
	{
		try {
			return $this->appData->getFolder($path);
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder($path);
		}
	}

	private function read(string $path, string $fileName): string
	{
		$this->assertSafeName($fileName);
		try {
			return $this->appData->getFolder($path)->getFile($fileName)->getContent();
		} catch (FilesNotFoundException) {
			throw new NotFoundException();
		}
	}

	private function delete(string $path, string $fileName): void
	{
		$this->assertSafeName($fileName);
		try {
			$this->appData->getFolder($path)->getFile($fileName)->delete();
		} catch (FilesNotFoundException) {
			// already gone — deletion is idempotent
		}
	}

	/**
	 * File names come from our own DB rows, but re-validate anyway so a
	 * corrupted row can never traverse paths.
	 */
	private function assertSafeName(string $fileName): void
	{
		if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,127}$/', $fileName) || str_contains($fileName, '..')) {
			throw new NotFoundException();
		}
	}

	/**
	 * Magic-byte sniffing — never trust client MIME.
	 */
	private function sniffImageMime(string $content): ?string
	{
		if (str_starts_with($content, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}
		if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
			return 'image/png';
		}
		if (strlen($content) >= 12
			&& str_starts_with($content, 'RIFF')
			&& substr($content, 8, 4) === 'WEBP'
		) {
			return 'image/webp';
		}
		return null;
	}
}
