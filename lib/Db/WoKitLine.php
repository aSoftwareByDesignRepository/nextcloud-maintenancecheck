<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W2 kit instance line with packing progress. The kit is "ready" when every
 * non-optional line has qty_packed >= qty_required
 * ({@see \OCA\MaintenanceCheck\Service\KitReadiness}).
 *
 * @method int getWoKitId()
 * @method void setWoKitId(int $v)
 * @method string getLineType()
 * @method void setLineType(string $v)
 * @method string|null getSku()
 * @method void setSku(?string $v)
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method int getQtyRequired()
 * @method void setQtyRequired(int $v)
 * @method int getQtyPacked()
 * @method void setQtyPacked(int $v)
 * @method bool getOptional()
 * @method void setOptional(bool $v)
 * @method int getSortOrder()
 * @method void setSortOrder(int $v)
 */
class WoKitLine extends Entity
{
	protected int $woKitId = 0;
	protected string $lineType = KitTplLine::TYPE_PART;
	protected ?string $sku = null;
	protected string $label = '';
	protected int $qtyRequired = 1;
	protected int $qtyPacked = 0;
	protected bool $optional = false;
	protected int $sortOrder = 0;

	public function __construct()
	{
		$this->addType('woKitId', 'integer');
		$this->addType('lineType', 'string');
		$this->addType('sku', 'string');
		$this->addType('label', 'string');
		$this->addType('qtyRequired', 'integer');
		$this->addType('qtyPacked', 'integer');
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
			'woKitId' => $this->woKitId,
			'lineType' => $this->lineType,
			'sku' => $this->sku,
			'label' => $this->label,
			'qtyRequired' => $this->qtyRequired,
			'qtyPacked' => $this->qtyPacked,
			'optional' => $this->optional,
			'sortOrder' => $this->sortOrder,
		];
	}
}
