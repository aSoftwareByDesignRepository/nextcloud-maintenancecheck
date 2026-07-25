<?php

declare(strict_types=1);

/**
 * Dual-process visit races against real PostgreSQL (SPEC §14.2-I5 / AC-6 / N3 / N6).
 *
 * Mirrors the production locking protocol used by VisitService::close and
 * PlanService::schedule — independent PDO connections in two PHP processes,
 * because sequential PHPUnit cannot prove row-lock serialization:
 *
 *   Complete (S6):
 *     BEGIN
 *     → UPDATE mn_visits SET status='done' WHERE id=:id AND status='scheduled'
 *     → if affected=0: CONFLICT visit_not_open
 *     → SELECT mn_plans … FOR UPDATE
 *     → if plan active AND no open visit: INSERT next scheduled visit
 *     → COMMIT
 *
 *   Schedule (S14 / §6.3.2):
 *     BEGIN
 *     → SELECT mn_plans … FOR UPDATE
 *     → if inactive: plan_inactive
 *     → if open visit exists: CONFLICT visit_already_open
 *     → INSERT scheduled visit
 *     → COMMIT
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
	// Connect to the maintenance DB first so CREATE DATABASE is allowed.
	$adminDsn = preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn) ?? $dsn;
	$pdo = pg_connect_retry($adminDsn, $user, $pass);
	$exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'mn_race'")->fetchColumn();
	if (!$exists) {
		$pdo->exec('CREATE DATABASE mn_race');
	}
}

function ensureSchema(PDO $pdo): void
{
	$pdo->exec('DROP TABLE IF EXISTS mn_visits CASCADE');
	$pdo->exec('DROP TABLE IF EXISTS mn_plans CASCADE');
	$pdo->exec(<<<'SQL'
CREATE TABLE mn_plans (
	id BIGSERIAL PRIMARY KEY,
	active BOOLEAN NOT NULL DEFAULT TRUE,
	interval_unit TEXT NOT NULL DEFAULT 'month',
	interval_count INT NOT NULL DEFAULT 1
)
SQL);
	$pdo->exec(<<<'SQL'
CREATE TABLE mn_visits (
	id BIGSERIAL PRIMARY KEY,
	plan_id BIGINT NOT NULL REFERENCES mn_plans(id),
	status TEXT NOT NULL,
	due_on DATE NOT NULL,
	done_on DATE NULL,
	done_by TEXT NULL,
	created_at INT NOT NULL DEFAULT 0
)
SQL);
	$pdo->exec('CREATE INDEX mn_visits_plan_status ON mn_visits (plan_id, status)');
}

/**
 * Production S6 complete protocol (simplified columns).
 *
 * @return array{ok:bool,code:string,nextId?:int}
 */
function tryComplete(PDO $pdo, int $visitId, string $uid, string $doneOn): array
{
	$pdo->beginTransaction();
	try {
		$upd = $pdo->prepare(
			"UPDATE mn_visits SET status = 'done', done_on = :done_on, done_by = :done_by
			 WHERE id = :id AND status = 'scheduled'"
		);
		$upd->execute(['done_on' => $doneOn, 'done_by' => $uid, 'id' => $visitId]);
		if ($upd->rowCount() === 0) {
			$pdo->rollBack();
			return ['ok' => false, 'code' => 'visit_not_open'];
		}

		$planStmt = $pdo->prepare('SELECT id, active, interval_unit, interval_count FROM mn_plans WHERE id = (
			SELECT plan_id FROM mn_visits WHERE id = :id
		) FOR UPDATE');
		$planStmt->execute(['id' => $visitId]);
		$plan = $planStmt->fetch();
		if ($plan === false) {
			$pdo->commit();
			return ['ok' => true, 'code' => 'OK'];
		}

		$nextId = null;
		if (dbBool($plan['active'])) {
			$open = $pdo->prepare("SELECT id FROM mn_visits WHERE plan_id = :pid AND status = 'scheduled' LIMIT 1");
			$open->execute(['pid' => (int)$plan['id']]);
			if ($open->fetch() === false) {
				// Simplified next-due: +1 month (clamp not needed for race proof).
				$insert = $pdo->prepare(
					"INSERT INTO mn_visits (plan_id, status, due_on, created_at)
					 VALUES (:pid, 'scheduled', (:done_on::date + INTERVAL '1 month')::date, :ts)
					 RETURNING id"
				);
				$insert->execute(['pid' => (int)$plan['id'], 'done_on' => $doneOn, 'ts' => time()]);
				$nextId = (int)$insert->fetchColumn();
			}
		}
		$pdo->commit();
		$result = ['ok' => true, 'code' => 'OK'];
		if ($nextId !== null) {
			$result['nextId'] = $nextId;
		}
		return $result;
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $e;
	}
}

