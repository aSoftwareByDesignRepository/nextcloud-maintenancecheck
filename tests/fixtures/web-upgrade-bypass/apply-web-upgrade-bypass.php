<?php

declare(strict_types=1);

/**
 * Apply NC 34+ web-upgrade acknowledgement bypass inside the Nextcloud volume.
 *
 * Stock Nextcloud ignores the “Upgrade via web on my own risk” ack when
 * upgrade.disable-web=true, so the button reloads the same CLI page.
 *
 * Idempotent. Run inside the container:
 *   php /tmp/apply-web-upgrade-bypass.php
 * Or via host wrapper:
 *   bash docker/patches/apply-web-upgrade-bypass.sh
 */

$queryKey = 'IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup';
$token = 'IAmSuperSureToDoThis';
$marker = 'SBD_WEB_UPGRADE_BYPASS';

function alreadyPatched(string $path, string $marker): bool {
	return is_file($path) && str_contains((string)file_get_contents($path), $marker);
}

function mustReplace(string $path, string $from, string $to, string $label): void {
	$src = file_get_contents($path);
	if ($src === false) {
		throw new RuntimeException("Cannot read {$path}");
	}
	if (!str_contains($src, $from)) {
		throw new RuntimeException("{$label}: expected needle missing in {$path} (image changed?)");
	}
	$count = 0;
	$out = str_replace($from, $to, $src, $count);
	if ($count !== 1) {
		throw new RuntimeException("{$label}: expected exactly 1 replacement, got {$count}");
	}
	if (file_put_contents($path, $out) === false) {
		throw new RuntimeException("Cannot write {$path}");
	}
	fwrite(STDOUT, "patched {$label}\n");
}

$base = '/var/www/html/lib/base.php';
$controller = '/var/www/html/core/Controller/UpdateController.php';

if (!alreadyPatched($base, $marker)) {
	$fromDef = <<<'PHP'
		$ignoreTooBigWarning = isset($_GET['IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup'])
			&& $_GET['IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup'] === 'IAmSuperSureToDoThis';
PHP;
	$toDef = <<<PHP
		// {$marker}: same ack token unlocks web upgrade when upgrade.disable-web=true
		\$ignoreWarning = isset(\$_GET['{$queryKey}'])
			&& \$_GET['{$queryKey}'] === '{$token}';
PHP;
	mustReplace($base, $fromDef, $toDef, 'base.php ignoreWarning');

	$fromIf = "\t\tif (\$disableWebUpdater || (\$tooBig && !\$ignoreTooBigWarning)) {";
	$toIf = "\t\tif ((\$disableWebUpdater && !\$ignoreWarning) || (\$tooBig && !\$ignoreWarning)) {";
	mustReplace($base, $fromIf, $toIf, 'base.php condition');
} else {
	fwrite(STDOUT, "skip base.php (already patched)\n");
}

if (!alreadyPatched($controller, $marker)) {
	$fromBlock = <<<'PHP'
		if ($this->config->getSystemValueBool('upgrade.disable-web', false)) {
			$eventSource->send('failure', $this->l->t('Please use the command line updater because updating via browser is disabled in your config.php.'));
			$eventSource->close();
			return new DataResponse(null);
		}
PHP;
	$toBlock = <<<PHP
		// {$marker}: forward page ack so Start update works after “Upgrade via web on my own risk”
		\$ignoreWarning = \$this->request->getParam('{$queryKey}') === '{$token}';
		if (\$this->config->getSystemValueBool('upgrade.disable-web', false) && !\$ignoreWarning) {
			\$eventSource->send('failure', \$this->l->t('Please use the command line updater because updating via browser is disabled in your config.php.'));
			\$eventSource->close();
			return new DataResponse(null);
		}
PHP;
	mustReplace($controller, $fromBlock, $toBlock, 'UpdateController bypass');
} else {
	fwrite(STDOUT, "skip UpdateController.php (already patched)\n");
}

$chunkCandidates = glob('/var/www/html/dist/*-*.js') ?: [];
$patchedChunk = false;
$needle = '(0,i.KT)("/core/update")';
$replacement = '(0,i.KT)("/core/update")+(function(){try{var q=new URLSearchParams(window.location.search),k='
	. json_encode($queryKey)
	. ';return q.get(k)==='
	. json_encode($token)
	. '?"?"+k+"="+encodeURIComponent(q.get(k)):""}catch(e){return""}})()/*'
	. $marker
	. '*/';

foreach ($chunkCandidates as $chunk) {
	$src = file_get_contents($chunk);
	if ($src === false || !str_contains($src, '/core/update')) {
		continue;
	}
	if (str_contains($src, $marker)) {
		fwrite(STDOUT, 'skip ' . basename($chunk) . " (already patched)\n");
		$patchedChunk = true;
		continue;
	}
	if (!str_contains($src, $needle)) {
		if (preg_match('#(\([^)]*\)\("/core/update"\))#', $src, $m) !== 1) {
			continue;
		}
		$needleLocal = $m[1];
		$replacementLocal = $needleLocal . '+(function(){try{var q=new URLSearchParams(window.location.search),k='
			. json_encode($queryKey)
			. ';return q.get(k)==='
			. json_encode($token)
			. '?"?"+k+"="+encodeURIComponent(q.get(k)):""}catch(e){return""}})()/*'
			. $marker
			. '*/';
		$count = 0;
		$out = str_replace($needleLocal, $replacementLocal, $src, $count);
		if ($count !== 1) {
			throw new RuntimeException('chunk ' . basename($chunk) . ": expected 1 replacement, got {$count}");
		}
		file_put_contents($chunk, $out);
		fwrite(STDOUT, 'patched ' . basename($chunk) . " (loose)\n");
		$patchedChunk = true;
		continue;
	}
	$count = 0;
	$out = str_replace($needle, $replacement, $src, $count);
	if ($count !== 1) {
		throw new RuntimeException('chunk ' . basename($chunk) . ": expected 1 replacement, got {$count}");
	}
	file_put_contents($chunk, $out);
	fwrite(STDOUT, 'patched ' . basename($chunk) . "\n");
	$patchedChunk = true;
}

if (!$patchedChunk) {
	throw new RuntimeException('Could not find UpdaterAdmin /core/update EventSource in dist/*.js');
}

fwrite(STDOUT, "OK: web-upgrade bypass applied\n");
