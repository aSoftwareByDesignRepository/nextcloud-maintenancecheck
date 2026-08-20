<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: CoreWebUpgradeBypassPolicy (NC 34 web-upgrade ack).
 *
 * Usage (from nextcloud/apps/maintenancecheck):
 *   php tests/Mutation/run-core-web-upgrade-bypass-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/tests/Mutation/harness.php';

runMutations($appRoot, 'CoreWebUpgradeBypassContractTest', [
	[
		'name' => 'disable-web bypass ignored',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return ($disableWebUpdater && !$ignoreWarning) || ($tooBig && !$ignoreWarning);',
		'replace' => 'return $disableWebUpdater || ($tooBig && !$ignoreWarning);',
	],
	[
		'name' => 'tooBig bypass ignored',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return ($disableWebUpdater && !$ignoreWarning) || ($tooBig && !$ignoreWarning);',
		'replace' => 'return ($disableWebUpdater && !$ignoreWarning) || $tooBig;',
	],
	[
		'name' => 'endpoint never blocks',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return $disableWebUpdater && !self::isValidBypassToken($bypassToken);',
		'replace' => 'return false;',
	],
	[
		'name' => 'endpoint always blocks when disable-web',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return $disableWebUpdater && !self::isValidBypassToken($bypassToken);',
		'replace' => 'return $disableWebUpdater;',
	],
	[
		'name' => 'js forwards any token',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'if (isset($params[self::QUERY_KEY]) && $params[self::QUERY_KEY] === self::TOKEN) {',
		'replace' => 'if (isset($params[self::QUERY_KEY])) {',
	],
	[
		'name' => 'bypass link only for tooBig',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return $tooBig || $disableWebUpdater;',
		'replace' => 'return $tooBig;',
	],
	[
		'name' => 'accept any non-empty token',
		'file' => 'lib/Support/CoreWebUpgradeBypassPolicy.php',
		'search' => 'return $bypassToken === self::TOKEN;',
		'replace' => 'return $bypassToken !== null && $bypassToken !== \'\';',
	],
]);
