<?php

declare(strict_types=1);

/**
 * Label-in-name contract: instructional/help strings that name a visible
 * control must embed the catalog's own localized label for that control —
 * never the raw English source text and never a divergent synonym.
 *
 * Rules: label msgid => list of dependent msgids that reference the control.
 * For each locale (except en), the dependent msgstr must contain the locale's
 * msgstr of the label (case-insensitive, quotes/punctuation ignored), and must
 * not retain the literal English label when the label itself is localized.
 *
 * Usage: php scripts/check-l10n-label-in-name.php
 */

$base = __DIR__ . '/../l10n';
$locales = ['de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt', 'pt_BR'];

$rules = [
	'Assign' => [
		'2. Tap Assign',
		'Jobs without an owner — tap Assign, pick a person, done.',
		'Tap Assign on a job — search and pick a technician, never type an id.',
		'Tap Assign on a job — search and pick, never type an id.',
	],
	'Complete visit' => [
		'Inspections must be closed on a work order with result and inspector — not with Complete visit.',
	],
	'Complete' => [
		'Tap Complete on a row when the job is done. Overdue is always at the top.',
	],
	'Only my jobs' => [
		'Filter by status or search the number — the list updates as you change it. Use Only my jobs if you are a technician.',
	],
	'All open' => [
		'Every open job already has a technician — switch to All open to review.',
	],
	'Only my visits' => [
		'Tap a status or a due window (this week, this month, or pick dates). Only my visits is one tap too.',
	],
];

$failures = 0;
foreach ($locales as $locale) {
	$doc = json_decode((string) file_get_contents("$base/$locale.json"), true, 512, JSON_THROW_ON_ERROR);
	$tr = $doc['translations'] ?? [];
	foreach ($rules as $label => $dependents) {
		$labelStr = $tr[$label] ?? null;
		if (!is_string($labelStr) || $labelStr === '') {
			fwrite(STDERR, "FAIL $locale: label '$label' untranslated\n");
			$failures++;
			continue;
		}
		foreach ($dependents as $dep) {
			$depStr = $tr[$dep] ?? null;
			if (!is_string($depStr) || $depStr === '') {
				fwrite(STDERR, "FAIL $locale: dependent missing: " . substr($dep, 0, 60) . "\n");
				$failures++;
				continue;
			}
			// dependent must embed the localized label (case-insensitive)
			if (mb_stripos($depStr, $labelStr) === false) {
				fwrite(STDERR, "FAIL $locale: dependent does not embed label '$labelStr': " . substr($dep, 0, 60) . " => " . substr($depStr, 0, 90) . "\n");
				$failures++;
			}
			// raw-English-label retention when the label is localized
			if ($labelStr !== $label && preg_match('/\b' . preg_quote($label, '/') . '\b/u', $depStr)) {
				fwrite(STDERR, "FAIL $locale: dependent retains raw English '$label': " . substr($dep, 0, 60) . " => " . substr($depStr, 0, 90) . "\n");
				$failures++;
			}
		}
	}
}

if ($failures > 0) {
	fwrite(STDERR, "$failures label-in-name failure(s)\n");
	exit(1);
}
echo "label-in-name contract OK\n";
