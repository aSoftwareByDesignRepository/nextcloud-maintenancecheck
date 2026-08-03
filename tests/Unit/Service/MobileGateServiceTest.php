<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MobileCapabilities;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §9.1 gate ladder ordering: each rung fails with its own code and
 * earlier rungs shadow later ones.
 */
final class MobileGateServiceTest extends TestCase
{
	/**
	 * @param array<string, mixed> $overrides
	 */
	private function gate(array $overrides): MobileGateService
	{
		$state = array_merge([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => 'PAYLOAD',
			'signatureB64' => 'SIG',
		], $overrides);

		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->with('tech1')->willReturn($state);
		$license->method('status')->willReturn([
			'state' => ['validUntil' => '2027-12-31'],
			'seats' => ['assigned' => 1, 'limit' => 5],
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
		]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('notifications')->willReturn(true);
		$policies = $this->createMock(\OCA\MaintenanceCheck\Service\PolicyService::class);
		$policies->method('failureCodeOnCorrective')->willReturn('warn');
		$policies->method('defectFollowUp')->willReturn('warn');
		$policies->method('inspectionResultRequired')->willReturn(true);
		return new MobileGateService($license, $apps, $policies);
	}

	public function testAllRungsPass(): void
	{
		$this->gate([])->assertGatePassed('tech1');
		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function ladder(): array
	{
		return [
			'rung 3 no license' => [
				['hasLicense' => false, 'licenseValid' => false, 'seatAssigned' => false, 'seatWithinLimit' => false],
				'license_missing',
			],
			'rung 4 expired' => [
				['licenseValid' => false, 'seatAssigned' => false, 'seatWithinLimit' => false],
				'license_expired',
			],
			'rung 5 no seat' => [
				['seatAssigned' => false, 'seatWithinLimit' => false],
				'seat_required',
			],
			'rung 6 over limit' => [
				['seatWithinLimit' => false],
				'seat_limit_exceeded',
			],
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @dataProvider ladder
	 */
	public function testRungFailsWithItsCode(array $overrides, string $expectedCode): void
	{
		try {
			$this->gate($overrides)->assertGatePassed('tech1');
			$this->fail('Expected MobileGateException ' . $expectedCode);
		} catch (MobileGateException $e) {
			$this->assertSame($expectedCode, $e->getErrorCode());
		}
	}

	public function testBootstrapReportsInsteadOfGating(): void
	{
		// Even fully unlicensed, bootstrap must not throw (SPEC §9.2).
		$payload = $this->gate([
			'hasLicense' => false,
			'licenseValid' => false,
			'seatAssigned' => false,
			'seatWithinLimit' => false,
			'payloadB64' => null,
			'signatureB64' => null,
		])->bootstrapPayload('tech1', 'Tech One', false);

		$this->assertNull($payload['licensing']);
		$this->assertFalse($payload['seatAssigned']);
		$this->assertFalse($payload['seatWithinLimit']);
		$this->assertSame(LicenseService::MOBILE_APP_STATUS, $payload['mobileAppStatus']);
		$this->assertSame('tech1', $payload['user']['userId']);
		$this->assertSame('tech1', $payload['user']['uid']);
		$this->assertSame('Tech One', $payload['user']['displayName']);
		$this->assertFalse($payload['user']['isOffice']);
		$this->assertTrue($payload['capabilities']['visits']);
		$this->assertTrue($payload['capabilities']['workOrders']);
		$this->assertTrue($payload['capabilities']['qr']);
		$this->assertTrue($payload['capabilities']['conditionalChecklist']);
		$this->assertTrue($payload['capabilities']['serviceReport']);
		$this->assertTrue($payload['capabilities']['meters']);
		$this->assertSame(MobileCapabilities::MIN_APP_VERSION, $payload['capabilities']['minAppVersion']);
		$this->assertSame(MobileCapabilities::COMPANION_MIN, $payload['capabilities']['maintenancecheck.companion.min']);
		$this->assertTrue($payload['pushAvailable']);
		$this->assertTrue($payload['capabilities']['push']);
		$this->assertSame('warn', $payload['policies']['failureCodeOnCorrective']);
		$this->assertSame('warn', $payload['policies']['defectFollowUp']);
		$this->assertTrue($payload['policies']['inspectionResultRequired']);
	}

	public function testBootstrapExposesWireParts(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('gateState')->with('tech1')->willReturn([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => 'PAYLOAD',
			'signatureB64' => 'SIG',
		]);
		$license->method('status')->willReturn([
			'state' => ['validUntil' => '2027-12-31'],
			'seats' => ['assigned' => 1, 'limit' => 5],
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
		]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('notifications')->willReturn(true);
		$policies = $this->createMock(\OCA\MaintenanceCheck\Service\PolicyService::class);
		$policies->method('failureCodeOnCorrective')->willReturn('required');
		$policies->method('defectFollowUp')->willReturn('auto');
		$policies->method('inspectionResultRequired')->willReturn(true);
		$payload = (new MobileGateService($license, $apps, $policies))->bootstrapPayload('tech1', 'Tech One', true);

		$this->assertSame('MN2', $payload['licensing']['format']);
		$this->assertSame('PAYLOAD', $payload['licensing']['payloadB64']);
		$this->assertSame('SIG', $payload['licensing']['signatureB64']);
		$this->assertSame('PAYLOAD', $payload['licensing']['envelope']['payloadB64']);
		$this->assertTrue($payload['licensing']['mobile']['enabledForUser']);
		$this->assertSame('2027-12-31', $payload['licensing']['mobile']['expiresAt']);
		$this->assertNotSame('', $payload['licensing']['vendorPublicKeyB64']);
		$this->assertTrue($payload['seatAssigned']);
		$this->assertTrue($payload['user']['isOffice']);
	}
}
