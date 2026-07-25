<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MobileGateService;
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
		return new MobileGateService($license);
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
		$this->assertSame(
			['uid' => 'tech1', 'displayName' => 'Tech One', 'isOffice' => false],
			$payload['user'],
		);
	}

	public function testBootstrapExposesWireParts(): void
	{
		$payload = $this->gate([])->bootstrapPayload('tech1', 'Tech One', true);
		$this->assertSame(
			['format' => 'MN2', 'payloadB64' => 'PAYLOAD', 'signatureB64' => 'SIG'],
			$payload['licensing'],
		);
		$this->assertTrue($payload['seatAssigned']);
		$this->assertTrue($payload['user']['isOffice']);
	}
}
