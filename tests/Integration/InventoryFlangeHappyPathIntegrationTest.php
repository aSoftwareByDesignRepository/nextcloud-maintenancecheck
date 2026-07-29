<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Public\StockIssueRequest;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCP\Server;

/**
 * WP-S2-MN-F6 / AC-S2.2 — happy path: stock issues when F6 enabled + stock present.
 *
 * @group integration
 */
final class InventoryFlangeHappyPathIntegrationTest extends IntegrationTestCase
{
	private const ACTOR = 'admin';

	private ?bool $prevEnabled = null;
	private ?string $prevPolicy = null;
	private ?int $prevLocation = null;

	/** @var list<int> */
	private array $itemIds = [];

	protected function tearDown(): void
	{
		if (!class_exists(\OC::class)) {
			parent::tearDown();
			return;
		}
		try {
			$flange = Server::get(InventoryFlangeService::class);
			if ($this->prevEnabled !== null) {
				$flange->setEnabled($this->prevEnabled);
			}
			if ($this->prevPolicy !== null) {
				$flange->setLocationPolicy($this->prevPolicy);
			}
			$flange->setExplicitLocationId($this->prevLocation);
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	public function testIssueSucceedsAndPostsMaintWoMovement(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		if (!class_exists(ItemService::class) || !class_exists(StockIssueRequest::class)) {
			$this->markTestSkipped('InventoryCheck not available');
		}

		$flange = Server::get(InventoryFlangeService::class);
		$locations = Server::get(LocationService::class);
		$items = Server::get(ItemService::class);
		$movements = Server::get(MovementService::class);
		$balances = Server::get(BalanceMapper::class);
		$movementMapper = Server::get(MovementMapper::class);

		$this->prevEnabled = $flange->isEnabled();
		$this->prevPolicy = $flange->locationPolicy();
		$this->prevLocation = $flange->explicitLocationId();

		$suffix = bin2hex(random_bytes(3));
		$loc = $locations->create(self::ACTOR, [
			'code' => 'F6-L-' . $suffix,
			'name' => 'F6 Van',
			'kind' => 'van',
		]);
		$item = $items->create(self::ACTOR, [
			'sku' => 'F6-SKU-' . $suffix,
			'name' => 'F6 Filter',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->itemIds[] = $itemId;

		$movements->receive(self::ACTOR, $itemId, $locId, 10, 'f6-seed');
		$before = $balances->findPair($itemId, $locId);
		$this->assertNotNull($before);
		$this->assertSame(10, $before->getQty());

		$flange->setEnabled(true);
		$flange->setLocationPolicy(InventoryFlangeService::POLICY_EXPLICIT);
		$flange->setExplicitLocationId($locId);

		$woId = 920000 + random_int(1, 9999);
		$result = $flange->issueForWorkOrder(self::ACTOR, $woId, [
			['sku' => 'F6-SKU-' . $suffix, 'qty' => 3],
		]);

		$this->assertSame('ok', $result['sync'], 'code=' . (string)($result['code'] ?? ''));

		$after = $balances->findPair($itemId, $locId);
		$this->assertNotNull($after);
		$this->assertSame(7, $after->getQty());

		$posted = $movementMapper->findByRef(StockIssueRequest::REF_MAINT_WO, $woId);
		$this->assertNotSame([], $posted);
		$qtySum = 0;
		foreach ($posted as $mov) {
			if ((int)$mov->getItemId() === $itemId) {
				$qtySum += abs((int)$mov->getQtyDelta());
			}
		}
		$this->assertSame(3, $qtySum);

		// Idempotent replay must soft-succeed without double-consuming stock.
		$replay = $flange->issueForWorkOrder(self::ACTOR, $woId, [
			['sku' => 'F6-SKU-' . $suffix, 'qty' => 3],
		]);
		$this->assertSame('ok', $replay['sync']);
		$afterReplay = $balances->findPair($itemId, $locId);
		$this->assertNotNull($afterReplay);
		$this->assertSame(7, $afterReplay->getQty());
	}
}
