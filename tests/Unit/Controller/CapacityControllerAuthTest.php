<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\CapacityController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CapacityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Daily capacity is a staffing plan. The settings UI is office/admin; the
 * dispatch board already carries per-lane minutes. Listing every UID's
 * minutes via GET /api/capacity must not be a technician API.
 */
final class CapacityControllerAuthTest extends TestCase
{
	private function controller(AccessControlService $access, ?CapacityService $capacity = null): CapacityController
	{
		return new CapacityController(
			$this->createMock(IRequest::class),
			$access,
			$capacity ?? $this->createMock(CapacityService::class),
		);
	}

	public function testIndexRejectsTechnician(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('requireOffice')->with('tech1')->willThrowException(new PermissionDeniedException());
		$this->expectException(PermissionDeniedException::class);
		$this->controller($access)->index();
	}

	public function testIndexAllowsOffice(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->expects($this->once())->method('requireOffice')->with('office1');
		$capacity = $this->createMock(CapacityService::class);
		$capacity->expects($this->once())->method('list')->willReturn(['data' => [['uid' => 'tech1', 'dailyMinutes' => 480]]]);
		$res = $this->controller($access, $capacity)->index();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(480, $res->getData()['data'][0]['dailyMinutes']);
	}
}
