<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

final class EnsureMaintenanceCheckSchema implements IRepairStep
{
	/** @var list<array{code: string, name: string, sort: int}> */
	public const SEED_EQUIP_TYPES = [
		['code' => 'alarm_panel', 'name' => 'Alarm panel', 'sort' => 10],
		['code' => 'cctv', 'name' => 'CCTV / video', 'sort' => 20],
		['code' => 'boiler', 'name' => 'Boiler', 'sort' => 30],
		['code' => 'heat_pump', 'name' => 'Heat pump', 'sort' => 40],
		['code' => 'ventilation', 'name' => 'Ventilation unit', 'sort' => 50],
		['code' => 'ups', 'name' => 'UPS', 'sort' => 60],
		['code' => 'extinguisher', 'name' => 'Fire extinguisher', 'sort' => 70],
		['code' => 'other', 'name' => 'Other', 'sort' => 999],
	];

	/** @var list<array{code: string, name: string, sort: int}> */
	public const SEED_MAINT_TYPES = [
		['code' => 'annual_inspection', 'name' => 'Annual inspection', 'sort' => 10],
		['code' => 'battery', 'name' => 'Battery replacement', 'sort' => 20],
		['code' => 'filter', 'name' => 'Filter change', 'sort' => 30],
		['code' => 'safety_check', 'name' => 'Safety check', 'sort' => 40],
		['code' => 'calibration', 'name' => 'Calibration', 'sort' => 50],
		['code' => 'other', 'name' => 'Other', 'sort' => 999],
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure MaintenanceCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore !== []) {
			$output->info(sprintf(
				'MaintenanceCheck: %d table(s) missing (%s); running pending migrations.',
				count($missingBefore),
				implode(', ', $missingBefore),
			));

			$migrationService = new MigrationService(
				UninstallDropTables::APP_ID,
				Server::get(Connection::class),
			);
			$migrationService->migrate('latest', false);

			$missingAfter = $this->missingTables();
			if ($missingAfter !== []) {
				throw new \RuntimeException(sprintf(
					'MaintenanceCheck schema is still incomplete after migrate("latest"). Missing: %s.',
					implode(', ', $missingAfter),
				));
			}
		}

		$seeded = $this->seedCatalogs();
		$output->info(sprintf(
			'MaintenanceCheck: all %d tables are present; seeded %d catalog row(s).',
			count(UninstallDropTables::TABLES),
			$seeded,
		));
	}

	/**
	 * Idempotent catalog seed (AC-1): inserts only codes that do not exist yet.
	 */
	private function seedCatalogs(): int
	{
		$inserted = 0;
		$inserted += $this->seedTable('mn_equip_types', self::SEED_EQUIP_TYPES);
		$inserted += $this->seedTable('mn_maint_types', self::SEED_MAINT_TYPES);
		return $inserted;
	}

	/**
	 * @param list<array{code: string, name: string, sort: int}> $rows
	 */
	private function seedTable(string $table, array $rows): int
	{
		$inserted = 0;
		foreach ($rows as $row) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id')->from($table)
				->where($qb->expr()->eq('code', $qb->createNamedParameter($row['code'])));
			$result = $qb->executeQuery();
			$exists = $result->fetch() !== false;
			$result->closeCursor();
			if ($exists) {
				continue;
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->insert($table)->values([
				'code' => $qb->createNamedParameter($row['code']),
				'name' => $qb->createNamedParameter($row['name']),
				'sort_order' => $qb->createNamedParameter($row['sort'], \PDO::PARAM_INT),
				'active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
			]);
			$qb->executeStatement();
			$inserted++;
		}
		return $inserted;
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
