#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AC-W7-11 / EXEC-2: fail if banned legal-overclaim phrases appear in
 * product copy (app l10n + procedure packs). Companion locales are checked
 * by Jest AC-C20 when present on the host.
 */

$root = dirname(__DIR__);
$banned = [
	'rechtskonform',
	'dguv-zertifiziert',
	'konformitätsbescheinigung',
	'konformitaetsbescheinigung',
	'bg-approved',
	'certificate of compliance',
	'compliance certificate',
];

$files = [];
foreach ([$root . '/l10n', $root . '/data/procedure-packs'] as $path) {
	if (!is_dir($path)) {
		continue;
	}
	foreach (glob($path . '/*.{json,js}', GLOB_BRACE) ?: [] as $f) {
		$files[] = $f;
	}
}
$infoXml = $root . '/appinfo/info.xml';
if (is_file($infoXml)) {
	$files[] = $infoXml;
}

$hits = [];
foreach ($files as $file) {
	$raw = (string)file_get_contents($file);
	$lower = mb_strtolower($raw);
	foreach ($banned as $needle) {
		if (str_contains($lower, $needle)) {
			$hits[] = basename($file) . ': ' . $needle;
		}
	}
	if (preg_match('/prüfnachweis.{0,40}zertifikat|zertifikat.{0,40}prüfnachweis/iu', $raw)) {
		$hits[] = basename($file) . ': Prüfnachweis+Zertifikat pairing';
	}
}

if ($hits !== []) {
	fwrite(STDERR, "Legal overclaim ban-list violations:\n- " . implode("\n- ", array_unique($hits)) . "\n");
	exit(1);
}

fwrite(STDOUT, 'Ban-list OK (' . count($files) . " files scanned)\n");
exit(0);
