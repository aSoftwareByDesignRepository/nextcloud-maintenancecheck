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
 * @method int|null getSiteId()
 * @method void setSiteId(?int $v)
 * @method string|null getLat()
 * @method void setLat(?string $v)
 * @method string|null getLng()
 * @method void setLng(?string $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method string|null getQrTokenHash()
 * @method void setQrTokenHash(?string $v)
 * @method int|null getQrTokenRotatedAt()
 * @method void setQrTokenRotatedAt(?int $v)
 * @method string|null getWarrantyEnd()
 * @method void setWarrantyEnd(?string $v)
 * @method string|null getEquipmentClass()
 * @method void setEquipmentClass(?string $v)
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
	protected ?int $siteId = null;
	protected ?string $lat = null;
	protected ?string $lng = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';
	protected ?string $qrTokenHash = null;
	protected ?int $qrTokenRotatedAt = null;
	protected ?string $warrantyEnd = null;
	protected ?string $equipmentClass = null;

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
		$this->addType('siteId', 'integer');
		// Decimal columns travel as strings — no float drift.
		$this->addType('lat', 'string');
		$this->addType('lng', 'string');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('qrTokenHash', 'string');
		$this->addType('qrTokenRotatedAt', 'integer');
		$this->addType('warrantyEnd', 'string');
		$this->addType('equipmentClass', 'string');
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
			'siteId' => $this->siteId,
			'lat' => $this->lat,
			'lng' => $this->lng,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
			'hasQrToken' => $this->qrTokenHash !== null && $this->qrTokenHash !== '',
			'qrTokenRotatedAt' => $this->qrTokenRotatedAt,
			'warrantyEnd' => $this->warrantyEnd,
			'equipmentClass' => $this->equipmentClass,
		];
	}

	/**
	 * Non-blocking warn when warranty_end is in the past (AC-W6-3).
	 */
	public function isWarrantyExpired(string $todayYmd): bool
	{
		return $this->warrantyEnd !== null
			&& $this->warrantyEnd !== ''
			&& $this->warrantyEnd < $todayYmd;
	}
}
