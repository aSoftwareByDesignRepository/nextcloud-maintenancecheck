<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Db\NotifLogMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * W6 overdue NC Notifications (CORE §20 W6-R7, AC-W6-9).
 * At most one notification per entity per calendar day (server TZ via Clock).
 */
class OverdueReminderService
{
	public const TYPE_VISIT_OVERDUE = 'visit.overdue';
	public const TYPE_WO_OVERDUE = 'wo.overdue';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly NotifLogMapper $log,
		private readonly INotificationManager $notifications,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{visits: int, workOrders: int, skipped: int}
	 */
	public function run(bool $dryRun = false): array
	{
		$today = $this->clock->today();
		$sentVisits = 0;
		$sentWo = 0;
		$skipped = 0;

		foreach ($this->overdueAssignedVisits($today) as $row) {
			$assignee = (string)$row['assigned_uid'];
			$visitId = (int)$row['id'];
			$recipients = $this->recipientsFor($assignee);
			foreach ($recipients as $uid) {
				$dedupe = sprintf('%s:visit:%d:%s:%s', self::TYPE_VISIT_OVERDUE, $visitId, $today, $uid);
				if ($dryRun) {
					if ($this->log->wasSent($dedupe)) {
						$skipped++;
					} else {
						$sentVisits++;
					}
					continue;
				}
				if ($this->emit(self::TYPE_VISIT_OVERDUE, $uid, 'visit', $visitId, $dedupe, [
					'visitId' => $visitId,
					'dueOn' => (string)$row['due_on'],
					'title' => (string)($row['label'] ?? ''),
				])) {
					$sentVisits++;
				} else {
					$skipped++;
				}
			}
		}

		foreach ($this->overdueAssignedWorkOrders($today) as $row) {
			$assignee = (string)$row['primary_user_id'];
			$woId = (int)$row['id'];
			$recipients = $this->recipientsFor($assignee);
			foreach ($recipients as $uid) {
				$dedupe = sprintf('%s:wo:%d:%s:%s', self::TYPE_WO_OVERDUE, $woId, $today, $uid);
				if ($dryRun) {
					if ($this->log->wasSent($dedupe)) {
						$skipped++;
					} else {
						$sentWo++;
					}
					continue;
				}
				if ($this->emit(self::TYPE_WO_OVERDUE, $uid, 'work_order', $woId, $dedupe, [
					'workOrderId' => $woId,
					'number' => (string)$row['number'],
					'dueOn' => (string)$row['due_on'],
					'title' => (string)$row['title'],
				])) {
					$sentWo++;
				} else {
					$skipped++;
				}
			}
		}

		return ['visits' => $sentVisits, 'workOrders' => $sentWo, 'skipped' => $skipped];
	}

	/**
	 * @return list<string>
	 */
	private function recipientsFor(string $assignee): array
	{
		$office = $this->access->officeUserIds();
		$out = [$assignee];
		foreach ($office as $uid) {
			$out[] = $uid;
		}
		return array_values(array_unique(array_filter($out, static fn (string $u): bool => $u !== '')));
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function emit(
		string $type,
		string $recipient,
		string $entityType,
		int $entityId,
		string $dedupeKey,
		array $params,
	): bool {
		if (!$this->log->reserve($type, $recipient, $entityType, $entityId, $dedupeKey, $this->clock->now())) {
			return false;
		}
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($recipient)
				->setDateTime($this->time->getDateTime())
				->setObject($entityType, (string)$entityId)
				->setSubject($type, $params);
			$this->notifications->notify($n);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('MaintenanceCheck overdue notify failed', [
				'exception' => $e,
				'dedupe' => $dedupeKey,
			]);
			return false;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function overdueAssignedVisits(string $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.id', 'v.due_on', 'v.assigned_uid', 'e.label')
			->from('mn_visits', 'v')
			->leftJoin('v', 'mn_equipment', 'e', $qb->expr()->eq('e.id', 'v.equipment_id'))
			->where($qb->expr()->eq('v.status', $qb->createNamedParameter(Visit::STATUS_SCHEDULED)))
			->andWhere($qb->expr()->lt('v.due_on', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->isNotNull('v.assigned_uid'))
			->andWhere($qb->expr()->neq('v.assigned_uid', $qb->createNamedParameter('')))
			->setMaxResults(500);
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function overdueAssignedWorkOrders(string $today): array
	{
		$open = [
			WorkOrder::STATUS_DRAFT,
			WorkOrder::STATUS_PLANNED,
			WorkOrder::STATUS_READY,
			WorkOrder::STATUS_IN_PROGRESS,
			WorkOrder::STATUS_BLOCKED,
		];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'number', 'title', 'due_on', 'primary_user_id')
			->from('mn_work_orders')
			->where($qb->expr()->in('status', $qb->createNamedParameter($open, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('due_on'))
			->andWhere($qb->expr()->lt('due_on', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->isNotNull('primary_user_id'))
			->andWhere($qb->expr()->neq('primary_user_id', $qb->createNamedParameter('')))
			->setMaxResults(500);
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}
}
