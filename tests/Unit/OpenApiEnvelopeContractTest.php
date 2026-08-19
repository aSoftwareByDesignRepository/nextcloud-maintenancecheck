<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Tests\Support\JsonEnvelope;
use OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory;
use PHPUnit\Framework\TestCase;

final class OpenApiEnvelopeContractTest extends TestCase
{
	public function testJsonApiOperationsReferenceErrorAndSuccessSchemas(): void
	{
		$appRoot = dirname(__DIR__, 2);
		$routes = require $appRoot . '/appinfo/routes.php';
		$doc = RouteAuthInventory::openapiDocument($routes);
		$this->assertArrayHasKey('ErrorEnvelope', $doc['components']['schemas']);
		$this->assertArrayHasKey('ListEnvelope', $doc['components']['schemas']);

		$jsonOps = 0;
		foreach ($routes['routes'] as $route) {
			$name = (string)$route['name'];
			$url = (string)$route['url'];
			$verb = strtolower((string)$route['verb']);
			if (!RouteAuthInventory::isJsonApi($url) || RouteAuthInventory::isBinaryDownload($name)) {
				continue;
			}
			$jsonOps++;
			$op = $doc['paths'][$url][$verb];
			$this->assertSame(
				'#/components/schemas/ErrorEnvelope',
				$op['responses']['401']['content']['application/json']['schema']['$ref'],
				$name . ' 401',
			);
			$this->assertSame(
				'#/components/schemas/ErrorEnvelope',
				$op['responses']['403']['content']['application/json']['schema']['$ref'],
				$name . ' 403',
			);
			$oneOf = $op['responses']['200']['content']['application/json']['schema']['oneOf'] ?? [];
			$refs = array_column($oneOf, '$ref');
			$this->assertContains('#/components/schemas/ListEnvelope', $refs, $name . ' 200 ListEnvelope');
			$this->assertContains('#/components/schemas/JsonObject', $refs, $name . ' 200 JsonObject');
		}
		$this->assertGreaterThan(100, $jsonOps, 'JSON API surface shrank unexpectedly');
	}

	public function testErrorEnvelopeMatchesSpec71Samples(): void
	{
		$this->assertTrue(JsonEnvelope::isError([
			'error' => ['code' => 'permission_denied', 'message' => 'No'],
		]));
		$this->assertTrue(JsonEnvelope::isError([
			'error' => ['code' => 'visit_not_open', 'message' => 'Closed', 'details' => ['id' => 1]],
		]));
		$this->assertFalse(JsonEnvelope::isError(['error' => ['code' => '', 'message' => 'x']]));
		$this->assertFalse(JsonEnvelope::isError(['data' => []]));
		$this->assertTrue(JsonEnvelope::isList(['data' => [], 'total' => 0]));
		$this->assertFalse(JsonEnvelope::isList(['id' => 1]));
		$this->assertTrue(JsonEnvelope::isJsonObject(['id' => 1, 'name' => 'Acme']));
	}
}
