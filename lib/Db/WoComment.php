<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W6 append-only work-order comment (CORE §20 W6-R5).
 *
 * @method int getWoId()
 * @method void setWoId(int $v)
 * @method string getBody()
 * @method void setBody(string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class WoComment extends Entity
{
	public const MAX_BODY = 4000;

	protected int $woId = 0;
	protected string $body = '';
	protected string $createdBy = '';
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('woId', 'integer');
		$this->addType('body', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'woId' => $this->woId,
			'body' => $this->body,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
		];
	}
}
