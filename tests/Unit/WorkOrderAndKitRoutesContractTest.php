<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WP-S2-MN-W1/W2 — controllers must be routable (no orphan HTTP surface).
 */
final class WorkOrderAndKitRoutesContractTest extends TestCase
{
	public function testWorkOrderAndKitRoutesAreRegistered(): void
	{
		$routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		$required = [
			'work_order#create',
			'work_order#createFromVisit',
			'work_order#setChecklistResult',
			'work_order#listPhotos',
			'work_order#addPhoto',
			'work_order#setSignature',
			'kit#indexTemplates',
			'kit#createTemplate',
			'kit#attach',
			'kit#packLine',
			'mobile#workOrderPackLine',
			'mobile#workOrderSignature',
			'mobile#equipmentMeters',
		];
		foreach ($required as $name) {
			$this->assertContains($name, $names, "Missing route $name");
		}
	}
}
