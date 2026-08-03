<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * W6 ops KPI snapshot (CORE §20.3, AC-W6-8) — rolling window, no chart builder.
 */
class KpiService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{
	 *   windowDays: int,
	 *   from: string,
	 *   to: string,
	 *   pmCompliancePercent: float|null,
	 *   pmDoneOnTime: int,
	 *   pmDoneLate: int,
	 *   pmStillOverdueOpen: int,
	 *   overdueVisitCount: int,
	 *   openWorkOrdersByStatus: array<string, int>,
	 *   mttrMinutes: float|null,
	 *   correctiveClosedInWindow: int
	 * }
	 */
	public function snapshot(int $windowDays = 30): array
	{
		$windowDays = max(1, min(365, $windowDays));
		$today = $this->clock->today();
		$from = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))
			->modify('-' . ($windowDays - 1) . ' days')
			->format('Y-m-d');

		$pm = $this->pmCompliance($from, $today);
		$overdueVisits = $this->countOverdueVisits($today);
		$openByStatus = $this->openWorkOrdersByStatus();
		$mttr = $this->mttrProxy($from, $today);
		$insp = $this->inspectionCompliance($from, $today);

		$denom = $pm['onTime'] + $pm['late'] + $pm['stillOverdue'];
		$compliance = $denom > 0
			? round(100.0 * $pm['onTime'] / $denom, 1)
			: null;

		$inspDenom = $insp['onTime'] + $insp['late'] + $insp['stillOverdue'];
		$inspCompliance = $inspDenom > 0
			? round(100.0 * $insp['onTime'] / $inspDenom, 1)
			: null;

		return [
			'windowDays' => $windowDays,
			'from' => $from,
			'to' => $today,
			'pmCompliancePercent' => $compliance,
			'pmDoneOnTime' => $pm['onTime'],
			'pmDoneLate' => $pm['late'],
			'pmStillOverdueOpen' => $pm['stillOverdue'],
			'overdueVisitCount' => $overdueVisits,
			'openWorkOrdersByStatus' => $openByStatus,
			'mttrMinutes' => $mttr['avg'],
			'correctiveClosedInWindow' => $mttr['count'],
			'inspectionOverdueCount' => $insp['stillOverdue'],
			'inspectionCompliancePercent' => $inspCompliance,
			'inspectionDoneOnTime' => $insp['onTime'],
			'inspectionDoneLate' => $insp['late'],
		];
	}

	/**
	 * CSV for office+ (AC-W6-8).
	 */
	public function toCsv(int $windowDays = 30): string
	{
		$s = $this->snapshot($windowDays);
		$lines = [
			'metric,value',
			'window_days,' . $s['windowDays'],
			'from,' . $s['from'],
			'to,' . $s['to'],
			'pm_compliance_percent,' . ($s['pmCompliancePercent'] === null ? '' : (string)$s['pmCompliancePercent']),
			'pm_done_on_time,' . $s['pmDoneOnTime'],
			'pm_done_late,' . $s['pmDoneLate'],
			'pm_still_overdue_open,' . $s['pmStillOverdueOpen'],
			'overdue_visit_count,' . $s['overdueVisitCount'],
			'mttr_minutes,' . ($s['mttrMinutes'] === null ? '' : (string)$s['mttrMinutes']),
			'corrective_closed_in_window,' . $s['correctiveClosedInWindow'],
			'inspection_overdue_count,' . $s['inspectionOverdueCount'],
			'inspection_compliance_percent,' . ($s['inspectionCompliancePercent'] === null ? '' : (string)$s['inspectionCompliancePercent']),
			'inspection_done_on_time,' . $s['inspectionDoneOnTime'],
			'inspection_done_late,' . $s['inspectionDoneLate'],
		];
		foreach ($s['openWorkOrdersByStatus'] as $status => $count) {
			$lines[] = 'open_wo_' . $status . ',' . $count;
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * @return array{onTime: int, late: int, stillOverdue: int}
	 */
	private function pmCompliance(string $from, string $today): array
	{
		$onTime = 0;
		$late = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->select('due_on', 'done_on')
			->from('mn_visits')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Visit::STATUS_DONE)))
			->andWhere($qb->expr()->gte('done_on', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('done_on', $qb->createNamedParameter($today)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$due = (string)($row['due_on'] ?? '');
			$done = (string)($row['done_on'] ?? '');
			if ($due === '' || $done === '') {
				continue;
			}
			if ($done <= $due) {
				$onTime++;
			} else {
				$late++;
			}
		}
		$result->closeCursor();

		return [
			'onTime' => $onTime,
			'late' => $late,
			'stillOverdue' => $this->countOverdueVisits($today),
		];
	}

	private function countOverdueVisits(string $today): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from('mn_visits')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Visit::STATUS_SCHEDULED)))
			->andWhere($qb->expr()->lt('due_on', $qb->createNamedParameter($today)));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return array<string, int>
	 */
	private function openWorkOrdersByStatus(): array
	{
		$open = [
			WorkOrder::STATUS_DRAFT,
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_READY,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_BLOCKED,
		];
		$counts = array_fill_keys($open, 0);
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from('mn_work_orders')
			->where($qb->expr()->in('status', $qb->createNamedParameter($open, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('status');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$status = (string)($row['status'] ?? '');
			if (isset($counts[$status])) {
				$counts[$status] = (int)$row['cnt'];
			}
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @return array{avg: float|null, count: int}
	 */
	private function mttrProxy(string $from, string $today): array
	{
		$fromTs = (new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$toTs = (new \DateTimeImmutable($today . ' 23:59:59', new \DateTimeZone('UTC')))->getTimestamp();

		$qb = $this->db->getQueryBuilder();
		$qb->select('started_at', 'completed_at')
			->from('mn_work_orders')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter(WorkOrder::KIND_CORRECTIVE)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(WorkOrder::STATUS_DONE)))
			->andWhere($qb->expr()->isNotNull('started_at'))
			->andWhere($qb->expr()->isNotNull('completed_at'))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($fromTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($toTs, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$sum = 0;
		$count = 0;
		while ($row = $result->fetch()) {
			$started = (int)($row['started_at'] ?? 0);
			$completed = (int)($row['completed_at'] ?? 0);
			if ($started <= 0 || $completed < $started) {
				continue;
			}
			$sum += (int)floor(($completed - $started) / 60);
			$count++;
		}
		$result->closeCursor();
		return [
			'avg' => $count > 0 ? round($sum / $count, 1) : null,
			'count' => $count,
		];
	}

	/**
	 * W7 inspection on-time % + overdue open inspection WOs (CORE §21 W7-R14).
	 *
	 * @return array{onTime: int, late: int, stillOverdue: int}
	 */
	private function inspectionCompliance(string $from, string $today): array
	{
		$onTime = 0;
		$late = 0;
		$fromTs = (new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$toTs = (new \DateTimeImmutable($today . ' 23:59:59', new \DateTimeZone('UTC')))->getTimestamp();

		$qb = $this->db->getQueryBuilder();
		$qb->select('due_on', 'completed_at')
			->from('mn_work_orders')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter(WorkOrder::KIND_INSPECTION)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(WorkOrder::STATUS_DONE)))
			->andWhere($qb->expr()->isNotNull('completed_at'))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($fromTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter($toTs, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$due = (string)($row['due_on'] ?? '');
			$completedAt = (int)($row['completed_at'] ?? 0);
			if ($due === '' || $completedAt <= 0) {
				continue;
			}
			$doneOn = (new \DateTimeImmutable('@' . $completedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
			if ($doneOn <= $due) {
				$onTime++;
			} else {
				$late++;
			}
		}
		$result->closeCursor();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->select($qb2->func()->count('id', 'cnt'))
			->from('mn_work_orders')
			->where($qb2->expr()->eq('kind', $qb2->createNamedParameter(WorkOrder::KIND_INSPECTION)))
			->andWhere($qb2->expr()->notIn('status', $qb2->createNamedParameter(WorkOrder::TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb2->expr()->isNotNull('due_on'))
			->andWhere($qb2->expr()->lt('due_on', $qb2->createNamedParameter($today)));
		$result2 = $qb2->executeQuery();
		$still = (int)($result2->fetchOne() ?: 0);
		$result2->closeCursor();

		return ['onTime' => $onTime, 'late' => $late, 'stillOverdue' => $still];
	}
}
