<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 procedure checklist item incl. light conditional visibility
 * (`show_if`, CORE §10.6 / §14.1a).
 *
 * @method int getProcedureId()
 * @method void setProcedureId(int $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method bool getRequired()
 * @method void setRequired(bool $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 * @method string|null getShowIfItemCode()
 * @method void setShowIfItemCode(?string $v)
 * @method string|null getShowIfResult()
 * @method void setShowIfResult(?string $v)
 */
class ProcItem extends Entity
{
	protected int $procedureId = 0;
	protected string $code = '';
	protected string $label = '';
	protected bool $required = true;
	protected int $sortOrder = 0;
	protected ?string $showIfItemCode = null;
	protected ?string $showIfResult = null;

	public function __construct()
	{
		$this->addType('procedureId', 'integer');
		$this->addType('code', 'string');
		$this->addType('label', 'string');
		$this->addType('required', 'boolean');
		$this->addType('sortOrder', 'integer');
		$this->addType('showIfItemCode', 'string');
		$this->addType('showIfResult', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'procedureId' => $this->procedureId,
			'code' => $this->code,
			'label' => $this->label,
			'required' => $this->required,
			'sortOrder' => $this->sortOrder,
			'showIfItemCode' => $this->showIfItemCode,
			'showIfResult' => $this->showIfResult,
		];
	}
}
