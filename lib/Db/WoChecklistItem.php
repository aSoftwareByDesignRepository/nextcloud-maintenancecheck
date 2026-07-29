<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 checklist instance row — a snapshot of a procedure item taken when the
 * procedure was attached, so later template edits/forks never mutate running
 * work orders.
 *
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method string getItemCode()
 * @method void setItemCode(string $v)
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
 * @method string|null getResult()
 * @method void setResult(?string $v)
 * @method string|null getNote()
 * @method void setNote(?string $v)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class WoChecklistItem extends Entity
{
	public const RESULT_OK = 'ok';
	public const RESULT_FAIL = 'fail';
	public const RESULT_NA = 'na';

	public const RESULTS = [self::RESULT_OK, self::RESULT_FAIL, self::RESULT_NA];

	protected int $workOrderId = 0;
	protected string $itemCode = '';
	protected string $label = '';
	protected bool $required = true;
	protected int $sortOrder = 0;
	protected ?string $showIfItemCode = null;
	protected ?string $showIfResult = null;
	protected ?string $result = null;
	protected ?string $note = null;
	protected ?string $updatedBy = null;
	protected int $updatedAt = 0;

	public function __construct()
	{
		$this->addType('workOrderId', 'integer');
		$this->addType('itemCode', 'string');
		$this->addType('label', 'string');
		$this->addType('required', 'boolean');
		$this->addType('sortOrder', 'integer');
		$this->addType('showIfItemCode', 'string');
		$this->addType('showIfResult', 'string');
		$this->addType('result', 'string');
		$this->addType('note', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'workOrderId' => $this->workOrderId,
			'itemCode' => $this->itemCode,
			'code' => $this->itemCode,
			'label' => $this->label,
			'required' => $this->required,
			'sortOrder' => $this->sortOrder,
			'showIfItemCode' => $this->showIfItemCode,
			'showIfResult' => $this->showIfResult,
			'result' => $this->result,
			'note' => $this->note,
			'updatedBy' => $this->updatedBy,
			'updatedAt' => $this->updatedAt,
		];
	}
}
