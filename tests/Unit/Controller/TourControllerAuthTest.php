<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\TourController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\TourService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * CORE §7 / COMPANION S2: technicians may see their own day tour. The web
 * list/show endpoints must not dump every technician's stops, customer names
 * and coordinates to any L2 user who guesses `/api/tours` or a sequential id.
 */
final class TourControllerAuthTest extends TestCase
{
	private function controller(AccessControlService $access, TourService $tours): TourController
	{
		return new TourController(
			$this->createMock(IRequest::class),
			$access,
			$tours,
		);
	}

	public function testIndexScopesTechnicianToOwnTourEnvelope(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->with('tech1')->willReturn(false);
		$tours = $this->createMock(TourService::class);
		$tours->expects($this->once())->method('todayForTech')->with('tech1', '2026-08-18')
			->willReturn([
				'date' => '2026-08-18',
				'tour' => [
					'id' => 7,
					'techUid' => 'tech1',
					'stops' => [['workOrder' => ['customerName' => 'Mine']]],
				],
			]);
		$tours->expects($this->never())->method('forDate');

		$res = $this->controller($access, $tours)->index('2026-08-18');
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame('2026-08-18', $res->getData()['date']);
		$this->assertCount(1, $res->getData()['data']);
		$this->assertSame(7, $res->getData()['data'][0]['id']);
	}

	public function testIndexTechnicianWithNoTourGetsEmptyData(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->willReturn(false);
		$tours = $this->createMock(TourService::class);
		$tours->method('todayForTech')->willReturn(['date' => '2026-08-18', 'tour' => null]);

		$res = $this->controller($access, $tours)->index('2026-08-18');
		$this->assertSame([], $res->getData()['data']);
		$this->assertSame('2026-08-18', $res->getData()['date']);
	}

	public function testIndexOfficeSeesAllToursForTheDay(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->method('isOffice')->with('office1')->willReturn(true);
		$tours = $this->createMock(TourService::class);
		$tours->expects($this->once())->method('forDate')->with('2026-08-18')
			->willReturn([
				'data' => [
					['id' => 1, 'techUid' => 'tech-a'],
					['id' => 2, 'techUid' => 'tech-b'],
				],
				'date' => '2026-08-18',
			]);
		$tours->expects($this->never())->method('todayForTech');

		$res = $this->controller($access, $tours)->index('2026-08-18');
		$this->assertCount(2, $res->getData()['data']);
	}

	public function testShowRejectsAnotherTechniciansTour(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->willReturn(false);
		$tours = $this->createMock(TourService::class);
		$tours->method('get')->with(99)->willReturn([
			'id' => 99,
			'techUid' => 'tech-other',
			'stops' => [['workOrder' => ['customerName' => 'Secret GmbH', 'title' => 'Boiler']]],
		]);

		$this->expectException(PermissionDeniedException::class);
		$this->controller($access, $tours)->show(99);
	}

	public function testShowAllowsOwnTourAndOffice(): void
	{
		$techAccess = $this->createMock(AccessControlService::class);
		$techAccess->method('currentUserId')->willReturn('tech1');
		$techAccess->method('isOffice')->willReturn(false);
		$tours = $this->createMock(TourService::class);
		$tours->method('get')->with(7)->willReturn(['id' => 7, 'techUid' => 'tech1']);
		$own = $this->controller($techAccess, $tours)->show(7);
		$this->assertSame(7, $own->getData()['id']);

		$officeAccess = $this->createMock(AccessControlService::class);
		$officeAccess->method('currentUserId')->willReturn('office1');
		$officeAccess->method('isOffice')->willReturn(true);
		$officeTours = $this->createMock(TourService::class);
		$officeTours->method('get')->with(99)->willReturn(['id' => 99, 'techUid' => 'tech-other']);
		$asOffice = $this->controller($officeAccess, $officeTours)->show(99);
		$this->assertSame('tech-other', $asOffice->getData()['techUid']);
	}
}
