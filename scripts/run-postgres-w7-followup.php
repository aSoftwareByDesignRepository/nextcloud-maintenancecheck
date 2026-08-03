<?php

declare(strict_types=1);

/**
 * Postgres smoke for W7 auto-corrective UNIQUE(source_wo_id) (MN-EXEC-2 / AC-W7-*).
 *
 * Starts from the same ephemeral deskcheck-postgres service as visit races.
 * Does not touch the live MariaDB Nextcloud instance.
 *
 * Env:
 *   MN_PG_DSN  default: pgsql:host=deskcheck-postgres;port=5432;dbname=mn_race
 *   MN_PG_USER default: deskcheck
 *   MN_PG_PASS default: deskcheck
 *
 * Exit 0 on pass; non-zero on failure.
 */

$dsn = getenv('MN_PG_DSN') ?: 'pgsql:host=deskcheck-postgres;port=5432;dbname=mn_race';
$user = getenv('MN_PG_USER') ?: 'deskcheck';
$pass = getenv('MN_PG_PASS') ?: 'deskcheck';

function pg_connect_retry(string $dsn, string $user, string $pass, int $attempts = 30): PDO
{
	$last = null;
	for ($i = 0; $i < $attempts; $i++) {
		try {
			$pdo = new PDO($dsn, $user, $pass, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
			$pdo->exec('SELECT 1');
			return $pdo;
		} catch (Throwable $e) {
			$last = $e;
			usleep(250000);
		}
	}
	throw new RuntimeException('Postgres unreachable: ' . ($last?->getMessage() ?? 'unknown'));
}

function ensureDatabase(string $dsn, string $user, string $pass): void
{
	$adminDsn = preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn) ?? $dsn;
	$pdo = pg_connect_retry($adminDsn, $user, $pass);
	$exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'mn_race'")->fetchColumn();
	if (!$exists) {
		$pdo->exec('CREATE DATABASE mn_race');
	}
}

ensureDatabase($dsn, $user, $pass);
$pdo = pg_connect_retry($dsn, $user, $pass);

$pdo->exec('DROP TABLE IF EXISTS mn_work_orders CASCADE');
$pdo->exec(<<<'SQL'
CREATE TABLE mn_work_orders (
	id BIGSERIAL PRIMARY KEY,
	kind TEXT NOT NULL,
	status TEXT NOT NULL,
	source_wo_id BIGINT NULL,
	title TEXT NOT NULL DEFAULT '',
	CONSTRAINT mn_wo_src_uq UNIQUE (source_wo_id)
)
SQL);

$pdo->exec("INSERT INTO mn_work_orders (kind, status, title) VALUES ('inspection', 'done', 'source')");
$sourceId = (int)$pdo->query('SELECT id FROM mn_work_orders ORDER BY id DESC LIMIT 1')->fetchColumn();

$ok = 0;
$dup = 0;
for ($i = 0; $i < 2; $i++) {
	try {
		$st = $pdo->prepare("INSERT INTO mn_work_orders (kind, status, source_wo_id, title) VALUES ('corrective', 'draft', :s, 'follow-up')");
		$st->execute([':s' => $sourceId]);
		$ok++;
	} catch (PDOException $e) {
		// 23505 unique_violation
		if (($e->errorInfo[0] ?? '') === '23505' || str_contains($e->getMessage(), 'mn_wo_src_uq')) {
			$dup++;
		} else {
			fwrite(STDERR, "Unexpected insert error: {$e->getMessage()}\n");
			exit(2);
		}
	}
}

$nullOk = 0;
for ($i = 0; $i < 3; $i++) {
	$pdo->exec("INSERT INTO mn_work_orders (kind, status, source_wo_id, title) VALUES ('corrective', 'draft', NULL, 'orphan')");
	$nullOk++;
}

$count = (int)$pdo->query('SELECT COUNT(*) FROM mn_work_orders WHERE source_wo_id = ' . $sourceId)->fetchColumn();

if ($ok !== 1 || $dup !== 1 || $count !== 1) {
	fwrite(STDERR, "UNIQUE(source_wo_id) failed: ok=$ok dup=$dup count=$count (want 1/1/1)\n");
	exit(1);
}
if ($nullOk !== 3) {
	fwrite(STDERR, "NULL source_wo_id must allow multiples: got $nullOk\n");
	exit(1);
}

echo "Postgres W7 follow-up UNIQUE(source_wo_id): PASS (1 winner, 1 conflict, NULLs ok)\n";
exit(0);
