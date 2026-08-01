<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\AppInfo;

use OCA\MaintenanceCheck\Listener\UserDeletedListener;
use OCP\User\Events\UserDeletedEvent;
use OCP\Lock\ILockingProvider;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use OCA\MaintenanceCheck\Repair\BackupBeforeUpdate;
use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\DayTourMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\KitTemplateMapper;
use OCA\MaintenanceCheck\Db\KitTplLineMapper;
use OCA\MaintenanceCheck\Db\LicenseStateMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\MeterMapper;
use OCA\MaintenanceCheck\Db\MeterReadingMapper;
use OCA\MaintenanceCheck\Db\MobileSeatMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\ProcedureMapper;
use OCA\MaintenanceCheck\Db\ProcItemMapper;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\SkillMapper;
use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\UserCapacityMapper;
use OCA\MaintenanceCheck\Db\UserSkillMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Db\WoChecklistMapper;
use OCA\MaintenanceCheck\Db\WoKitLineMapper;
use OCA\MaintenanceCheck\Db\WoKitMapper;
use OCA\MaintenanceCheck\Db\WoPhotoMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Db\WoSignatureMapper;
use OCA\MaintenanceCheck\Db\WoSkillMapper;
use OCA\MaintenanceCheck\Db\WoCommentMapper;
use OCA\MaintenanceCheck\Db\NotifLogMapper;
use OCA\MaintenanceCheck\Db\FailureCodeMapper;
use OCA\MaintenanceCheck\Db\EquipDocMapper;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Repair\EnsureMaintenanceCheckSchema;
use OCA\MaintenanceCheck\Repair\SeedBuiltinProcedurePacks;
use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\CapacityCalculator;
use OCA\MaintenanceCheck\Service\CapacityService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\DispatchService;
use OCA\MaintenanceCheck\Service\DueBoard;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\EvidenceStorage;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCA\MaintenanceCheck\Service\KitReadiness;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MeterMath;
use OCA\MaintenanceCheck\Service\MeterService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\ProjectCheckHoursDeepLinkService;
use OCA\MaintenanceCheck\Service\ArbeitszeitCheckDeepLinkService;
use OCA\MaintenanceCheck\Service\DutyCheckOnDutyService;
use OCA\MaintenanceCheck\Service\ReferenceDatasetSeeder;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use OCA\MaintenanceCheck\Service\SiteService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\SkillsAssignPolicy;
use OCA\MaintenanceCheck\Service\TourService;
use OCA\MaintenanceCheck\Service\TourSort;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderAccessPolicy;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCA\MaintenanceCheck\Service\WorkOrderStateMachine;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCA\MaintenanceCheck\BackgroundJob\OverdueReminderJob;
use OCA\MaintenanceCheck\Notification\Notifier;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\EquipDocService;
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

