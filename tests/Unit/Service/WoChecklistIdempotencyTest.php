<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\ProcItemMapper;
use OCA\MaintenanceCheck\Db\WoChecklistItem;
use OCA\MaintenanceCheck\Db\WoChecklistMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WorkOrderAccessPolicy;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * SF-Z04 — checklist PUT idempotency via clientRequestId + same-result short-circuit.
 */
final class WoChecklistIdempotencyTest extends TestCase
{
	private IDBConnection $db;
	private WoChecklistMapper $checklist;
	private WorkOrderMapper $workOrders;
	private WoChecklistService $service;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('inTransaction')->willReturn(false);

		$this->checklist = $this->createMock(WoChecklistMapper::class);
		$this->workOrders = $this->createMock(WorkOrderMapper::class);

		$access = $this->createMock(AccessControlService::class);
		$access->method('isOffice')->willReturn(true);
		$woAccess = new WorkOrderAccessPolicy($access);

		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_700_000_000);

		$showIf = new ShowIfEvaluator();
		$policy = new ChecklistPolicy($showIf);
		$policies = $this->createMock(PolicyService::class);
		$policies->method('checklistDonePolicy')->willReturn('all_required');
		$policies->method('checklistMinPercent')->willReturn(100);

		$this->service = new WoChecklistService(
			$this->db,
			$this->checklist,
			$this->createMock(ProcItemMapper::class),
			$this->workOrders,
			$showIf,
			$policy,
			$policies,
			new InputValidator(new IntervalCalculator()),
			$clock,
			$woAccess,
		);
	}

	public function testClientRequestIdReplayReturnsWithoutUpdate(): void
	{
		$item = $this->item('a', WoChecklistItem::RESULT_OK, 'req-replay-01');
		$this->stubInProgressWo();
		$this->checklist->expects($this->never())->method('update');
		$this->checklist->method('findByWorkOrder')->willReturn([$item]);

		$first = $this->service->setResult('tech', 9, 'a', [
			'result' => 'ok',
			'clientRequestId' => 'req-replay-01',
		]);
		$this->assertTrue($first['idempotentReplay'] ?? false);

		$second = $this->service->setResult('tech', 9, 'a', [
			'result' => 'ok',
			'clientRequestId' => 'req-replay-01',
		]);
		$this->assertTrue($second['idempotentReplay'] ?? false);
	}

	public function testClientRequestIdConflictWhenPayloadDiffers(): void
	{
		$item = $this->item('a', WoChecklistItem::RESULT_OK, 'req-replay-01');
		$this->stubInProgressWo();
		$this->checklist->method('findByWorkOrder')->willReturn([$item]);

		$this->expectException(ConflictException::class);
		try {
			$this->service->setResult('tech', 9, 'a', [
				'result' => 'fail',
				'clientRequestId' => 'req-replay-01',
			]);
		} catch (ConflictException $e) {
			$this->assertSame('idempotency_conflict', $e->getErrorCode());
			throw $e;
		}
	}

	public function testFirstWriteStoresClientRequestId(): void
	{
		$item = $this->item('a', null, null);
		$this->stubInProgressWo();
		$this->checklist->method('findByWorkOrder')->willReturn([$item]);

		$this->checklist->expects($this->once())->method('update')->with($this->callback(
			static function (WoChecklistItem $row): bool {
				return $row->getResult() === 'ok'
					&& $row->getClientRequestId() === 'req-new-01';
			}
		));

		$out = $this->service->setResult('tech', 9, 'a', [
			'result' => 'ok',
			'clientRequestId' => 'req-new-01',
		]);
		$this->assertArrayNotHasKey('idempotentReplay', $out);
	}

	public function testSameResultWithoutClientIdIsIdempotent(): void
	{
		$item = $this->item('a', WoChecklistItem::RESULT_OK, null);
		$this->stubInProgressWo();
		$this->checklist->method('findByWorkOrder')->willReturn([$item]);
		$this->checklist->expects($this->never())->method('update');

		$out = $this->service->setResult('tech', 9, 'a', ['result' => 'ok']);
		$this->assertTrue($out['idempotentReplay'] ?? false);
	}

	private function stubInProgressWo(): void
	{
		$wo = new WorkOrder();
		$wo->setId(9);
		$wo->setStatus(WorkOrder::STATUS_IN_PROGRESS);

		$this->workOrders->method('lockRow')->willReturn(true);
		$this->workOrders->method('findById')->willReturn($wo);
	}

	private function item(string $code, ?string $result, ?string $clientRequestId): WoChecklistItem
	{
		$row = new WoChecklistItem();
		$row->setId(1);
		$row->setWorkOrderId(9);
		$row->setItemCode($code);
		$row->setLabel($code);
		$row->setRequired(true);
		$row->setSortOrder(0);
		$row->setResult($result);
		$row->setNote(null);
		$row->setClientRequestId($clientRequestId);
		$row->setUpdatedAt(1);
		return $row;
	}
}
