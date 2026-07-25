<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use OCA\MaintenanceCheck\Repair\BackupBeforeUpdate;
use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\LicenseStateMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\MobileSeatMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Repair\EnsureMaintenanceCheckSchema;
use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\DueBoard;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\ReferenceDatasetSeeder;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Command\SeedReferenceDatasetCommand;
use OCA\MaintenanceCheck\Command\UpgradeBackupCommand;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * All factories are explicit and pass the full constructor dependency list
 * (nextcloud-repair-step-di skill, rules 2 and 5): repair steps are resolved
 * through this container during `occ upgrade`, and a short factory would
 * fatal the upgrade with an ArgumentCountError.
 */
class Application extends App implements IBootstrap
{
	public const APP_ID = 'maintenancecheck';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		// ── Mappers ─────────────────────────────────────────────────────
		$context->registerService(CustomerMapper::class, static function ($c): CustomerMapper {
			return new CustomerMapper($c->get(IDBConnection::class));
		});
		$context->registerService(EquipmentMapper::class, static function ($c): EquipmentMapper {
			return new EquipmentMapper($c->get(IDBConnection::class));
		});
		$context->registerService(EquipTypeMapper::class, static function ($c): EquipTypeMapper {
			return new EquipTypeMapper($c->get(IDBConnection::class));
		});
		$context->registerService(MaintTypeMapper::class, static function ($c): MaintTypeMapper {
			return new MaintTypeMapper($c->get(IDBConnection::class));
		});
		$context->registerService(PlanMapper::class, static function ($c): PlanMapper {
			return new PlanMapper($c->get(IDBConnection::class));
		});
		$context->registerService(VisitMapper::class, static function ($c): VisitMapper {
			return new VisitMapper($c->get(IDBConnection::class));
		});
		$context->registerService(LicenseStateMapper::class, static function ($c): LicenseStateMapper {
			return new LicenseStateMapper($c->get(IDBConnection::class));
		});
		$context->registerService(MobileSeatMapper::class, static function ($c): MobileSeatMapper {
			return new MobileSeatMapper($c->get(IDBConnection::class));
		});

		// ── Pure logic ──────────────────────────────────────────────────
		$context->registerService(Clock::class, static function (): Clock {
			return new Clock();
		});
		$context->registerService(IntervalCalculator::class, static function (): IntervalCalculator {
			return new IntervalCalculator();
		});
		$context->registerService(DueBoard::class, static function ($c): DueBoard {
			return new DueBoard($c->get(IntervalCalculator::class));
		});
		$context->registerService(InputValidator::class, static function ($c): InputValidator {
			return new InputValidator($c->get(IntervalCalculator::class));
		});

		// ── Domain services ─────────────────────────────────────────────
		$context->registerService(AccessControlService::class, static function ($c): AccessControlService {
			return new AccessControlService(
				$c->get(IConfig::class),
				$c->get(IGroupManager::class),
				$c->get(IUserSession::class),
			);
		});
		$context->registerService(CustomerService::class, static function ($c): CustomerService {
			return new CustomerService(
				$c->get(IDBConnection::class),
				$c->get(CustomerMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(PlanMapper::class),
				$c->get(VisitMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(EquipmentService::class, static function ($c): EquipmentService {
			return new EquipmentService(
				$c->get(EquipmentMapper::class),
				$c->get(CustomerMapper::class),
				$c->get(EquipTypeMapper::class),
				$c->get(PlanMapper::class),
				$c->get(VisitMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(CatalogService::class, static function ($c): CatalogService {
			return new CatalogService(
				$c->get(EquipTypeMapper::class),
				$c->get(MaintTypeMapper::class),
				$c->get(InputValidator::class),
			);
		});
		$context->registerService(PlanService::class, static function ($c): PlanService {
			return new PlanService(
				$c->get(IDBConnection::class),
				$c->get(PlanMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(MaintTypeMapper::class),
				$c->get(VisitMapper::class),
				$c->get(IntervalCalculator::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(VisitService::class, static function ($c): VisitService {
			return new VisitService(
				$c->get(IDBConnection::class),
				$c->get(VisitMapper::class),
				$c->get(PlanMapper::class),
				$c->get(CustomerMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(MaintTypeMapper::class),
				$c->get(IntervalCalculator::class),
				$c->get(DueBoard::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
			);
		});
		$context->registerService(LicenseService::class, static function ($c): LicenseService {
			return new LicenseService(
				$c->get(IDBConnection::class),
				$c->get(LicenseStateMapper::class),
				$c->get(MobileSeatMapper::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
				$c->get(InputValidator::class),
				$c->get(ILockingProvider::class),
			);
		});
		$context->registerService(MobileGateService::class, static function ($c): MobileGateService {
			return new MobileGateService($c->get(LicenseService::class));
		});
		$context->registerService(ReferenceDatasetSeeder::class, static function ($c): ReferenceDatasetSeeder {
			return new ReferenceDatasetSeeder(
				$c->get(IDBConnection::class),
				$c->get(CustomerService::class),
				$c->get(EquipmentService::class),
				$c->get(CatalogService::class),
				$c->get(PlanService::class),
				$c->get(VisitMapper::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(SeedReferenceDatasetCommand::class, static function ($c): SeedReferenceDatasetCommand {
			return new SeedReferenceDatasetCommand($c->get(ReferenceDatasetSeeder::class));
		});
		$context->registerService(UpgradeBackupCommand::class, static function ($c): UpgradeBackupCommand {
			return new UpgradeBackupCommand($c->get(UpgradeBackupService::class));
		});

		// ── Repair steps (resolved by occ upgrade — full arity mandatory)
		$context->registerService(EnsureMaintenanceCheckSchema::class, static function ($c): EnsureMaintenanceCheckSchema {
			return new EnsureMaintenanceCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(UninstallDropTables::class, static function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});


		// ── Middleware ──────────────────────────────────────────────────
		$context->registerService(AppAccessMiddleware::class, static function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->get(IUserSession::class),
				$c->get(AccessControlService::class),
				$c->get(IRequest::class),
				$c->get(IURLGenerator::class),
				$c->get(IFactory::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);
	}

	public function boot(IBootContext $context): void
	{
	}
}
