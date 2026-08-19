<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory;
use PHPUnit\Framework\TestCase;

/**
 * Every registered route must have an explicit auth gate. Adding a route
 * without classifying it fails CI instead of shipping another hidden Planning leak.
 */
final class RoutesAuthInventoryContractTest extends TestCase
{
	public function testEveryRegisteredRouteHasAnAuthGate(): void
	{
		$routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		$gates = RouteAuthInventory::gates();
		$unknown = array_values(array_diff($names, array_keys($gates)));
		$stale = array_values(array_diff(array_keys($gates), $names));
		$this->assertSame([], $unknown, 'New routes must be classified in RouteAuthInventory::gates()');
		$this->assertSame([], $stale, 'Remove stale gate rows for deleted routes');
		$this->assertCount(count($names), $gates);
		foreach ($gates as $name => $gate) {
			$this->assertContains($gate, RouteAuthInventory::ALLOWED_GATES, $name . ' has unknown gate ' . $gate);
			$this->assertTrue(class_exists(RouteAuthInventory::controllerClass($name)));
		}
	}
}
