<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * W6 exception board (CORE §20.3, AC-W6-10): blocked, overdue, kit-incomplete, skills_missing.
 */
class ExceptionBoardService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly KitService $kits,
		private readonly SkillService $skills,
		private readonly PolicyService $policies,
		private readonly Clock $clock,
		private readonly InputValidator $validator,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $limit, ?string $offset, ?string $filter = null, ?string $assigneeUid = null): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$today = $this->clock->today();
		$want = $filter === null || $filter === '' ? 'all' : $filter;
		if (!in_array($want, ['all', 'blocked', 'overdue', 'kit', 'skills'], true)) {
			$want = 'all';
		}

		$candidates = $this->loadOpenCandidates($assigneeUid);
		$rows = [];
		$skillsMode = $this->policies->skillsEnforcement();
		foreach ($candidates as $wo) {
			$reasons = [];
			if ($wo['status'] === WorkOrder::STATUS_BLOCKED) {
				$reasons[] = 'blocked';
			}
			$dueOn = $wo['dueOn'];
			if (is_string($dueOn) && $dueOn !== '' && $dueOn < $today) {
				$reasons[] = 'overdue';
			}
			$kitIncomplete = false;
			if (in_array($wo['status'], [WorkOrder::STATUS_PLANNED, WorkOrder::STATUS_READY, WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_BLOCKED], true)) {
				$readiness = $this->kits->readinessFor((int)$wo['id']);
				if (!$readiness['ready'] && empty($wo['kitOverride'])) {
					$reasons[] = 'kit_incomplete';
					$kitIncomplete = true;
				}
			}
			$skillsMissing = false;
			$primary = $wo['primaryUserId'];
			if (
				is_string($primary)
				&& $primary !== ''
				&& $skillsMode !== PolicyService::ENFORCEMENT_OFF
				&& in_array($wo['status'], [WorkOrder::STATUS_PLANNED, WorkOrder::STATUS_READY, WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_BLOCKED], true)
			) {
				$missing = $this->skills->missingSkillsFor((int)$wo['id'], $primary);
				if ($missing !== []) {
					$reasons[] = 'skills_missing';
					$skillsMissing = true;
				}
			}
			if ($reasons === []) {
				continue;
			}
			if ($want === 'blocked' && !in_array('blocked', $reasons, true)) {
				continue;
			}
			if ($want === 'overdue' && !in_array('overdue', $reasons, true)) {
				continue;
			}
			if ($want === 'kit' && !$kitIncomplete) {
				continue;
			}
			if ($want === 'skills' && !$skillsMissing) {
				continue;
			}
			$wo['exceptionReasons'] = $reasons;
			$rows[] = $wo;
		}

		usort($rows, static function (array $a, array $b): int {
			$prio = static fn (array $r): int => match (true) {
				in_array('blocked', $r['exceptionReasons'], true) => 0,
				in_array('skills_missing', $r['exceptionReasons'], true) => 1,
				in_array('overdue', $r['exceptionReasons'], true) => 2,
				default => 3,
			};
			$cmp = $prio($a) <=> $prio($b);
			if ($cmp !== 0) {
				return $cmp;
			}
			return ((string)($a['dueOn'] ?? '9999')) <=> ((string)($b['dueOn'] ?? '9999'));
		});

		$total = count($rows);
		$slice = array_slice($rows, $page['offset'], $page['limit']);
		return [
			'data' => $slice,
			'total' => $total,
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function loadOpenCandidates(?string $assigneeUid): array
	{
		$open = [
			WorkOrder::STATUS_DRAFT,
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_READY,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_BLOCKED,
		];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'number', 'kind', 'status', 'priority', 'title', 'due_on', 'primary_user_id', 'kit_override', 'block_reason_code', 'customer_id', 'equipment_id')
			->from('mn_work_orders')
			->where($qb->expr()->in('status', $qb->createNamedParameter($open, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('due_on', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(500);
		if ($assigneeUid !== null && $assigneeUid !== '') {
			$qb->andWhere($qb->expr()->eq('primary_user_id', $qb->createNamedParameter($assigneeUid)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'number' => (string)$row['number'],
				'kind' => (string)$row['kind'],
				'status' => (string)$row['status'],
				'priority' => (string)$row['priority'],
				'title' => (string)$row['title'],
				'dueOn' => $row['due_on'] !== null ? (string)$row['due_on'] : null,
				'primaryUserId' => $row['primary_user_id'] !== null ? (string)$row['primary_user_id'] : null,
				'kitOverride' => (bool)$row['kit_override'],
				'blockReasonCode' => $row['block_reason_code'] !== null ? (string)$row['block_reason_code'] : null,
				'customerId' => (int)$row['customer_id'],
				'equipmentId' => $row['equipment_id'] !== null ? (int)$row['equipment_id'] : null,
			];
		}
		$result->closeCursor();
		return $out;
	}
}
