<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory;
use PHPUnit\Framework\TestCase;

/**
 * Committed OpenAPI must match routes.php + gates. Regenerating:
 * php scripts/generate-openapi.php
 */
final class OpenApiInventoryContractTest extends TestCase
{
	public function testOpenApiFixtureMatchesRoutesAndGates(): void
	{
		$appRoot = dirname(__DIR__, 2);
		$routes = require $appRoot . '/appinfo/routes.php';
		$generated = RouteAuthInventory::openapiDocument($routes);
		$fixturePath = $appRoot . '/tests/fixtures/openapi.json';
		$this->assertFileExists($fixturePath);
		$onDisk = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(
			$generated,
			$onDisk,
			'openapi.json is stale. Run: php scripts/generate-openapi.php',
		);
		$operationIds = [];
		foreach ($generated['paths'] as $methods) {
			foreach ($methods as $op) {
				$operationIds[] = $op['operationId'];
			}
		}
		sort($operationIds);
		$names = array_column($routes['routes'], 'name');
		sort($names);
		$this->assertSame($names, $operationIds);
	}
}
