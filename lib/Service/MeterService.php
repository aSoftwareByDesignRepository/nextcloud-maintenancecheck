<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\Meter;
use OCA\MaintenanceCheck\Db\MeterMapper;
use OCA\MaintenanceCheck\Db\MeterReading;
use OCA\MaintenanceCheck\Db\MeterReadingMapper;
use OCA\MaintenanceCheck\Db\Plan;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * W5 meters + readings + the meter-due engine (CORE §10.7).
 *
 * A reading insert runs in ONE transaction that serialises on the meter row
 * lock: monotonic check, reading insert, and due evaluation cannot
 * interleave with a concurrent reading on the same meter. Due evaluation
 * additionally takes the plan row lock — the same lock the interval engine
 * uses — so a meter trigger can never race a complete/skip roll into a
 * duplicate open visit (AC-W5-3 idempotency).
 */
class MeterService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MeterMapper $meters,
		private readonly MeterReadingMapper $readings,
		private readonly EquipmentMapper $equipment,
		private readonly PlanMapper $plans,
		private readonly VisitMapper $visits,
		private readonly MeterMath $math,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	// ── Meters ──────────────────────────────────────────────────────────

	/**
	 * Meters of one equipment, each with its latest reading (AC-W5-2).
	 *
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function listForEquipment(int $equipmentId): array
	{
		$this->equipment->findById($equipmentId);
		$data = [];
		foreach ($this->meters->findByEquipment($equipmentId) as $meter) {
			$row = $meter->toApi();
			$latest = $this->readings->findLatest((int)$meter->getId());
			$row['latestReading'] = $latest?->toApi();
			$data[] = $row;
		}
		return ['data' => $data];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $equipmentId, array $body): array
	{
		$this->equipment->findById($equipmentId);
		$code = $this->validator->catalogCode($body);
		$now = $this->clock->now();

		$meter = new Meter();
		$meter->setEquipmentId($equipmentId);
		$meter->setCode($code);
		$meter->setName($this->validator->requiredString($body, 'name', 'name_required', 255, 'name_too_long'));
		$meter->setUnit($this->validator->boundedOptionalString($body, 'unit', 16, 'unit_too_long'));
		$meter->setMonotonic($this->validator->boolOrDefault($body, 'monotonic', true));
		$meter->setActive($this->validator->boolOrDefault($body, 'active', true));
		$meter->setCreatedAt($now);
		$meter->setUpdatedAt($now);
		$meter->setCreatedBy($uid);
		try {
			return $this->meters->insert($meter)->toApi();
		} catch (\OCP\DB\Exception $e) {
			// Unique (equipment, code) held — concurrent or repeated create.
			if ($this->meters->findByEquipmentAndCode($equipmentId, $code) !== null) {
				throw new ConflictException('code_exists', 'A meter with this code already exists on this equipment.');
			}
			throw $e;
		}
	}

	/**
	 * Code is immutable — plans reference meters by code (§12.2).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $meterId, array $body): array
	{
		$meter = $this->meters->findById($meterId);
		if (array_key_exists('name', $body)) {
			$meter->setName($this->validator->requiredString($body, 'name', 'name_required', 255, 'name_too_long'));
		}
		if (array_key_exists('unit', $body)) {
			$meter->setUnit($this->validator->boundedOptionalString($body, 'unit', 16, 'unit_too_long'));
		}
		if (array_key_exists('monotonic', $body)) {
			$meter->setMonotonic($this->validator->boolOrDefault($body, 'monotonic', $meter->getMonotonic()));
		}
		if (array_key_exists('active', $body)) {
			$meter->setActive($this->validator->boolOrDefault($body, 'active', $meter->getActive()));
		}
		$meter->setUpdatedAt($this->clock->now());
		return $this->meters->update($meter)->toApi();
	}

	/**
	 * Deleting a meter that an active meter/either plan references would
	 * silently disarm that plan → 409 `meter_in_use`.
	 */
	public function delete(int $meterId): void
	{
		$meter = $this->meters->findById($meterId);
		if ($this->plans->findActiveMeterPlans($meter->getEquipmentId(), $meter->getCode()) !== []) {
			throw new ConflictException('meter_in_use', 'An active plan is triggered by this meter. Deactivate or change the plan first.');
		}
		$this->db->beginTransaction();
		try {
			$this->readings->deleteForMeter($meterId);
			$this->meters->delete($meter);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	// ── Readings + due engine ───────────────────────────────────────────

	/**
	 * @param array{limit: ?string, offset: ?string} $query
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listReadings(int $meterId, array $query): array
	{
		$this->meters->findById($meterId);
		['limit' => $limit, 'offset' => $offset] = $this->validator->pagination($query['limit'] ?? null, $query['offset'] ?? null);
		$page = $this->readings->findByMeter($meterId, $limit, $offset);
		return [
			'data' => array_map(static fn (MeterReading $r) => $r->toApi(), $page['data']),
			'total' => $page['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Record a reading and evaluate meter-due plans in one transaction.
	 *
	 * @param array<string, mixed> $body
	 * @return array{reading: array<string, mixed>, triggered: list<array<string, mixed>>}
	 */
	public function addReading(string $uid, int $meterId, array $body): array
	{
		$today = $this->clock->today();
		$now = $this->clock->now();
		$value = $this->math->normalizeValue($body['value'] ?? null);
		$readOn = $this->validator->doneOn($this->validator->optionalString($body, 'readOn'), $today);
		$note = $this->validator->boundedOptionalString($body, 'note', 512, 'note_too_long');

		$this->db->beginTransaction();
		try {
			$inserted = $this->insertReadingLocked(
				$uid,
				$meterId,
				$value,
				$readOn,
				$note,
				MeterReading::SOURCE_MANUAL,
				$now,
			);
			$triggered = $this->evaluateDue($inserted['meter'], $value, $today, $now);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		return ['reading' => $inserted['reading']->toApi(), 'triggered' => $triggered];
	}

	/**
	 * W5 exit / UC-METER: optional closing reading recorded inside the
	 * caller's complete/done transaction. Does **not** re-run the meter-due
	 * engine — the visit being closed would otherwise re-open immediately
	 * while value still sits above the same threshold (red-team M1).
	 *
	 * Body shape: `{ "meterCode": "runtime_h", "value": "2005.0", "readOn"?: "Y-m-d", "note"?: "…" }`
	 * or `{ "meterId": 12, "value": … }`.
	 *
	 * @param array<string, mixed> $closingReading
	 * @return array<string, mixed> reading API row
	 */
	public function recordClosingWithinTransaction(
		string $uid,
		int $equipmentId,
		array $closingReading,
		string $today,
		int $now,
	): array {
		$meterId = $this->resolveClosingMeterId($equipmentId, $closingReading);
		$value = $this->math->normalizeValue($closingReading['value'] ?? null);
		$readOn = $this->validator->doneOn($this->validator->optionalString($closingReading, 'readOn'), $today);
		$note = $this->validator->boundedOptionalString($closingReading, 'note', 512, 'note_too_long');
		$inserted = $this->insertReadingLocked(
			$uid,
			$meterId,
			$value,
			$readOn,
			$note,
			MeterReading::SOURCE_MANUAL,
			$now,
		);
		return $inserted['reading']->toApi();
	}

	/**
	 * Contender §19: manual + CSV import max. Strict all-or-nothing batch
	 * (max 500 rows). Header row optional when first cell is `meter_code`.
	 *
	 * Columns: meter_code,value,read_on?,note?
	 *
	 * @return array{imported: int, readings: list<array<string, mixed>>, triggered: list<array<string, mixed>>}
	 */
	public function importCsv(string $uid, int $equipmentId, string $csv): array
	{
		$this->equipment->findById($equipmentId);
		$rows = $this->parseCsvRows($csv);
		if ($rows === []) {
			throw new ValidationException('validation_failed', 'The CSV has no data rows.', [
				['field' => 'csv', 'code' => 'empty'],
			]);
		}
		if (count($rows) > 500) {
			throw new ValidationException('validation_failed', 'CSV import is limited to 500 rows per request.', [
				['field' => 'csv', 'code' => 'too_many_rows'],
			]);
		}

		$today = $this->clock->today();
		$now = $this->clock->now();
		$readings = [];
		$triggered = [];

		$this->db->beginTransaction();
		try {
			foreach ($rows as $index => $row) {
				$line = $index + 1;
				$code = strtolower(trim((string)($row['meter_code'] ?? '')));
				if ($code === '' || !preg_match('/^[a-z0-9_]+$/', $code)) {
					throw new ValidationException('validation_failed', 'Row ' . $line . ': meter_code is invalid.', [
						['field' => 'csv', 'code' => 'invalid_meter_code', 'row' => $line],
					]);
				}
				$meter = $this->meters->findByEquipmentAndCode($equipmentId, $code);
				if ($meter === null) {
					throw new ValidationException('validation_failed', 'Row ' . $line . ': unknown meter code.', [
						['field' => 'csv', 'code' => 'unknown_meter', 'row' => $line],
					]);
				}
				try {
					$value = $this->math->normalizeValue($row['value'] ?? null);
					$readOnRaw = trim((string)($row['read_on'] ?? ''));
					$readOn = $readOnRaw === ''
						? $today
						: $this->validator->doneOn($readOnRaw, $today);
					$noteRaw = trim((string)($row['note'] ?? ''));
					$note = $noteRaw === '' ? null : $this->validator->boundedOptionalString(
						['note' => $noteRaw],
						'note',
						512,
						'note_too_long',
					);
				} catch (ValidationException $e) {
					throw new ValidationException($e->getErrorCode(), 'Row ' . $line . ': ' . $e->getMessage(), array_merge(
						[['field' => 'csv', 'code' => $e->getErrorCode(), 'row' => $line]],
						$e->getDetails(),
					));
				}

				$inserted = $this->insertReadingLocked(
					$uid,
					(int)$meter->getId(),
					$value,
					$readOn,
					$note,
					MeterReading::SOURCE_IMPORT,
					$now,
				);
				$readings[] = $inserted['reading']->toApi();
				foreach ($this->evaluateDue($inserted['meter'], $value, $today, $now) as $event) {
					$triggered[] = $event;
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		return [
			'imported' => count($readings),
			'readings' => $readings,
			'triggered' => $triggered,
		];
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * Shared insert path under meter row lock. Caller owns the transaction.
	 *
	 * @return array{meter: Meter, reading: MeterReading}
	 */
	private function insertReadingLocked(
		string $uid,
		int $meterId,
		string $value,
		string $readOn,
		?string $note,
		string $source,
		int $now,
	): array {
		if (!$this->meters->lockRow($meterId)) {
			throw new NotFoundException();
		}
		$meter = $this->meters->findById($meterId);
		if (!$meter->getActive()) {
			throw new ConflictException('meter_inactive', 'This meter is deactivated.');
		}
		$latest = $this->readings->findLatest($meterId);
		if ($meter->getMonotonic() && $latest !== null && $this->math->compare($value, $latest->getValue()) < 0) {
			throw new ValidationException('meter_not_monotonic', 'This meter only counts up. The value must be at least ' . $latest->getValue() . '.');
		}

		$reading = new MeterReading();
		$reading->setMeterId($meterId);
		$reading->setEquipmentId($meter->getEquipmentId());
		$reading->setMeterCode($meter->getCode());
		$reading->setValue($value);
		$reading->setReadOn($readOn);
		$reading->setSource($source);
		$reading->setNote($note);
		$reading->setCreatedAt($now);
		$reading->setCreatedBy($uid);

		return ['meter' => $meter, 'reading' => $this->readings->insert($reading)];
	}

	/**
	 * @param array<string, mixed> $closingReading
	 */
	private function resolveClosingMeterId(int $equipmentId, array $closingReading): int
	{
		if (isset($closingReading['meterId'])) {
			if (!is_int($closingReading['meterId']) && !(is_string($closingReading['meterId']) && preg_match('/^\d+$/', $closingReading['meterId']))) {
				throw new ValidationException('validation_failed', 'meterId must be a positive integer.', [
					['field' => 'closingReading.meterId', 'code' => 'invalid_type'],
				]);
			}
			$meterId = (int)$closingReading['meterId'];
			$meter = $this->meters->findById($meterId);
			if ($meter->getEquipmentId() !== $equipmentId) {
				throw new ValidationException('validation_failed', 'This meter does not belong to the equipment.', [
					['field' => 'closingReading.meterId', 'code' => 'invalid_value'],
				]);
			}
			return $meterId;
		}

		$code = strtolower(trim((string)($this->validator->optionalString($closingReading, 'meterCode') ?? '')));
		if ($code === '') {
			throw new ValidationException('validation_failed', 'closingReading requires meterCode or meterId.', [
				['field' => 'closingReading.meterCode', 'code' => 'required'],
			]);
		}
		$meter = $this->meters->findByEquipmentAndCode($equipmentId, $code);
		if ($meter === null) {
			throw new ValidationException('validation_failed', 'Unknown meter code on this equipment.', [
				['field' => 'closingReading.meterCode', 'code' => 'unknown_meter'],
			]);
		}
		return (int)$meter->getId();
	}

	/**
	 * @return list<array{meter_code: string, value: string, read_on: string, note: string}>
	 */
	private function parseCsvRows(string $csv): array
	{
		$csv = str_replace("\r\n", "\n", $csv);
		$csv = str_replace("\r", "\n", $csv);
		$lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => trim($line) !== ''));
		if ($lines === []) {
			return [];
		}

		$start = 0;
		$first = str_getcsv($lines[0]);
		$firstCells = array_map(static fn ($c) => strtolower(trim((string)$c)), $first);
		if (in_array('meter_code', $firstCells, true) || in_array('code', $firstCells, true)) {
			$start = 1;
		}

		$rows = [];
		for ($i = $start; $i < count($lines); $i++) {
			$cells = str_getcsv($lines[$i]);
			if ($cells === [null] || $cells === false) {
				continue;
			}
			$rows[] = [
				'meter_code' => (string)($cells[0] ?? ''),
				'value' => (string)($cells[1] ?? ''),
				'read_on' => (string)($cells[2] ?? ''),
				'note' => (string)($cells[3] ?? ''),
			];
		}
		return $rows;
	}

	/**
	 * §10.7: value ≥ threshold → ensure an open visit due today exists for
	 * every matching active plan. Runs inside the caller's transaction.
	 *
	 * Idempotent (AC-W5-3): an open visit already due today (or overdue) is
	 * left untouched; a later-dated open visit is pulled forward to today.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function evaluateDue(Meter $meter, string $value, string $today, int $now): array
	{
		$equipment = $this->equipment->findById($meter->getEquipmentId());
		if (!$equipment->getActive()) {
			// Inactive equipment never auto-schedules (matches S10 gating).
			return [];
		}

		$triggered = [];
		foreach ($this->plans->findActiveMeterPlans($meter->getEquipmentId(), $meter->getCode()) as $plan) {
			$threshold = $plan->getMeterThreshold();
			if ($threshold === null || $this->math->compare($value, $threshold) < 0) {
				continue;
			}
			$planId = (int)$plan->getId();
			if (!$this->plans->lockRow($planId)) {
				continue; // plan vanished mid-flight
			}
			$open = $this->visits->findOpenByPlan($planId);
			if ($open === null) {
				$visit = new Visit();
				$visit->setPlanId($planId);
				$visit->setEquipmentId($meter->getEquipmentId());
				$visit->setCustomerId($equipment->getCustomerId());
				$visit->setMaintTypeId($plan->getMaintTypeId());
				$visit->setDueOn($today);
				$visit->setStatus(Visit::STATUS_SCHEDULED);
				$visit->setCreatedAt($now);
				$visit->setUpdatedAt($now);
				$visit = $this->visits->insert($visit);
				$triggered[] = ['planId' => $planId, 'visitId' => (int)$visit->getId(), 'action' => 'created', 'dueOn' => $today];
			} elseif ($open->getDueOn() > $today) {
				$this->visits->updateScheduled((int)$open->getId(), [
					'due_on' => $today,
					'updated_at' => $now,
				]);
				$triggered[] = ['planId' => $planId, 'visitId' => (int)$open->getId(), 'action' => 'pulled_forward', 'dueOn' => $today];
			}
		}
		return $triggered;
	}
}
