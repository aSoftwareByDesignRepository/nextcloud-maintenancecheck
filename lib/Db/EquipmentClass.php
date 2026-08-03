<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W7 equipment class catalog (CORE §21 W7-R2).
 *
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getNameDe()
 * @method void setNameDe(string $v)
 * @method string getNameEn()
 * @method void setNameEn(string $v)
 * @method string getDefaultIntervalUnit()
 * @method void setDefaultIntervalUnit(string $v)
 * @method int getDefaultIntervalCount()
 * @method void setDefaultIntervalCount(int $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 */
class EquipmentClass extends Entity
{
	protected string $code = '';
	protected string $nameDe = '';
	protected string $nameEn = '';
	protected string $defaultIntervalUnit = 'year';
	protected int $defaultIntervalCount = 1;
	protected int $sortOrder = 0;
	protected bool $active = true;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('nameDe', 'string');
		$this->addType('nameEn', 'string');
		$this->addType('defaultIntervalUnit', 'string');
		$this->addType('defaultIntervalCount', 'integer');
		$this->addType('sortOrder', 'integer');
		$this->addType('active', 'boolean');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'code' => $this->code,
			'nameDe' => $this->nameDe,
			'nameEn' => $this->nameEn,
			'defaultIntervalUnit' => $this->defaultIntervalUnit,
			'defaultIntervalCount' => $this->defaultIntervalCount,
			'sortOrder' => $this->sortOrder,
			'active' => $this->active,
		];
	}
}
