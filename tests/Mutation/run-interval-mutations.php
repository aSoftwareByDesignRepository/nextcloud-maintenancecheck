#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: IntervalCalculator (SPEC §6.1 clamp math + S19 bounds).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/IntervalCalculator.php';

runMutations(dirname(__DIR__, 2), 'IntervalCalculatorTest', [
	[
		'name' => 'clamp-min-becomes-max',
		'file' => $file,
		'search' => '$targetDay = min($d, $this->daysInMonth($targetYear, $targetMonth));',
		'replace' => '$targetDay = max($d, $this->daysInMonth($targetYear, $targetMonth));',
	],
	[
		'name' => 'year-is-eleven-months',
		'file' => $file,
		'search' => "\$count *= 12;",
		'replace' => "\$count *= 11;",
	],
	[
		'name' => 'week-is-eight-days',
		'file' => $file,
		'search' => '$this->addDays($y, $m, $d, $count * 7)',
		'replace' => '$this->addDays($y, $m, $d, $count * 8)',
	],
	[
		'name' => 'count-zero-allowed',
		'file' => $file,
		'search' => 'if ($count < 1 || $count > self::MAX_COUNT[$unit]) {',
		'replace' => 'if ($count < 0 || $count > self::MAX_COUNT[$unit]) {',
	],
	[
		'name' => 'max-count-off-by-one',
		'file' => $file,
		'search' => 'if ($count < 1 || $count > self::MAX_COUNT[$unit]) {',
		'replace' => 'if ($count < 1 || $count >= self::MAX_COUNT[$unit]) {',
	],
	[
		'name' => 'target-month-off-by-one',
		'file' => $file,
		'search' => '$targetMonth = ($monthIndex % 12) + 1;',
		'replace' => '$targetMonth = ($monthIndex % 12) + 2;',
	],
	[
		'name' => 'target-year-truncated',
		'file' => $file,
		'search' => '$targetYear = $y + intdiv($monthIndex, 12);',
		'replace' => '$targetYear = $y + intdiv($monthIndex, 13);',
	],
	[
		'name' => 'checkdate-bypassed',
		'file' => $file,
		'search' => 'return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);',
		'replace' => 'return true;',
	],
	[
		'name' => 'unit-allowlist-bypassed',
		'file' => $file,
		'search' => "if (!in_array(\$unit, self::UNITS, true)) {",
		'replace' => "if (false) {",
	],
]);
