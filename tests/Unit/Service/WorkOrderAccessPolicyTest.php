<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\WorkOrderAccessPolicy;
use PHPUnit\Framework\TestCase;

final class WorkOrderAccessPolicyTest extends TestCase
{
	private function policy(bool $isOffice): WorkOrderAccessPolicy
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isOffice')->willReturn($isOffice);
		return new WorkOrderAccessPolicy($access);
	}

	private function wo(?string $primary, array $helpers = []): WorkOrder
	{
		$wo = new WorkOrder();
		$wo->setPrimaryUserId($primary);
		$wo->setHelperUids($helpers === [] ? null : json_encode(array_values($helpers), JSON_UNESCAPED_UNICODE));
		return $wo;
	}

	public function testOfficeAlwaysMayExecute(): void
	{
		$wo = $this->wo('other-tech');
		$this->assertTrue($this->policy(true)->canExecute('office1', $wo));
	}

	public function testPoolAllowsAnyTech(): void
	{
		$wo = $this->wo(null);
		$this->assertTrue($this->policy(false)->canExecute('tech-a', $wo));
	}

	public function testPrimaryMayExecute(): void
	{
		$wo = $this->wo('tech-a');
		$this->assertTrue($this->policy(false)->canExecute('tech-a', $wo));
	}

	public function testHelperMayExecute(): void
	{
		$wo = $this->wo('tech-a', ['tech-b']);
		$this->assertTrue($this->policy(false)->canExecute('tech-b', $wo));
	}

	public function testUnrelatedTechDenied(): void
	{
		$wo = $this->wo('tech-a', ['tech-b']);
		$this->assertFalse($this->policy(false)->canExecute('tech-c', $wo));
		$this->expectException(PermissionDeniedException::class);
		$this->policy(false)->assertCanExecute('tech-c', $wo);
	}
}
