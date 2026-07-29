<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W2 kit instance — a per-work-order copy of a template (or ad-hoc kit).
 * At most one per WO (unique index).
 *
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method int|null getTemplateId()
 * @method void setTemplateId(?int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class WoKit extends Entity
{
	protected int $workOrderId = 0;
	protected ?int $templateId = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('workOrderId', 'integer');
		$this->addType('templateId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
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
			'templateId' => $this->templateId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
