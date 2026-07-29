<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Service\WorkOrderStateMachine;
use PHPUnit\Framework\TestCase;

/** CORE §14.2 / AC-W1 status machine — pure transition legality. */
final class WorkOrderStateMachineTest extends TestCase
{
	private WorkOrderStateMachine $sm;

	protected function setUp(): void
	{
		$this->sm = new WorkOrderStateMachine();
	}

	public function testDraftCanPlanOrCancel(): void
	{
		$this->assertTrue($this->sm->canTransition(WorkOrder::STATUS_DRAFT, WorkOrder::STATUS_PLANNED));
		$this->assertTrue($this->sm->canTransition(WorkOrder::STATUS_DRAFT, WorkOrder::STATUS_CANCELLED));
		$this->assertFalse($this->sm->canTransition(WorkOrder::STATUS_DRAFT, WorkOrder::STATUS_DONE));
	}

	public function testTerminalStatusesHaveNoExits(): void
	{
		$this->assertTrue($this->sm->isTerminal(WorkOrder::STATUS_DONE));
		$this->assertTrue($this->sm->isTerminal(WorkOrder::STATUS_CANCELLED));
		$this->assertSame([], WorkOrderStateMachine::ALLOWED[WorkOrder::STATUS_DONE]);
		$this->assertSame([], WorkOrderStateMachine::ALLOWED[WorkOrder::STATUS_CANCELLED]);
		$this->assertFalse($this->sm->canTransition(WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS));
	}

	public function testInProgressMayCompleteOrBlock(): void
	{
		$this->assertTrue($this->sm->canTransition(WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_DONE));
		$this->assertTrue($this->sm->canTransition(WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_BLOCKED));
		$this->assertFalse($this->sm->canTransition(WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_READY));
	}

	public function testAssertTransitionThrowsInvalidStatus(): void
	{
		try {
			$this->sm->assertTransition(WorkOrder::STATUS_DRAFT, WorkOrder::STATUS_DONE);
			$this->fail('expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('invalid_status', $e->getErrorCode());
			$this->assertSame(WorkOrder::STATUS_DRAFT, $e->getDetails()['from'] ?? null);
			$this->assertSame(WorkOrder::STATUS_DONE, $e->getDetails()['to'] ?? null);
		}
	}

	public function testSourcesForDoneAreOnlyInProgress(): void
	{
		$this->assertSame(
			[WorkOrder::STATUS_IN_PROGRESS],
			$this->sm->sourcesFor(WorkOrder::STATUS_DONE),
		);
	}

	public function testSourcesForReadyIncludesPlannedAndBlocked(): void
	{
		$sources = $this->sm->sourcesFor(WorkOrder::STATUS_READY);
		sort($sources);
		$this->assertSame(
			[WorkOrder::STATUS_BLOCKED, WorkOrder::STATUS_PLANNED],
			$sources,
		);
	}

	public function testUnknownFromStatusIsNotAllowed(): void
	{
		$this->assertFalse($this->sm->canTransition('garbage', WorkOrder::STATUS_PLANNED));
		$this->assertSame([], $this->sm->sourcesFor('not_a_status'));
	}
}
