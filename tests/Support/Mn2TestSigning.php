<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Support;

use OCA\MaintenanceCheck\Config\VendorPublicKey;
use OCA\MaintenanceCheck\License\Mn2Codec;

/**
 * Deterministic MN2 signing for tests — matches the fixture key in
 * license_mn2.json. Seed: sha256("maintenancecheck-mn2-test-signing-v1").
 * NEVER use outside of tests.
 */
final class Mn2TestSigning
{
	public const SEED_STRING = 'maintenancecheck-mn2-test-signing-v1';

	public static function secretKey(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return sodium_crypto_sign_secretkey($keypair);
	}

	public static function publicKeyB64(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($keypair));
	}

	/**
	 * Signs a canonicalised payload into a full MN2 wire key.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function signPayload(array $payload): string
	{
		$json = Mn2Codec::canonicalJson($payload);
		return self::signRawBytes($json);
	}

	/**
	 * Signs arbitrary bytes (for non-canonical / tampered-payload tests).
	 */
	public static function signRawBytes(string $payloadBytes): string
	{
		$signature = sodium_crypto_sign_detached($payloadBytes, self::secretKey());
		return Mn2Codec::FORMAT . '.'
			. VendorPublicKey::base64urlEncode($payloadBytes) . '.'
			. VendorPublicKey::base64urlEncode($signature);
	}
}
