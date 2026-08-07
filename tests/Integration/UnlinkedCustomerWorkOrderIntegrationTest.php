<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\Server;

/**
 * SHARED-IDENTITY AC-C-07 / NN-09 — WO create works with null soft links.
 *
 * @group integration
 */
final class UnlinkedCustomerWorkOrderIntegrationTest extends IntegrationTestCase
{
	private const UID = 'admin';
	private const MARKER = 'mn_unlink_wo_';

	/** @var list<int> */
	private array $customerIds = [];

	/** @var list<int> */
	private array $woIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		parent::setUp();
	}

	protected function tearDown(): void
	{
		if (class_exists(\OC::class)) {
			$customers = Server::get(CustomerService::class);
			$db = Server::get(\OCP\IDBConnection::class);
			foreach ($this->woIds as $id) {
				try {
					$qb = $db->getQueryBuilder();
					$qb->delete('mn_work_orders')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
					$qb->executeStatement();
				} catch (\Throwable) {
				}
			}
			$this->woIds = [];
			foreach ($this->customerIds as $id) {
				try {
					$customers->delete($id, true);
				} catch (\Throwable) {
				}
			}
			$this->customerIds = [];
		}
		parent::tearDown();
	}

	public function testWorkOrderCreateSucceedsWhenSoftLinksNull(): void
	{
		$customers = Server::get(CustomerService::class);
		$workOrders = Server::get(WorkOrderService::class);

		$row = $customers->create(self::UID, [
			'name' => self::MARKER . uniqid('', true),
		]);
		$customerId = (int)$row['id'];
		$this->customerIds[] = $customerId;
		$this->assertNull($row['pcCustomerId'] ?? null);
		$this->assertNull($row['crmCompanyId'] ?? null);

		$wo = $workOrders->create(self::UID, [
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $customerId,
			'title' => self::MARKER . 'title',
			'status' => WorkOrder::STATUS_DRAFT,
		], true);
		$this->assertGreaterThan(0, (int)$wo['id']);
		$this->woIds[] = (int)$wo['id'];
		$this->assertSame($customerId, (int)$wo['customerId']);
	}
}
