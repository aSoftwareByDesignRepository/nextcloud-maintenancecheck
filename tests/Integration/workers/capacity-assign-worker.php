<?php

declare(strict_types=1);

/**
 * Isolated CLI worker for W4 capacity assign TOCTOU race.
 *
 * Usage: php capacity-assign-worker.php <workOrderId> <officeUid> <techUid>
 * Tokens: OK | CONFLICT:<code> | ERROR:<msg>
 *
 * ValidationException capacity_exceeded is reported as CONFLICT so the
 * race harness can assert exactly one winner under block enforcement.
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once rtrim($nextcloudRoot, '/') . '/lib/base.php';

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\IDBConnection;
use OCP\Server;

try {
	$db = Server::get(IDBConnection::class);
	while ($db->inTransaction()) {
		$db->rollBack();
	}
} catch (Throwable) {
}

$woId = (int)($argv[1] ?? 0);
$officeUid = (string)($argv[2] ?? '');
$techUid = (string)($argv[3] ?? '');
if ($woId < 1 || $officeUid === '' || $techUid === '') {
	fwrite(STDOUT, "ERROR:bad_args\n");
	exit(1);
}

try {
	Server::get(WorkOrderService::class)->assign($officeUid, $woId, [
		'primaryUserId' => $techUid,
	]);
	fwrite(STDOUT, "OK\n");
	exit(0);
} catch (ValidationException $e) {
	$code = $e->getErrorCode();
	if ($code === 'capacity_exceeded') {
		fwrite(STDOUT, 'CONFLICT:' . $code . "\n");
		exit(2);
	}
	fwrite(STDOUT, 'ERROR:' . $code . "\n");
	exit(1);
} catch (ConflictException $e) {
	fwrite(STDOUT, 'CONFLICT:' . $e->getErrorCode() . "\n");
	exit(2);
} catch (NotFoundException $e) {
	fwrite(STDOUT, "ERROR:not_found\n");
	exit(1);
} catch (Throwable $e) {
	fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
	exit(1);
}
