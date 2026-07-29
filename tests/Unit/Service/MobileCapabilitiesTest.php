<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\MobileCapabilities;
use PHPUnit\Framework\TestCase;

final class MobileCapabilitiesTest extends TestCase
{
	public function testCurrentAdvertisesCompanionContenderSurfaces(): void
	{
		$caps = MobileCapabilities::current();
		$this->assertTrue($caps['visits']);
		$this->assertTrue($caps['workOrders']);
		$this->assertTrue($caps['tours']);
		$this->assertTrue($caps['kits']);
		$this->assertTrue($caps['qr']);
		$this->assertTrue($caps['conditionalChecklist']);
		$this->assertTrue($caps['serviceReport']);
		$this->assertTrue($caps['meters']);
		$this->assertSame('1.0.0', $caps['minAppVersion']);
		$this->assertCount(9, $caps);
	}
}
