<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WoPhoto;
use OCA\MaintenanceCheck\Db\WoPhotoMapper;
use OCA\MaintenanceCheck\Db\WoSignature;
use OCA\MaintenanceCheck\Db\WoSignatureMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W1 photos + W3 signature on a work order. Binaries live in
 * {@see EvidenceStorage}; rows here are the index. Photo/signature writes
 * are allowed while the WO is open; the signature additionally while `done`
 * (UC-SB: captured on the Done dialog, sometimes right after closing).
 */
class WoEvidenceService
{
	public function __construct(
		private readonly WorkOrderMapper $workOrders,
		private readonly WoPhotoMapper $photos,
		private readonly WoSignatureMapper $signatures,
		private readonly EvidenceStorage $storage,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly WorkOrderAccessPolicy $woAccess,
	) {
	}

	// ── Photos ──────────────────────────────────────────────────────────

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listPhotos(int $workOrderId, ?string $viewerUid = null): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($viewerUid !== null) {
			$this->woAccess->assertCanExecute($viewerUid, $wo);
		}
		return array_map(static fn (WoPhoto $p) => $p->toApi(), $this->photos->findByWorkOrder($workOrderId));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function addPhoto(string $uid, int $workOrderId, string $content, ?string $originalName): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		$this->woAccess->assertCanExecute($uid, $wo);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}
		if ($this->photos->countForWorkOrder($workOrderId) >= EvidenceStorage::MAX_PHOTOS_PER_WO) {
			throw new ValidationException('photo_limit_reached', 'At most ' . EvidenceStorage::MAX_PHOTOS_PER_WO . ' photos per work order.');
		}

		$stored = $this->storage->storePhoto($workOrderId, $content);
		$originalName = $originalName !== null ? mb_substr(trim($originalName), 0, 255) : null;

		$photo = new WoPhoto();
		$photo->setWorkOrderId($workOrderId);
		$photo->setFileName($stored['fileName']);
		$photo->setOriginalName($originalName !== '' ? $originalName : null);
		$photo->setMime($stored['mime']);
		$photo->setSizeBytes($stored['sizeBytes']);
		$photo->setCreatedAt($this->clock->now());
		$photo->setCreatedBy($uid);
		try {
			return $this->photos->insert($photo)->toApi();
		} catch (\Throwable $e) {
			// Never leave an orphaned binary behind a failed row insert.
			$this->storage->deletePhoto($workOrderId, $stored['fileName']);
			throw $e;
		}
	}

	/**
	 * @return array{content: string, mime: string, name: string}
	 */
	public function readPhoto(int $workOrderId, int $photoId): array
	{
		$photo = $this->photos->findById($photoId);
		if ($photo->getWorkOrderId() !== $workOrderId) {
			throw new NotFoundException();
		}
		return [
			'content' => $this->storage->readPhoto($workOrderId, $photo->getFileName()),
			'mime' => $photo->getMime(),
			'name' => $photo->getOriginalName() ?? $photo->getFileName(),
		];
	}

	public function deletePhoto(int $workOrderId, int $photoId, ?string $uid = null): void
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($uid !== null) {
			$this->woAccess->assertCanExecute($uid, $wo);
		}
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}
		$photo = $this->photos->findById($photoId);
		if ($photo->getWorkOrderId() !== $workOrderId) {
			throw new NotFoundException();
		}
		// Row first, then binary — a stray binary is harmless, a stray row
		// would 500 on download.
		$this->photos->delete($photo);
		$this->storage->deletePhoto($workOrderId, $photo->getFileName());
	}

	// ── Signature (W3, UC-SB) ───────────────────────────────────────────

	/**
	 * Store/replace the signature. Accepted while open or `done` — never on
	 * a cancelled WO.
	 *
	 * @return array<string, mixed>
	 */
	public function setSignature(string $uid, int $workOrderId, string $pngContent, ?string $signerName): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		$this->woAccess->assertCanExecute($uid, $wo);
		if ($wo->getStatus() === WorkOrder::STATUS_CANCELLED) {
			throw new ConflictException('invalid_status', 'This work order was cancelled.');
		}
		$signerName = $signerName !== null ? mb_substr(trim($signerName), 0, 128) : null;

		$stored = $this->storage->storeSignature($workOrderId, $pngContent);
		$existing = $this->signatures->findByWorkOrder($workOrderId);
		if ($existing !== null) {
			$existing->setFileName($stored['fileName']);
			$existing->setSizeBytes($stored['sizeBytes']);
			$existing->setSignerName($signerName !== '' ? $signerName : null);
			$existing->setCreatedAt($this->clock->now());
			$existing->setCreatedBy($uid);
			return $this->signatures->update($existing)->toApi();
		}

		$signature = new WoSignature();
		$signature->setWorkOrderId($workOrderId);
		$signature->setFileName($stored['fileName']);
		$signature->setSizeBytes($stored['sizeBytes']);
		$signature->setSignerName($signerName !== '' ? $signerName : null);
		$signature->setCreatedAt($this->clock->now());
		$signature->setCreatedBy($uid);
		try {
			return $this->signatures->insert($signature)->toApi();
		} catch (\OCP\DB\Exception $e) {
			// Concurrent first capture: the unique index held — update the
			// winner's row instead.
			$existing = $this->signatures->findByWorkOrder($workOrderId);
			if ($existing === null) {
				throw $e;
			}
			$existing->setFileName($stored['fileName']);
			$existing->setSizeBytes($stored['sizeBytes']);
			$existing->setSignerName($signerName !== '' ? $signerName : null);
			$existing->setCreatedAt($this->clock->now());
			$existing->setCreatedBy($uid);
			return $this->signatures->update($existing)->toApi();
		}
	}

	/**
	 * @return array{content: string, mime: string, name: string}
	 */
	public function readSignature(int $workOrderId): array
	{
		$signature = $this->signatures->findByWorkOrder($workOrderId);
		if ($signature === null) {
			throw new NotFoundException();
		}
		return [
			'content' => $this->storage->readSignature($workOrderId, $signature->getFileName()),
			'mime' => 'image/png',
			'name' => 'signature-' . $workOrderId . '.png',
		];
	}
}
