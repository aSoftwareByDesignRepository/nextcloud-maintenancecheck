<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getPlanId()
 * @method void setPlanId(int $v)
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method int getCustomerId()
 * @method void setCustomerId(int $v)
 * @method int getMaintTypeId()
 * @method void setMaintTypeId(int $v)
 * @method string getDueOn()
 * @method void setDueOn(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string|null getAssignedUid()
 * @method void setAssignedUid(?string $v)
 * @method int|null getDoneAt()
 * @method void setDoneAt(?int $v)
 * @method string|null getDoneBy()
 * @method void setDoneBy(?string $v)
 * @method string|null getDoneOn()
 * @method void setDoneOn(?string $v)
 * @method string|null getNotes()
 * @method void setNotes(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class Visit extends Entity
{
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_DONE = 'done';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_CANCELLED = 'cancelled';

	public const STATUSES = [
		self::STATUS_SCHEDULED,
		self::STATUS_DONE,
		self::STATUS_SKIPPED,
		self::STATUS_CANCELLED,
	];

	protected int $planId = 0;
	protected int $equipmentId = 0;
	protected int $customerId = 0;
	protected int $maintTypeId = 0;
	protected string $dueOn = '';
	protected string $status = self::STATUS_SCHEDULED;
	protected ?string $assignedUid = null;
	protected ?int $doneAt = null;
	protected ?string $doneBy = null;
	protected ?string $doneOn = null;
	protected ?string $notes = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct()
	{
		$this->addType('planId', 'integer');
		$this->addType('equipmentId', 'integer');
		$this->addType('customerId', 'integer');
		$this->addType('maintTypeId', 'integer');
		$this->addType('dueOn', 'string');
		$this->addType('status', 'string');
		$this->addType('assignedUid', 'string');
		$this->addType('doneAt', 'integer');
		$this->addType('doneBy', 'string');
		$this->addType('doneOn', 'string');
		$this->addType('notes', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'planId' => $this->planId,
			'equipmentId' => $this->equipmentId,
			'customerId' => $this->customerId,
			'maintTypeId' => $this->maintTypeId,
			'dueOn' => $this->dueOn,
			'status' => $this->status,
			'assignedUid' => $this->assignedUid,
			'doneAt' => $this->doneAt,
			'doneBy' => $this->doneBy,
			'doneOn' => $this->doneOn,
			'notes' => $this->notes,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
