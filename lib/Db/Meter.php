<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W5 equipment meter (operating hours, cycles, km, …). Unique per
 * (equipment, code). Monotonic meters reject decreasing readings.
 *
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getUnit()
 * @method void setUnit(?string $v)
 * @method bool getMonotonic()
 * @method void setMonotonic(bool $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Meter extends Entity
{
	protected int $equipmentId = 0;
	protected string $code = '';
	protected string $name = '';
	protected ?string $unit = null;
	protected bool $monotonic = true;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('equipmentId', 'integer');
		$this->addType('code', 'string');
		$this->addType('name', 'string');
		$this->addType('unit', 'string');
		$this->addType('monotonic', 'boolean');
		$this->addType('active', 'boolean');
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
			'equipmentId' => $this->equipmentId,
			'code' => $this->code,
			'name' => $this->name,
			'unit' => $this->unit,
			'monotonic' => $this->monotonic,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
