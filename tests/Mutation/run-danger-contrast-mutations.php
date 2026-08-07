#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: danger buttons must keep solid --mn-danger-fill
 * (NC --color-element-error), never pale --color-error tint + white text.
 */

require __DIR__ . '/harness.php';

$app = 'css/app.css';
$chrome = 'css/common/shell-chrome.css';
$tokens = 'css/common/tokens.css';

runMutations(dirname(__DIR__, 2), 'DesignSystemDangerContrastContractTest', [
	[
		'name' => 'danger-fill-reverts-to-color-error-tint',
		'file' => $app,
		'search' => "background: var(--mn-danger-fill);\n\tborder-color: var(--mn-danger-fill);\n\tcolor: var(--mn-danger-on-fill);",
		'replace' => "background: var(--color-error);\n\tborder-color: var(--color-error);\n\tcolor: var(--color-primary-text, #fff);",
	],
	[
		'name' => 'shell-danger-fill-reverts-to-color-error-tint',
		'file' => $chrome,
		'search' => "background: var(--mn-danger-fill);\n\tborder-color: var(--mn-danger-fill);\n\tcolor: var(--mn-danger-on-fill);",
		'replace' => "background: var(--color-error, #cc4646);\n\tborder-color: var(--color-error, #cc4646);\n\tcolor: var(--color-primary-text, #fff);",
	],
	[
		'name' => 'danger-fill-token-points-at-tint',
		'file' => $tokens,
		'search' => '--mn-danger-fill: var(--color-element-error, #c90000);',
		'replace' => '--mn-danger-fill: var(--color-error, #FFE7E7);',
	],
	[
		'name' => 'field-error-ink-uses-pale-tint',
		'file' => $app,
		'search' => "color: var(--mn-danger-ink);",
		'replace' => "color: var(--color-error);",
	],
	[
		'name' => 'dark-theme-danger-darken-dropped',
		'file' => $tokens,
		'search' => "body[data-theme-dark],\nbody[data-theme-dark-highcontrast] {\n\t--mn-danger-fill: color-mix(in srgb, var(--color-element-error, #ff5050) 52%, var(--color-main-background));",
		'replace' => "body[data-theme-dark],\nbody[data-theme-dark-highcontrast] {\n\t--mn-danger-fill: var(--color-element-error, #ff5050);",
	],
]);
