<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Command;

use OCA\MaintenanceCheck\Service\ReferenceDatasetSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SPEC §12 N4 — seed or purge the reference dataset used for latency gates.
 */
class SeedReferenceDatasetCommand extends Command
{
	public function __construct(
		private readonly ReferenceDatasetSeeder $seeder,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('maintenancecheck:seed-dataset')
			->setDescription('Seed or purge the MaintenanceCheck N4 reference dataset.')
			->addArgument('action', InputArgument::OPTIONAL, 'seed|purge', 'seed')
			->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'smoke|n4', 'smoke')
			->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Creating user id', 'admin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$action = (string)$input->getArgument('action');
		if ($action === 'purge') {
			$deleted = $this->seeder->purge();
			$io->success(sprintf('Purged %d marker customer(s).', $deleted));
			return Command::SUCCESS;
		}
		if ($action !== 'seed') {
			$io->error('Action must be seed or purge.');
			return Command::FAILURE;
		}
		$profile = (string)$input->getOption('profile');
		$uid = (string)$input->getOption('uid');
		$io->writeln(sprintf('Seeding profile <info>%s</info> as <comment>%s</comment>…', $profile, $uid));
		$result = $this->seeder->seed($profile, $uid);
		$io->table(
			['Metric', 'Count'],
			[
				['customers', (string)$result['customers']],
				['equipment', (string)$result['equipment']],
				['plans', (string)$result['plans']],
				['visits', (string)$result['visits']],
				['openVisits', (string)$result['openVisits']],
			],
		);
		$io->success('Dataset ready.');
		return Command::SUCCESS;
	}
}
