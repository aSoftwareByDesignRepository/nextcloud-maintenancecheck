<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\KitReadiness;
use PHPUnit\Framework\TestCase;

/** AC-W2-1 / CORE §10.2 kit ready gate. */
final class KitReadinessTest extends TestCase
{
	private KitReadiness $kit;

	protected function setUp(): void
	{
		$this->kit = new KitReadiness();
	}

	public function testEmptyKitIsReady(): void
	{
		$result = $this->kit->assess([]);
		$this->assertTrue($result['ready']);
		$this->assertSame([], $result['missing']);
	}

	public function testIncompleteBlocksReady(): void
	{
		$result = $this->kit->assess([
			['label' => 'Filter', 'qtyRequired' => 2, 'qtyPacked' => 1, 'optional' => false],
		]);
		$this->assertFalse($result['ready']);
		$this->assertSame([
			['label' => 'Filter', 'qtyRequired' => 2, 'qtyPacked' => 1],
		], $result['missing']);
	}

	public function testExactPackIsReady(): void
	{
		$result = $this->kit->assess([
			['label' => 'Filter', 'qtyRequired' => 2, 'qtyPacked' => 2, 'optional' => false],
		]);
		$this->assertTrue($result['ready']);
		$this->assertSame([], $result['missing']);
	}

	public function testOverpackIsReady(): void
	{
		$result = $this->kit->assess([
			['label' => 'Filter', 'qtyRequired' => 2, 'qtyPacked' => 5, 'optional' => false],
		]);
		$this->assertTrue($result['ready']);
	}

	public function testOptionalNeverBlocks(): void
	{
		$result = $this->kit->assess([
			['label' => 'Spare', 'qtyRequired' => 10, 'qtyPacked' => 0, 'optional' => true],
			['label' => 'Must', 'qtyRequired' => 1, 'qtyPacked' => 1, 'optional' => false],
		]);
		$this->assertTrue($result['ready']);
		$this->assertSame([], $result['missing']);
	}

	public function testZeroRequiredNonOptionalIsReady(): void
	{
		$result = $this->kit->assess([
			['label' => 'Info', 'qtyRequired' => 0, 'qtyPacked' => 0, 'optional' => false],
		]);
		$this->assertTrue($result['ready']);
	}
}
