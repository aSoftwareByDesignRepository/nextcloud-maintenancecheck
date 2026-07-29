#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation score for pure table helpers in js/app.js (design-system §3.7).
 * Kills: drop key-alias, blank cell text, empty-column leakage, wrong rowCount.
 */

$root = dirname(__DIR__, 2);
$source = (string)file_get_contents($root . '/js/app.js');

function extractFunction(string $source, string $name): string
{
	if (!preg_match('/function ' . preg_quote($name, '/') . '\((.*?)\{/s', $source, $m, PREG_OFFSET_CAPTURE)) {
		fwrite(STDERR, "Cannot find function {$name}\n");
		exit(2);
	}
	$start = (int)$m[0][1];
	$brace = strpos($source, '{', $start);
	$depth = 0;
	$len = strlen($source);
	for ($i = $brace; $i < $len; $i++) {
		$ch = $source[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($source, $start, $i - $start + 1);
			}
		}
	}
	fwrite(STDERR, "Unbalanced braces for {$name}\n");
	exit(2);
}

$helpers = extractFunction($source, 'normalizeTableColumns')
	. "\n"
	. extractFunction($source, 'tableCellText')
	. "\n"
	. extractFunction($source, 'buildTableModel');

$harness = <<<'JS'
const assert = require('assert');
%s
function runSuite() {
	assert.deepStrictEqual(normalizeTableColumns(null), []);
	assert.deepStrictEqual(normalizeTableColumns([{ key: 'status', label: 'Status' }]), [
		{ id: 'status', label: 'Status', className: '', actions: false },
	]);
	assert.deepStrictEqual(normalizeTableColumns([{ id: 'x', label: ' X ', actions: 1 }]), [
		{ id: 'x', label: 'X', className: '', actions: true },
	]);
	assert.strictEqual(tableCellText(null), '—');
	assert.strictEqual(tableCellText(''), '—');
	assert.strictEqual(tableCellText('ok'), 'ok');
	const model = buildTableModel([{ id: 'a', label: 'A' }, { key: 'b', label: 'B', actions: true }], [1, 2, 3]);
	assert.strictEqual(model.rowCount, 3);
	assert.strictEqual(model.columns.length, 2);
	assert.strictEqual(model.columns[1].actions, true);
	assert.strictEqual(buildTableModel([], null).rowCount, 0);
}
runSuite();
JS;

$tmpBase = sys_get_temp_dir() . '/mn-table-mut-' . getmypid();
@mkdir($tmpBase);

function writeHarness(string $dir, string $helpers, string $harnessTpl): string
{
	$path = $dir . '/run.js';
	file_put_contents($path, sprintf($harnessTpl, $helpers));
	return $path;
}

function nodeOk(string $file): bool
{
	$cmd = 'node ' . escapeshellarg($file) . ' 2>/dev/null';
	exec($cmd, $out, $code);
	return $code === 0;
}

$baseline = writeHarness($tmpBase, $helpers, $harness);
if (!nodeOk($baseline)) {
	fwrite(STDERR, "Baseline table helpers failed\n");
	exit(1);
}

$mutations = [
	'key_alias' => static function (string $h): string {
		return str_replace(
			"if (!id && typeof col.key === 'string') {\n\t\t\t\tid = col.key.trim();\n\t\t\t}",
			"if (false && typeof col.key === 'string') {\n\t\t\t\tid = col.key.trim();\n\t\t\t}",
			$h
		);
	},
	'blank_cell' => static function (string $h): string {
		return str_replace("return text === '' ? '—' : text;", "return text;", $h);
	},
	'null_cell' => static function (string $h): string {
		return preg_replace(
			'/if \(value === null \|\| value === undefined\) \{\s*return \'—\';/s',
			"if (value === null || value === undefined) {\n\t\t\treturn '';",
			$h,
			1
		) ?? $h;
	},
	'drop_actions' => static function (string $h): string {
		return str_replace('actions: !!col.actions,', 'actions: false,', $h);
	},
	'row_count_zero' => static function (string $h): string {
		return str_replace(
			'rowCount: Array.isArray(rows) ? rows.length : 0,',
			'rowCount: 0,',
			$h
		);
	},
	'skip_label_trim' => static function (string $h): string {
		return str_replace(
			"var label = typeof col.label === 'string' ? col.label.trim() : '';",
			"var label = typeof col.label === 'string' ? col.label : '';",
			$h
		);
	},
];

$killed = 0;
$total = 0;
foreach ($mutations as $name => $mutator) {
	$total++;
	$mutated = $mutator($helpers);
	if ($mutated === $helpers) {
		fwrite(STDERR, "Mutation {$name} did not change source\n");
		exit(1);
	}
	$path = writeHarness($tmpBase, $mutated, $harness);
	if (nodeOk($path)) {
		fwrite(STDERR, "SURVIVED: {$name}\n");
	} else {
		$killed++;
		echo "Killed {$name}\n";
	}
}

$score = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "Mutation score: {$killed}/{$total} ({$score}%)\n";
if ($killed !== $total) {
	exit(1);
}
