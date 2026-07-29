<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Repair;

use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Install W1 vertical procedure packs after schema is present.
 */
final class SeedBuiltinProcedurePacks implements IRepairStep
{
	public function __construct(
		private readonly BuiltinProcedurePackSeeder $seeder,
	) {
	}

	public function getName(): string
	{
		return 'Seed MaintenanceCheck builtin procedure packs (SHK, security, electro, facility, HVAC, industrial — EN+DE where shipped)';
	}

	public function run(IOutput $output): void
	{
		$result = $this->seeder->ensureInstalled();
		$output->info(sprintf(
			'MaintenanceCheck packs: installed=%d skipped=%d failed=%d',
			count($result['installed']),
			count($result['skipped']),
			count($result['failed']),
		));
		foreach ($result['failed'] as $fail) {
			$output->warning('Pack seed issue: ' . $fail);
		}
	}
}
