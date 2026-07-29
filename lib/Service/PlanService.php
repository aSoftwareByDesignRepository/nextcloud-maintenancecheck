<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\MeterMapper;
use OCA\MaintenanceCheck\Db\Plan;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IDBConnection;

class PlanService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly PlanMapper $plans,
		private readonly EquipmentMapper $equipment,
		private readonly MaintTypeMapper $maintTypes,
		private readonly VisitMapper $visits,
		private readonly MeterMapper $meters,
		private readonly IntervalCalculator $intervals,
		private readonly MeterMath $meterMath,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Plans of one equipment, each with its open visit (or null).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForEquipment(int $equipmentId): array
	{
		$this->equipment->findById($equipmentId); // 404 when unknown
		$result = [];
		foreach ($this->plans->findByEquipment($equipmentId) as $plan) {
			$row = $plan->toApi();
			$open = $this->visits->findOpenByPlan((int)$plan->getId());
			$row['openVisit'] = $open?->toApi();
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * AC-4: creating a plan with first_due_on = D creates exactly one
	 * scheduled visit with due_on = D — plan + visit in one transaction.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $equipmentId, array $body): array
	{
		$equipment = $this->equipment->findById($equipmentId);
		$maintTypeId = $this->requireActiveMaintType($body, null);
		$triggerKind = $this->validatedTriggerKind($body, Plan::TRIGGER_INTERVAL);

		// §14.1b: interval fields mandatory for interval/either; a pure
		// meter plan never uses them (M1) — store safe defaults.
		$unit = IntervalCalculator::UNIT_MONTH;
		$count = 1;
		if ($triggerKind !== Plan::TRIGGER_METER || array_key_exists('intervalUnit', $body) || array_key_exists('intervalCount', $body)) {
			$unit = (string)($this->validator->optionalString($body, 'intervalUnit') ?? '');
			$countRaw = $body['intervalCount'] ?? null;
			if (!is_int($countRaw)) {
				throw new ValidationException('invalid_interval', 'Interval count must be an integer.');
			}
			$count = $countRaw;
			$this->intervals->assertValidInterval($unit, $count);
		}

		[$meterCode, $meterThreshold] = $this->validatedMeterTrigger($body, $triggerKind, $equipmentId, null, null);

		$today = $this->clock->today();
		// Pure meter plans get their visits from the meter-due engine only.
		$firstDueOn = null;
		if ($triggerKind !== Plan::TRIGGER_METER) {
			$firstDueOn = $this->validator->dueOn($this->validator->optionalString($body, 'firstDueOn'), $today);
		}
		$contractNotes = $this->validator->contractNotes($body);
		$hasContract = $this->validator->boolOrDefault($body, 'hasContract', false);
		$now = $this->clock->now();

		$plan = new Plan();
		$plan->setEquipmentId($equipmentId);
		$plan->setMaintTypeId($maintTypeId);
		$plan->setIntervalUnit($unit);
		$plan->setIntervalCount($count);
		$plan->setActive(true);
		$plan->setHasContract($hasContract);
		$plan->setContractNotes($contractNotes);
		$plan->setTriggerKind($triggerKind);
		$plan->setMeterCode($meterCode);
		$plan->setMeterThreshold($meterThreshold);
		$plan->setCreatedAt($now);
		$plan->setUpdatedAt($now);
		$plan->setCreatedBy($uid);

		$visit = null;
		$this->db->beginTransaction();
		try {
			$plan = $this->plans->insert($plan);
			if ($firstDueOn !== null) {
				$visit = $this->insertScheduledVisit($plan, $equipment, $firstDueOn, $now);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$result = $plan->toApi();
		$result['openVisit'] = $visit?->toApi();
		return $result;
	}

	/**
	 * S3: optional `recalculateOpenVisit` (default false). When true and an
	 * open visit exists: due_on := addInterval(today, newUnit, newCount).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$plan = $this->plans->findById($id);
		$triggerKind = $this->validatedTriggerKind($body, $plan->getTriggerKind());

		$unit = $plan->getIntervalUnit();
		$count = $plan->getIntervalCount();
		if (array_key_exists('intervalUnit', $body)) {
			$unit = (string)($this->validator->optionalString($body, 'intervalUnit') ?? '');
		}
		if (array_key_exists('intervalCount', $body)) {
			if (!is_int($body['intervalCount'])) {
				throw new ValidationException('invalid_interval', 'Interval count must be an integer.');
			}
			$count = $body['intervalCount'];
		}
		$this->intervals->assertValidInterval($unit, $count);

		[$meterCode, $meterThreshold] = $this->validatedMeterTrigger(
			$body,
			$triggerKind,
			$plan->getEquipmentId(),
			$plan->getMeterCode(),
			$plan->getMeterThreshold(),
		);
		$plan->setTriggerKind($triggerKind);
		$plan->setMeterCode($meterCode);
		$plan->setMeterThreshold($meterThreshold);

		if (array_key_exists('maintTypeId', $body)) {
			$plan->setMaintTypeId($this->requireActiveMaintType($body, $plan->getMaintTypeId()));
		}
		if (array_key_exists('hasContract', $body)) {
			$plan->setHasContract($this->validator->boolOrDefault($body, 'hasContract', $plan->getHasContract()));
		}
		if (array_key_exists('contractNotes', $body)) {
			$plan->setContractNotes($this->validator->contractNotes($body));
		}
		if (array_key_exists('active', $body)) {
			$plan->setActive($this->validator->boolOrDefault($body, 'active', $plan->getActive()));
		}
		$recalculate = $this->validator->boolOrDefault($body, 'recalculateOpenVisit', false);

		$plan->setIntervalUnit($unit);
		$plan->setIntervalCount($count);
		$now = $this->clock->now();
		$plan->setUpdatedAt($now);

		$this->db->beginTransaction();
		try {
			$plan = $this->plans->update($plan);
			$openVisit = $this->visits->findOpenByPlan($id);
			if ($recalculate && $openVisit !== null) {
				$newDueOn = $this->intervals->addInterval($this->clock->today(), $unit, $count);
				$updated = $this->visits->updateScheduled((int)$openVisit->getId(), [
					'due_on' => $newDueOn,
					'updated_at' => $now,
				]);
				// Concurrent complete/skip can close the visit between find and
				// update — never return a closed row as openVisit.
				$openVisit = $updated
					? $this->visits->findById((int)$openVisit->getId())
					: $this->visits->findOpenByPlan($id);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$result = $plan->toApi();
		$result['openVisit'] = $openVisit?->toApi();
		return $result;
	}

	/**
	 * §5.2: deactivate leaves an existing open visit untouched.
	 *
	 * @return array<string, mixed>
	 */
	public function deactivate(int $id): array
	{
		$plan = $this->plans->findById($id);
		$plan->setActive(false);
		$plan->setUpdatedAt($this->clock->now());
		$plan = $this->plans->update($plan);
		$result = $plan->toApi();
		$result['openVisit'] = $this->visits->findOpenByPlan($id)?->toApi();
		return $result;
	}

	/**
	 * S14 recovery/manual schedule: plan must be active, no open visit may
	 * exist. Plan-row lock serialises concurrent schedules (SPEC §6.3.2).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function schedule(int $id, array $body): array
	{
		$plan = $this->plans->findById($id);
		if (!$plan->getActive()) {
			throw new ValidationException('plan_inactive', 'This plan is deactivated — reactivate it before scheduling a visit.');
		}
		$dueOn = $this->validator->dueOn($this->validator->optionalString($body, 'dueOn'), $this->clock->today());
		$equipment = $this->equipment->findById($plan->getEquipmentId());
		$now = $this->clock->now();

		$this->db->beginTransaction();
		try {
			if (!$this->plans->lockRow($id)) {
				throw new NotFoundException();
			}
			// Re-read under the row lock so a concurrent deactivate (S18) cannot
			// lose the race against a schedule that passed the pre-check.
			$plan = $this->plans->findById($id);
			if (!$plan->getActive()) {
				throw new ValidationException('plan_inactive', 'This plan is deactivated — reactivate it before scheduling a visit.');
			}
			$existingOpen = $this->visits->findOpenByPlan($id);
			if ($existingOpen !== null) {
				throw new ConflictException(
					'visit_already_open',
					'This plan already has an open visit.',
					[
						'openVisitId' => (int)$existingOpen->getId(),
						'equipmentId' => (int)$existingOpen->getEquipmentId(),
					],
				);
			}
			$visit = $this->insertScheduledVisit($plan, $equipment, $dueOn, $now);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $visit->toApi();
	}

	private function insertScheduledVisit(Plan $plan, Equipment $equipment, string $dueOn, int $now): Visit
	{
		$visit = new Visit();
		$visit->setPlanId((int)$plan->getId());
		$visit->setEquipmentId((int)$equipment->getId());
		$visit->setCustomerId($equipment->getCustomerId());
		$visit->setMaintTypeId($plan->getMaintTypeId());
		$visit->setDueOn($dueOn);
		$visit->setStatus(Visit::STATUS_SCHEDULED);
		$visit->setCreatedAt($now);
		$visit->setUpdatedAt($now);
		return $this->visits->insert($visit);
	}

	private function validatedTriggerKind(array $body, string $current): string
	{
		if (!array_key_exists('triggerKind', $body)) {
			return $current;
		}
		$kind = (string)($this->validator->optionalString($body, 'triggerKind') ?? '');
		if (!in_array($kind, Plan::TRIGGER_KINDS, true)) {
			throw new ValidationException('validation_failed', 'triggerKind must be interval, meter, or either.', [
				['field' => 'triggerKind', 'code' => 'invalid_value'],
			]);
		}
		return $kind;
	}

	/**
	 * §14.1b: kind ≠ interval ⇒ meter_code must match a meter on this
	 * equipment and a threshold is required (422 `meter_threshold_required`).
	 * kind = interval ⇒ both fields are cleared.
	 *
	 * @param array<string, mixed> $body
	 * @return array{0: ?string, 1: ?string} [meterCode, meterThreshold]
	 */
	private function validatedMeterTrigger(array $body, string $triggerKind, int $equipmentId, ?string $currentCode, ?string $currentThreshold): array
	{
		if ($triggerKind === Plan::TRIGGER_INTERVAL) {
			return [null, null];
		}
		$code = array_key_exists('meterCode', $body)
			? ($this->validator->optionalString($body, 'meterCode') ?? '')
			: ($currentCode ?? '');
		if ($code === '' || $this->meters->findByEquipmentAndCode($equipmentId, $code) === null) {
			throw new ValidationException('validation_failed', 'meterCode must match a meter on this equipment.', [
				['field' => 'meterCode', 'code' => 'unknown_meter'],
			]);
		}
		$thresholdRaw = array_key_exists('meterThreshold', $body) ? $body['meterThreshold'] : $currentThreshold;
		if ($thresholdRaw === null || $thresholdRaw === '') {
			throw new ValidationException('meter_threshold_required', 'A meter-triggered plan needs a threshold.');
		}
		return [$code, $this->meterMath->normalizeValue($thresholdRaw)];
	}

	/**
	 * S11: creating/updating a plan with an inactive maintenance type → 422;
	 * keeping the current type on update stays allowed.
	 */
	private function requireActiveMaintType(array $body, ?int $currentTypeId): int
	{
		$typeId = $body['maintTypeId'] ?? null;
		if (!is_int($typeId) || $typeId < 1) {
			throw new ValidationException('validation_failed', 'Unknown maintenance type.', [
				['field' => 'maintTypeId', 'code' => 'unknown_maint_type'],
			]);
		}
		try {
			$type = $this->maintTypes->findById($typeId);
		} catch (NotFoundException) {
			throw new ValidationException('validation_failed', 'Unknown maintenance type.', [
				['field' => 'maintTypeId', 'code' => 'unknown_maint_type'],
			]);
		}
		if (!$type->getActive() && $typeId !== $currentTypeId) {
			throw new ValidationException('inactive_maint_type', 'This maintenance type is deactivated.');
		}
		return $typeId;
	}
}
