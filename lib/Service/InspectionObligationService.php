<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\InspectionObligation;
use OCA\MaintenanceCheck\Db\InspectionObligationMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W7 inspection obligations (CORE §21 W7-R1/R3) — schedules via Plan + Visit SoT.
 *
 * Does not create work orders here (avoids DI cycles); due board uses visits.
 */
class InspectionObligationService
{
	public const MAINT_TYPE_CODE = 'inspection';

	public function __construct(
		private readonly InspectionObligationMapper $obligations,
		private readonly EquipmentMapper $equipment,
		private readonly PlanMapper $plans,
		private readonly VisitMapper $visits,
		private readonly EquipmentClassService $classes,
		private readonly CatalogService $catalogs,
		private readonly PlanService $planService,
		private readonly IntervalCalculator $intervals,
		private readonly Clock $clock,
		private readonly InputValidator $validator,
		private readonly AccessControlService $access,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listForEquipment(string $uid, int $equipmentId): array
	{
		if (!$this->access->canUseApp($uid)) {
			throw new \OCA\MaintenanceCheck\Exception\PermissionDeniedException('No access to MaintenanceCheck.');
		}
		$this->equipment->findById($equipmentId);
		$out = [];
		foreach ($this->obligations->findByEquipment($equipmentId, false) as $row) {
			$out[] = $this->enrich($row);
		}
		return $out;
	}

	/**
	 * Create obligation from class template → plan + scheduled visit (AC-W7-2).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $equipmentId, array $body): array
	{
		if (!$this->access->canUseApp($uid)) {
			throw new \OCA\MaintenanceCheck\Exception\PermissionDeniedException('No access to MaintenanceCheck.');
		}
		if (!$this->access->isOffice($uid)) {
			throw new \OCA\MaintenanceCheck\Exception\PermissionDeniedException('Only office users can create inspection obligations.');
		}
		$equipment = $this->equipment->findById($equipmentId);

		$classCode = trim((string)($this->validator->boundedOptionalString($body, 'classCode', 64, 'class_code_too_long') ?? ''));
		if ($classCode === '') {
			throw new ValidationException('validation_failed', 'classCode is required.', [
				['field' => 'classCode', 'code' => 'required'],
			]);
		}
		$class = $this->classes->requireActive($classCode);

		$unit = (string)($this->validator->optionalString($body, 'intervalUnit') ?? $class->getDefaultIntervalUnit());
		$countRaw = $body['intervalCount'] ?? $class->getDefaultIntervalCount();
		if (!is_int($countRaw)) {
			throw new ValidationException('invalid_interval', 'Interval count must be an integer.');
		}
		$this->intervals->assertValidInterval($unit, $countRaw);

		$procedureId = null;
		if (array_key_exists('procedureId', $body) && $body['procedureId'] !== null) {
			if (!is_int($body['procedureId']) || $body['procedureId'] < 1) {
				throw new ValidationException('validation_failed', 'procedureId must be a positive integer.', [
					['field' => 'procedureId', 'code' => 'invalid_type'],
				]);
			}
			$procedureId = $body['procedureId'];
		}

		$firstDueOn = $this->validator->dueOn(
			$this->validator->optionalString($body, 'firstDueOn'),
			$this->clock->today(),
		);

		$maintTypeId = $this->ensureInspectionMaintTypeId();
		$plan = $this->planService->create($uid, $equipmentId, [
			'maintTypeId' => $maintTypeId,
			'intervalUnit' => $unit,
			'intervalCount' => $countRaw,
			'firstDueOn' => $firstDueOn,
			'triggerKind' => 'interval',
			'hasContract' => false,
			'contractNotes' => 'W7 inspection obligation: ' . $classCode,
		]);

		$now = $this->clock->now();
		$obligation = new InspectionObligation();
		$obligation->setEquipmentId($equipmentId);
		$obligation->setClassCode($classCode);
		$obligation->setIntervalUnit($unit);
		$obligation->setIntervalCount($countRaw);
		$obligation->setProcedureId($procedureId);
		$obligation->setPlanId((int)$plan['id']);
		$obligation->setActive(true);
		$obligation->setCreatedAt($now);
		$obligation->setUpdatedAt($now);
		$obligation->setCreatedBy($uid);
		$obligation = $this->obligations->insert($obligation);

		if (method_exists($equipment, 'getEquipmentClass')) {
			$classValue = $equipment->getEquipmentClass();
			if ($classValue === null || $classValue === '') {
				$equipment->setEquipmentClass($classCode);
				$equipment->setUpdatedAt($now);
				$this->equipment->update($equipment);
			}
		}

		return $this->enrich($obligation);
	}

	public function findByPlanId(int $planId): ?InspectionObligation
	{
		return $this->obligations->findByPlanId($planId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function enrich(InspectionObligation $row): array
	{
		$api = $row->toApi();
		$api['openVisit'] = null;
		$api['planActive'] = null;
		if ($row->getPlanId() !== null) {
			try {
				$plan = $this->plans->findById($row->getPlanId());
				$api['planActive'] = $plan->getActive();
				$open = $this->visits->findOpenByPlan($row->getPlanId());
				$api['openVisit'] = $open?->toApi();
			} catch (\Throwable) {
				// Plan may be gone — still return the obligation row.
			}
		}
		return $api;
	}

	private function ensureInspectionMaintTypeId(): int
	{
		$existing = $this->catalogs->list('maint', '200', '0');
		foreach ($existing['data'] as $row) {
			if (($row['code'] ?? '') === self::MAINT_TYPE_CODE) {
				$this->normalizeInspectionMaintTypeName($row);
				return (int)$row['id'];
			}
		}
		$created = $this->catalogs->create('maint', [
			'code' => self::MAINT_TYPE_CODE,
			'name' => 'Inspection',
		]);
		return (int)$created['id'];
	}

	/**
	 * Bachus: drop bilingual catalog leftovers from older seeds.
	 *
	 * @param array<string, mixed> $row
	 */
	private function normalizeInspectionMaintTypeName(array $row): void
	{
		$name = (string)($row['name'] ?? '');
		if ($name !== 'Inspection / Prüfung' && $name !== 'Prüfung / Inspection') {
			return;
		}
		try {
			$this->catalogs->update('maint', (int)$row['id'], ['name' => 'Inspection']);
		} catch (\Throwable) {
			// Best-effort rename — obligation flow must not fail on catalog write.
		}
	}
}
