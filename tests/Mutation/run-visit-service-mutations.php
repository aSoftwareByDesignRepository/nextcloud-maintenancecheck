#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: VisitService close / skip / conflict semantics (SPEC §14.4 ≥90%).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/VisitService.php';

runMutations(dirname(__DIR__, 2), 'VisitServiceTest', [
	[
		'name' => 'ignore-close-scheduled-failure',
		'file' => $file,
		'search' => 'if (!$this->visits->closeScheduled($visitId, $set)) {',
		'replace' => 'if (false && !$this->visits->closeScheduled($visitId, $set)) {',
	],
	[
		'name' => 'inactive-plan-still-schedules-follow-up',
		'file' => $file,
		'search' => "if (\$plan->getActive()\n\t\t\t&& \$plan->usesIntervalTrigger()\n\t\t\t&& \$this->visits->findOpenByPlan(\$planId) === null\n\t\t) {",
		'replace' => "if (!\$plan->getActive()\n\t\t\t&& \$plan->usesIntervalTrigger()\n\t\t\t&& \$this->visits->findOpenByPlan(\$planId) === null\n\t\t) {",
	],
	[
		'name' => 'lockrow-failure-still-schedules',
		'file' => $file,
		'search' => 'if (!$this->plans->lockRow($planId)) {',
		'replace' => 'if (false && !$this->plans->lockRow($planId)) {',
	],
	[
		'name' => 'skip-anchors-from-stale-date',
		'file' => $file,
		'search' => 'return $this->close($uid, $visitId, Visit::STATUS_SKIPPED, $today, $body, fn (): string => $this->clock->today());',
		'replace' => 'return $this->close($uid, $visitId, Visit::STATUS_SKIPPED, $today, $body, static fn (): string => \'2020-01-01\');',
	],
	[
		'name' => 'conflict-code-wrong',
		'file' => $file,
		'search' => "throw new ConflictException('visit_not_open', 'This visit was already closed.');",
		'replace' => "throw new ConflictException('visit_already_open', 'This visit was already closed.');",
	],
	[
		'name' => 'cancel-skips-conditional-close',
		'file' => $file,
		'search' => "\$closed = \$this->visits->closeScheduled(\$visitId, [\n\t\t\t\t'status' => Visit::STATUS_CANCELLED,\n\t\t\t\t'updated_at' => \$now,\n\t\t\t]);\n\t\t\tif (!\$closed) {",
		'replace' => "\$closed = true;\n\t\t\t\$this->visits->closeScheduled(\$visitId, [\n\t\t\t\t'status' => Visit::STATUS_CANCELLED,\n\t\t\t\t'updated_at' => \$now,\n\t\t\t]);\n\t\t\tif (!\$closed) {",
	],
	[
		'name' => 'assign-skips-user-exists',
		'file' => $file,
		'search' => 'if (!$this->userManager->userExists($userId)) {',
		'replace' => 'if (false && !$this->userManager->userExists($userId)) {',
	],
	[
		'name' => 'reschedule-ignores-update-failure',
		'file' => $file,
		'search' => 'if (!$this->visits->updateScheduled($visitId, $set)) {',
		'replace' => 'if (false && !$this->visits->updateScheduled($visitId, $set)) {',
	],
	[
		'name' => 'complete-uses-skip-status',
		'file' => $file,
		'search' => 'return $this->close($uid, $visitId, Visit::STATUS_DONE, $doneOn, $body, static fn (string $done): string => $done);',
		'replace' => 'return $this->close($uid, $visitId, Visit::STATUS_SKIPPED, $doneOn, $body, static fn (string $done): string => $done);',
	],
	[
		'name' => 'dbbool-uses-php-cast-on-strings',
		'file' => $file,
		'search' => "if (is_string(\$value)) {\n\t\t\t\$normalized = strtolower(trim(\$value));\n\t\t\treturn \$normalized === '1'\n\t\t\t\t|| \$normalized === 't'\n\t\t\t\t|| \$normalized === 'true'\n\t\t\t\t|| \$normalized === 'yes'\n\t\t\t\t|| \$normalized === 'on';\n\t\t}",
		'replace' => "if (is_string(\$value)) {\n\t\t\treturn (bool)\$value;\n\t\t}",
	],
]);
