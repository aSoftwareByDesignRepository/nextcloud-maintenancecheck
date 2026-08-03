#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: InspectionFollowUpGuard (AC-W7-5 idempotency).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/InspectionFollowUpGuard.php';

runMutations(dirname(__DIR__, 2), 'InspectionFollowUpGuardTest', [
	[
		'name' => 'always-create',
		'file' => $file,
		'search' => "if (\$existingCorrectiveId !== null && \$existingCorrectiveId > 0) {\n\t\t\treturn 'reuse';\n\t\t}",
		'replace' => "if (false && \$existingCorrectiveId !== null && \$existingCorrectiveId > 0) {\n\t\t\treturn 'reuse';\n\t\t}",
	],
	[
		'name' => 'zero-treated-as-reuse',
		'file' => $file,
		'search' => 'if ($existingCorrectiveId !== null && $existingCorrectiveId > 0) {',
		'replace' => 'if ($existingCorrectiveId !== null && $existingCorrectiveId >= 0) {',
	],
	[
		'name' => 'null-treated-as-reuse',
		'file' => $file,
		'search' => "if (\$existingCorrectiveId !== null && \$existingCorrectiveId > 0) {\n\t\t\treturn 'reuse';\n\t\t}\n\t\treturn 'create';",
		'replace' => "if (\$existingCorrectiveId !== null && \$existingCorrectiveId > 0) {\n\t\t\treturn 'reuse';\n\t\t}\n\t\treturn 'reuse';",
	],
]);
