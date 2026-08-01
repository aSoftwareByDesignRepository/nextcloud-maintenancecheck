<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 work order header (CORE §8.1 / §14.2) — the executable unit of field
 * work. Status transitions are owned by {@see \OCA\MaintenanceCheck\Service\WorkOrderStateMachine}.
 *
 * @method string getNumber()
 * @method void setNumber(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string getPriority()
 * @method void setPriority(string $v)
 * @method int getCustomerId()
 * @method void setCustomerId(int $v)
 * @method int|null getEquipmentId()
 * @method void setEquipmentId(?int $v)
 * @method int|null getSiteId()
 * @method void setSiteId(?int $v)
 * @method int|null getVisitId()
 * @method void setVisitId(?int $v)
 * @method int|null getProcedureId()
 * @method void setProcedureId(?int $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string|null getDescription()
 * @method void setDescription(?string $v)
 * @method string|null getDueOn()
 * @method void setDueOn(?string $v)
 * @method int|null getEstimatedMinutes()
 * @method void setEstimatedMinutes(?int $v)
 * @method string|null getPrimaryUserId()
 * @method void setPrimaryUserId(?string $v)
 * @method string|null getHelperUids()
 * @method void setHelperUids(?string $v)
 * @method bool getProcedureSkipped()
 * @method void setProcedureSkipped(bool $v)
 * @method string|null getProcedureSkipReason()
 * @method void setProcedureSkipReason(?string $v)
 * @method string|null getBlockReasonCode()
 * @method void setBlockReasonCode(?string $v)
 * @method string|null getBlockNote()
 * @method void setBlockNote(?string $v)
 * @method bool getKitOverride()
 * @method void setKitOverride(bool $v)
 * @method string|null getKitOverrideReason()
 * @method void setKitOverrideReason(?string $v)
 * @method string|null getForceCloseReason()
 * @method void setForceCloseReason(?string $v)
 * @method string|null getInventorySync()
 * @method void setInventorySync(?string $v)
 * @method int|null getStartedAt()
 * @method void setStartedAt(?int $v)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $v)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method string|null getRequesterName()
 * @method void setRequesterName(?string $v)
 * @method string|null getRequesterPhone()
 * @method void setRequesterPhone(?string $v)
 * @method string|null getSymptom()
 * @method void setSymptom(?string $v)
 * @method string|null getAccessNotes()
 * @method void setAccessNotes(?string $v)
 * @method string|null getFailureCode()
 * @method void setFailureCode(?string $v)
 * @method int|null getLaborMinutes()
 * @method void setLaborMinutes(?int $v)
 */
class WorkOrder extends Entity
{
	public const KIND_PREVENTIVE = 'preventive';
	public const KIND_CORRECTIVE = 'corrective';
	public const KIND_INSPECTION = 'inspection';
	public const KIND_OTHER = 'other';

	public const KINDS = [
		self::KIND_PREVENTIVE,
		self::KIND_CORRECTIVE,
		self::KIND_INSPECTION,
		self::KIND_OTHER,
	];

	public const STATUS_DRAFT = 'draft';
	public const STATUS_PLANNED = 'planned';
	public const STATUS_READY = 'ready';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_DONE = 'done';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_BLOCKED = 'blocked';

	public const STATUSES = [
		self::STATUS_DRAFT,
		self::STATUS_PLANNED,
		self::STATUS_READY,
		self::STATUS_IN_PROGRESS,
		self::STATUS_DONE,
		self::STATUS_CANCELLED,
		self::STATUS_BLOCKED,
	];

	public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_CANCELLED];

	public const PRIORITY_LOW = 'low';
	public const PRIORITY_NORMAL = 'normal';
	public const PRIORITY_HIGH = 'high';
	public const PRIORITY_EMERGENCY = 'emergency';

	public const PRIORITIES = [
		self::PRIORITY_LOW,
		self::PRIORITY_NORMAL,
		self::PRIORITY_HIGH,
		self::PRIORITY_EMERGENCY,
	];

	protected string $number = '';
	protected string $kind = self::KIND_CORRECTIVE;
	protected string $status = self::STATUS_DRAFT;
	protected string $priority = self::PRIORITY_NORMAL;
	protected int $customerId = 0;
	protected ?int $equipmentId = null;
	protected ?int $siteId = null;
	protected ?int $visitId = null;
	protected ?int $procedureId = null;
	protected string $title = '';
	protected ?string $description = null;
	protected ?string $dueOn = null;
	protected ?int $estimatedMinutes = null;
	protected ?string $primaryUserId = null;
	protected ?string $helperUids = null;
	protected bool $procedureSkipped = false;
	protected ?string $procedureSkipReason = null;
	protected ?string $blockReasonCode = null;
	protected ?string $blockNote = null;
	protected bool $kitOverride = false;
	protected ?string $kitOverrideReason = null;
	protected ?string $forceCloseReason = null;
	protected ?string $inventorySync = null;
	protected ?string $inventorySyncCode = null;
	protected ?int $startedAt = null;
	protected ?int $completedAt = null;
	protected ?string $completedBy = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';
	protected ?string $requesterName = null;
	protected ?string $requesterPhone = null;
	protected ?string $symptom = null;
	protected ?string $accessNotes = null;
	protected ?string $failureCode = null;
	protected ?int $laborMinutes = null;

	/** Max job-duration evidence minutes (W6-R4): 24×60. */
	public const MAX_LABOR_MINUTES = 1440;

	public function __construct()
	{
		$this->addType('number', 'string');
		$this->addType('kind', 'string');
		$this->addType('status', 'string');
		$this->addType('priority', 'string');
		$this->addType('customerId', 'integer');
		$this->addType('equipmentId', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('visitId', 'integer');
		$this->addType('procedureId', 'integer');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('dueOn', 'string');
		$this->addType('estimatedMinutes', 'integer');
		$this->addType('primaryUserId', 'string');
		$this->addType('helperUids', 'string');
		$this->addType('procedureSkipped', 'boolean');
		$this->addType('procedureSkipReason', 'string');
		$this->addType('blockReasonCode', 'string');
		$this->addType('blockNote', 'string');
		$this->addType('kitOverride', 'boolean');
		$this->addType('kitOverrideReason', 'string');
		$this->addType('forceCloseReason', 'string');
		$this->addType('inventorySync', 'string');
		$this->addType('inventorySyncCode', 'string');
		$this->addType('startedAt', 'integer');
		$this->addType('completedAt', 'integer');
		$this->addType('completedBy', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('requesterName', 'string');
		$this->addType('requesterPhone', 'string');
		$this->addType('symptom', 'string');
		$this->addType('accessNotes', 'string');
		$this->addType('failureCode', 'string');
		$this->addType('laborMinutes', 'integer');
	}

	public function isTerminal(): bool
	{
		return in_array($this->status, self::TERMINAL_STATUSES, true);
	}

	/**
	 * @return list<string>
	 */
	public function helperUidList(): array
	{
		if ($this->helperUids === null || $this->helperUids === '') {
			return [];
		}
		$decoded = json_decode($this->helperUids, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $uid) {
			if (is_string($uid) && trim($uid) !== '') {
				$out[] = trim($uid);
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Technician execute scope (CORE §7): primary, listed helpers, or the
	 * unassigned pool (null/empty primary).
	 */
	public function isAssigneeOrPool(string $uid): bool
	{
		$primary = $this->primaryUserId;
		if ($primary === null || $primary === '') {
			return true;
		}
		if ($primary === $uid) {
			return true;
		}
		return in_array($uid, $this->helperUidList(), true);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'number' => $this->number,
			'kind' => $this->kind,
			'status' => $this->status,
			'priority' => $this->priority,
			'customerId' => $this->customerId,
			'equipmentId' => $this->equipmentId,
			'siteId' => $this->siteId,
			'visitId' => $this->visitId,
			'procedureId' => $this->procedureId,
			'title' => $this->title,
			'description' => $this->description,
			'dueOn' => $this->dueOn,
			'estimatedMinutes' => $this->estimatedMinutes,
			'primaryUserId' => $this->primaryUserId,
			'helperUids' => $this->helperUidList(),
			'procedureSkipped' => $this->procedureSkipped,
			'procedureSkipReason' => $this->procedureSkipReason,
			'blockReasonCode' => $this->blockReasonCode,
			'blockNote' => $this->blockNote,
			'kitOverride' => $this->kitOverride,
			'kitOverrideReason' => $this->kitOverrideReason,
			'forceCloseReason' => $this->forceCloseReason,
			'inventorySync' => $this->inventorySync,
			'inventorySyncCode' => $this->inventorySyncCode,
			'startedAt' => $this->startedAt,
			'completedAt' => $this->completedAt,
			'completedBy' => $this->completedBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
			'requesterName' => $this->requesterName,
			'requesterPhone' => $this->requesterPhone,
			'symptom' => $this->symptom,
			'accessNotes' => $this->accessNotes,
			'failureCode' => $this->failureCode,
			'laborMinutes' => $this->laborMinutes,
		];
	}
}
