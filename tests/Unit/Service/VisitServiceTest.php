<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Db\Plan;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\DueBoard;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit gauntlet for VisitService close / assign / conflict paths.
 * Keeps SPEC §14.4 VisitService MSI targets killable without a live DB.
 */
final class VisitServiceTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private VisitMapper&MockObject $visits;
	private PlanMapper&MockObject $plans;
	private Clock&MockObject $clock;
	private IUserManager&MockObject $users;
	private VisitService $service;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->visits = $this->createMock(VisitMapper::class);
		$this->plans = $this->createMock(PlanMapper::class);
		$this->clock = $this->createMock(Clock::class);
		$this->users = $this->createMock(IUserManager::class);

		$intervals = new IntervalCalculator();
		$validator = new InputValidator($intervals);
		$dueBoard = new DueBoard($intervals);

		$this->clock->method('today')->willReturn('2026-07-24');
		$this->clock->method('now')->willReturn(1_721_800_000);

		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->service = new VisitService(
			$this->db,
			$this->visits,
			$this->plans,
			$this->createMock(CustomerMapper::class),
			$this->createMock(EquipmentMapper::class),
			$this->createMock(MaintTypeMapper::class),
			$intervals,
			$dueBoard,
			$validator,
			$this->clock,
			$this->users,
		);
	}

	public function testCompleteConflictsWhenConditionalCloseLoses(): void
	{
		$this->visits->expects($this->once())->method('closeScheduled')->willReturn(false);
		$this->visits->expects($this->once())->method('exists')->with(42)->willReturn(true);

		try {
			$this->service->complete('tech', 42, []);
			$this->fail('expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('visit_not_open', $e->getErrorCode());
		}
	}

	public function testCompleteNotFoundWhenVisitMissingAfterLostClose(): void
	{
		$this->visits->method('closeScheduled')->willReturn(false);
		$this->visits->method('exists')->willReturn(false);

		$this->expectException(NotFoundException::class);
		$this->service->complete('tech', 99, []);
	}

	public function testCompletePassesDoneStatusToConditionalClose(): void
	{
		$closed = $this->visitEntity(7, 3, 'done');
		$plan = $this->planEntity(3, false, 'month', 1);

		$this->visits->expects($this->once())->method('closeScheduled')
			->with(7, $this->callback(static fn (array $set): bool => ($set['status'] ?? '') === Visit::STATUS_DONE))
			->willReturn(true);
		$this->visits->method('findById')->willReturn($closed);
		$this->plans->method('lockRow')->willReturn(true);
		$this->plans->method('findById')->willReturn($plan);

		$result = $this->service->complete('tech', 7, []);
		$this->assertSame('done', $result['visit']['status']);
	}

	public function testDbBoolRejectsPostgresFalseString(): void
	{
		$method = new \ReflectionMethod(VisitService::class, 'dbBool');
		$method->setAccessible(true);
		$this->assertFalse($method->invoke(null, 'f'), "(bool)'f' would be true — dbBool must reject it");
		$this->assertFalse($method->invoke(null, '0'));
		$this->assertTrue($method->invoke(null, 't'));
		$this->assertTrue($method->invoke(null, 1));
		$this->assertTrue($method->invoke(null, true));
		$this->assertFalse($method->invoke(null, false));
	}

	public function testCompleteSchedulesFollowUpWhenPlanActive(): void
	{
		$closed = $this->visitEntity(7, 3, 'done');
		$closed->setDoneOn('2026-07-24');
		$closed->setDoneBy('tech');
		$plan = $this->planEntity(3, true, 'month', 1);
		$next = $this->visitEntity(8, 3, 'scheduled');
		$next->setDueOn('2026-08-24');

		$this->visits->expects($this->once())->method('closeScheduled')->willReturn(true);
		$this->visits->method('findById')->with(7)->willReturn($closed);
		$this->plans->expects($this->once())->method('lockRow')->with(3)->willReturn(true);
		$this->plans->method('findById')->with(3)->willReturn($plan);
		$this->visits->method('findOpenByPlan')->with(3)->willReturn(null);
		$this->visits->expects($this->once())->method('insert')->willReturn($next);

		$result = $this->service->complete('tech', 7, ['notes' => 'ok']);
		$this->assertSame('done', $result['visit']['status']);
		$this->assertTrue($result['planActive']);
		$this->assertNotNull($result['nextVisit']);
		$this->assertSame('2026-08-24', $result['nextVisit']['dueOn']);
	}

	public function testCompleteOnInactivePlanSkipsFollowUp(): void
	{
		$closed = $this->visitEntity(11, 5, 'scheduled');
		$plan = $this->planEntity(5, false, 'week', 2);

		$this->visits->method('closeScheduled')->willReturn(true);
		$this->visits->method('findById')->willReturn($closed);
		$this->plans->expects($this->once())->method('lockRow')->with(5)->willReturn(true);
		$this->plans->method('findById')->willReturn($plan);
		$this->visits->expects($this->never())->method('insert');

		$result = $this->service->complete('tech', 11, []);
		$this->assertNull($result['nextVisit']);
		$this->assertFalse($result['planActive']);
	}

	public function testCompleteWhenPlanRowGoneSkipsFollowUp(): void
	{
		$closed = $this->visitEntity(40, 12, 'done');
		$this->visits->method('closeScheduled')->willReturn(true);
		$this->visits->method('findById')->willReturn($closed);
		$this->plans->expects($this->once())->method('lockRow')->with(12)->willReturn(false);
		$this->plans->expects($this->never())->method('findById');
		$this->visits->expects($this->never())->method('insert');

		$result = $this->service->complete('tech', 40, []);
		$this->assertNull($result['nextVisit']);
		$this->assertFalse($result['planActive']);
	}

	public function testCompleteWhenPlanDeactivatedUnderLockSkipsFollowUp(): void
	{
		$closed = $this->visitEntity(41, 13, 'done');
		$active = $this->planEntity(13, true, 'month', 1);
		$inactive = $this->planEntity(13, false, 'month', 1);

		$this->visits->method('closeScheduled')->willReturn(true);
		$this->visits->method('findById')->willReturn($closed);
		$this->plans->method('lockRow')->willReturn(true);
		// First stale read is no longer used; only the under-lock findById matters.
		$this->plans->method('findById')->willReturn($inactive);
		$this->visits->expects($this->never())->method('insert');

		$result = $this->service->complete('tech', 41, []);
		$this->assertNull($result['nextVisit']);
		$this->assertFalse($result['planActive']);
		unset($active);
	}

	public function testSkipAnchorsNextDueFromTodayNotDoneOn(): void
	{
		$closed = $this->visitEntity(21, 9, 'scheduled');
		$closed->setDueOn('2020-01-01');
		$plan = $this->planEntity(9, true, 'day', 10);
		$next = $this->visitEntity(22, 9, 'scheduled');
		$next->setDueOn('2026-08-03');

		$this->visits->method('closeScheduled')->willReturnCallback(
			function (int $id, array $set): bool {
				$this->assertSame(Visit::STATUS_SKIPPED, $set['status']);
				$this->assertSame('2026-07-24', $set['done_on'], 'skip must stamp done_on = today');
				return true;
			},
		);
		$this->visits->method('findById')->willReturn($closed);
		$this->plans->method('findById')->willReturn($plan);
		$this->plans->method('lockRow')->willReturn(true);
		$this->visits->method('findOpenByPlan')->willReturn(null);
		$this->visits->expects($this->once())->method('insert')->willReturnCallback(
			function (Visit $visit) use ($next): Visit {
				$this->assertSame('2026-08-03', $visit->getDueOn(), 'skip rolls from today + interval');
				return $next;
			},
		);

		$result = $this->service->skip('tech', 21, []);
		$this->assertSame('2026-08-03', $result['nextVisit']['dueOn']);
	}

	public function testCancelConflictsWhenNotOpen(): void
	{
		$this->visits->method('closeScheduled')->willReturn(false);
		$this->visits->method('exists')->willReturn(true);
		try {
			$this->service->cancel(30);
			$this->fail('expected conflict');
		} catch (ConflictException $e) {
			$this->assertSame('visit_not_open', $e->getErrorCode());
		}
	}

	public function testCancelIsTerminalWithoutFollowUp(): void
	{
		$cancelled = $this->visitEntity(30, 1, 'cancelled');
		$this->visits->expects($this->once())->method('closeScheduled')->willReturn(true);
		$this->visits->method('findById')->willReturn($cancelled);
		$this->visits->expects($this->never())->method('insert');

		$result = $this->service->cancel(30);
		$this->assertSame('cancelled', $result['visit']['status']);
		$this->assertNull($result['nextVisit']);
	}

	public function testAssignRejectsUnknownUser(): void
	{
		$this->users->method('userExists')->with('ghost')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->service->assign(1, ['userId' => 'ghost']);
	}

	public function testAssignClearsWithNull(): void
	{
		$visit = $this->visitEntity(4, 1, 'scheduled');
		$this->visits->expects($this->once())->method('updateScheduled')
			->with(4, $this->callback(static fn (array $set): bool => array_key_exists('assigned_uid', $set) && $set['assigned_uid'] === null))
			->willReturn(true);
		$this->visits->method('findById')->willReturn($visit);

		$result = $this->service->assign(4, ['userId' => null]);
		$this->assertSame(4, $result['id']);
	}

	public function testRescheduleConflictsWhenNotOpen(): void
	{
		$this->visits->method('updateScheduled')->willReturn(false);
		$this->visits->method('exists')->willReturn(true);
		try {
			$this->service->reschedule(55, ['dueOn' => '2026-08-01']);
			$this->fail('expected conflict');
		} catch (ConflictException $e) {
			$this->assertSame('visit_not_open', $e->getErrorCode());
		}
	}

	public function testCompleteRejectsFutureDoneOn(): void
	{
		$this->expectException(ValidationException::class);
		$this->service->complete('tech', 1, ['doneOn' => '2099-01-01']);
	}

	private function visitEntity(int $id, int $planId, string $status): Visit
	{
		$v = new Visit();
		$v->setId($id);
		$v->setPlanId($planId);
		$v->setEquipmentId(100);
		$v->setCustomerId(200);
		$v->setMaintTypeId(300);
		$v->setDueOn('2026-07-20');
		$v->setStatus($status);
		$v->setCreatedAt(1);
		$v->setUpdatedAt(1);
		return $v;
	}

	private function planEntity(int $id, bool $active, string $unit, int $count): Plan
	{
		$p = new Plan();
		$p->setId($id);
		$p->setEquipmentId(100);
		$p->setMaintTypeId(300);
		$p->setIntervalUnit($unit);
		$p->setIntervalCount($count);
		$p->setActive($active);
		$p->setHasContract(false);
		$p->setCreatedAt(1);
		$p->setUpdatedAt(1);
		$p->setCreatedBy('office');
		return $p;
	}
}
