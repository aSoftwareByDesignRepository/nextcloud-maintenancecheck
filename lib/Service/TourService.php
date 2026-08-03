<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\DayTour;
use OCA\MaintenanceCheck\Db\DayTourMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\TourStop;
use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * W3 day tours (CORE §10.4, §12.1). One tour per (date, tech) — unique
 * index. Stop mutations serialise on the tour row lock so concurrent
 * reorder/insert can never interleave into duplicate positions.
 *
 * `order_locked` guards against *suggest* and reorder overwriting a
 * hand-tuned sequence (AC-W3-2) — unlocking is an explicit update.
 */
class TourService
{
	public const MAX_STOPS = 100;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly DayTourMapper $tours,
		private readonly TourStopMapper $stops,
		private readonly WorkOrderMapper $workOrders,
		private readonly SiteMapper $sites,
		private readonly TourSort $sort,
		private readonly InputValidator $validator,
		private readonly IntervalCalculator $intervals,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
	) {
	}

	// ── Queries ─────────────────────────────────────────────────────────

	/**
	 * All tours of one day with enriched stops.
	 *
	 * @return array{data: list<array<string, mixed>>, date: string}
	 */
	public function forDate(?string $date): array
	{
		$date = $this->validatedDate($date);
		$data = [];
		foreach ($this->tours->findByDate($date) as $tour) {
			$data[] = $this->detail($tour);
		}
		return ['data' => $data, 'date' => $date];
	}

	/**
	 * COMPANION S2: today's tour for one technician (empty when none planned).
	 *
	 * @return array{date: string, tour: ?array<string, mixed>}
	 */
	public function todayForTech(string $techUid, ?string $date = null): array
	{
		$date = $this->validatedDate($date);
		$tour = $this->tours->findByDateAndTech($date, $techUid);
		return [
			'date' => $date,
			'tour' => $tour !== null ? $this->detail($tour) : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(int $id): array
	{
		return $this->detail($this->tours->findById($id));
	}

	// ── Mutations ───────────────────────────────────────────────────────

	/**
	 * Create (or return) the tour for (date, tech) — idempotent by the
	 * unique index.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body): array
	{
		$date = $this->validatedDate($this->validator->optionalString($body, 'tourDate'));
		$tech = trim((string)$this->validator->optionalString($body, 'techUid'));
		if ($tech === '' || !$this->userManager->userExists($tech)) {
			throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
		}

		$existing = $this->tours->findByDateAndTech($date, $tech);
		if ($existing !== null) {
			return $this->detail($existing);
		}
		$now = $this->clock->now();
		$tour = new DayTour();
		$tour->setTourDate($date);
		$tour->setTechUid($tech);
		$tour->setOrderLocked(false);
		$tour->setNotes($this->validator->boundedOptionalString($body, 'notes', 512, 'notes_too_long'));
		$tour->setCreatedAt($now);
		$tour->setUpdatedAt($now);
		$tour->setCreatedBy($uid);
		try {
			return $this->detail($this->tours->insert($tour));
		} catch (\OCP\DB\Exception $e) {
			// Concurrent create: the unique index held — return the winner.
			$existing = $this->tours->findByDateAndTech($date, $tech);
			if ($existing !== null) {
				return $this->detail($existing);
			}
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$tour = $this->tours->findById($id);
		if (array_key_exists('orderLocked', $body)) {
			$tour->setOrderLocked($this->validator->boolOrDefault($body, 'orderLocked', $tour->getOrderLocked()));
		}
		if (array_key_exists('notes', $body)) {
			$tour->setNotes($this->validator->boundedOptionalString($body, 'notes', 512, 'notes_too_long'));
		}
		$tour->setUpdatedAt($this->clock->now());
		$this->tours->update($tour);
		return $this->detail($tour);
	}

	public function delete(int $id): void
	{
		$tour = $this->tours->findById($id);
		$this->db->beginTransaction();
		try {
			$this->tours->lockRow($id);
			$this->stops->deleteForTour($id);
			$this->tours->delete($tour);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Add a WO as a stop. Position: append by default; explicit `position`
	 * inserts and shifts (UC-BF emergency at 0).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function addStop(int $tourId, array $body): array
	{
		$tour = $this->tours->findById($tourId);
		$workOrderId = $this->validator->intOrThrow($body, 'workOrderId');
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}

		$position = null;
		if (array_key_exists('position', $body) && $body['position'] !== null) {
			$position = $this->validator->intOrThrow($body, 'position');
			if ($position < 0 || $position > self::MAX_STOPS) {
				throw new ValidationException('validation_failed', 'position must be between 0 and ' . self::MAX_STOPS . '.', [
					['field' => 'position', 'code' => 'out_of_range'],
				]);
			}
		}

		$this->db->beginTransaction();
		try {
			if (!$this->tours->lockRow($tourId)) {
				throw new NotFoundException();
			}
			$existingStop = $this->stops->findByWorkOrder($workOrderId);
			if ($existingStop !== null) {
				throw new ConflictException('wo_in_tour', 'This work order is already part of a tour.', [
					'tourId' => $existingStop->getTourId(),
				]);
			}
			$current = $this->stops->findByTour($tourId);
			if (count($current) >= self::MAX_STOPS) {
				throw new ValidationException('validation_failed', 'A tour may contain at most ' . self::MAX_STOPS . ' stops.', [
					['field' => 'workOrderId', 'code' => 'too_many'],
				]);
			}
			$insertAt = $position !== null ? min($position, count($current)) : count($current);

			$stop = new TourStop();
			$stop->setTourId($tourId);
			$stop->setWorkOrderId($workOrderId);
			$stop->setPosition($insertAt);
			try {
				$this->stops->insert($stop);
			} catch (\OCP\DB\Exception $e) {
				// Unique index on work_order_id: concurrent insert → 409.
				throw new ConflictException('wo_in_tour', 'This work order is already part of a tour.');
			}

			// Shift followers, then normalise 0..n-1.
			foreach (array_reverse($current) as $other) {
				if ($other->getPosition() >= $insertAt) {
					$other->setPosition($other->getPosition() + 1);
					$this->stops->update($other);
				}
			}
			$this->renumber($tourId);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->detail($tour);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function removeStop(int $tourId, int $stopId): array
	{
		$tour = $this->tours->findById($tourId);
		$this->db->beginTransaction();
		try {
			if (!$this->tours->lockRow($tourId)) {
				throw new NotFoundException();
			}
			$found = null;
			foreach ($this->stops->findByTour($tourId) as $stop) {
				if ((int)$stop->getId() === $stopId) {
					$found = $stop;
					break;
				}
			}
			if ($found === null) {
				throw new NotFoundException();
			}
			$this->stops->delete($found);
			$this->renumber($tourId);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->detail($tour);
	}

	/**
	 * Explicit full reorder. Refused while `order_locked` (AC-W3-2).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function reorder(int $tourId, array $body): array
	{
		$tour = $this->tours->findById($tourId);
		if ($tour->getOrderLocked()) {
			throw new ConflictException('tour_locked', 'This tour is locked. Unlock it to change the order.');
		}
		$ids = $body['workOrderIds'] ?? null;
		if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
			throw new ValidationException('validation_failed', 'workOrderIds must be a non-empty list.', [
				['field' => 'workOrderIds', 'code' => 'invalid_type'],
			]);
		}

		$this->db->beginTransaction();
		try {
			if (!$this->tours->lockRow($tourId)) {
				throw new NotFoundException();
			}
			$stops = $this->stops->findByTour($tourId);
			$byWo = [];
			foreach ($stops as $stop) {
				$byWo[$stop->getWorkOrderId()] = $stop;
			}
			$given = [];
			foreach ($ids as $woId) {
				if (!is_int($woId) || !isset($byWo[$woId]) || isset($given[$woId])) {
					throw new ValidationException('validation_failed', 'workOrderIds must be a permutation of the tour stops.', [
						['field' => 'workOrderIds', 'code' => 'invalid_value'],
					]);
				}
				$given[$woId] = true;
			}
			if (count($given) !== count($stops)) {
				throw new ValidationException('validation_failed', 'workOrderIds must be a permutation of the tour stops.', [
					['field' => 'workOrderIds', 'code' => 'invalid_value'],
				]);
			}
			$position = 0;
			foreach ($ids as $woId) {
				$stop = $byWo[$woId];
				if ($stop->getPosition() !== $position) {
					$stop->setPosition($position);
					$this->stops->update($stop);
				}
				$position++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->detail($tour);
	}

	/**
	 * §10.4 suggest-order — computes only, never applies. 409 while locked.
	 *
	 * @return array{tourId: int, suggestedWorkOrderIds: list<int>, applied: bool}
	 */
	public function suggestOrder(int $tourId): array
	{
		$tour = $this->tours->findById($tourId);
		if ($tour->getOrderLocked()) {
			throw new ConflictException('tour_locked', 'This tour is locked. Unlock it to use suggestions.');
		}
		$stops = $this->stops->findByTour($tourId);
		$sortInput = [];
		foreach ($stops as $stop) {
			$geo = $this->stopGeo($stop->getWorkOrderId());
			$sortInput[] = [
				'id' => $stop->getWorkOrderId(),
				'lat' => $geo['lat'],
				'lng' => $geo['lng'],
				'postalCode' => $geo['postalCode'],
				'city' => $geo['city'],
			];
		}
		return [
			'tourId' => $tourId,
			'suggestedWorkOrderIds' => $this->sort->suggestOrder($sortInput),
			'applied' => false,
		];
	}

	// ── Internals ───────────────────────────────────────────────────────

	private function renumber(int $tourId): void
	{
		$position = 0;
		foreach ($this->stops->findByTour($tourId) as $stop) {
			if ($stop->getPosition() !== $position) {
				$stop->setPosition($position);
				$this->stops->update($stop);
			}
			$position++;
		}
	}

	/**
	 * Geo/fallback data for a stop: equipment coords, else its site's
	 * coords, else site postal/city (A3 degradation).
	 *
	 * @return array{lat: ?string, lng: ?string, postalCode: ?string, city: ?string}
	 */
	private function stopGeo(int $workOrderId): array
	{
		$out = ['lat' => null, 'lng' => null, 'postalCode' => null, 'city' => null];
		try {
			$wo = $this->workOrders->findById($workOrderId);
		} catch (NotFoundException) {
			return $out;
		}

		$siteId = $wo->getSiteId();
		$equipmentId = $wo->getEquipmentId();
		if ($equipmentId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('lat', 'lng', 'site_id')->from('mn_equipment')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($equipmentId, \PDO::PARAM_INT)));
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			if ($row !== false) {
				$out['lat'] = $row['lat'] !== null ? (string)$row['lat'] : null;
				$out['lng'] = $row['lng'] !== null ? (string)$row['lng'] : null;
				if ($siteId === null && $row['site_id'] !== null) {
					$siteId = (int)$row['site_id'];
				}
			}
		}
		if ($siteId !== null) {
			try {
				$site = $this->sites->findById($siteId);
				$out['lat'] ??= $site->getLat();
				$out['lng'] ??= $site->getLng();
				$out['postalCode'] = $site->getPostalCode();
				$out['city'] = $site->getCity();
			} catch (NotFoundException) {
				// dangling site link — geo degrades, nothing to do
			}
		}
		if ($out['postalCode'] === null && $out['city'] === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('postal_code', 'city')->from('mn_customers')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($wo->getCustomerId(), \PDO::PARAM_INT)));
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			if ($row !== false) {
				$out['postalCode'] = $row['postal_code'] !== null ? (string)$row['postal_code'] : null;
				$out['city'] = $row['city'] !== null ? (string)$row['city'] : null;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function detail(DayTour $tour): array
	{
		$row = $tour->toApi();
		$techUid = $tour->getTechUid();
		$row['techDisplayName'] = $this->userManager->get($techUid)?->getDisplayName() ?? $techUid;

		$stops = $this->stops->findByTour((int)$tour->getId());
		$woById = [];
		$customerIds = [];
		$equipmentIds = [];
		foreach ($stops as $stop) {
			try {
				$wo = $this->workOrders->findById($stop->getWorkOrderId());
				$woById[$stop->getWorkOrderId()] = $wo;
				$customerIds[$wo->getCustomerId()] = true;
				if ($wo->getEquipmentId() !== null) {
					$equipmentIds[$wo->getEquipmentId()] = true;
				}
			} catch (NotFoundException) {
				// stop points at a vanished WO — skip enrichment
			}
		}
		$customerNames = $this->nameMap(CustomerMapper::TABLE, 'name', array_keys($customerIds));
		$equipmentLabels = $this->nameMap(EquipmentMapper::TABLE, 'label', array_keys($equipmentIds));

		$stopRows = [];
		foreach ($stops as $stop) {
			$stopRow = $stop->toApi();
			$wo = $woById[$stop->getWorkOrderId()] ?? null;
			$stopRow['workOrder'] = $wo !== null ? [
				'id' => (int)$wo->getId(),
				'number' => $wo->getNumber(),
				'title' => $wo->getTitle(),
				'status' => $wo->getStatus(),
				'priority' => $wo->getPriority(),
				'dueOn' => $wo->getDueOn(),
				'estimatedMinutes' => $wo->getEstimatedMinutes(),
				'customerName' => $customerNames[$wo->getCustomerId()] ?? '',
				'equipmentLabel' => $wo->getEquipmentId() !== null
					? ($equipmentLabels[$wo->getEquipmentId()] ?? '')
					: '',
			] : null;
			$stopRows[] = $stopRow;
		}
		$row['stops'] = $stopRows;
		return $row;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	private function nameMap(string $table, string $column, array $ids): array
	{
		$map = [];
		if ($ids === []) {
			return $map;
		}
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', $column)->from($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$map[(int)$row['id']] = (string)$row[$column];
			}
			$result->closeCursor();
		}
		return $map;
	}

	private function validatedDate(?string $date): string
	{
		$date = trim((string)$date);
		if ($date === '') {
			return $this->clock->today();
		}
		if (!$this->intervals->isValidYmd($date)) {
			throw new ValidationException('invalid_query', 'date must be a valid Y-m-d date.');
		}
		return $date;
	}
}
