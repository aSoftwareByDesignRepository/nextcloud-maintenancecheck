<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ExceptionBoardServiceTest extends TestCase
{
	public function testSkillsMissingReasonWhenAssigneeLacksSkills(): void
	{
		$row = [
			'id' => 7,
			'number' => 'WO-1',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'status' => WorkOrder::STATUS_READY,
			'priority' => WorkOrder::PRIORITY_NORMAL,
			'title' => 'Fix',
			'due_on' => '2099-01-01',
			'primary_user_id' => 'tech1',
			'kit_override' => 0,
			'block_reason_code' => null,
			'customer_id' => 1,
			'equipment_id' => 2,
		];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls($row, false);
		$result->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn(new class {
			public function in($a, $b) { return 'in'; }
			public function eq($a, $b) { return 'eq'; }
		});
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$kits = $this->createMock(KitService::class);
		$kits->method('readinessFor')->willReturn(['hasKit' => false, 'ready' => true, 'missing' => []]);

		$skills = $this->createMock(SkillService::class);
		$skills->method('missingSkillsFor')->with(7, 'tech1')->willReturn([
			['id' => 1, 'code' => 'gas', 'name' => 'Gas'],
		]);

		$policies = $this->createMock(PolicyService::class);
		$policies->method('skillsEnforcement')->willReturn(PolicyService::ENFORCEMENT_BLOCK);

		$clock = $this->createMock(Clock::class);
		$clock->method('today')->willReturn('2026-08-01');

		$service = new ExceptionBoardService(
			$db,
			$kits,
			$skills,
			$policies,
			$clock,
			new InputValidator($this->createMock(IntervalCalculator::class)),
		);
		$payload = $service->list('50', '0', 'skills');
		$this->assertSame(1, $payload['total']);
		$this->assertContains('skills_missing', $payload['data'][0]['exceptionReasons']);
	}
}
