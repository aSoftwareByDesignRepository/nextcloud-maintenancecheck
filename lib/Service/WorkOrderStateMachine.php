<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ConflictException;

/**
 * CORE §14.2 work order status machine. This class only decides *legality*
 * of a transition — atomicity is enforced by the conditional UPDATE in
 * {@see \OCA\MaintenanceCheck\Db\WorkOrderMapper::transition()}.
 *
 *   draft ──► planned ──► ready ──► in_progress ──► done
 *     │          │  ▲        │  ▲        │
 *     │          │  └────────┘  └────────┤ (blocked ⇄ working states)
 *     ▼          ▼                       ▼
 *  cancelled  blocked ◄──────────────────┘
 *
 * done and cancelled are terminal (no exits).
 *
 * Pure service — no I/O, mutation-test target.
 */
class WorkOrderStateMachine
{
	/** @var array<string, list<string>> */
	public const ALLOWED = [
		WorkOrder::STATUS_DRAFT => [
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_CANCELLED,
		],
		WorkOrder::STATUS_PLANNED => [
			WorkOrder::STATUS_READY,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_BLOCKED,
			WorkOrder::STATUS_CANCELLED,
		],
		WorkOrder::STATUS_READY => [
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_BLOCKED,
			WorkOrder::STATUS_CANCELLED,
		],
		WorkOrder::STATUS_IN_PROGRESS => [
			WorkOrder::STATUS_DONE,
			WorkOrder::STATUS_BLOCKED,
			WorkOrder::STATUS_CANCELLED,
		],
		WorkOrder::STATUS_BLOCKED => [
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_READY,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_CANCELLED,
		],
		WorkOrder::STATUS_DONE => [],
		WorkOrder::STATUS_CANCELLED => [],
	];

	public function canTransition(string $from, string $to): bool
	{
		return in_array($to, self::ALLOWED[$from] ?? [], true);
	}

	/**
	 * All statuses from which `$to` is legally reachable — feeds the
	 * conditional UPDATE's `status IN (…)` guard.
	 *
	 * @return list<string>
	 */
	public function sourcesFor(string $to): array
	{
		$sources = [];
		foreach (self::ALLOWED as $from => $targets) {
			if (in_array($to, $targets, true)) {
				$sources[] = $from;
			}
		}
		return $sources;
	}

	/**
	 * @throws ConflictException `invalid_status` (HTTP 409, CORE §12.5)
	 */
	public function assertTransition(string $from, string $to): void
	{
		if (!$this->canTransition($from, $to)) {
			throw new ConflictException('invalid_status', sprintf(
				'A work order cannot move from "%s" to "%s".',
				$from,
				$to,
			), ['from' => $from, 'to' => $to]);
		}
	}

	public function isTerminal(string $status): bool
	{
		return in_array($status, WorkOrder::TERMINAL_STATUSES, true);
	}
}
