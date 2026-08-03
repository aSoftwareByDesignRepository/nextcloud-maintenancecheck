<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\ProcedureMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use Psr\Log\LoggerInterface;

/**
 * Ships ≥3 vertical procedure packs (AC-W1-8). Idempotent: skips packs
 * whose `pack_code` is already installed.
 */
final class BuiltinProcedurePackSeeder
{
	public const ACTOR = 'maintenancecheck-seed';

	/** @var list<string> */
	public const PACK_FILES = [
		'builtin-shk-v1.json',
		'builtin-security-v1.json',
		'builtin-electro-v1.json',
		'builtin-facility-v1.json',
		'builtin-hvac-v1.json',
		'builtin-industrial-v1.json',
		'builtin-shk-de-v1.json',
		'builtin-security-de-v1.json',
		'builtin-electro-de-v1.json',
		// W7 Prüfpflichten seed packs (CORE §21.6 AC-W7-1) — DE + EN
		'de-portable-electrical-v1.json',
		'de-ladders-v1.json',
		'de-fire-extinguisher-v1.json',
		'en-portable-electrical-v1.json',
		'en-ladders-v1.json',
		'en-fire-extinguisher-v1.json',
	];

	public function __construct(
		private readonly ProcedureService $procedures,
		private readonly ProcedureMapper $procedureMapper,
		private readonly PackSchema $packSchema,
		private readonly LoggerInterface $logger,
	) {
	}

	public function packsDirectory(): string
	{
		return dirname(__DIR__, 2) . '/data/procedure-packs';
	}

	/**
	 * @return array{installed: list<string>, skipped: list<string>, failed: list<string>}
	 */
	public function ensureInstalled(): array
	{
		$installed = [];
		$skipped = [];
		$failed = [];
		$dir = $this->packsDirectory();
		foreach (self::PACK_FILES as $file) {
			$path = $dir . '/' . $file;
			if (!is_readable($path)) {
				$failed[] = $file . ':missing';
				$this->logger->warning('MaintenanceCheck builtin pack missing: ' . $path);
				continue;
			}
			$raw = (string)file_get_contents($path);
			try {
				$parsed = $this->packSchema->parse($raw);
			} catch (\Throwable $e) {
				$failed[] = $file . ':invalid';
				$this->logger->error('MaintenanceCheck builtin pack invalid: ' . $file, ['exception' => $e]);
				continue;
			}
			$code = $parsed['packCode'];
			if ($this->procedureMapper->findBySourcePack($code) !== []) {
				$skipped[] = $code;
				continue;
			}
			try {
				$this->procedures->importPack(self::ACTOR, $raw, false);
				$installed[] = $code;
			} catch (ConflictException) {
				$skipped[] = $code;
			} catch (\Throwable $e) {
				$failed[] = $code . ':import';
				$this->logger->error('MaintenanceCheck builtin pack import failed: ' . $code, ['exception' => $e]);
			}
		}

		return ['installed' => $installed, 'skipped' => $skipped, 'failed' => $failed];
	}
}
