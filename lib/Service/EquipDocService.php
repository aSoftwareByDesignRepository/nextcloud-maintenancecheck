<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\EquipDoc;
use OCA\MaintenanceCheck\Db\EquipDocMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W6 equipment documents (CORE §20 W6-R2, AC-W6-4) — max 20 per asset;
 * requires file_id and/or external_url.
 */
class EquipDocService
{
	public function __construct(
		private readonly EquipDocMapper $docs,
		private readonly EquipmentMapper $equipment,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
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
		return $this->docs->insert($doc)->toApi();
	}

	public function delete(int $id): void
	{
		$doc = $this->docs->findById($id);
		$this->docs->delete($doc);
	}
}
