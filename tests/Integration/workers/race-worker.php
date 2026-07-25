<?php

declare(strict_types=1);

/**
 * Isolated CLI worker for AC-6 / I5 concurrency races.
 *
 * Usage:
 *   php complete-visit.php <visitId> <uid>
 *   php complete-visit.php --schedule <planId> <dueOn>
 *
 * Exit codes: 0 = success, 2 = expected conflict, 1 = unexpected error.
 * Prints a single machine-readable token on stdout: OK | CONFLICT:<code> | ERROR:<msg>
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once rtrim($nextcloudRoot, '/') . '/lib/base.php';

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\Server;

$args = array_slice($argv, 1);
if ($args === []) {
	fwrite(STDERR, "Usage: complete-visit.php <visitId> <uid> | --schedule <planId> <dueOn>\n");
	exit(1);
}

try {
	if (($args[0] ?? '') === '--schedule') {
		$planId = (int)($args[1] ?? 0);
		$dueOn = (string)($args[2] ?? '');
		if ($planId < 1 || $dueOn === '') {
			fwrite(STDOUT, "ERROR:bad_args\n");
			exit(1);
		}
		Server::get(PlanService::class)->schedule($planId, ['dueOn' => $dueOn]);
		fwrite(STDOUT, "OK\n");
		exit(0);
	}

	$visitId = (int)($args[0] ?? 0);
	$uid = (string)($args[1] ?? '');
	if ($visitId < 1 || $uid === '') {
		fwrite(STDOUT, "ERROR:bad_args\n");
		exit(1);
	}
	Server::get(VisitService::class)->complete($uid, $visitId, []);
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