// Dompdf lives in the app-local vendor directory. Nextcloud only autoloads
// OCA\* classes, so the app must load its own composer autoloader.
//
// CRITICAL: composer's autoload.php registers itself *prepended*. The vendor
// tree may contain dev dependencies (nextcloud/ocp stubs) — a prepended
// loader would shadow the server's real OCP interfaces and break the whole
// instance. Re-register appended so Nextcloud's own autoloader always wins
// for OCP\*/OC\* while Dompdf still resolves here.
$maintenancecheckAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!class_exists(\Dompdf\Dompdf::class, false) && is_file($maintenancecheckAutoload)) {
	$maintenancecheckLoader = require $maintenancecheckAutoload;
	if ($maintenancecheckLoader instanceof \Composer\Autoload\ClassLoader) {
		$maintenancecheckLoader->unregister();
		$maintenancecheckLoader->register(false);
	}
}

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
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerNotifierService(Notifier::class);
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
		$context->registerService(SiteMapper::class, static function ($c): SiteMapper {
			return new SiteMapper($c->get(IDBConnection::class));
		});
		$context->registerService(ProcedureMapper::class, static function ($c): ProcedureMapper {
			return new ProcedureMapper($c->get(IDBConnection::class));
		});
		$context->registerService(ProcItemMapper::class, static function ($c): ProcItemMapper {
			return new ProcItemMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WorkOrderMapper::class, static function ($c): WorkOrderMapper {
			return new WorkOrderMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoChecklistMapper::class, static function ($c): WoChecklistMapper {
			return new WoChecklistMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoPhotoMapper::class, static function ($c): WoPhotoMapper {
			return new WoPhotoMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoSignatureMapper::class, static function ($c): WoSignatureMapper {
			return new WoSignatureMapper($c->get(IDBConnection::class));
		});
		$context->registerService(KitTemplateMapper::class, static function ($c): KitTemplateMapper {
			return new KitTemplateMapper($c->get(IDBConnection::class));
		});
		$context->registerService(KitTplLineMapper::class, static function ($c): KitTplLineMapper {
			return new KitTplLineMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoKitMapper::class, static function ($c): WoKitMapper {
			return new WoKitMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoKitLineMapper::class, static function ($c): WoKitLineMapper {
			return new WoKitLineMapper($c->get(IDBConnection::class));
		});
		$context->registerService(SkillMapper::class, static function ($c): SkillMapper {
			return new SkillMapper($c->get(IDBConnection::class));
		});
		$context->registerService(UserSkillMapper::class, static function ($c): UserSkillMapper {
			return new UserSkillMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoSkillMapper::class, static function ($c): WoSkillMapper {
			return new WoSkillMapper($c->get(IDBConnection::class));
		});

		$context->registerService(FailureCodeMapper::class, static function ($c): FailureCodeMapper {
			return new FailureCodeMapper($c->get(IDBConnection::class));
		});
		$context->registerService(EquipDocMapper::class, static function ($c): EquipDocMapper {
			return new EquipDocMapper($c->get(IDBConnection::class));
		});
		$context->registerService(WoCommentMapper::class, static function ($c): WoCommentMapper {
			return new WoCommentMapper($c->get(IDBConnection::class));
		});
		$context->registerService(NotifLogMapper::class, static function ($c): NotifLogMapper {
			return new NotifLogMapper($c->get(IDBConnection::class));
		});

		$context->registerService(DayTourMapper::class, static function ($c): DayTourMapper {
			return new DayTourMapper($c->get(IDBConnection::class));
		});
		$context->registerService(TourStopMapper::class, static function ($c): TourStopMapper {
			return new TourStopMapper($c->get(IDBConnection::class));
		});
		$context->registerService(UserCapacityMapper::class, static function ($c): UserCapacityMapper {
			return new UserCapacityMapper($c->get(IDBConnection::class));
		});
		$context->registerService(MeterMapper::class, static function ($c): MeterMapper {
			return new MeterMapper($c->get(IDBConnection::class));
		});
		$context->registerService(MeterReadingMapper::class, static function ($c): MeterReadingMapper {
			return new MeterReadingMapper($c->get(IDBConnection::class));
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
		$context->registerService(ShowIfEvaluator::class, static function (): ShowIfEvaluator {
			return new ShowIfEvaluator();
		});
		$context->registerService(WorkOrderStateMachine::class, static function (): WorkOrderStateMachine {
			return new WorkOrderStateMachine();
		});
		$context->registerService(ChecklistPolicy::class, static function ($c): ChecklistPolicy {
			return new ChecklistPolicy($c->get(ShowIfEvaluator::class));
		});
		$context->registerService(KitReadiness::class, static function (): KitReadiness {
			return new KitReadiness();
		});
		$context->registerService(TourSort::class, static function (): TourSort {
			return new TourSort();
		});
		$context->registerService(CapacityCalculator::class, static function (): CapacityCalculator {
			return new CapacityCalculator();
		});
		$context->registerService(MeterMath::class, static function (): MeterMath {
			return new MeterMath();
		});
		$context->registerService(PackSchema::class, static function ($c): PackSchema {
			return new PackSchema($c->get(ShowIfEvaluator::class));
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
				$c->get(SiteMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(ISecureRandom::class),
				$c->get(IURLGenerator::class),
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
				$c->get(MeterMapper::class),
				$c->get(IntervalCalculator::class),
				$c->get(MeterMath::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(InventoryFlangeService::class, static function ($c): InventoryFlangeService {
			return new InventoryFlangeService(
				$c->get(IConfig::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(ProjectCheckHoursDeepLinkService::class, static function ($c): ProjectCheckHoursDeepLinkService {
			return new ProjectCheckHoursDeepLinkService(
				$c->get(IAppManager::class),
				$c->get(IURLGenerator::class),
			);
		});
		$context->registerService(ArbeitszeitCheckDeepLinkService::class, static function ($c): ArbeitszeitCheckDeepLinkService {
			return new ArbeitszeitCheckDeepLinkService(
				$c->get(IAppManager::class),
				$c->get(IURLGenerator::class),
			);
		});
		$context->registerService(DutyCheckOnDutyService::class, static function ($c): DutyCheckOnDutyService {
			return new DutyCheckOnDutyService(
				$c->get(IAppManager::class),
				$c->get(LoggerInterface::class),
				$c->get(IUserSession::class),
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
				$c->get(WorkOrderMapper::class),
				$c->get(ProjectCheckHoursDeepLinkService::class),
				$c->get(ArbeitszeitCheckDeepLinkService::class),
				$c->get(MeterService::class),
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
		$context->registerService(SiteService::class, static function ($c): SiteService {
			return new SiteService(
				$c->get(SiteMapper::class),
				$c->get(CustomerMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(PolicyService::class, static function ($c): PolicyService {
			return new PolicyService($c->get(IConfig::class));
		});
		$context->registerService(EvidenceStorage::class, static function ($c): EvidenceStorage {
			return new EvidenceStorage(
				$c->get(IAppDataFactory::class),
				$c->get(ISecureRandom::class),
			);
		});
		$context->registerService(ProcedureService::class, static function ($c): ProcedureService {
			return new ProcedureService(
				$c->get(IDBConnection::class),
				$c->get(ProcedureMapper::class),
				$c->get(ProcItemMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(ShowIfEvaluator::class),
				$c->get(PackSchema::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(KitService::class, static function ($c): KitService {
			return new KitService(
				$c->get(IDBConnection::class),
				$c->get(KitTemplateMapper::class),
				$c->get(KitTplLineMapper::class),
				$c->get(WoKitMapper::class),
				$c->get(WoKitLineMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(KitReadiness::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(WorkOrderAccessPolicy::class),
			);
		});
		$context->registerService(SkillService::class, static function ($c): SkillService {
			return new SkillService(
				$c->get(SkillMapper::class),
				$c->get(UserSkillMapper::class),
				$c->get(WoSkillMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
			);
		});
		$context->registerService(CapacityService::class, static function ($c): CapacityService {
			return new CapacityService(
				$c->get(UserCapacityMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(CapacityCalculator::class),
				$c->get(PolicyService::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
				$c->get(DutyCheckOnDutyService::class),
			);
		});
		$context->registerService(WoChecklistService::class, static function ($c): WoChecklistService {
			return new WoChecklistService(
				$c->get(IDBConnection::class),
				$c->get(WoChecklistMapper::class),
				$c->get(ProcItemMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(ShowIfEvaluator::class),
				$c->get(ChecklistPolicy::class),
				$c->get(PolicyService::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(WorkOrderAccessPolicy::class),
			);
		});
		$context->registerService(WorkOrderAccessPolicy::class, static function ($c): WorkOrderAccessPolicy {
			return new WorkOrderAccessPolicy(
				$c->get(AccessControlService::class),
			);
		});
		$context->registerService(WorkOrderService::class, static function ($c): WorkOrderService {
			return new WorkOrderService(
				$c->get(IDBConnection::class),
				$c->get(WorkOrderMapper::class),
				$c->get(VisitMapper::class),
				$c->get(CustomerMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(SiteMapper::class),
				$c->get(ProcedureMapper::class),
				$c->get(WoPhotoMapper::class),
				$c->get(WoSignatureMapper::class),
				$c->get(TourStopMapper::class),
				$c->get(WorkOrderStateMachine::class),
				$c->get(WoChecklistService::class),
				$c->get(KitService::class),
				$c->get(SkillService::class),
				$c->get(SkillsAssignPolicy::class),
				$c->get(CapacityService::class),
				$c->get(PolicyService::class),
				$c->get(VisitService::class),
				$c->get(AccessControlService::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
				$c->get(IEventDispatcher::class),
				$c->get(LoggerInterface::class),
				$c->get(InventoryFlangeService::class),
				$c->get(ProjectCheckHoursDeepLinkService::class),
				$c->get(ArbeitszeitCheckDeepLinkService::class),
				$c->get(MeterService::class),
				$c->get(WorkOrderAccessPolicy::class),
			);
		});
		$context->registerService(SkillsAssignPolicy::class, static function (): SkillsAssignPolicy {
			return new SkillsAssignPolicy();
		});
		$context->registerService(WoEvidenceService::class, static function ($c): WoEvidenceService {
			return new WoEvidenceService(
				$c->get(WorkOrderMapper::class),
				$c->get(WoPhotoMapper::class),
				$c->get(WoSignatureMapper::class),
				$c->get(EvidenceStorage::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
				$c->get(WorkOrderAccessPolicy::class),
			);
		});
		$context->registerService(TourService::class, static function ($c): TourService {
			return new TourService(
				$c->get(IDBConnection::class),
				$c->get(DayTourMapper::class),
				$c->get(TourStopMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(SiteMapper::class),
				$c->get(TourSort::class),
				$c->get(InputValidator::class),
				$c->get(IntervalCalculator::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
			);
		});
		$context->registerService(DispatchService::class, static function ($c): DispatchService {
			return new DispatchService(
				$c->get(WorkOrderMapper::class),
				$c->get(TourStopMapper::class),
				$c->get(WorkOrderService::class),
				$c->get(CapacityService::class),
				$c->get(IntervalCalculator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(MeterService::class, static function ($c): MeterService {
			return new MeterService(
				$c->get(IDBConnection::class),
				$c->get(MeterMapper::class),
				$c->get(MeterReadingMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(PlanMapper::class),
				$c->get(VisitMapper::class),
				$c->get(MeterMath::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(WoPdfService::class, static function ($c): WoPdfService {
			return new WoPdfService(
				$c->get(WorkOrderMapper::class),
				$c->get(WorkOrderService::class),
				$c->get(WoSignatureMapper::class),
				$c->get(EvidenceStorage::class),
				$c->get(IL10N::class),
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
		$context->registerService(BuiltinProcedurePackSeeder::class, static function ($c): BuiltinProcedurePackSeeder {
			return new BuiltinProcedurePackSeeder(
				$c->get(ProcedureService::class),
				$c->get(ProcedureMapper::class),
				$c->get(PackSchema::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(SeedReferenceDatasetCommand::class, static function ($c): SeedReferenceDatasetCommand {
			return new SeedReferenceDatasetCommand($c->get(ReferenceDatasetSeeder::class));
		});
		$context->registerService(UpgradeBackupCommand::class, static function ($c): UpgradeBackupCommand {
			return new UpgradeBackupCommand($c->get(UpgradeBackupService::class));
		});

		
		$context->registerService(FailureCodeService::class, static function ($c): FailureCodeService {
			return new FailureCodeService(
				$c->get(FailureCodeMapper::class),
				$c->get(InputValidator::class),
			);
		});
		$context->registerService(EquipDocService::class, static function ($c): EquipDocService {
			return new EquipDocService(
				$c->get(EquipDocMapper::class),
				$c->get(EquipmentMapper::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(WoCommentService::class, static function ($c): WoCommentService {
			return new WoCommentService(
				$c->get(WoCommentMapper::class),
				$c->get(WorkOrderMapper::class),
				$c->get(WorkOrderAccessPolicy::class),
				$c->get(InputValidator::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(KpiService::class, static function ($c): KpiService {
			return new KpiService(
				$c->get(IDBConnection::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(ExceptionBoardService::class, static function ($c): ExceptionBoardService {
			return new ExceptionBoardService(
				$c->get(IDBConnection::class),
				$c->get(KitService::class),
				$c->get(Clock::class),
				$c->get(InputValidator::class),
			);
		});
		$context->registerService(OverdueReminderService::class, static function ($c): OverdueReminderService {
			return new OverdueReminderService(
				$c->get(IDBConnection::class),
				$c->get(NotifLogMapper::class),
				$c->get(INotificationManager::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(ITimeFactory::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(OverdueReminderJob::class, static function ($c): OverdueReminderJob {
			return new OverdueReminderJob(
				$c->get(ITimeFactory::class),
				$c->get(OverdueReminderService::class),
			);
		});

		// ── Repair steps (resolved by occ upgrade — full arity mandatory)
		$context->registerService(EnsureMaintenanceCheckSchema::class, static function ($c): EnsureMaintenanceCheckSchema {
			return new EnsureMaintenanceCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(SeedBuiltinProcedurePacks::class, static function ($c): SeedBuiltinProcedurePacks {
			return new SeedBuiltinProcedurePacks(
				$c->get(BuiltinProcedurePackSeeder::class),
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
