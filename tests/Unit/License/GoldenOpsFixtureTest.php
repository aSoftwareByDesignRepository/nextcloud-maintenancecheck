<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\License;

use OCA\MaintenanceCheck\Config\VendorPublicKey;
use OCA\MaintenanceCheck\License\Mn2Codec;
use PHPUnit\Framework\TestCase;

/**
 * Optional cross-check against vendor ops golden fixtures.
 * Enable with MN_OPS_FIXTURES_DIR pointing at a directory of golden JSON files.
 * Standalone clones skip — app fixtures under tests/fixtures/ remain the public SoT.
 */
final class GoldenOpsFixtureTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		if (!defined('PHPUNIT_RUNNING')) {
			define('PHPUNIT_RUNNING', true);
		}
	}

	private static function opsFixturePath(string $filename): ?string
	{
		$dir = getenv('MN_OPS_FIXTURES_DIR');
		if (!is_string($dir) || trim($dir) === '') {
			return null;
		}
		$path = rtrim($dir, '/') . '/' . $filename;
		return is_file($path) ? $path : null;
	}

	public function testOpsGoldenWireVerifies(): void
	{
		$opsFixture = self::opsFixturePath('license_mn2_golden.json');
		if ($opsFixture === null) {
			self::markTestSkipped('Set MN_OPS_FIXTURES_DIR to run optional vendor ops golden checks.');
		}
		$data = json_decode((string)file_get_contents($opsFixture), true, 512, JSON_THROW_ON_ERROR);
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . (string)$data['publicKeyB64']);
		putenv('MN_ALLOW_VENDOR_KEY_OVERRIDE=1');
		try {
			self::assertSame('', Mn2Codec::classifyError((string)$data['wireKey']));
			$parsed = Mn2Codec::parseAndVerify((string)$data['wireKey']);
			self::assertNotNull($parsed);
			self::assertSame($data['payload'], $parsed['payload']);
			self::assertSame($data['payloadB64'], $parsed['payloadB64']);
			self::assertSame($data['signatureB64'], $parsed['signatureB64']);
		} finally {
			putenv('MN_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
			putenv('MN_ALLOW_VENDOR_KEY_OVERRIDE=1');
		}
	}

	public function testForeignAzc2Rejected(): void
	{
		$opsFixture = self::opsFixturePath('license_azc2_golden.json');
		if ($opsFixture === null) {
			self::markTestSkipped('Set MN_OPS_FIXTURES_DIR to run optional vendor ops golden checks.');
		}
		$data = json_decode((string)file_get_contents($opsFixture), true, 512, JSON_THROW_ON_ERROR);
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . (string)$data['publicKeyB64']);
		putenv('MN_ALLOW_VENDOR_KEY_OVERRIDE=1');
		try {
			$code = Mn2Codec::classifyError((string)$data['wireKey']);
			self::assertSame(Mn2Codec::ERROR_INVALID_FORMAT, $code);
		} finally {
			putenv('MN_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
			putenv('MN_ALLOW_VENDOR_KEY_OVERRIDE=1');
		}
	}
}
