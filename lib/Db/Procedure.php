<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 checklist template header (CORE §14.1).
 *
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string|null getVertical()
 * @method void setVertical(?string $v)
 * @method string getLocale()
 * @method void setLocale(string $v)
 * @method int getVersion()
 * @method void setVersion(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method string|null getSourcePack()
 * @method void setSourcePack(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Procedure extends Entity
{
	protected string $code = '';
	protected string $title = '';
	protected ?string $vertical = null;
	protected string $locale = 'en';
	protected int $version = 1;
	protected bool $active = true;
	protected ?string $sourcePack = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('title', 'string');
		$this->addType('vertical', 'string');
		$this->addType('locale', 'string');
		$this->addType('version', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('sourcePack', 'string');
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
			'code' => $this->code,
			'title' => $this->title,
			'vertical' => $this->vertical,
			'locale' => $this->locale,
			'version' => $this->version,
			'active' => $this->active,
			'sourcePack' => $this->sourcePack,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
