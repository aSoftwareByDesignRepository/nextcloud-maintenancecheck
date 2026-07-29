<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W3 day tour — one per (date, tech) enforced by unique index.
 *
 * @method string getTourDate()
 * @method void setTourDate(string $v)
 * @method string getTechUid()
 * @method void setTechUid(string $v)
 * @method bool getOrderLocked()
 * @method void setOrderLocked(bool $v)
 * @method string|null getNotes()
 * @method void setNotes(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class DayTour extends Entity
{
	protected string $tourDate = '';
	protected string $techUid = '';
	protected bool $orderLocked = false;
	protected ?string $notes = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('tourDate', 'string');
		$this->addType('techUid', 'string');
		$this->addType('orderLocked', 'boolean');
		$this->addType('notes', 'string');
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
			'tourDate' => $this->tourDate,
			'techUid' => $this->techUid,
			'orderLocked' => $this->orderLocked,
			'notes' => $this->notes,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
