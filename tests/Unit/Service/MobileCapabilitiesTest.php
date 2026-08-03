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
		$this->assertTrue($caps['requestIntake']);
		$this->assertTrue($caps['failureCodes']);
		$this->assertTrue($caps['laborMinutes']);
		$this->assertTrue($caps['woComments']);
		$this->assertTrue($caps['equipmentDocs']);
		$this->assertTrue($caps['opsAlerts']);
		$this->assertTrue($caps['inspectionObligations']);
		$this->assertTrue($caps['inspectionResults']);
		$this->assertTrue($caps['defectFollowUp']);
		$this->assertTrue($caps['inspectionEvidencePdf']);
		$this->assertSame(MobileCapabilities::MIN_APP_VERSION, $caps['minAppVersion']);
		$this->assertSame(MobileCapabilities::COMPANION_MIN, $caps['maintenancecheck.companion.min']);
		$this->assertCount(20, $caps);
	}
}
