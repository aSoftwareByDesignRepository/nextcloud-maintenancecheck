<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 evidence photo metadata. Binary lives in appdata under a
 * server-generated file name (S12 pattern); this row is the index.
 *
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method string getFileName()
 * @method void setFileName(string $v)
 * @method string|null getOriginalName()
 * @method void setOriginalName(?string $v)
 * @method string getMime()
 * @method void setMime(string $v)
 * @method int getSizeBytes()
 * @method void setSizeBytes(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class WoPhoto extends Entity
{
	protected int $workOrderId = 0;
	protected string $fileName = '';
	protected ?string $originalName = null;
	protected string $mime = '';
	protected int $sizeBytes = 0;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('workOrderId', 'integer');
		$this->addType('fileName', 'string');
		$this->addType('originalName', 'string');
		$this->addType('mime', 'string');
		$this->addType('sizeBytes', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'workOrderId' => $this->workOrderId,
			// Opaque server-generated name — required for Servicebericht embed + download.
			'fileName' => $this->fileName,
			'originalName' => $this->originalName,
			'name' => $this->originalName,
			'mime' => $this->mime,
			'sizeBytes' => $this->sizeBytes,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
