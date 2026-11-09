<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\TourStopMapper;
use OCA\MaintenanceCheck\Db\Visit;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CalendarService;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * FA-2 calendar read model — window validation, scope branching,
 * truncation, sort order, office narrowing (spec §9 contract + §10 edges).
 */
final class CalendarServiceTest extends TestCase
{
	private WorkOrderMapper $wos;
	private VisitMapper $visits;
	private AccessControlService $access;
	private CalendarService $service;

	protected function setUp(): void
	{
		$this->wos = $this->createMock(WorkOrderMapper::class);
		$this->visits = $this->createMock(VisitMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->service = new CalendarService(
			$this->wos,
			$this->visits,
			$this->createMock(TourStopMapper::class),
			$this->dbMock(),
			$this->access,
			new IntervalCalculator(),
			$this->createMock(IURLGenerator::class),
		);
	}

	/**
	 * nameMap() hits IDBConnection for customer/equipment labels — mock the
	 * fluent QB chain so fetch() ends immediately and the map stays empty.
	 */
	private function dbMock(): IDBConnection
	{
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('in')->willReturn('');
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function wo(int $id, ?string $dueOn, ?string $dueUntil = null, ?string $uid = 'tech-a'): WorkOrder
	{
		$wo = new WorkOrder();
		$wo->setId($id);
		$wo->setNumber('WO-' . $id);
		$wo->setTitle('Job ' . $id);
		$wo->setKind(WorkOrder::KIND_PREVENTIVE);
		$wo->setStatus(WorkOrder::STATUS_PLANNED);
		$wo->setPriority('normal');
		$wo->setDueOn($dueOn);
		$wo->setDueUntil($dueUntil ?? $dueOn);
		$wo->setPrimaryUserId($uid);
		return $wo;
	}

	public function testMissingWindowParamsRejected(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->service->events('tech-a', []);
	}

	public function testInvalidDateRejected(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		foreach ([['from' => 'not-a-date', 'to' => '2026-11-30'], ['from' => '2026-11-01', 'to' => '2026-13-01']] as $query) {
			try {
				$this->service->events('tech-a', $query);
				$this->fail('expected 422 for ' . json_encode($query));
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testFromAfterToRejected(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->service->events('tech-a', ['from' => '2026-11-30', 'to' => '2026-11-01']);
	}

	public function testNinetyTwoDayWindowAllowed(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->wos->expects($this->once())
			->method('findForCalendar')
			->with('2026-11-01', '2027-01-31', 'tech-a', null, null, null, 1001)
			->willReturn([]);
		$this->visits->method('findForCalendar')->willReturn([]);
		$out = $this->service->events('tech-a', ['from' => '2026-11-01', 'to' => '2027-01-31']);
		$this->assertSame('2026-11-01', $out['from']);
		$this->assertFalse($out['truncated']);
	}

	public function testNinetyThreeDayWindowRejected(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->service->events('tech-a', ['from' => '2026-11-01', 'to' => '2027-02-01']);
	}

	public function testNonOfficeDropsAllFilterParams(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		// Adversarial: tech/kind/status params on a tech session must never
		// reach the mapper — scope is fixed server-side (US-CAL-05 AC-2).
		$this->wos->expects($this->once())
			->method('findForCalendar')
			->with('2026-11-01', '2026-11-30', 'tech-a', null, null, null, 1001)
			->willReturn([]);
		$this->visits->method('findForCalendar')->willReturn([]);
		$this->service->events('tech-a', [
			'from' => '2026-11-01',
			'to' => '2026-11-30',
			'tech' => 'admin',
			'kind' => 'corrective',
			'status' => 'blocked',
		]);
	}

	public function testOfficeNarrowsToTechList(): void
	{
		$this->access->method('isOffice')->willReturn(true);
		$this->wos->expects($this->once())
			->method('findForCalendar')
			->with('2026-11-01', '2026-11-30', null, ['tech-b'], null, null, 1001)
			->willReturn([]);
		$this->visits->method('findForCalendar')->willReturn([]);
		$this->service->events('office-1', [
			'from' => '2026-11-01', 'to' => '2026-11-30', 'tech' => 'tech-b',
		]);
	}

	public function testOfficeKindStatusFiltersValidate(): void
	{
		$this->access->method('isOffice')->willReturn(true);
		foreach (['kind' => 'bogus', 'status' => 'bogus'] as $param => $value) {
			try {
				$this->service->events('office-1', [
					'from' => '2026-11-01', 'to' => '2026-11-30', $param => $value,
				]);
				$this->fail('expected 422 for ' . $param);
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testEventsSortedByStartDayThenEntityThenId(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->wos->method('findForCalendar')->willReturn([
			$this->wo(2, '2026-11-16', '2026-11-18'),
			$this->wo(1, '2026-11-15'),
		]);
		$this->visits->method('findForCalendar')->willReturn([]);
		$out = $this->service->events('tech-a', ['from' => '2026-11-01', 'to' => '2026-11-30']);
		$this->assertSame([1, 2], array_column($out['events'], 'id'));
		$this->assertSame('2026-11-15', $out['events'][0]['startDay']);
		$this->assertSame('2026-11-18', $out['events'][1]['endDay']);
	}

	public function testTruncationFlagAboveCap(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$many = [];
		for ($i = 1; $i <= 1001; $i++) {
			$many[] = $this->wo($i, '2026-11-15');
		}
		$this->wos->method('findForCalendar')->willReturn($many);
		$this->visits->method('findForCalendar')->willReturn([]);
		$out = $this->service->events('tech-a', ['from' => '2026-11-01', 'to' => '2026-11-30']);
		$this->assertTrue($out['truncated']);
		$this->assertCount(1000, $out['events']);
	}

	public function testVisitEventShape(): void
	{
		$this->access->method('isOffice')->willReturn(false);
		$this->wos->method('findForCalendar')->willReturn([]);
		$visit = new Visit();
		$visit->setId(7);
		$visit->setStatus(Visit::STATUS_SCHEDULED);
		$visit->setDueOn('2026-11-16');
		$visit->setAssignedUid('tech-a');
		$visit->setEquipmentId(3);
		$visit->setCustomerId(9);
		$this->visits->method('findForCalendar')->willReturn([$visit]);
		$out = $this->service->events('tech-a', ['from' => '2026-11-01', 'to' => '2026-11-30']);
		$ev = $out['events'][0];
		$this->assertSame('visit', $ev['entity']);
		$this->assertSame(7, $ev['id']);
		$this->assertSame('2026-11-16', $ev['startDay']);
		$this->assertSame('2026-11-16', $ev['endDay']);
		$this->assertFalse($ev['unassigned']);
	}
}
