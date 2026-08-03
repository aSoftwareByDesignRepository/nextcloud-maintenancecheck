<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W6 equipment document reference (CORE §20 W6-R2) — Files file_id and/or URL.
 *
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method int|null getFileId()
 * @method void setFileId(?int $v)
 * @method string|null getExternalUrl()
 * @method void setExternalUrl(?string $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class EquipDoc extends Entity
{
	public const MAX_PER_EQUIPMENT = 20;

	protected int $equipmentId = 0;
	protected string $title = '';
	protected ?int $fileId = null;
	protected ?string $externalUrl = null;
	protected int $sortOrder = 0;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('equipmentId', 'integer');
		$this->addType('title', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('externalUrl', 'string');
		$this->addType('sortOrder', 'integer');
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
			'equipmentId' => $this->equipmentId,
			'title' => $this->title,
			'fileId' => $this->fileId,
			'externalUrl' => $this->externalUrl,
			'sortOrder' => $this->sortOrder,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
