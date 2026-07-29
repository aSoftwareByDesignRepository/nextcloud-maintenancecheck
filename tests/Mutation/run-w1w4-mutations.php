#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for W1–W4 pure gates used by MSI overall ≥ 80%.
 */

require __DIR__ . '/harness.php';

runMutations(
	dirname(__DIR__, 2),
	'KitReadinessTest|ChecklistPolicyTest|WorkOrderStateMachineTest|TourSortTest|CapacityCalculatorTest',
	[
		[
			'name' => 'optional-still-blocks',
			'file' => 'lib/Service/KitReadiness.php',
			'search' => "if (\$line['optional']) {\n\t\t\t\tcontinue;\n\t\t\t}",
			'replace' => "if (false) {\n\t\t\t\tcontinue;\n\t\t\t}",
		],
		[
			'name' => 'off-by-one-pack-gate',
			'file' => 'lib/Service/KitReadiness.php',
			'search' => "if (\$line['qtyPacked'] < \$line['qtyRequired']) {",
			'replace' => "if (\$line['qtyPacked'] <= \$line['qtyRequired']) {",
		],
		[
			'name' => 'fail-does-not-block',
			'file' => 'lib/Service/ChecklistPolicy.php',
			'search' => "if (\$item['result'] === WoChecklistItem::RESULT_FAIL) {\n\t\t\t\t\$failed[] = ['code' => \$item['code'], 'label' => \$item['label']];\n\t\t\t}",
			'replace' => "if (false) {\n\t\t\t\t\$failed[] = ['code' => \$item['code'], 'label' => \$item['label']];\n\t\t\t}",
		],
		[
			'name' => 'hidden-items-still-required',
			'file' => 'lib/Service/ChecklistPolicy.php',
			'search' => "if (!(\$visibility[\$item['code']] ?? false)) {\n\t\t\t\tcontinue;\n\t\t\t}",
			'replace' => "if (false) {\n\t\t\t\tcontinue;\n\t\t\t}",
		],
		[
			'name' => 'done-allows-restart',
			'file' => 'lib/Service/WorkOrderStateMachine.php',
			'search' => "WorkOrder::STATUS_DONE => [],",
			'replace' => "WorkOrder::STATUS_DONE => [WorkOrder::STATUS_IN_PROGRESS],",
		],
		[
			'name' => 'tie-break-by-id-desc',
			'file' => 'lib/Service/TourSort.php',
			'search' => "|| (\$distance === \$bestDistance && \$candidate['id'] < \$remaining[\$bestIndex]['id'])",
			'replace' => "|| (\$distance === \$bestDistance && \$candidate['id'] > \$remaining[\$bestIndex]['id'])",
		],
		[
			'name' => 'exceeds-uses-gte',
			'file' => 'lib/Service/CapacityCalculator.php',
			'search' => "'exceeds' => \$projected > \$threshold,",
			'replace' => "'exceeds' => \$projected >= \$threshold,",
		],
	],
);
