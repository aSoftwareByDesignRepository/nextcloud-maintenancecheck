<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W2 required skill on a work order (unique per WO+skill).
 *
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method int getSkillId()
 * @method void setSkillId(int $v)
 */
class WoSkill extends Entity
{
	protected int $workOrderId = 0;
	protected int $skillId = 0;

	public function __construct()
	{
		$this->addType('workOrderId', 'integer');
		$this->addType('skillId', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'workOrderId' => $this->workOrderId,
			'skillId' => $this->skillId,
		];
	}
}
