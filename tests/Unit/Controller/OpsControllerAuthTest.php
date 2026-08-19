<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\OpsController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Planning nav is office-only. KPI CSV already requires office. The JSON
 * snapshot and exception board must not leak org-wide ops data to technicians
 * who hide the nav and call the API directly (BOLA / function-level auth).
 */
final class OpsControllerAuthTest extends TestCase
{
	private function controller(
		AccessControlService $access,
		?KpiService $kpi = null,
		?ExceptionBoardService $exceptions = null,
	): OpsController {
		return new OpsController(
			$this->createMock(IRequest::class),
			$access,
			$kpi ?? $this->createMock(KpiService::class),
			$exceptions ?? $this->createMock(ExceptionBoardService::class),
			$this->createMock(FailureCodeService::class),
			$this->createMock(OverdueReminderService::class),
		);
	}

	private function techAccess(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('requireOffice')->with('tech1')->willThrowException(new PermissionDeniedException());
		return $access;
	}

	private function officeAccess(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->expects($this->once())->method('requireOffice')->with('office1');
		return $access;
	}

	public function testKpiRejectsTechnician(): void
	{
		$this->expectException(PermissionDeniedException::class);
		$this->controller($this->techAccess())->kpi();
	}

	public function testExceptionsRejectsTechnician(): void
	{
		$this->expectException(PermissionDeniedException::class);
		$this->controller($this->techAccess())->exceptions();
	}

	public function testKpiAllowsOfficeSnapshot(): void
	{
		$kpi = $this->createMock(KpiService::class);
		$kpi->expects($this->once())->method('snapshot')->with(30)->willReturn([
			'windowDays' => 30,
			'pmCompliancePercent' => 80.0,
		]);
		$res = $this->controller($this->officeAccess(), $kpi)->kpi();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(80.0, $res->getData()['pmCompliancePercent']);
	}

	public function testExceptionsAllowsOfficeUnscopedBoard(): void
	{
		$board = $this->createMock(ExceptionBoardService::class);
		$board->expects($this->once())->method('list')->with(null, null, null)
			->willReturn(['data' => [['id' => 4, 'primaryUserId' => 'other-tech']], 'total' => 1, 'limit' => 50, 'offset' => 0]);
		$res = $this->controller($this->officeAccess(), null, $board)->exceptions();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame('other-tech', $res->getData()['data'][0]['primaryUserId']);
	}
}
