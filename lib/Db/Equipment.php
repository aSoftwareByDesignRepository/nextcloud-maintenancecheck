<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCustomerId()
 * @method void setCustomerId(int $v)
 * @method int getEquipTypeId()
 * @method void setEquipTypeId(int $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method string|null getManufacturer()
 * @method void setManufacturer(?string $v)
 * @method string|null getModel()
 * @method void setModel(?string $v)
 * @method string|null getSerialNo()
 * @method void setSerialNo(?string $v)
 * @method string|null getLocationText()
 * @method void setLocationText(?string $v)
 * @method string|null getNotes()
 * @method void setNotes(?string $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Equipment extends Entity
{
	protected int $customerId = 0;
	protected int $equipTypeId = 0;
	protected string $label = '';
	protected ?string $manufacturer = null;
	protected ?string $model = null;
	protected ?string $serialNo = null;
	protected ?string $locationText = null;
	protected ?string $notes = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('customerId', 'integer');
		$this->addType('equipTypeId', 'integer');
		$this->addType('label', 'string');
		$this->addType('manufacturer', 'string');
		$this->addType('model', 'string');
		$this->addType('serialNo', 'string');
		$this->addType('locationText', 'string');
		$this->addType('notes', 'string');
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
			'customerId' => $this->customerId,
			'equipTypeId' => $this->equipTypeId,
			'label' => $this->label,
			'manufacturer' => $this->manufacturer,
			'model' => $this->model,
			'serialNo' => $this->serialNo,
			'locationText' => $this->locationText,
			'notes' => $this->notes,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
