#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Isolated CLI worker for seat-assign concurrency (AC-15).
 *
 * Usage: php seat-assign-worker.php <adminUid> <userId>
 *
 * Exit: 0 = created/idempotent OK, 2 = seat_limit_reached, 1 = error.
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once rtrim($nextcloudRoot, '/') . '/lib/base.php';

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCP\Server;

$adminUid = (string)($argv[1] ?? '');
$userId = (string)($argv[2] ?? '');
if ($adminUid === '' || $userId === '') {
	fwrite(STDOUT, "ERROR:bad_args\n");
	exit(1);
}

try {
	$result = Server::get(LicenseService::class)->assignSeat($adminUid, $userId);
	fwrite(STDOUT, ($result['created'] ? 'CREATED:' : 'EXISTS:') . $result['seat']['uid'] . "\n");
	exit(0);
} catch (ConflictException $e) {
	fwrite(STDOUT, 'CONFLICT:' . $e->getErrorCode() . "\n");
	exit(2);
} catch (ValidationException $e) {
	fwrite(STDOUT, 'ERROR:' . $e->getErrorCode() . "\n");
	exit(1);
} catch (Throwable $e) {
	fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
	exit(1);
}
