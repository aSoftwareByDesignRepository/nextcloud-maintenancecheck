<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;

/**
 * FA-2 technician calendar read model (spec §9 `/api/calendar`).
 *
 * Scope contract (NN-01): non-office callers are hard-scoped server-side
 * to primary/helper/pool WOs and assigned/unassigned visits — identical to
 * the work-order list's `constrainToMine`. Office may only *narrow* via
 * `tech`; scope can never widen through query parameters.
 *
 * Data minimization (Argus F-4): events carry only fields the caller's
 * role already sees on the WO/visit lists — nothing new is exposed.
 */
class CalendarService
{
	/** Spec §6: window hard cap (92 days ≈ 3 month views). */
	public const MAX_WINDOW_DAYS = 92;
	/** Spec §6: response event cap — truncation flag, never silent drop. */
	public const MAX_EVENTS = 1000;

	public function __construct(
		private readonly WorkOrderMapper $workOrders,
		private readonly VisitMapper $visits,
		private readonly TourStopMapper $tourStops,
		private readonly IDBConnection $db,
		private readonly AccessControlService $access,
		private readonly IntervalCalculator $intervals,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @param array<string, ?string|list<string>> $query raw query params
	 * @return array{from: string, to: string, truncated: bool, events: list<array<string, mixed>>}
	 */
	public function events(string $uid, array $query): array
	{
		$from = $this->requiredDate($query, 'from');
		$to = $this->requiredDate($query, 'to');
		if ($from > $to) {
			throw new ValidationException('invalid_query', 'from must not be after to.');
		}
		if ($this->intervals->addInterval($from, IntervalCalculator::UNIT_DAY, self::MAX_WINDOW_DAYS - 1) < $to) {
			throw new ValidationException('invalid_query', 'The calendar window may span at most ' . self::MAX_WINDOW_DAYS . ' days.');
		}

		$isOffice = $this->access->isOffice($uid);
		// Non-office: every scope-widening parameter is ignored (NN-01 /
		// US-CAL-05 AC-2). Office may only narrow via tech/kind/status.
		$mineUid = $isOffice ? null : $uid;
		$techUids = $isOffice ? $this->techFilter($query['tech'] ?? null) : null;
		$kinds = $isOffice ? $this->kindFilter($query['kind'] ?? null) : null;
		$statuses = $isOffice ? $this->statusFilter($query['status'] ?? null) : null;

		$orders = $this->workOrders->findForCalendar($from, $to, $mineUid, $techUids, $kinds, $statuses, self::MAX_EVENTS + 1);
		$visitRows = $this->visits->findForCalendar($from, $to, $mineUid, $techUids, self::MAX_EVENTS + 1);

		$events = array_merge(
			$this->woEvents($orders),
			$this->visitEvents($visitRows),
		);
		usort($events, static function (array $a, array $b): int {
			return [$a['startDay'], $a['entity'], $a['id']] <=> [$b['startDay'], $b['entity'], $b['id']];
		});

		$truncated = count($events) > self::MAX_EVENTS;
		if ($truncated) {
			$events = array_slice($events, 0, self::MAX_EVENTS);
		}

		return ['from' => $from, 'to' => $to, 'truncated' => $truncated, 'events' => $events];
	}

	private function requiredDate(array $query, string $param): string
	{
		$raw = trim((string)($query[$param] ?? ''));
		if ($raw === '' || !$this->intervals->isValidYmd($raw)) {
			throw new ValidationException('invalid_query', $param . ' must be a valid Y-m-d date.');
		}
		return $raw;
	}

	/**
	 * Office-only technician narrowing. Strict list<string>; unknown/deleted
	 * uids simply yield zero events — never an existence oracle.
	 *
	 * @return list<string>|null null = no filter
	 */
	private function techFilter(mixed $raw): ?array
	{
		if ($raw === null) {
			return null;
		}
		$values = is_array($raw) ? $raw : explode(',', (string)$raw);
		$out = [];
		foreach ($values as $uid) {
			if (!is_string($uid) && !is_int($uid)) {
				throw new ValidationException('invalid_query', 'tech must be a list of user ids.');
			}
			$uid = trim((string)$uid);
			if ($uid === '' || mb_strlen($uid) > 64) {
				throw new ValidationException('invalid_query', 'tech must be a list of user ids.');
			}
			$out[] = $uid;
		}
		return $out === [] ? null : array_values(array_unique($out));
	}

	/**
	 * @return list<string>|null
	 */
	private function kindFilter(mixed $raw): ?array
	{
		if ($raw === null) {
			return null;
		}
		$values = is_array($raw) ? $raw : explode(',', (string)$raw);
		$out = [];
		foreach ($values as $kind) {
			$kind = trim((string)$kind);
			if (!in_array($kind, WorkOrder::KINDS, true)) {
				throw new ValidationException('invalid_query', 'kind must be one of ' . implode(', ', WorkOrder::KINDS) . '.');
			}
			$out[] = $kind;
		}
		return $out === [] ? null : array_values(array_unique($out));
	}

	/**
	 * @return list<string>|null
	 */
	private function statusFilter(mixed $raw): ?array
	{
		if ($raw === null) {
			return null;
		}
		$values = is_array($raw) ? $raw : explode(',', (string)$raw);
		$out = [];
		foreach ($values as $status) {
			$status = trim((string)$status);
			if (!in_array($status, WorkOrder::STATUSES, true)) {
				throw new ValidationException('invalid_query', 'status must be one of ' . implode(', ', WorkOrder::STATUSES) . '.');
			}
			$out[] = $status;
		}
		return $out === [] ? null : array_values(array_unique($out));
	}

	/**
	 * @param list<WorkOrder> $orders
	 * @return list<array<string, mixed>>
	 */
	private function woEvents(array $orders): array
	{
		if ($orders === []) {
			return [];
		}
		$tourWoIds = $this->tourStops->tourIdsByWorkOrder(
			array_map(static fn (WorkOrder $wo) => (int)$wo->getId(), $orders),
		);
		$events = [];
		foreach ($orders as $wo) {
			$dueOn = $wo->getDueOn();
			$id = (int)$wo->getId();
			$primary = $wo->getPrimaryUserId();
			$events[] = [
				'entity' => 'work_order',
				'id' => $id,
				'number' => $wo->getNumber(),
				'title' => $wo->getTitle(),
				'kind' => $wo->getKind(),
				'status' => $wo->getStatus(),
				'priority' => $wo->getPriority(),
				'startDay' => $dueOn,
				'endDay' => $wo->effectiveDueUntil(),
				'primaryUserId' => $primary,
				'unassigned' => $primary === null || $primary === '',
				'inTourId' => $tourWoIds[$id] ?? null,
				'url' => $this->urlGenerator->linkToRoute('maintenancecheck.page.workOrderShow', ['id' => $id]),
			];
		}
		return $events;
	}

	/**
	 * @param list<\OCA\MaintenanceCheck\Db\Visit> $rows
	 * @return list<array<string, mixed>>
	 */
	private function visitEvents(array $rows): array
	{
		if ($rows === []) {
			return [];
		}
		$equipmentIds = $customerIds = [];
		foreach ($rows as $visit) {
			$equipmentIds[$visit->getEquipmentId()] = true;
			$customerIds[$visit->getCustomerId()] = true;
		}
		$equipmentLabels = $this->nameMap(EquipmentMapper::TABLE, 'label', array_keys($equipmentIds));
		$customerNames = $this->nameMap(CustomerMapper::TABLE, 'name', array_keys($customerIds));

		$events = [];
		foreach ($rows as $visit) {
			$id = (int)$visit->getId();
			$assignee = $visit->getAssignedUid();
			$label = trim((string)($equipmentLabels[$visit->getEquipmentId()] ?? ''));
			$customer = trim((string)($customerNames[$visit->getCustomerId()] ?? ''));
			$title = $label !== '' ? $label : ($customer !== '' ? $customer : 'Visit');
			$events[] = [
				'entity' => 'visit',
				'id' => $id,
				'number' => 'V-' . $id,
				'title' => $title,
				'kind' => 'visit',
				'status' => $visit->getStatus(),
				'priority' => 'normal',
				'startDay' => $visit->getDueOn(),
				'endDay' => $visit->getDueOn(),
				'primaryUserId' => $assignee,
				'unassigned' => $assignee === null || $assignee === '',
				'inTourId' => null,
				'url' => $this->urlGenerator->linkToRoute('maintenancecheck.page.visits'),
			];
		}
		return $events;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	private function nameMap(string $table, string $column, array $ids): array
	{
		$map = [];
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
}
