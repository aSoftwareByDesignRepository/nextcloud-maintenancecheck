<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Event;

use OCP\EventDispatcher\Event;

/**
 * W2 InventoryCheck flange (CORE §11.1): dispatched *after* a work order
 * commits to `done`, carrying the packed SKU lines so an inventory app can
 * post stock issues. Listener failures never affect the WO (AC-F1) — the
 * dispatcher records `inventory_sync=failed` instead.
 */
class WorkOrderClosedEvent extends Event
{
	/**
	 * @param list<array{sku: string, label: string, qty: int}> $skuLines
	 */
	public function __construct(
		private readonly int $workOrderId,
		private readonly string $number,
		private readonly int $customerId,
		private readonly ?int $equipmentId,
		private readonly array $skuLines,
	) {
		parent::__construct();
	}

	public function getWorkOrderId(): int
	{
		return $this->workOrderId;
	}

	public function getNumber(): string
	{
		return $this->number;
	}

	public function getCustomerId(): int
	{
		return $this->customerId;
	}

	public function getEquipmentId(): ?int
	{
		return $this->equipmentId;
	}

	/**
	 * @return list<array{sku: string, label: string, qty: int}>
	 */
	public function getSkuLines(): array
	{
		return $this->skuLines;
	}
}
