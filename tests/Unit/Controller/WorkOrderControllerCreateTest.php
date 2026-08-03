<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\WorkOrderController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** WO create: office any kind; tech corrective draft only (CORE §7). */
final class WorkOrderControllerCreateTest extends TestCase
{
	private function controller(
		AccessControlService $access,
		WorkOrderService $workOrders,
		?IRequest $request = null,
	): WorkOrderController {
		return new WorkOrderController(
			$request ?? $this->createMock(IRequest::class),
			$access,
			$workOrders,
			$this->createMock(WoChecklistService::class),
			$this->createMock(WoEvidenceService::class),
			$this->createMock(SkillService::class),
			$this->createMock(WoPdfService::class),
			$this->createMock(WoCommentService::class),
		);
	}

	public function testCreateAllowsTechCorrective(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'kind' => 'corrective',
			'customerId' => 1,
			'title' => 'Fix pump',
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->with('tech1')->willReturn(false);
		$access->expects($this->never())->method('requireOffice');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('create')->with('tech1', $this->isType('array'), false)
			->willReturn(['id' => 9, 'kind' => 'corrective']);

		$res = $this->controller($access, $workOrders, $request)->create();
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(9, $res->getData()['id']);
	}

	public function testCreateRejectsTechNonCorrectiveInService(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'kind' => 'preventive',
			'customerId' => 1,
			'title' => 'Annual',
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->with('tech1')->willReturn(false);
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('create')->with('tech1', $this->isType('array'), false)
			->willThrowException(new PermissionDeniedException('Technicians may only create corrective work orders.'));

		$this->expectException(PermissionDeniedException::class);
		$this->controller($access, $workOrders, $request)->create();
	}

	public function testCreateDelegatesWhenOffice(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'kind' => 'corrective',
			'customerId' => 1,
			'title' => 'Fix pump',
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->method('isOffice')->with('office1')->willReturn(true);
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('create')->with('office1', $this->isType('array'), true)
			->willReturn(['id' => 12, 'title' => 'Fix pump']);

		$res = $this->controller($access, $workOrders, $request)->create();
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(12, $res->getData()['id']);
	}
}
