#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: InspectionClosePolicy (W7 Done gates).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/InspectionClosePolicy.php';

runMutations(dirname(__DIR__, 2), 'InspectionClosePolicyTest', [
	[
		'name' => 'result-required-check-dropped',
		'file' => $file,
		'search' => 'if ($resultRequired && ($result === \'\' || !in_array($result, WorkOrder::RESULTS, true))) {',
		'replace' => 'if (false && $resultRequired && ($result === \'\' || !in_array($result, WorkOrder::RESULTS, true))) {',
	],
	[
		'name' => 'inspector-required-check-dropped',
		'file' => $file,
		'search' => 'if ($resultRequired && $inspector === \'\') {',
		'replace' => 'if (false && $resultRequired && $inspector === \'\') {',
	],
	[
		'name' => 'defects-required-on-fail-dropped',
		'file' => $file,
		'search' => 'if ($result !== \'\' && $result !== WorkOrder::RESULT_PASS && $defects === []) {',
		'replace' => 'if (false && $result !== \'\' && $result !== WorkOrder::RESULT_PASS && $defects === []) {',
	],
	[
		'name' => 'invalid-result-accepted',
		'file' => $file,
		'search' => 'if ($result !== \'\' && !in_array($result, WorkOrder::RESULTS, true)) {',
		'replace' => 'if (false && $result !== \'\' && !in_array($result, WorkOrder::RESULTS, true)) {',
	],
	[
		'name' => 'empty-defect-code-accepted',
		'file' => $file,
		'search' => 'if ($code === \'\' || $body === \'\') {',
		'replace' => 'if (false && ($code === \'\' || $body === \'\')) {',
	],
	[
		'name' => 'non-array-defects-accepted',
		'file' => $file,
		'search' => 'if (!is_array($raw)) {',
		'replace' => 'if (false && !is_array($raw)) {',
	],
	[
		'name' => 'pass-still-requires-defects',
		'file' => $file,
		'search' => 'if ($result !== \'\' && $result !== WorkOrder::RESULT_PASS && $defects === []) {',
		'replace' => 'if ($result !== \'\' && $defects === []) {',
	],
]);
