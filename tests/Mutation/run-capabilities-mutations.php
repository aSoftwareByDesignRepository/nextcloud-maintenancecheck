<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: MobileCapabilities advertisement (COMPANION §9.2).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/MobileCapabilities.php';

runMutations(dirname(__DIR__, 2), 'MobileCapabilitiesTest|MobileGateServiceTest|W6FieldOpsContractTest', [
	[
		'name' => 'visits-false',
		'file' => $file,
		'search' => "'visits' => true,",
		'replace' => "'visits' => false,",
	],
	[
		'name' => 'work-orders-false',
		'file' => $file,
		'search' => "'workOrders' => true,",
		'replace' => "'workOrders' => false,",
	],
	[
		'name' => 'qr-false',
		'file' => $file,
		'search' => "'qr' => true,",
		'replace' => "'qr' => false,",
	],
	[
		'name' => 'conditional-false',
		'file' => $file,
		'search' => "'conditionalChecklist' => true,",
		'replace' => "'conditionalChecklist' => false,",
	],
	[
		'name' => 'service-report-false',
		'file' => $file,
		'search' => "'serviceReport' => true,",
		'replace' => "'serviceReport' => false,",
	],
	[
		'name' => 'meters-false',
		'file' => $file,
		'search' => "'meters' => true,",
		'replace' => "'meters' => false,",
	],
	[
		'name' => 'request-intake-false',
		'file' => $file,
		'search' => "'requestIntake' => true,",
		'replace' => "'requestIntake' => false,",
	],
	[
		'name' => 'failure-codes-false',
		'file' => $file,
		'search' => "'failureCodes' => true,",
		'replace' => "'failureCodes' => false,",
	],
]);
