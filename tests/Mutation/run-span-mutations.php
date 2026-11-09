#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: WorkOrderSpan (FA-1 multi-day span math).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/WorkOrderSpan.php';

runMutations(dirname(__DIR__, 2), 'WorkOrderSpanTest', [
	[
		'name' => 'span-cap-31-to-32',
		'file' => $file,
		'search' => 'public const MAX_SPAN_DAYS = 31;',
		'replace' => 'public const MAX_SPAN_DAYS = 32;',
	],
	[
		'name' => 'inclusive-span-off-by-one',
		'file' => $file,
		'search' => 'return intdiv($endTs - $startTs, 86400) + 1;',
		'replace' => 'return intdiv($endTs - $startTs, 86400);',
	],
	[
		'name' => 'inverted-span-accepted',
		'file' => $file,
		'search' => 'if ($startTs === false || $endTs === false || $endTs < $startTs) {',
		'replace' => 'if ($startTs === false || $endTs === false) {',
	],
	[
		'name' => 'covers-upper-bound-exclusive',
		'file' => $file,
		'search' => 'return $dueOn <= $day && $end !== null && $end >= $day;',
		'replace' => 'return $dueOn <= $day && $end !== null && $end > $day;',
	],
	[
		'name' => 'charge-ceil-to-floor',
		'file' => $file,
		'search' => 'return (int)ceil($estimatedMinutes / $spanDays);',
		'replace' => 'return (int)floor($estimatedMinutes / $spanDays);',
	],
	[
		'name' => 'charge-guard-dropped',
		'file' => $file,
		'search' => "if (\$estimatedMinutes <= 0) {\n\t\t\treturn 0;\n\t\t}\n\t\t",
		'replace' => '',
	],
	[
		'name' => 'span-cap-strict-gte',
		'file' => $file,
		'search' => 'if ($days !== null && $days > self::MAX_SPAN_DAYS) {',
		'replace' => 'if ($days !== null && $days >= self::MAX_SPAN_DAYS) {',
	],
	[
		'name' => 'inverted-pair-equal-rejected',
		'file' => $file,
		'search' => 'if ($dueUntil !== null && $dueUntil < $dueOn) {',
		'replace' => 'if ($dueUntil !== null && $dueUntil <= $dueOn) {',
	],
	[
		'name' => 'until-without-on-check-dropped',
		'file' => $file,
		'search' => 'if ($dueUntil !== null && $dueOn === null) {',
		'replace' => 'if (false) {',
	],
	[
		'name' => 'effective-end-fallback-dropped',
		'file' => $file,
		'search' => 'return ($dueUntil === null || $dueUntil === \'\') ? $dueOn : $dueUntil;',
		'replace' => 'return $dueUntil;',
	],
]);
