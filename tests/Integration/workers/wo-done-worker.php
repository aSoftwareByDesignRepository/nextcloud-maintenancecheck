<?php

declare(strict_types=1);

/**
 * Isolated CLI worker for AC-W1-4 concurrent dual-done on a work order.
 *
 * Usage: php wo-done-worker.php <workOrderId> <uid>
 * Tokens: OK | CONFLICT:<code> | ERROR:<msg>
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
$uid = (string)($argv[2] ?? '');
if ($woId < 1 || $uid === '') {
	fwrite(STDOUT, "ERROR:bad_args\n");
	exit(1);
}

try {
	Server::get(WorkOrderService::class)->transition($uid, $woId, ['to' => 'done'], true);
	fwrite(STDOUT, "OK\n");
	exit(0);
} catch (ConflictException $e) {
	fwrite(STDOUT, 'CONFLICT:' . $e->getErrorCode() . "\n");
	exit(2);
} catch (NotFoundException | ValidationException $e) {
	$code = method_exists($e, 'getErrorCode') ? $e->getErrorCode() : 'error';
	fwrite(STDOUT, 'ERROR:' . $code . "\n");
	exit(1);
} catch (Throwable $e) {
	fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
	exit(1);
}
