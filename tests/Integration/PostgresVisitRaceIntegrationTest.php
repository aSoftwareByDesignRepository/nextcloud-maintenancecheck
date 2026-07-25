<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * SPEC §14.2-I5 / AC-6 / N3 / N6 on real PostgreSQL (dual-process FOR UPDATE).
 *
 * MariaDB races live in {@see ConcurrencyRaceIntegrationTest}. This suite
 * shells the portable locking protocol against Postgres so N6 is not
 * "schema-declared only".
 *
 * @group DB
 * @group concurrency
 * @group postgres
 */
final class PostgresVisitRaceIntegrationTest extends IntegrationTestCase
{
	public function testAc6AndI5PostgresDualProcessVisitRaces(): void
	{
		if (!extension_loaded('pdo_pgsql')) {
			$this->markTestSkipped('pdo_pgsql extension required');
		}

		$script = dirname(__DIR__, 2) . '/scripts/run-postgres-visit-races.php';
		$this->assertFileExists($script);

		$dsn = getenv('MN_PG_DSN') ?: 'pgsql:host=deskcheck-postgres;port=5432;dbname=mn_race';
		$env = [
			'MN_PG_DSN' => $dsn,
			'MN_PG_USER' => getenv('MN_PG_USER') ?: 'deskcheck',
			'MN_PG_PASS' => getenv('MN_PG_PASS') ?: 'deskcheck',
		];

		// Probe reachability first so a missing container becomes a clear skip.
		try {
			$adminDsn = preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn) ?? $dsn;
			$pdo = new \PDO(
				$adminDsn,
				$env['MN_PG_USER'],
				$env['MN_PG_PASS'],
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
			);
			$pdo->query('SELECT 1');
		} catch (\Throwable $e) {
			$this->markTestSkipped(
				'Postgres race service not reachable (' . $e->getMessage() . '). '
				. 'Start: docker compose -f docker-compose.yml -f docker-compose.deskcheck-pg.yml up -d deskcheck-postgres'
			);
		}

		$cmd = 'php ' . escapeshellarg($script);
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		// Merge into the current environment — a replacement env without PATH
		// makes PHP_BINARY empty inside the harness and breaks worker spawns.
		$fullEnv = array_merge($_ENV, $_SERVER, $env);
		$cleanEnv = [];
		foreach ($fullEnv as $key => $value) {
			if (is_string($key) && is_scalar($value)) {
				$cleanEnv[$key] = (string)$value;
			}
		}
		$proc = proc_open($cmd, $descriptors, $pipes, null, $cleanEnv);
		$this->assertIsResource($proc);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);

		$this->assertSame(
			0,
			$code,
			"Postgres visit race harness failed (exit $code)\nSTDOUT:\n$stdout\nSTDERR:\n$stderr",
		);
		$this->assertStringContainsString('AC-6 Postgres dual-process complete: PASS', (string)$stdout);
		$this->assertStringContainsString('I5 Postgres dual-process schedule: PASS', (string)$stdout);
	}
}
