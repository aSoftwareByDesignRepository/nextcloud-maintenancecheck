<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: SkillsAssignPolicy (AC-W2-2 skills bypass).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/SkillsAssignPolicy.php';

runMutations(dirname(__DIR__, 2), 'SkillsAssignPolicyTest', [
	[
		'name' => 'block-never-throws',
		'file' => $file,
		'search' => "if (\$enforcement === PolicyService::ENFORCEMENT_BLOCK) {\n\t\t\tthrow new ValidationException('skills_missing'",
		'replace' => "if (false && \$enforcement === PolicyService::ENFORCEMENT_BLOCK) {\n\t\t\tthrow new ValidationException('skills_missing'",
	],
	[
		'name' => 'warn-skips-force-gate',
		'file' => $file,
		'search' => "if (!\$force) {\n\t\t\t\tthrow new ConflictException('skills_warning'",
		'replace' => "if (false && !\$force) {\n\t\t\t\tthrow new ConflictException('skills_warning'",
	],
	[
		'name' => 'empty-missing-still-blocks',
		'file' => $file,
		'search' => "if (\$enforcement === PolicyService::ENFORCEMENT_OFF || \$missing === []) {\n\t\t\treturn [];\n\t\t}",
		'replace' => "if (\$enforcement === PolicyService::ENFORCEMENT_OFF) {\n\t\t\treturn [];\n\t\t}",
	],
]);