/**
 * Production S14 schedule protocol.
 *
 * @return array{ok:bool,code:string,visitId?:int}
 */
function trySchedule(PDO $pdo, int $planId, string $dueOn): array
{
	$pdo->beginTransaction();
	try {
		$planStmt = $pdo->prepare('SELECT id, active FROM mn_plans WHERE id = :id FOR UPDATE');
		$planStmt->execute(['id' => $planId]);
		$plan = $planStmt->fetch();
		if ($plan === false) {
			$pdo->rollBack();
			return ['ok' => false, 'code' => 'not_found'];
		}
		if (!dbBool($plan['active'])) {
			$pdo->rollBack();
			return ['ok' => false, 'code' => 'plan_inactive'];
		}
		$open = $pdo->prepare("SELECT id FROM mn_visits WHERE plan_id = :pid AND status = 'scheduled' LIMIT 1");
		$open->execute(['pid' => $planId]);
		$existing = $open->fetch();
		if ($existing !== false) {
			$pdo->rollBack();
			return ['ok' => false, 'code' => 'visit_already_open'];
		}
		$insert = $pdo->prepare(
			"INSERT INTO mn_visits (plan_id, status, due_on, created_at)
			 VALUES (:pid, 'scheduled', :due_on, :ts) RETURNING id"
		);
		$insert->execute(['pid' => $planId, 'due_on' => $dueOn, 'ts' => time()]);
		$visitId = (int)$insert->fetchColumn();
		$pdo->commit();
		return ['ok' => true, 'code' => 'OK', 'visitId' => $visitId];
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $e;
	}
}

function dbBool(mixed $value): bool
{
	if (is_bool($value)) {
		return $value;
	}
	if (is_int($value) || is_float($value)) {
		return (int)$value !== 0;
	}
	if (is_string($value)) {
		$normalized = strtolower(trim($value));
		return $normalized === '1'
			|| $normalized === 't'
			|| $normalized === 'true'
			|| $normalized === 'yes'
			|| $normalized === 'on';
	}
	return false;
}

function resolvePhpBinary(): string
{
	$candidates = [
		PHP_BINARY,
		getenv('PHP_BINARY') ?: '',
		'/usr/local/bin/php',
		'/usr/bin/php',
		'php',
	];
	foreach ($candidates as $candidate) {
		$candidate = trim((string)$candidate);
		if ($candidate === '') {
			continue;
		}
		// Absolute paths must exist; bare "php" resolves via PATH.
		if ($candidate === 'php' || is_executable($candidate)) {
			return $candidate;
		}
	}
	throw new RuntimeException('Cannot resolve a usable PHP binary for race workers.');
}

/**
 * Spawn two worker processes that race the same action.
 *
 * @param list<string> $argsA
 * @param list<string> $argsB
 * @return list<array{token:string,code:int}>
 */
function raceTwo(string $self, array $argsA, array $argsB, array $env): array
{
	$php = resolvePhpBinary();
	$descriptors = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$procs = [];
	$pipes = [];
	foreach ([$argsA, $argsB] as $i => $args) {
		$cmd = array_merge([$php, $self, '--worker'], $args);
		$proc = proc_open($cmd, $descriptors, $pipeSet, null, $env);
		if (!is_resource($proc)) {
			fwrite(STDERR, "Failed to spawn worker $i\n");
			exit(2);
		}
		$procs[$i] = $proc;
		$pipes[$i] = $pipeSet;
		fclose($pipeSet[0]);
	}
	usleep(50_000);

	$results = [];
	foreach ($procs as $i => $proc) {
		$stdout = stream_get_contents($pipes[$i][1]) ?: '';
		$stderr = stream_get_contents($pipes[$i][2]) ?: '';
		fclose($pipes[$i][1]);
		fclose($pipes[$i][2]);
		$code = proc_close($proc);
		$token = trim(explode("\n", trim($stdout))[0] ?? '');
		if ($token === '') {
			fwrite(STDERR, "Worker $i empty stdout (exit=$code stderr=$stderr)\n");
			exit(2);
		}
		$results[] = ['token' => $token, 'code' => $code];
	}
	return $results;
}

