#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: DueQueryKind (COMP §9.2 filter alias).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/DueQueryKind.php';

runMutations(dirname(__DIR__, 2), 'DueQueryKindTest', [
	[
		'name' => 'kind-precedence-dropped',
		'file' => $file,
		'search' => "\$primary = self::normalize(\$kind);\n\t\tif (\$primary !== null) {\n\t\t\treturn \$primary;\n\t\t}\n\t\treturn self::normalize(\$filter);",
		'replace' => "return self::normalize(\$filter) ?? self::normalize(\$kind);",
	],
	[
		'name' => 'empty-string-kept',
		'file' => $file,
		'search' => "\$trimmed = trim(\$raw);\n\t\treturn \$trimmed === '' ? null : \$trimmed;",
		'replace' => 'return $raw;',
	],
	[
		'name' => 'filter-ignored',
		'file' => $file,
		'search' => "\$primary = self::normalize(\$kind);\n\t\tif (\$primary !== null) {\n\t\t\treturn \$primary;\n\t\t}\n\t\treturn self::normalize(\$filter);",
		'replace' => "return self::normalize(\$kind);",
	],
]);
