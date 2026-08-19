<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\MobileController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\InspectionObligationService;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\MeterService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\TourService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Official companion sends Basic loginName:appPassword. A cookie session plus
 * `Authorization: Bearer garbage` must not skip CSRF.
 */
final class MobileControllerCsrfTest extends TestCase
{
	private function invokeChannel(IRequest $request, IUserSession $session, IUserManager $users): void
	{
		$controller = new MobileController(
			$request,
			$session,
			$this->createMock(AccessControlService::class),
			$this->createMock(MobileGateService::class),
			$this->createMock(VisitService::class),
			$this->createMock(EquipmentService::class),
			$this->createMock(CustomerService::class),
			$this->createMock(WorkOrderService::class),
			$this->createMock(WoChecklistService::class),
			$this->createMock(WoEvidenceService::class),
			$this->createMock(WoPdfService::class),
			$this->createMock(KitService::class),
			$this->createMock(TourService::class),
			$this->createMock(MeterService::class),
			$this->createMock(WoCommentService::class),
			$this->createMock(EquipDocService::class),
			$this->createMock(FailureCodeService::class),
			$this->createMock(ExceptionBoardService::class),
			$this->createMock(InspectionObligationService::class),
			$users,
		);
		$method = new ReflectionMethod(MobileController::class, 'assertSafeMutationChannel');
		$method->setAccessible(true);
		$method->invoke($controller);
	}

	public function testBearerGarbageDoesNotSkipCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Bearer definitely-not-a-token');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->expectException(PermissionDeniedException::class);
		$this->invokeChannel($request, $session, $this->createMock(IUserManager::class));
	}

	public function testForgedBasicDoesNotSkipCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Basic ' . base64_encode('tech1:wrong-password'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tech1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('checkPassword')->willReturn(false);

		$this->expectException(PermissionDeniedException::class);
		$this->invokeChannel($request, $session, $users);
	}

	public function testValidBasicMatchingSessionSkipsCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Basic ' . base64_encode('tech1:app-password'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tech1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$authed = $this->createMock(IUser::class);
		$authed->method('getUID')->willReturn('tech1');
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('checkPassword')->with('tech1', 'app-password')->willReturn($authed);

		$this->invokeChannel($request, $session, $users);
		$this->addToAssertionCount(1);
	}

	public function testValidCsrfPassesWithoutAuthorization(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->expects($this->never())->method('getHeader');
		$session = $this->createMock(IUserSession::class);
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('checkPassword');

		$this->invokeChannel($request, $session, $users);
		$this->addToAssertionCount(1);
	}

	public function testCookieOnlyWithoutCsrfIsRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('');
		$session = $this->createMock(IUserSession::class);

		$this->expectException(PermissionDeniedException::class);
		$this->invokeChannel($request, $session, $this->createMock(IUserManager::class));
	}
}
