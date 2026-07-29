<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W5 meter reading. `value` is a DECIMAL(12,3) travelling as a string.
 * equipment_id + meter_code are denormalised for cheap due-engine scans.
 *
 * @method int getMeterId()
 * @method void setMeterId(int $v)
 * @method int getEquipmentId()
 * @method void setEquipmentId(int $v)
 * @method string getMeterCode()
 * @method void setMeterCode(string $v)
 * @method string getValue()
 * @method void setValue(string $v)
 * @method string getReadOn()
 * @method void setReadOn(string $v)
 * @method string getSource()
 * @method void setSource(string $v)
 * @method string|null getNote()
 * @method void setNote(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class MeterReading extends Entity
{
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_IMPORT = 'import';
	public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_IMPORT];

	protected int $meterId = 0;
	protected int $equipmentId = 0;
	protected string $meterCode = '';
	protected string $value = '0';
	protected string $readOn = '';
	protected string $source = self::SOURCE_MANUAL;
	protected ?string $note = null;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('meterId', 'integer');
		$this->addType('equipmentId', 'integer');
		$this->addType('meterCode', 'string');
		// Decimal columns travel as strings — no float drift.
		$this->addType('value', 'string');
		$this->addType('readOn', 'string');
		$this->addType('source', 'string');
		$this->addType('note', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'meterId' => $this->meterId,
			'equipmentId' => $this->equipmentId,
			'meterCode' => $this->meterCode,
			'value' => $this->value,
			'readOn' => $this->readOn,
			'source' => $this->source,
			'note' => $this->note,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
