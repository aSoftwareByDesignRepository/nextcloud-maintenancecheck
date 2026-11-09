#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * NN-03 gate (spec §10): every `due_on` day-window predicate on
 * mn_work_orders must use FA-1 span semantics.
 *
 * Rules:
 *  - lte('due_on', ...) is the legitimate *start* bound of an overlap pair —
 *    allowed.
 *  - lt|gt|gte|eq('due_on') in a file that queries mn_work_orders is a
 *    violation — the span-end comparator must be `due_until`.
 *  - Files whose only due_on use is on mn_visits (single-day entities) or
 *    validation/entity/migration code sit in the allowlist.
 *
 * PHPUnit mirror: tests/Unit/Service/CalendarScopeContractTest.php
 * (runs inside the suite; this script is the named CI check).
 *
 * Usage: php scripts/check-due-span-predicates.php
 */

$src = dirname(__DIR__) . '/lib';

$allowlist = [
	'Migration/',
	'Db/WorkOrder.php',
	'Service/WorkOrderSpan.php',
	'Service/InputValidator.php',
	'Service/VisitService.php',          // visit rows are single-day; WO rows go through dueUntil
	'Service/KpiService.php',            // visit KPI uses mn_visits.due_on; WO paths use due_until
	'Service/OverdueReminderService.php', // visit reminders use v.due_on; WO reminder uses due_until
];

$violations = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
	$path = str_replace('\\', '/', $file->getPathname());
	if (!str_ends_with($path, '.php')) {
		continue;
	}
	$relative = substr($path, strlen(dirname($src)) + 1);
	foreach ($allowlist as $allowed) {
		if (str_contains($relative, $allowed)) {
			continue 2;
		}
	}
	$content = (string)file_get_contents($path);
	if (!str_contains($content, 'mn_work_orders')) {
		continue;
	}
	if (preg_match('/->(lt|gt|gte|eq)\(\s*[\'"](?:[a-z]\.)?due_on[\'"]/', $content, $m)) {
		$violations[] = sprintf('%s — raw `%s(due_on)` predicate (use due_until for span-end comparisons)', $relative, $m[1]);
	}
}

if ($violations !== []) {
	fwrite(STDERR, "FA-1 span-semantics violations:\n  - " . implode("\n  - ", $violations) . "\n");
	exit(1);
}

echo "due-span predicates OK (FA-1 overlap semantics enforced).\n";