// ── Worker mode (spawned by the orchestrator) ───────────────────────────
if (($argv[1] ?? '') === '--worker') {
	$mode = $argv[2] ?? '';
	try {
		$pdo = pg_connect_retry($dsn, $user, $pass);
		if ($mode === 'complete') {
			$visitId = (int)($argv[3] ?? 0);
			$result = tryComplete($pdo, $visitId, 'worker', date('Y-m-d'));
			echo ($result['ok'] ? 'OK' : 'CONFLICT:' . $result['code']) . "\n";
			exit(0);
		}
		if ($mode === 'schedule') {
			$planId = (int)($argv[3] ?? 0);
			$dueOn = $argv[4] ?? date('Y-m-d');
			$result = trySchedule($pdo, $planId, $dueOn);
			echo ($result['ok'] ? 'OK' : 'CONFLICT:' . $result['code']) . "\n";
			exit(0);
		}
		fwrite(STDERR, "Unknown worker mode: $mode\n");
		exit(2);
	} catch (Throwable $e) {
		fwrite(STDERR, $e->getMessage() . "\n");
		exit(2);
	}
}

// ── Orchestrator ────────────────────────────────────────────────────────
try {
	ensureDatabase($dsn, $user, $pass);
	$pdo = pg_connect_retry($dsn, $user, $pass);
	ensureSchema($pdo);

	$env = [];
	foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
		if (is_string($key) && is_scalar($value)) {
			$env[$key] = (string)$value;
		}
	}
	$env['MN_PG_DSN'] = $dsn;
	$env['MN_PG_USER'] = $user;
	$env['MN_PG_PASS'] = $pass;
	$self = __FILE__;
	$failures = 0;

	// AC-6: parallel complete → one OK, one CONFLICT:visit_not_open, one follow-up.
	$pdo->exec("INSERT INTO mn_plans (active) VALUES (TRUE)");
	$planId = (int)$pdo->lastInsertId('mn_plans_id_seq');
	$ins = $pdo->prepare("INSERT INTO mn_visits (plan_id, status, due_on, created_at) VALUES (:pid, 'scheduled', CURRENT_DATE, :ts) RETURNING id");
	$ins->execute(['pid' => $planId, 'ts' => time()]);
	$visitId = (int)$ins->fetchColumn();

	[$a, $b] = raceTwo($self, ['complete', (string)$visitId], ['complete', (string)$visitId], $env);
	$tokens = [$a['token'], $b['token']];
	$ok = in_array('OK', $tokens, true);
	$conflicts = array_values(array_filter($tokens, static fn (string $t): bool => $t === 'CONFLICT:visit_not_open'));
	$openCount = (int)$pdo->query("SELECT COUNT(*) FROM mn_visits WHERE plan_id = $planId AND status = 'scheduled'")->fetchColumn();
	$doneCount = (int)$pdo->query("SELECT COUNT(*) FROM mn_visits WHERE plan_id = $planId AND status = 'done'")->fetchColumn();

	if ($ok && count($conflicts) === 1 && $openCount === 1 && $doneCount === 1) {
		echo "AC-6 Postgres dual-process complete: PASS (tokens=" . implode(',', $tokens) . ")\n";
	} else {
		echo "AC-6 Postgres dual-process complete: FAIL tokens=" . implode(',', $tokens)
			. " open=$openCount done=$doneCount\n";
		$failures++;
	}

	// I5: parallel schedule after cancel → one OK, one CONFLICT:visit_already_open.
	$pdo->exec("UPDATE mn_visits SET status = 'cancelled' WHERE plan_id = $planId AND status = 'scheduled'");
	$openBefore = (int)$pdo->query("SELECT COUNT(*) FROM mn_visits WHERE plan_id = $planId AND status = 'scheduled'")->fetchColumn();
	if ($openBefore !== 0) {
		echo "I5 Postgres dual-process schedule: FAIL precondition open=$openBefore\n";
		$failures++;
	} else {
		$due = date('Y-m-d');
		[$c, $d] = raceTwo($self, ['schedule', (string)$planId, $due], ['schedule', (string)$planId, $due], $env);
		$tokens2 = [$c['token'], $d['token']];
		$ok2 = in_array('OK', $tokens2, true);
		$conflicts2 = array_values(array_filter($tokens2, static fn (string $t): bool => $t === 'CONFLICT:visit_already_open'));
		$openAfter = (int)$pdo->query("SELECT COUNT(*) FROM mn_visits WHERE plan_id = $planId AND status = 'scheduled'")->fetchColumn();
		if ($ok2 && count($conflicts2) === 1 && $openAfter === 1) {
			echo "I5 Postgres dual-process schedule: PASS (tokens=" . implode(',', $tokens2) . ")\n";
		} else {
			echo "I5 Postgres dual-process schedule: FAIL tokens=" . implode(',', $tokens2)
				. " open=$openAfter\n";
			$failures++;
		}
	}

	exit($failures === 0 ? 0 : 1);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(2);
}
