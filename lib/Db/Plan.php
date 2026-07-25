<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method int getMaintTypeId()
 * @method void setMaintTypeId(int $v)
 * @method string getIntervalUnit()
 * @method void setIntervalUnit(string $v)
 * @method int getIntervalCount()
 * @method void setIntervalCount(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method bool getHasContract()
 * @method void setHasContract(bool $v)
 * @method string|null getContractNotes()
 * @method void setContractNotes(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Plan extends Entity
{
	protected int $equipmentId = 0;
	protected int $maintTypeId = 0;
	protected string $intervalUnit = 'month';
	protected int $intervalCount = 1;
	protected bool $active = true;
	protected bool $hasContract = false;
	protected ?string $contractNotes = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('equipmentId', 'integer');
		$this->addType('maintTypeId', 'integer');
		$this->addType('intervalUnit', 'string');
		$this->addType('intervalCount', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('hasContract', 'boolean');
		$this->addType('contractNotes', 'string');
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
			'maintTypeId' => $this->maintTypeId,
			'intervalUnit' => $this->intervalUnit,
			'intervalCount' => $this->intervalCount,
			'active' => $this->active,
			'hasContract' => $this->hasContract,
			'contractNotes' => $this->contractNotes,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
