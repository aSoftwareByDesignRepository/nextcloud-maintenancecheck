<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Config;

use OCA\MaintenanceCheck\Config\VendorPublicKey;
use PHPUnit\Framework\TestCase;

/**
 * N5: production must never honour a forged MN_VENDOR_PUBLIC_KEY_B64 from env
 * unless an explicit test harness flag (or PHPUnit) is present.
 */
final class VendorPublicKeyTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('MN_VENDOR_PUBLIC_KEY_B64');
		putenv('MN_ALLOW_VENDOR_KEY_OVERRIDE');
	}

	public function testEmbeddedDefaultIsUsedWhenEnvEmpty(): void
	{
		putenv('MN_VENDOR_PUBLIC_KEY_B64');
		$this->assertSame(VendorPublicKey::DEFAULT_PUBLIC_KEY_B64, VendorPublicKey::publicKeyB64());
	}

	public function testPhpunitAllowsEnvOverrideForFixtures(): void
	{
		$this->assertTrue(VendorPublicKey::envOverrideAllowed());
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
		$this->assertSame(VendorPublicKey::TEST_PUBLIC_KEY_B64, VendorPublicKey::publicKeyB64());
	}

	public function testBytesRejectInvalidKeyMaterial(): void
	{
		putenv('MN_VENDOR_PUBLIC_KEY_B64=not-valid-base64url!!!!');
		$this->expectException(\RuntimeException::class);
		VendorPublicKey::bytes();
	}
}
