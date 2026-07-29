<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W2 kit template line (part or tool).
 *
 * @method int getTemplateId()
 * @method void setTemplateId(int $v)
 * @method string getLineType()
 * @method void setLineType(string $v)
 * @method string|null getSku()
 * @method void setSku(?string $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method int getQtyRequired()
 * @method void setQtyRequired(int $v)
 * @method bool getOptional()
 * @method void setOptional(bool $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 */
class KitTplLine extends Entity
{
	public const TYPE_PART = 'part';
	public const TYPE_TOOL = 'tool';
	public const TYPES = [self::TYPE_PART, self::TYPE_TOOL];

	protected int $templateId = 0;
	protected string $lineType = self::TYPE_PART;
	protected ?string $sku = null;
	protected string $label = '';
	protected int $qtyRequired = 1;
	protected bool $optional = false;
	protected int $sortOrder = 0;

	public function __construct()
	{
		$this->addType('templateId', 'integer');
		$this->addType('lineType', 'string');
		$this->addType('sku', 'string');
		$this->addType('label', 'string');
		$this->addType('qtyRequired', 'integer');
		$this->addType('optional', 'boolean');
		$this->addType('sortOrder', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'templateId' => $this->templateId,
			'lineType' => $this->lineType,
			'sku' => $this->sku,
			'label' => $this->label,
			'qtyRequired' => $this->qtyRequired,
			'optional' => $this->optional,
			'sortOrder' => $this->sortOrder,
		];
	}
}
