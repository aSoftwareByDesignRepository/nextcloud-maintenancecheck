<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Support;

/**
 * SPEC §7.1 JSON envelopes. Used to validate live controller output against
 * the OpenAPI components.schemas (no extra JSON-Schema library).
 */
final class JsonEnvelope
{
	public static function isError(mixed $body): bool
	{
		if (!is_array($body) || !isset($body['error']) || !is_array($body['error'])) {
			return false;
		}
		$code = $body['error']['code'] ?? null;
		$message = $body['error']['message'] ?? null;
		return is_string($code) && $code !== '' && is_string($message);
	}

	public static function isList(mixed $body): bool
	{
		return is_array($body) && array_key_exists('data', $body) && is_array($body['data']);
	}

	public static function isJsonObject(mixed $body): bool
	{
		return is_array($body) && $body !== [] && array_is_list($body) === false;
	}
}
