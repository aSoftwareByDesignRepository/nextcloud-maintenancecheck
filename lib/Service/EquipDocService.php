<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\EquipDoc;
use OCA\MaintenanceCheck\Db\EquipDocMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;

/**
 * W6 equipment documents (CORE §20 W6-R2, AC-W6-4) — max 20 per asset;
 * requires file_id and/or external_url.
 *
 * Files attachments are ACL-checked for the attaching actor, then materialised
 * into appdata so seated technicians can download without a Files share.
 */
class EquipDocService
{
	public function __construct(
		private readonly EquipDocMapper $docs,
		private readonly EquipmentMapper $equipment,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly IRootFolder $rootFolder,
		private readonly EquipDocStorage $storage,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function listForEquipment(int $equipmentId): array
	{
		$this->equipment->findById($equipmentId);
		return [
			'data' => array_map(static fn (EquipDoc $d) => $d->toApi(), $this->docs->findByEquipment($equipmentId)),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $equipmentId, array $body): array
	{
		$this->equipment->findById($equipmentId);
		if ($this->docs->countForEquipment($equipmentId) >= EquipDoc::MAX_PER_EQUIPMENT) {
			throw new ConflictException('docs_limit', 'An equipment may have at most ' . EquipDoc::MAX_PER_EQUIPMENT . ' documents.');
		}

		$title = $this->validator->requiredString($body, 'title', 'title_required', 255, 'title_too_long');
		$fileId = null;
		$sourceFile = null;
		if (array_key_exists('fileId', $body) && $body['fileId'] !== null && $body['fileId'] !== '') {
			if (!is_int($body['fileId']) && !(is_string($body['fileId']) && preg_match('/^\d+$/', $body['fileId']))) {
				throw new ValidationException('validation_failed', 'fileId must be a positive integer.', [
					['field' => 'fileId', 'code' => 'invalid_type'],
				]);
			}
			$fileId = (int)$body['fileId'];
			if ($fileId <= 0) {
				throw new ValidationException('validation_failed', 'fileId must be a positive integer.', [
					['field' => 'fileId', 'code' => 'invalid_value'],
				]);
			}
			$sourceFile = $this->assertReadableFile($uid, $fileId);
		}
		$url = $this->validator->boundedOptionalString($body, 'externalUrl', 2048, 'url_too_long');
		if ($url !== null && $url !== '') {
			if (!preg_match('#^https?://#i', $url)) {
				throw new ValidationException('validation_failed', 'externalUrl must start with http:// or https://.', [
					['field' => 'externalUrl', 'code' => 'invalid_url'],
				]);
			}
		} else {
			$url = null;
		}
		if ($fileId === null && $url === null) {
			throw new ValidationException('validation_failed', 'Provide a Files fileId or an externalUrl.', [
				['field' => 'fileId', 'code' => 'required_one_of'],
				['field' => 'externalUrl', 'code' => 'required_one_of'],
			]);
		}

		$sort = 0;
		if (array_key_exists('sortOrder', $body) && is_int($body['sortOrder'])) {
			$sort = $body['sortOrder'];
		}

		$doc = new EquipDoc();
		$doc->setEquipmentId($equipmentId);
		$doc->setTitle($title);
		$doc->setFileId($fileId);
		$doc->setExternalUrl($url);
		$doc->setSortOrder($sort);
		$doc->setCreatedAt($this->clock->now());
		$doc->setCreatedBy($uid);
		$inserted = $this->docs->insert($doc);
		if ($sourceFile !== null) {
			$this->storage->storeFromFile((int)$inserted->getId(), $sourceFile);
		}
		return $inserted->toApi();
	}

	/**
	 * Actor-scoped Files resolution — never trust a raw file id alone (confused deputy).
	 */
	private function assertReadableFile(string $uid, int $fileId): File
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$nodes = $userFolder->getById($fileId);
		} catch (NotPermittedException | FilesNotFoundException) {
			throw new ValidationException('validation_failed', 'fileId is not readable in your Files.', [
				['field' => 'fileId', 'code' => 'file_not_readable'],
			]);
		}
		if ($nodes === []) {
			throw new ValidationException('validation_failed', 'fileId is not readable in your Files.', [
				['field' => 'fileId', 'code' => 'file_not_readable'],
			]);
		}
		$node = $nodes[0];
		if ($node->getType() === FileInfo::TYPE_FOLDER) {
			throw new ValidationException('validation_failed', 'fileId must point to a file, not a folder.', [
				['field' => 'fileId', 'code' => 'not_a_file'],
			]);
		}
		if (!$node instanceof File) {
			throw new ValidationException('validation_failed', 'fileId is not readable in your Files.', [
				['field' => 'fileId', 'code' => 'file_not_readable'],
			]);
		}
		return $node;
	}

	/**
	 * Download payload for a document row.
	 * Prefers materialised appdata (any API-authorised actor). Falls back to
	 * actor Files ACL for legacy rows that were never copied.
	 *
	 * @return array{content: string, name: string, mime: string}
	 */
	public function readFileForDownload(string $uid, int $docId): array
	{
		$doc = $this->docs->findById($docId);
		$fileId = $doc->getFileId();
		if ($fileId === null || $fileId <= 0) {
			throw new ValidationException('validation_failed', 'This document has no Files attachment — open the external URL instead.', [
				['field' => 'fileId', 'code' => 'no_file'],
			]);
		}

		$cached = $this->storage->tryRead((int)$doc->getId());
		if ($cached !== null) {
			return $cached;
		}

		// Legacy: materialise on first successful ACL read so the next tech succeeds.
		try {
			$file = $this->assertReadableFile($uid, $fileId);
			$this->storage->storeFromFile((int)$doc->getId(), $file);
			$cached = $this->storage->tryRead((int)$doc->getId());
			if ($cached !== null) {
				return $cached;
			}
			$content = $file->getContent();
			$name = $file->getName();
			if ($name === '') {
				$name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $doc->getTitle()) ?: 'document';
			}
			$mime = $file->getMimeType() ?: 'application/octet-stream';
			return [
				'content' => $content,
				'name' => $name,
				'mime' => $mime,
			];
		} catch (ValidationException) {
			throw new ValidationException(
				'validation_failed',
				'This document is not shared with your account. Ask office to re-attach it (or add an external URL).',
				[['field' => 'fileId', 'code' => 'file_not_readable']],
			);
		}
	}

	public function delete(int $id): void
	{
		$doc = $this->docs->findById($id);
		$this->storage->delete((int)$doc->getId());
		$this->docs->delete($doc);
	}
}
