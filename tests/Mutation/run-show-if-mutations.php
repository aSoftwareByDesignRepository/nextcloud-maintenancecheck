#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: ShowIfEvaluator visibility + authoring validation.
 * Avoid mutants that remove cycle trail checks (infinite recursion hangs PHPUnit).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/ShowIfEvaluator.php';

runMutations(dirname(__DIR__, 2), 'ShowIfEvaluatorTest', [
	[
		'name' => 'incomplete-rule-check-dropped',
		'file' => $file,
		'search' => 'if (($refCode === null) !== ($refResult === null)) {',
		'replace' => 'if (false && ($refCode === null) !== ($refResult === null)) {',
	],
	[
		'name' => 'self-ref-message-weakened',
		'file' => $file,
		'search' => "throw new ValidationException('show_if_cycle', 'An item cannot depend on itself.');",
		'replace' => "throw new ValidationException('show_if_cycle', 'Visibility rules must not form a cycle.');",
	],
	[
		'name' => 'unknown-ref-ignored',
		'file' => $file,
		'search' => 'if ($refCode !== null && !array_key_exists($refCode, $refs)) {',
		'replace' => 'if (false && $refCode !== null && !array_key_exists($refCode, $refs)) {',
	],
	[
		'name' => 'cycle-message-weakened',
		'file' => $file,
		'search' => "throw new ValidationException('show_if_cycle', 'Visibility rules must not form a cycle.');",
		'replace' => "throw new ValidationException('show_if_cycle', 'An item cannot depend on itself.');",
	],
	[
		'name' => 'always-visible-when-no-rule-inverted',
		'file' => $file,
		'search' => "if (\$item['showIfItemCode'] === null) {\n\t\t\t\treturn \$memo[\$code] = true;\n\t\t\t}",
		'replace' => "if (\$item['showIfItemCode'] === null) {\n\t\t\t\treturn \$memo[\$code] = false;\n\t\t\t}",
	],
	[
		'name' => 'parent-hidden-ignored',
		'file' => $file,
		'search' => "if (!\$resolve(\$item['showIfItemCode'], \$trail)) {\n\t\t\t\treturn \$memo[\$code] = false;\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\treturn \$memo[\$code] = false;\n\t\t\t}",
	],
	[
		'name' => 'any-answered-always-true',
		'file' => $file,
		'search' => "if (\$item['showIfResult'] === self::RESULT_ANY_ANSWERED) {\n\t\t\t\treturn \$memo[\$code] = in_array(\$parentResult, WoChecklistItem::RESULTS, true);\n\t\t\t}",
		'replace' => "if (\$item['showIfResult'] === self::RESULT_ANY_ANSWERED) {\n\t\t\t\treturn \$memo[\$code] = true;\n\t\t\t}",
	],
	[
		'name' => 'exact-result-always-true',
		'file' => $file,
		'search' => 'return $memo[$code] = ($parentResult !== null && $parentResult === $item[\'showIfResult\']);',
		'replace' => 'return $memo[$code] = true;',
	],
	[
		'name' => 'runtime-cycle-degrades-to-visible',
		'file' => $file,
		'search' => "if (isset(\$trail[\$code]) || !isset(\$byCode[\$code])) {\n\t\t\t\treturn \$memo[\$code] = false;",
		'replace' => "if (isset(\$trail[\$code]) || !isset(\$byCode[\$code])) {\n\t\t\t\treturn \$memo[\$code] = true;",
	],
]);
