#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPEC §14.4 MSI gate — Mutation Score Indicator for MaintenanceCheck.
 *
 * Infection against a full Nextcloud bootstrap is unreliable in-app (DB services
 * + OCP stubs). This gate runs the custom targeted harnesses and enforces
 * kill-rate thresholds that map to SPEC MSI floors:
 *   - IntervalCalculator + VisitService + AccessControlService + Mn2Codec ≥ 90%
 *   - overall lib/Service hotspot harness ≥ 80%
 *
 * Exit 0 only when every runner kills 100% of its mutants (no survivors) and
 * both thresholds are met.
 */

$appRoot = dirname(__DIR__, 2);
require_once __DIR__ . '/harness.php';
restoreMutationOriginals($appRoot);

$runners = [
	// Hotspots — SPEC §14.4 ≥ 90% (IntervalCalculator, VisitService, Access, Mn2)
	['file' => 'tests/Mutation/run-interval-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-visit-service-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-visit-rules-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-access-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-license-mutations.php', 'bucket' => 'hot'],
	// Broader service surface — contributes to overall ≥ 80%
	['file' => 'tests/Mutation/run-support-us-links-mutations.php', 'bucket' => 'service'],
];

$totals = ['hot' => ['killed' => 0, 'total' => 0], 'service' => ['killed' => 0, 'total' => 0]];
$failed = [];

foreach ($runners as $runner) {
	$path = $appRoot . '/' . $runner['file'];
	if (!is_file($path)) {
		fwrite(STDERR, "Missing runner: {$runner['file']}\n");
		exit(2);
	}
	$out = [];
	$code = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
	$text = implode("\n", $out);
	if (preg_match('/Killed\s+(\d+)\s+\/\s+(\d+)/', $text, $m)) {
		$killed = (int)$m[1];
		$total = (int)$m[2];
	} elseif ($code === 0 && str_contains($text, 'All SupportUsLinks mutations killed')) {
		// Support-us runner uses a bespoke harness (6 mutants, no Killed N/N line).
		$killed = 6;
		$total = 6;
	} else {
		fwrite(STDERR, "Unparseable mutation output for {$runner['file']}:\n{$text}\n");
		exit(2);
	}

	$bucket = $runner['bucket'];
	$totals[$bucket]['killed'] += $killed;
	$totals[$bucket]['total'] += $total;
	$pct = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
	echo sprintf("[%s] %s — %d/%d (%.1f%%) code=%d\n", $bucket, basename($runner['file']), $killed, $total, $pct, $code);
	if ($code !== 0 || $killed < $total) {
		$failed[] = $runner['file'];
	}
}

$hotPct = $totals['hot']['total'] > 0
	? 100 * $totals['hot']['killed'] / $totals['hot']['total']
	: 0.0;
$svcPct = ($totals['hot']['total'] + $totals['service']['total']) > 0
	? 100 * ($totals['hot']['killed'] + $totals['service']['killed'])
		/ ($totals['hot']['total'] + $totals['service']['total'])
	: 0.0;

$report = [
	'generatedAt' => gmdate('c'),
	'hot' => [
		'killed' => $totals['hot']['killed'],
		'total' => $totals['hot']['total'],
		'msi' => round($hotPct, 1),
		'threshold' => 90.0,
		'scope' => 'IntervalCalculator, VisitService, DueBoard rules, AccessControlService, Mn2Codec/SeatRank',
	],
	'overall' => [
		'killed' => $totals['hot']['killed'] + $totals['service']['killed'],
		'total' => $totals['hot']['total'] + $totals['service']['total'],
		'msi' => round($svcPct, 1),
		'threshold' => 80.0,
		'scope' => 'lib/Service hotspots + SupportUsLinks',
	],
	'survivors' => $failed,
];

$reportPath = $appRoot . '/tests/Mutation/msi-summary.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo sprintf(
	"MSI hotspots (Interval/Visit/Access/License): %.1f%% (need ≥ 90)\n",
	$hotPct,
);
echo sprintf(
	"MSI overall service harness: %.1f%% (need ≥ 80)\n",
	$svcPct,
);
echo "Wrote {$reportPath}\n";

if ($failed !== [] || $hotPct < 90.0 || $svcPct < 80.0) {
	fwrite(STDERR, 'MSI gate FAILED: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "MSI gate OK — all mutants killed; SPEC §14.4 thresholds met.\n";
exit(0);
