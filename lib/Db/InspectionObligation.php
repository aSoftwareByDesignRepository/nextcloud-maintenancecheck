<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W7 recurring inspection obligation (CORE §21 W7-R3) — plan-linked.
 *
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method string getClassCode()
 * @method void setClassCode(string $v)
 * @method string getIntervalUnit()
 * @method void setIntervalUnit(string $v)
 * @method int getIntervalCount()
 * @method void setIntervalCount(int $v)
 * @method int|null getProcedureId()
 * @method void setProcedureId(?int $v)
 * @method int|null getPlanId()
 * @method void setPlanId(?int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class InspectionObligation extends Entity
{
	protected int $equipmentId = 0;
	protected string $classCode = '';
	/** Empty defaults so setters always mark dirty (MySQL cols have no DEFAULT). */
	protected string $intervalUnit = '';
	protected int $intervalCount = 0;
	protected ?int $procedureId = null;
	protected ?int $planId = null;
	protected bool $active = false;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('equipmentId', 'integer');
		$this->addType('classCode', 'string');
		$this->addType('intervalUnit', 'string');
		$this->addType('intervalCount', 'integer');
		$this->addType('procedureId', 'integer');
		$this->addType('planId', 'integer');
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
			'classCode' => $this->classCode,
			'intervalUnit' => $this->intervalUnit,
			'intervalCount' => $this->intervalCount,
			'procedureId' => $this->procedureId,
			'planId' => $this->planId,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
