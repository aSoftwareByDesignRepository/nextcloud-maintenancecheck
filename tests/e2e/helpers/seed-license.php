#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * E2E-only: apply / clear an MN2 fixture via CLI with the test trust anchor.
 *
 * Web requests keep the production vendor key (N5). Validity shown in the UI
 * is derived from stored dates, so seeding via CLI is enough for UJ-6 Alt B.
 *
 * Usage (from nextcloud/):
 *   docker compose exec -T \
 *     -e MN_ALLOW_VENDOR_KEY_OVERRIDE=1 \
 *     -e MN_VENDOR_PUBLIC_KEY_B64=… \
 *     nextcloud php /var/www/html/custom_apps/maintenancecheck/tests/e2e/helpers/seed-license.php expired
 *   … seed-license.php clear
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once rtrim($nextcloudRoot, '/') . '/lib/base.php';

use OCA\MaintenanceCheck\Config\VendorPublicKey;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCP\Server;

$action = strtolower((string)($argv[1] ?? ''));
$fixtureDir = dirname(__DIR__, 2) . '/fixtures';

if (getenv('MN_ALLOW_VENDOR_KEY_OVERRIDE') !== '1') {
	fwrite(STDERR, "Refusing to run without MN_ALLOW_VENDOR_KEY_OVERRIDE=1\n");
	exit(2);
}

// CLI-process only — Apache/FPM keeps the production trust anchor (N5).
putenv('MN_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);

$license = Server::get(LicenseService::class);

try {
	if ($action === 'clear' || $action === 'remove') {
		$license->remove();
		fwrite(STDOUT, "CLEARED\n");
		exit(0);
	}

	$file = match ($action) {
		'expired' => $fixtureDir . '/license_mn2_expired.txt',
		'valid' => $fixtureDir . '/license_mn2_valid.txt',
		default => '',
	};
	if ($file === '' || !is_file($file)) {
		fwrite(STDERR, "Usage: seed-license.php expired|valid|clear\n");
		exit(1);
	}

	$wire = trim((string)file_get_contents($file));
	$status = $license->apply('e2e-seed', $wire);
	$valid = !empty($status['state']['valid']) ? '1' : '0';
	fwrite(STDOUT, 'SEEDED:' . $action . ':valid=' . $valid . "\n");
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, 'ERROR:' . $e->getMessage() . "\n");
	exit(1);
}
