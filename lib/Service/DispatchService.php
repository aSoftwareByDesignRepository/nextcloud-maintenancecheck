<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W3 dispatch board (CORE §10.4, UC-DL): open work orders in a date window,
 * grouped per day into an "unassigned" lane plus one lane per primary tech,
 * each tech/day cell annotated with the W4 capacity assessment.
 *
 * Read-only — assignment itself goes through WorkOrderService::assign, which
 * owns the warn/block gates.
 */
class DispatchService
{
	public const MAX_RANGE_DAYS = 14;
	public const LANE_UNASSIGNED = 'unassigned';

	public function __construct(
		private readonly WorkOrderMapper $workOrders,
		private readonly TourStopMapper $tourStops,
		private readonly WorkOrderService $workOrderService,
		private readonly CapacityService $capacity,
		private readonly IntervalCalculator $intervals,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{from: string, to: string, days: list<array<string, mixed>>,
	 *               noDueDate: list<array<string, mixed>>}
	 */
	public function board(?string $from, ?string $to): array
	{
		$today = $this->clock->today();
		$from = $this->validatedDate($from, $today);
		$to = $this->validatedDate($to, $this->intervals->addInterval($from, IntervalCalculator::UNIT_DAY, 6));
		if ($to < $from) {
			throw new ValidationException('invalid_query', 'to must not be before from.');
		}
		if ($this->intervals->addInterval($from, IntervalCalculator::UNIT_DAY, self::MAX_RANGE_DAYS - 1) < $to) {
			throw new ValidationException('invalid_query', 'The dispatch window may span at most ' . self::MAX_RANGE_DAYS . ' days.');
		}

		$orders = $this->workOrders->findForDispatch($from, $to);
		$rows = $this->workOrderService->enrich($orders);
		$tourWoIds = $this->tourStops->tourIdsByWorkOrder(array_map(static fn (WorkOrder $wo) => (int)$wo->getId(), $orders));

		// day → lane → rows; remember per-lane minutes for capacity cells.
		$byDay = [];
		foreach ($rows as $row) {
			$row['inTourId'] = $tourWoIds[$row['id']] ?? null;
			$day = (string)$row['dueOn'];
			$lane = $row['primaryUserId'] !== null && $row['primaryUserId'] !== '' ? (string)$row['primaryUserId'] : self::LANE_UNASSIGNED;
			$byDay[$day][$lane][] = $row;
		}

		$days = [];
		for ($day = $from; $day <= $to; $day = $this->intervals->addInterval($day, IntervalCalculator::UNIT_DAY, 1)) {
			$lanes = [];
			foreach ($byDay[$day] ?? [] as $laneUid => $laneRows) {
				$lane = [
					'uid' => $laneUid,
					'workOrders' => $laneRows,
				];
				if ($laneUid !== self::LANE_UNASSIGNED) {
					// Existing load only — nothing is being added here.
					$lane['capacity'] = $this->capacity->assessAssign($laneUid, $day, 0, null);
				}
				$lanes[] = $lane;
			}
			usort($lanes, static function (array $a, array $b): int {
				// Unassigned lane always first, then techs alphabetically.
				if ($a['uid'] === self::LANE_UNASSIGNED) {
					return -1;
				}
				if ($b['uid'] === self::LANE_UNASSIGNED) {
					return 1;
				}
				return strcmp($a['uid'], $b['uid']);
			});
			$days[] = ['date' => $day, 'lanes' => $lanes];
		}

		// Open WOs without a due date never disappear from planning (§8 A2).
		$noDueDate = $this->workOrderService->enrich($this->workOrders->findOpenWithoutDueDate());
		foreach ($noDueDate as &$row) {
			$row['inTourId'] = null;
		}
		unset($row);

		return ['from' => $from, 'to' => $to, 'days' => $days, 'noDueDate' => $noDueDate];
	}

	private function validatedDate(?string $value, string $default): string
	{
		$value = trim((string)$value);
		if ($value === '') {
			return $default;
		}
		if (!$this->intervals->isValidYmd($value)) {
			throw new ValidationException('invalid_query', 'Dates must be valid Y-m-d dates.');
		}
		return $value;
	}
}
