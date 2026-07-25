<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\Customer;
use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CustomerService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly CustomerMapper $customers,
		private readonly EquipmentMapper $equipment,
		private readonly PlanMapper $plans,
		private readonly VisitMapper $visits,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $q, ?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$term = $this->validator->searchTerm($q);
		$result = $this->customers->search($term, $page['limit'], $page['offset']);
		return [
			'data' => array_map(static fn (Customer $c) => $c->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * Detail incl. descendant counts for the S9 force-delete confirm modal.
	 *
	 * @return array<string, mixed>
	 */
	public function get(int $id): array
	{
		$customer = $this->customers->findById($id);
		$equipmentIds = $this->equipment->idsForCustomer($id);
		$data = $customer->toApi();
		$data['counts'] = [
			'equipment' => count($equipmentIds),
			'plans' => $this->plans->countForEquipmentIds($equipmentIds),
			'visits' => $this->visits->countForCustomer($id),
		];
		return $data;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body): array
	{
		$fields = $this->validator->customer($body);
		$now = $this->clock->now();

		$customer = new Customer();
		$this->applyFields($customer, $fields);
		$customer->setCreatedAt($now);
		$customer->setUpdatedAt($now);
		$customer->setCreatedBy($uid);
		return $this->customers->insert($customer)->toApi();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$customer = $this->customers->findById($id);
		$fields = $this->validator->customer($body);
		$this->applyFields($customer, $fields);
		$customer->setUpdatedAt($this->clock->now());
		return $this->customers->update($customer)->toApi();
	}

	/**
	 * S9 cascade contract: without force and existing equipment → 409;
	 * with force → one transaction, child-first order.
	 *
	 * @return array{deleted: bool, counts: array{equipment: int, plans: int, visits: int}}
	 */
	public function delete(int $id, bool $force): array
	{
		$customer = $this->customers->findById($id);
		$equipmentIds = $this->equipment->idsForCustomer($id);

		if ($equipmentIds !== [] && !$force) {
			throw new ConflictException('customer_has_equipment', 'This customer still has equipment. Use force delete to remove everything.');
		}

		$counts = [
			'equipment' => count($equipmentIds),
			'plans' => $this->plans->countForEquipmentIds($equipmentIds),
			'visits' => $this->visits->countForCustomer($id),
		];

		$this->db->beginTransaction();
		try {
			$this->visits->deleteForCustomer($id);
			$this->plans->deleteForEquipmentIds($equipmentIds);
			foreach (array_chunk($equipmentIds, 500) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete(EquipmentMapper::TABLE)
					->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$this->customers->delete($customer);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return ['deleted' => true, 'counts' => $counts];
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(Customer $customer, array $fields): void
	{
		$customer->setName($fields['name']);
		$customer->setCustomerNo($fields['customerNo']);
		$customer->setStreet($fields['street']);
		$customer->setPostalCode($fields['postalCode']);
		$customer->setCity($fields['city']);
		$customer->setCountry($fields['country']);
		$customer->setEmail($fields['email']);
		$customer->setPhone($fields['phone']);
		$customer->setNotes($fields['notes']);
		$customer->setActive($fields['active']);
	}
}
