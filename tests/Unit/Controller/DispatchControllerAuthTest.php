<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\DispatchController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\DispatchService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Dispatch is office planning. The nav hides it; the API must 403 technicians.
 */
final class DispatchControllerAuthTest extends TestCase
{
	public function testBoardRejectsTechnician(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('requireOffice')->with('tech1')->willThrowException(new PermissionDeniedException());
		$dispatch = $this->createMock(DispatchService::class);
		$dispatch->expects($this->never())->method('board');
		$controller = new DispatchController($this->createMock(IRequest::class), $access, $dispatch);
		$this->expectException(PermissionDeniedException::class);
		$controller->board();
	}

	public function testBoardAllowsOffice(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->expects($this->once())->method('requireOffice')->with('office1');
		$dispatch = $this->createMock(DispatchService::class);
		$dispatch->expects($this->once())->method('board')->with('2026-08-01', '2026-08-31')
			->willReturn(['days' => []]);
		$controller = new DispatchController($this->createMock(IRequest::class), $access, $dispatch);
		$res = $controller->board('2026-08-01', '2026-08-31');
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['days' => []], $res->getData());
	}
}
