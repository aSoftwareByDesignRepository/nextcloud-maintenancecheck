<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Shared shape for mn_equip_types and mn_maint_types rows.
 *
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 */
class CatalogType extends Entity
{
	protected string $code = '';
	protected string $name = '';
	protected int $sortOrder = 0;
	protected bool $active = true;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('name', 'string');
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
			'name' => $this->name,
			'sortOrder' => $this->sortOrder,
			'active' => $this->active,
		];
	}
}
