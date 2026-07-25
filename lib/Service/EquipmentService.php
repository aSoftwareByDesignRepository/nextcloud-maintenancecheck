<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;

class EquipmentService
{
	public function __construct(
		private readonly EquipmentMapper $equipment,
		private readonly CustomerMapper $customers,
		private readonly EquipTypeMapper $equipTypes,
		private readonly PlanMapper $plans,
		private readonly VisitMapper $visits,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $customerId, ?string $q, ?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$term = $this->validator->searchTerm($q);
		$customerFilter = null;
		if ($customerId !== null && $customerId !== '') {
			if (!preg_match('/^\d+$/', $customerId)) {
				throw new ValidationException('invalid_query', 'customerId must be a positive integer.');
			}
			$customerFilter = (int)$customerId;
		}
		$result = $this->equipment->search($customerFilter, $term, $page['limit'], $page['offset']);
		return [
			'data' => array_map(static fn (Equipment $e) => $e->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(int $id): array
	{
		$equipment = $this->equipment->findById($id);
		$data = $equipment->toApi();
		$data['counts'] = [
			'plans' => $this->plans->countForEquipment($id),
			'visits' => $this->visits->countForEquipment($id),
		];
		return $data;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body): array
	{
		$fields = $this->validator->equipment($body);
		$customerId = $this->requireCustomer($body);
		$equipTypeId = $this->requireActiveEquipType($body, null);
		$now = $this->clock->now();

		$equipment = new Equipment();
		$equipment->setCustomerId($customerId);
		$equipment->setEquipTypeId($equipTypeId);
		$this->applyFields($equipment, $fields);
		$equipment->setCreatedAt($now);
		$equipment->setUpdatedAt($now);
		$equipment->setCreatedBy($uid);
		return $this->equipment->insert($equipment)->toApi();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$equipment = $this->equipment->findById($id);
		$fields = $this->validator->equipment($body);

		if (array_key_exists('customerId', $body)) {
			$equipment->setCustomerId($this->requireCustomer($body));
		}
		if (array_key_exists('equipTypeId', $body)) {
			$equipment->setEquipTypeId($this->requireActiveEquipType($body, $equipment->getEquipTypeId()));
		}

		$this->applyFields($equipment, $fields);
		$equipment->setUpdatedAt($this->clock->now());
		return $this->equipment->update($equipment)->toApi();
	}

	/**
	 * S10: block — equipment with ≥ 1 plan or ≥ 1 visit → 409 `equipment_in_use`.
	 */
	public function delete(int $id): void
	{
		$equipment = $this->equipment->findById($id);
		if ($this->plans->countForEquipment($id) > 0 || $this->visits->countForEquipment($id) > 0) {
			throw new ConflictException('equipment_in_use', 'This equipment has plans or visits. Deactivate it instead.');
		}
		$this->equipment->delete($equipment);
	}

	/**
	 * SPEC §9.2: mobile equipment detail — summary + active plans + last 5 visits.
	 *
	 * @return array<string, mixed>
	 */
	public function mobileSummary(int $id): array
	{
		$equipment = $this->equipment->findById($id);
		$customer = $this->customers->findById($equipment->getCustomerId());
		$equipType = null;
		try {
			$equipType = $this->equipTypes->findById($equipment->getEquipTypeId());
		} catch (NotFoundException) {
			// Catalog row may have been removed after historical inserts — omit name.
		}

		$activePlans = [];
		foreach ($this->plans->findByEquipment($id) as $plan) {
			if (!$plan->getActive()) {
				continue;
			}
			$row = $plan->toApi();
			$open = $this->visits->findOpenByPlan((int)$plan->getId());
			$row['openVisit'] = $open?->toApi();
			$activePlans[] = $row;
		}

		$recent = array_map(
			static fn ($visit) => $visit->toApi(),
			$this->visits->findRecentForEquipment($id, 5),
		);

		$data = $equipment->toApi();
		$data['customerName'] = $customer->getName();
		$data['equipTypeName'] = $equipType?->getName() ?? '';
		$data['activePlans'] = $activePlans;
		$data['recentVisits'] = $recent;
		return $data;
	}

	private function requireCustomer(array $body): int
	{
		$customerId = $body['customerId'] ?? null;
		if (!is_int($customerId) || $customerId < 1 || !$this->customers->exists($customerId)) {
			throw new ValidationException('validation_failed', 'Unknown customer.', [
				['field' => 'customerId', 'code' => 'unknown_customer'],
			]);
		}
		return $customerId;
	}

	/**
	 * S11: creating/updating equipment with an inactive type → 422; keeping
	 * the current (possibly deactivated) type on update stays allowed.
	 */
	private function requireActiveEquipType(array $body, ?int $currentTypeId): int
	{
		$typeId = $body['equipTypeId'] ?? null;
		if (!is_int($typeId) || $typeId < 1) {
			throw new ValidationException('validation_failed', 'Unknown equipment type.', [
				['field' => 'equipTypeId', 'code' => 'unknown_equip_type'],
			]);
		}
		try {
			$type = $this->equipTypes->findById($typeId);
		} catch (NotFoundException) {
			throw new ValidationException('validation_failed', 'Unknown equipment type.', [
				['field' => 'equipTypeId', 'code' => 'unknown_equip_type'],
			]);
		}
		if (!$type->getActive() && $typeId !== $currentTypeId) {
			throw new ValidationException('inactive_equip_type', 'This equipment type is deactivated.');
		}
		return $typeId;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(Equipment $equipment, array $fields): void
	{
		$equipment->setLabel($fields['label']);
		$equipment->setManufacturer($fields['manufacturer']);
		$equipment->setModel($fields['model']);
		$equipment->setSerialNo($fields['serialNo']);
		$equipment->setLocationText($fields['locationText']);
		$equipment->setNotes($fields['notes']);
		$equipment->setActive($fields['active']);
	}
}
