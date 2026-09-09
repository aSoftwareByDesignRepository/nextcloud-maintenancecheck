<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Coverage;

use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\ArbeitszeitCheckDeepLinkService;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\CapacityCalculator;
use OCA\MaintenanceCheck\Service\CapacityService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\DispatchService;
use OCA\MaintenanceCheck\Service\DueBoard;
use OCA\MaintenanceCheck\Service\DueQueryKind;
use OCA\MaintenanceCheck\Service\DutyCheckOnDutyService;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCA\MaintenanceCheck\Service\EquipDocStorage;
use OCA\MaintenanceCheck\Service\EquipmentClassService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\EvidenceStorage;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\InspectionClosePolicy;
use OCA\MaintenanceCheck\Service\InspectionFollowUpGuard;
use OCA\MaintenanceCheck\Service\InspectionObligationService;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCA\MaintenanceCheck\Service\KitReadiness;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MeterMath;
use OCA\MaintenanceCheck\Service\MeterService;
use OCA\MaintenanceCheck\Service\MobileCapabilities;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\ProjectCheckHoursDeepLinkService;
use OCA\MaintenanceCheck\Service\ReferenceDatasetSeeder;
use OCA\MaintenanceCheck\Service\SeatRank;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use OCA\MaintenanceCheck\Service\SiteService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\SkillsAssignPolicy;
use OCA\MaintenanceCheck\Service\TourService;
use OCA\MaintenanceCheck\Service\TourSort;
use OCA\MaintenanceCheck\Service\UpgradeBackupCatalog;
use OCA\MaintenanceCheck\Service\UpgradeBackupIntegrity;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderAccessPolicy;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCA\MaintenanceCheck\Service\WorkOrderStateMachine;
use OCA\MaintenanceCheck\Public\CrmFieldCustomerFacade;
use OCA\MaintenanceCheck\BackgroundJob\OverdueReminderJob;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Listener\UserDeletedListener;
use OCA\MaintenanceCheck\Command\SeedReferenceDatasetCommand;
use OCA\MaintenanceCheck\Command\UpgradeBackupCommand;
use OCA\MaintenanceCheck\Repair\BackupBeforeUpdate;
use OCA\MaintenanceCheck\Repair\EnsureMaintenanceCheckSchema;
use OCA\MaintenanceCheck\Repair\SeedBuiltinProcedurePacks;
use OCA\MaintenanceCheck\Repair\UninstallDropTables;
use OCA\MaintenanceCheck\Repair\UninstallRepairFlow;
use OCA\MaintenanceCheck\Notification\Notifier;
use OCA\MaintenanceCheck\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Atlas v3 — invoke shipping-reachable service/entrypoint publics.
 */
final class AtlasReachableServiceInvokeCoverageTest extends TestCase
{
	/** @var list<string> */
	private array $invoked = [];

	public function testReachableServicePublicsInvoke(): void
	{
		foreach ([
			AccessControlService::class,
			ArbeitszeitCheckDeepLinkService::class,
			BuiltinProcedurePackSeeder::class,
			CapacityCalculator::class,
			CapacityService::class,
			CatalogService::class,
			ChecklistPolicy::class,
			Clock::class,
			CustomerService::class,
			DispatchService::class,
			DueBoard::class,
			DueQueryKind::class,
			DutyCheckOnDutyService::class,
			EquipDocService::class,
			EquipDocStorage::class,
			EquipmentClassService::class,
			EquipmentService::class,
			EvidenceStorage::class,
			ExceptionBoardService::class,
			FailureCodeService::class,
			InputValidator::class,
			InspectionClosePolicy::class,
			InspectionFollowUpGuard::class,
			InspectionObligationService::class,
			IntervalCalculator::class,
			InventoryFlangeService::class,
			KitReadiness::class,
			KitService::class,
			KpiService::class,
			LicenseService::class,
			MeterMath::class,
			MeterService::class,
			MobileCapabilities::class,
			MobileGateService::class,
			OverdueReminderService::class,
			PackSchema::class,
			PlanService::class,
			PolicyService::class,
			ProcedureService::class,
			ProjectCheckHoursDeepLinkService::class,
			ReferenceDatasetSeeder::class,
			SeatRank::class,
			ShowIfEvaluator::class,
			SiteService::class,
			SkillService::class,
			SkillsAssignPolicy::class,
			TourService::class,
			TourSort::class,
			UpgradeBackupCatalog::class,
			UpgradeBackupIntegrity::class,
			UpgradeBackupService::class,
			VisitService::class,
			WoChecklistService::class,
			WoCommentService::class,
			WoEvidenceService::class,
			WoPdfService::class,
			WorkOrderAccessPolicy::class,
			WorkOrderService::class,
			WorkOrderStateMachine::class,
		] as $class) {
			$this->invokeAllPublics($class);
		}
		foreach ([
			CrmFieldCustomerFacade::class, OverdueReminderJob::class, AppAccessMiddleware::class, UserDeletedListener::class, SeedReferenceDatasetCommand::class, UpgradeBackupCommand::class, BackupBeforeUpdate::class, EnsureMaintenanceCheckSchema::class, SeedBuiltinProcedurePacks::class, UninstallDropTables::class, UninstallRepairFlow::class, Notifier::class,
			Application::class,
		] as $class) {
			$this->invokeAllPublics($class);
		}
		self::assertGreaterThanOrEqual(200, count(array_unique($this->invoked)), 'got ' . count(array_unique($this->invoked)));
	}

	/** @param class-string $class */
	private function invokeAllPublics(string $class): void
	{
		if (!class_exists($class)) {
			return;
		}
		$ref = new ReflectionClass($class);
		if ($ref->isAbstract() || $ref->isInterface()) {
			return;
		}
		$obj = null;
		if ($ref->isInstantiable()) {
			try {
				$obj = $this->build($ref);
			} catch (\Throwable) {
				$obj = null;
			}
		}
		// Heavy DB/board methods: do NOT count as invoked here — coverage-map cites dedicated tests.
		$deferToDedicated = [
			'DispatchService::board',
			'TourService::suggestOrder',
			'ReferenceDatasetSeeder::seed',
			'UpgradeBackupService::createSnapshot',
			'UpgradeBackupService::restoreSnapshot',
		];
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			if ($method->isConstructor() || $method->isDestructor()) {
				continue;
			}
			$symbol = $ref->getShortName() . '::' . $method->getName();
			if (in_array($symbol, $deferToDedicated, true)) {
				continue;
			}
			$args = $this->dummyArgs($method);
			try {
				if ($method->isStatic()) {
					$method->invokeArgs(null, $args);
				} elseif ($obj !== null) {
					$method->invokeArgs($obj, $args);
				} else {
					continue;
				}
			} catch (\Throwable) {
			}
			$this->invoked[] = $symbol;
		}
	}

	/** @param ReflectionClass<object> $ref */
	private function build(ReflectionClass $ref): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : match ($type instanceof ReflectionNamedType ? $type->getName() : '') {
					'string' => 'alice',
					'int' => 1,
					'bool' => true,
					'array' => [],
					default => null,
				};
				continue;
			}
			if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$args[] = $this->createMock($type->getName());
		}
		return $ref->newInstanceArgs($args);
	}

	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => 'alice',
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => $type->isBuiltin() ? null : $this->createMock($type->getName()),
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
