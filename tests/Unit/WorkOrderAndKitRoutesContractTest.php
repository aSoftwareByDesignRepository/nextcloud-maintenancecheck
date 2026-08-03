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
			'work_order#comments',
			'work_order#addComment',
			'work_order#jobPackPdf',
			'work_order#serviceberichtPdf',
			'work_order#inspectionEvidencePdf',
			'work_order#downloadPhoto',
			'work_order#downloadSignature',
			'kit#indexTemplates',
			'kit#createTemplate',
			'kit#attach',
			'kit#packLine',
			'ops#kpi',
			'ops#kpiCsv',
			'ops#exceptions',
			'ops#failureCodes',
			'equip_doc#index',
			'equip_doc#download',
			'mobile#createWorkOrderFromVisit',
			'mobile#workOrderPackLine',
			'mobile#workOrderSignature',
			'mobile#equipmentMeters',
			'mobile#workOrderComments',
			'mobile#equipmentDocs',
			'mobile#failureCodes',
			'page#kpi',
			'page#exceptions',
		];
		foreach ($required as $name) {
			$this->assertContains($name, $names, "Missing route $name");
		}
	}
}
