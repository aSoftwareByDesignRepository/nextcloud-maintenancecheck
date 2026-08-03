<?php

declare(strict_types=1);

/**
 * Mutation harness for MaintenanceCheck settings underpages.
 *
 * Baseline must pass; each mutation must fail SettingsUnderpagesContractTest.
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit missing — run composer install in maintenancecheck\n");
	exit(1);
}

/**
 * @param list<string> $filters
 */
function run_unit(string $root, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($root . '/phpunit.xml')
		. ' --testsuite unit'
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$suiteFilters = ['SettingsUnderpagesContractTest'];

$baseline = run_unit($root, $phpunit, $suiteFilters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline settings underpage tests failed\n");
	exit($baseline);
}

/** @var list<array{name:string,file:string,from:string,to:string}> $mutations */
$mutations = [
	[
		'name' => 'drop-access-host',
		'file' => $root . '/templates/settings/access.php',
		'from' => 'id="mn-settings-access"',
		'to' => 'id="mn-settings-xxx"',
	],
	[
		'name' => 'css-narrow-settings',
		'file' => $root . '/css/app.css',
		'from' => ".mn-settings {\n\tmax-width: none;",
		'to' => ".mn-settings {\n\tmax-width: 56rem;",
	],
	[
		'name' => 'subnav-reintroduce-overview',
		'file' => $root . '/templates/parts/settings-subnav.php',
		'from' => "aria-label=\"<?php p(\$l->t('Settings sections')); ?>\"",
		'to' => "aria-label=\"<?php p(\$l->t('Overview')); ?>\"",
	],
	[
		'name' => 'js-drop-underpage-bind',
		'file' => $root . '/js/app.js',
		'from' => "page.indexOf('settings-') === 0",
		'to' => "page.indexOf('settingsxxx-') === 0",
	],
	[
		'name' => 'controller-drop-valid-gate',
		'file' => $root . '/lib/Controller/PageController.php',
		'from' => 'SettingsSections::isValid',
		'to' => 'SettingsSections::isValidXxx',
	],
	[
		'name' => 'controller-drop-default-redirect',
		'file' => $root . '/lib/Controller/PageController.php',
		'from' => 'SettingsSections::DEFAULT',
		'to' => 'SettingsSections::HUB',
	],
	[
		'name' => 'nav-introduce-admin-wrapper',
		'file' => $root . '/templates/common/navigation.php',
		'from' => 'id="mn-settings-subnav"',
		'to' => 'id="mn-admin-subnav"',
	],
	[
		'name' => 'catalog-drop-policies',
		'file' => $root . '/lib/Support/SettingsSections.php',
		'from' => "'policies',",
		'to' => "'policiess',",
	],
];

$killed = 0;
$survived = [];

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "Mutation source missing for {$m['name']}\n");
		$survived[] = $m['name'] . ' (source missing)';
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	$code = run_unit($root, $phpunit, $suiteFilters);
	file_put_contents($m['file'], $original);
	if ($code !== 0) {
		$killed++;
		fwrite(STDOUT, "KILLED {$m['name']}\n");
	} else {
		$survived[] = $m['name'];
		fwrite(STDERR, "SURVIVED {$m['name']}\n");
	}
}

fwrite(STDOUT, sprintf("Settings underpage mutations: killed %d / %d\n", $killed, count($mutations)));
if ($survived !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $survived) . "\n");
	exit(1);
}
exit(0);
