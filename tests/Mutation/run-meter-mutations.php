#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: AC-W5-2 pure meter skip of IntervalCalculator + MeterMath compare.
 */

require __DIR__ . '/harness.php';

runMutations(dirname(__DIR__, 2), 'VisitServiceTest|MeterMathTest|MeterClosingAndImportTest', [
	[
		'name' => 'meter-plan-still-calls-interval',
		'file' => 'lib/Service/VisitService.php',
		'search' => "&& \$plan->usesIntervalTrigger()\n\t\t\t&& \$this->visits->findOpenByPlan(\$planId) === null",
		'replace' => "&& true\n\t\t\t&& \$this->visits->findOpenByPlan(\$planId) === null",
	],
	[
		'name' => 'compare-always-zero',
		'file' => 'lib/Service/MeterMath.php',
		'search' => "\$cmp = \$cmp <=> 0;\n\t\treturn \$signA < 0 ? -\$cmp : \$cmp;",
		'replace' => "\$cmp = \$cmp <=> 0;\n\t\treturn 0;",
	],
	[
		'name' => 'normalize-skips-fraction-pad',
		'file' => 'lib/Service/MeterMath.php',
		'search' => "\$frac = str_pad(substr(\$frac, 0, 3), 3, '0');",
		'replace' => "\$frac = substr(\$frac, 0, 3);",
	],
	[
		'name' => 'closing-reading-still-evaluates-due',
		'file' => 'lib/Service/MeterService.php',
		'search' => "\$inserted = \$this->insertReadingLocked(\n\t\t\t\$uid,\n\t\t\t\$meterId,\n\t\t\t\$value,\n\t\t\t\$readOn,\n\t\t\t\$note,\n\t\t\tMeterReading::SOURCE_MANUAL,\n\t\t\t\$now,\n\t\t);\n\t\treturn \$inserted['reading']->toApi();",
		'replace' => "\$inserted = \$this->insertReadingLocked(\n\t\t\t\$uid,\n\t\t\t\$meterId,\n\t\t\t\$value,\n\t\t\t\$readOn,\n\t\t\t\$note,\n\t\t\tMeterReading::SOURCE_MANUAL,\n\t\t\t\$now,\n\t\t);\n\t\t\$this->evaluateDue(\$inserted['meter'], \$value, \$today, \$now);\n\t\treturn \$inserted['reading']->toApi();",
	],
	[
		'name' => 'import-uses-manual-source',
		'file' => 'lib/Service/MeterService.php',
		'search' => "MeterReading::SOURCE_IMPORT,",
		'replace' => "MeterReading::SOURCE_MANUAL,",
	],
]);
