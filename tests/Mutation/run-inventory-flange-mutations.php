#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: F6 InventoryFlangeService soft-fail + default-off (AC-S2.2 / AC-L5).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/InventoryFlangeService.php';

runMutations(dirname(__DIR__, 2), 'InventoryFlangeServiceTest', [
	[
		'name' => 'default-enabled-instead-of-off',
		'file' => $file,
		'search' => "self::KEY_F6_ENABLED, '0'",
		'replace' => "self::KEY_F6_ENABLED, '1'",
	],
	[
		'name' => 'throwable-returns-ok',
		'file' => $file,
		'search' => "return ['sync' => 'failed', 'code' => 'inventory_sync_failed'];",
		'replace' => "return ['sync' => 'ok', 'code' => null];",
	],
	[
		'name' => 'non-ok-facade-treated-as-ok',
		'file' => $file,
		'search' => "return ['sync' => 'failed', 'code' => \$result->code ?? 'inventory_sync_failed'];",
		'replace' => "return ['sync' => 'ok', 'code' => \$result->code ?? null];",
	],
	[
		'name' => 'disabled-path-still-issues',
		'file' => $file,
		'search' => "if (!\$this->isEnabled()) {\n\t\t\treturn ['sync' => 'disabled', 'code' => 'flange_disabled'];\n\t\t}",
		'replace' => "if (false && !\$this->isEnabled()) {\n\t\t\treturn ['sync' => 'disabled', 'code' => 'flange_disabled'];\n\t\t}",
	],
	[
		'name' => 'empty-lines-report-failed',
		'file' => $file,
		'search' => "if (\$lines === []) {\n\t\t\treturn ['sync' => 'ok', 'code' => null];\n\t\t}",
		'replace' => "if (\$lines === []) {\n\t\t\treturn ['sync' => 'failed', 'code' => 'empty'];\n\t\t}",
	],
]);
