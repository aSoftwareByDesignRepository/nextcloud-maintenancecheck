#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: DueBoard bucket fences (S8).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/DueBoard.php';

runMutations(dirname(__DIR__, 2), 'DueBoardTest', [
	[
		'name' => 'overdue-includes-today',
		'file' => $file,
		'search' => 'if ($dueOn < $today) {',
		'replace' => 'if ($dueOn <= $today) {',
	],
	[
		'name' => 'today-bucket-inverted',
		'file' => $file,
		'search' => 'if ($dueOn === $today) {',
		'replace' => 'if ($dueOn !== $today) {',
	],
	[
		'name' => 'next7-window-is-eight-days',
		'file' => $file,
		'search' => 'if ($dueOn <= $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 7)) {',
		'replace' => 'if ($dueOn <= $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 8)) {',
	],
	[
		'name' => 'next7-fence-exclusive',
		'file' => $file,
		'search' => 'if ($dueOn <= $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 7)) {',
		'replace' => 'if ($dueOn < $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 7)) {',
	],
	[
		'name' => 'horizon-fence-exclusive',
		'file' => $file,
		'search' => 'if ($dueOn <= $this->maxDueOn($today)) {',
		'replace' => 'if ($dueOn < $this->maxDueOn($today)) {',
	],
	[
		'name' => 'horizon-shrunk',
		'file' => $file,
		'search' => 'return $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, self::HORIZON_DAYS);',
		'replace' => 'return $this->intervals->addInterval($today, IntervalCalculator::UNIT_DAY, 29);',
	],
]);
