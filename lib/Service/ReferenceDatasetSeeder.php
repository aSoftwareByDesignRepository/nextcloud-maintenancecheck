<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCP\IDBConnection;

/**
 * N4 reference dataset seeder. Profiles:
 *   smoke — tiny fixture for CI (fast, marker-prefixed, deletable)
 *   n4    — SPEC §12 full scale (200 / 1 000 / 1 500 / 10 000)
 *
 * All customers use a stable marker prefix so tear-down is safe.
 */
final class ReferenceDatasetSeeder
{
	public const MARKER = 'mn_n4_';

	/** @var array<string, array{customers: int, equipment: int, plans: int, visits: int}> */
	public const PROFILES = [
		'smoke' => [
			'customers' => 5,
			'equipment' => 10,
			'plans' => 15,
			'visits' => 30,
		],
		'n4' => [
			'customers' => 200,
			'equipment' => 1000,
			'plans' => 1500,
			'visits' => 10000,
		],
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CustomerService $customers,
		private readonly EquipmentService $equipment,
		private readonly CatalogService $catalogs,
		private readonly PlanService $plans,
		private readonly VisitMapper $visitMapper,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{profile: string, customers: int, equipment: int, plans: int, visits: int, openVisits: int}
	 */
	public function seed(string $profile, string $uid): array
	{
		if (!isset(self::PROFILES[$profile])) {
			throw new \InvalidArgumentException('Unknown profile: ' . $profile . ' (use smoke|n4)');
		}
		$target = self::PROFILES[$profile];
		$equipTypeId = $this->ensureCatalog('equip', self::MARKER . 'et_' . $profile);
		$maintTypeId = $this->ensureCatalog('maint', self::MARKER . 'mt_' . $profile);
		$today = $this->clock->today();
		$now = $this->clock->now();

		$customerIds = [];
		for ($i = 0; $i < $target['customers']; $i++) {
			$row = $this->customers->create($uid, [
				'name' => self::MARKER . $profile . '_c_' . $i,
				'city' => 'SeedCity',
				'country' => 'de',
			]);
			$customerIds[] = (int)$row['id'];
		}

		$equipmentIds = [];
		$equipmentCustomer = [];
		for ($i = 0; $i < $target['equipment']; $i++) {
			$customerId = $customerIds[$i % count($customerIds)];
			$row = $this->equipment->create($uid, [
				'label' => self::MARKER . $profile . '_e_' . $i,
				'customerId' => $customerId,
				'equipTypeId' => $equipTypeId,
			]);
			$equipmentIds[] = (int)$row['id'];
			$equipmentCustomer[(int)$row['id']] = $customerId;
		}

		/** @var list<array{planId: int, equipmentId: int, customerId: int}> $planMeta */
		$planMeta = [];
		for ($i = 0; $i < $target['plans']; $i++) {
			$equipmentId = $equipmentIds[$i % count($equipmentIds)];
			$offset = ($i % 40) - 10;
			$due = $this->shiftDays($today, $offset);
			$plan = $this->plans->create($uid, $equipmentId, [
				'maintTypeId' => $maintTypeId,
				'intervalUnit' => 'month',
				'intervalCount' => 1 + ($i % 12),
				'firstDueOn' => $due,
			]);
			$planMeta[] = [
				'planId' => (int)$plan['id'],
				'equipmentId' => $equipmentId,
				'customerId' => $equipmentCustomer[$equipmentId],
			];
		}

		$openVisits = count($planMeta);
		$extraNeeded = max(0, $target['visits'] - $openVisits);
		for ($i = 0; $i < $extraNeeded; $i++) {
			$meta = $planMeta[$i % count($planMeta)];
			$visit = new Visit();
			$visit->setPlanId($meta['planId']);
			$visit->setEquipmentId($meta['equipmentId']);
			$visit->setCustomerId($meta['customerId']);
			$visit->setMaintTypeId($maintTypeId);
			$visit->setDueOn($this->shiftDays($today, -30 - ($i % 365)));
			$visit->setStatus(Visit::STATUS_DONE);
			$visit->setDoneOn($this->shiftDays($today, -20 - ($i % 300)));
			$visit->setDoneBy($uid);
			$visit->setDoneAt($now - $i);
			$visit->setNotes(self::MARKER . 'hist');
			$visit->setCreatedAt($now - $i);
			$visit->setUpdatedAt($now - $i);
			$this->visitMapper->insert($visit);
		}

		return [
			'profile' => $profile,
			'customers' => count($customerIds),
			'equipment' => count($equipmentIds),
			'plans' => count($planMeta),
			'visits' => $this->countMarkedVisits(),
			'openVisits' => $openVisits,
		];
	}

	/**
	 * Deletes every customer whose name starts with the marker (cascade force).
	 */
	public function purge(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(CustomerMapper::TABLE)
			->where($qb->expr()->like('name', $qb->createNamedParameter(self::MARKER . '%')));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		$deleted = 0;
		foreach ($ids as $id) {
			$this->customers->delete($id, true);
			$deleted++;
		}

		foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
			$del = $this->db->getQueryBuilder();
			$del->delete($table)->where($del->expr()->like('code', $del->createNamedParameter(self::MARKER . '%')));
			$del->executeStatement();
		}
		return $deleted;
	}

	private function ensureCatalog(string $kind, string $code): int
	{
		try {
			$row = $this->catalogs->create($kind, ['code' => $code, 'name' => 'N4 ' . $code]);
			return (int)$row['id'];
		} catch (ConflictException) {
			foreach ($this->catalogs->list($kind, '200', '0')['data'] as $entry) {
				if ($entry['code'] === $code) {
					return (int)$entry['id'];
				}
			}
			throw new \RuntimeException('Catalog vanished: ' . $code);
		}
	}

	private function shiftDays(string $ymd, int $delta): string
	{
		$dt = new \DateTimeImmutable($ymd . ' 12:00:00', new \DateTimeZone('UTC'));
		return $dt->modify(($delta >= 0 ? '+' : '') . $delta . ' days')->format('Y-m-d');
	}

	private function countMarkedVisits(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('v.id', 'cnt'))
			->from(VisitMapper::TABLE, 'v')
			->innerJoin('v', CustomerMapper::TABLE, 'c', $qb->expr()->eq('v.customer_id', 'c.id'))
			->where($qb->expr()->like('c.name', $qb->createNamedParameter(self::MARKER . '%')));
		$result = $qb->executeQuery();
		$count = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $count;
	}
}
