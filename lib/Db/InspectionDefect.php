<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W7 inspection defect line on a work order (CORE §21 W7-R6).
 *
 * @method int getWoId()
 * @method void setWoId(int $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getBody()
 * @method void setBody(string $v)
 * @method int|null getPhotoFileId()
 * @method void setPhotoFileId(?int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class InspectionDefect extends Entity
{
	public const MAX_BODY = 2000;

	protected int $woId = 0;
	protected string $code = '';
	protected string $body = '';
	protected ?int $photoFileId = null;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('woId', 'integer');
		$this->addType('code', 'string');
		$this->addType('body', 'string');
		$this->addType('photoFileId', 'integer');
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
			'woId' => $this->woId,
			'code' => $this->code,
			'body' => $this->body,
			'photoFileId' => $this->photoFileId,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
