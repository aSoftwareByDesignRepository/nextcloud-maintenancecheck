<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\UserCapacity;
use OCA\MaintenanceCheck\Db\UserCapacityMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IUserManager;

/**
 * W4 capacity (CORE §10.5): per-user daily minutes plus the assign-gate
 * assessment. Enforcement (off/warn/block) is applied by WorkOrderService.
 */
class CapacityService
{
	/** 24 h × 60 min — hard ceiling for a daily capacity. */
	public const MAX_DAILY_MINUTES = 1440;

	public function __construct(
		private readonly UserCapacityMapper $capacities,
		private readonly WorkOrderMapper $workOrders,
		private readonly CapacityCalculator $calculator,
		private readonly PolicyService $policies,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
		private readonly DutyCheckOnDutyService $onDuty,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function list(): array
	{
		return [
			'data' => array_map(static fn (UserCapacity $c) => $c->toApi(), $this->capacities->listAll()),
		];
	}

	public function dailyMinutesFor(string $uid): int
	{
		$row = $this->capacities->findByUid($uid);
		return $row !== null ? $row->getDailyMinutes() : UserCapacity::DEFAULT_DAILY_MINUTES;
	}

	/**
	 * Upsert one user's daily capacity.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function set(string $updatedBy, string $uid, array $body): array
	{
		if (!$this->userManager->userExists($uid)) {
			throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
		}
		$minutes = $body['dailyMinutes'] ?? null;
		if (!is_int($minutes) || $minutes < 1 || $minutes > self::MAX_DAILY_MINUTES) {
			throw new ValidationException('validation_failed', 'dailyMinutes must be between 1 and ' . self::MAX_DAILY_MINUTES . '.', [
				['field' => 'dailyMinutes', 'code' => 'out_of_range'],
			]);
		}
		$now = $this->clock->now();
		$row = $this->capacities->findByUid($uid);
		if ($row === null) {
			$row = new UserCapacity();
			$row->setUid($uid);
			$row->setDailyMinutes($minutes);
			$row->setUpdatedAt($now);
			$row->setUpdatedBy($updatedBy);
			try {
				return $this->capacities->insert($row)->toApi();
			} catch (\OCP\DB\Exception $e) {
				// Concurrent upsert: fall through to update the winner's row.
				$row = $this->capacities->findByUid($uid);
				if ($row === null) {
					throw $e;
				}
			}
		}
		$row->setDailyMinutes($minutes);
		$row->setUpdatedAt($now);
		$row->setUpdatedBy($updatedBy);
		return $this->capacities->update($row)->toApi();
	}

	/**
	 * Serialise capacity-checked assigns for one technician.
	 *
	 * Must be called inside the open assign transaction **after** the WO row
	 * lock (lock order: work order → capacity) so concurrent assigners cannot
	 * both observe the same load and both pass under `capacity_enforcement=block`.
	 */
	public function lockAssignGate(string $uid): void
	{
		$this->capacities->ensureAndLock($uid, 'capacity-gate', $this->clock->now());
	}

	/**
	 * §10.5 assign-gate assessment: projected load for `$uid` on `$dueOn`
	 * if `$addMinutes` more work lands there. `onDuty` is null when DutyCheck
	 * is unavailable (AC-F3 soft degrade).
	 *
	 * When enforcement is warn/block, callers must call {@see lockAssignGate}
	 * first inside the same transaction.
	 *
	 * @return array{exceeds: bool, capacityMinutes: int, thresholdMinutes: int,
	 *               loadMinutes: int, projectedMinutes: int, utilisation: float,
	 *               onDuty: ?bool}
	 */
	public function assessAssign(string $uid, ?string $dueOn, int $addMinutes, ?int $excludeWoId): array
	{
		$day = ($dueOn !== null && $dueOn !== '') ? $dueOn : $this->clock->today();
		$load = $this->workOrders->loadMinutesFor($uid, $day, $excludeWoId);
		$assessment = $this->calculator->assess(
			$this->dailyMinutesFor($uid),
			$this->policies->capacityWarnRatio(),
			$load,
			max(0, $addMinutes),
		);
		$assessment['onDuty'] = $this->onDuty->isOnDuty($uid, $day);
		return $assessment;
	}
}
