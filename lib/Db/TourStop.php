<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W3 tour stop — a work order slotted into a day tour. A WO can belong to at
 * most one tour (unique index on work_order_id).
 *
 * @method int getTourId()
 * @method void setTourId(int $v)
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method int getPosition()
 * @method void setPosition(int $v)
 */
class TourStop extends Entity
{
	protected int $tourId = 0;
	protected int $workOrderId = 0;
	protected int $position = 0;

	public function __construct()
	{
		$this->addType('tourId', 'integer');
		$this->addType('workOrderId', 'integer');
		$this->addType('position', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'tourId' => $this->tourId,
			'workOrderId' => $this->workOrderId,
			'position' => $this->position,
		];
	}
}
