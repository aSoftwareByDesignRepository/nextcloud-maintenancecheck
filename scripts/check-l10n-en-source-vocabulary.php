#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * En-source vocabulary contract: en msgids ARE the English source text
 * (msgid == msgstr in en.json), so no msgstr lint can see foreign vocabulary
 * leaking into them. This gate scans the en key space plus the tr()/t()
 * source literals for non-English vocabulary markers:
 *
 *   1. Accented Latin letters (U+00C0–U+024F — ä, ö, ü, Ä, Ö, Ü, ß, é, …).
 *      English source copy carries none; an umlaut in an en msgid means a
 *      German word ships to English users (mn-vis-en-source-german-vocabulary:
 *      '…schedule the next Prüfung.', '…for Prüfpflichten…' 2026-10-24).
 *   2. Denylist of umlaut-free German domain stems (word-boundary) so
 *      ASCII-ified German ('Pruefung', 'Wartung') cannot sneak past leg 1.
 *
 * Legitimate loanwords (proper nouns, quoted foreign terms) go in $allow with
 * a comment — an entry must name the exact msgid substring it permits.
 *
 * Usage: php scripts/check-l10n-en-source-vocabulary.php
 */

$base = dirname(__DIR__);

$allow = [
	// 'exact substring of the permitted msgid' => 'reason',
];

$denyStems = [
	'Pruefung', 'Pruefpflicht', 'Wartung', 'Instandhaltung', 'Arbeitszeit',
	'Zeiterfassung', 'Auftrag', 'Auftraege', 'Geraet', 'Mitarbeiter',
	'Besuch', 'Kunde', 'Kunden', 'Dienst', 'Inspektionspflicht',
];

$accented = '/[\x{00C0}-\x{024F}]/u';

$isAllowed = static function (string $msgid) use ($allow): bool {
	foreach (array_keys($allow) as $needle) {
		if ($needle !== '' && mb_strpos($msgid, $needle) !== false) {
			return true;
		}
	}
	return false;
};

$check = static function (string $where, string $msgid) use ($allow, $denyStems, $accented, $isAllowed): array {
	if ($msgid === '' || $isAllowed($msgid)) {
		return [];
	}
	$hits = [];
	if (preg_match($accented, $msgid, $m)) {
		$hits[] = "accented letter '{$m[0]}'";
	}
	foreach ($denyStems as $stem) {
		if (preg_match('/\b' . preg_quote($stem, '/') . '(?![A-Z])/i', $msgid)) {
			$hits[] = "german stem '$stem'";
		}
	}
	return array_map(static fn (string $h): string => "$where: $h => " . mb_substr($msgid, 0, 90), $hits);
};

$failures = [];

// Leg 1: catalog key space — en.json msgids are the rendered English text.
$en = json_decode((string) file_get_contents("$base/l10n/en.json"), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($en['translations'] ?? []) as $msgid) {
	array_push($failures, ...$check('en.json msgid', (string) $msgid));
}

// Leg 2: source literals — en.json lags extraction; scan tr()/t() directly.
$files = array_merge(
	glob("$base/js/*.js") ?: [],
	glob("$base/templates/*.php") ?: [],
	glob("$base/lib/**/*.php") ?: [],
	glob("$base/lib/*.php") ?: []
);
$lit = '/(?:\btr|->t|\bt|\bn)\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';
foreach ($files as $file) {
	$src = (string) file_get_contents($file);
	if (!preg_match_all($lit, $src, $mm, PREG_OFFSET_CAPTURE)) {
		continue;
	}
	foreach ($mm[2] as $i => $cap) {
		$msgid = stripcslashes($cap[0]);
		foreach ($check(basename($file), $msgid) as $line) {
			$failures[] = $line;
		}
	}
}

$failures = array_unique($failures);
if ($failures !== []) {
	foreach ($failures as $f) {
		fwrite(STDERR, "FAIL $f\n");
	}
	fwrite(STDERR, count($failures) . " en-source vocabulary failure(s)\n");
	exit(1);
}

echo "en-source vocabulary contract OK\n";
