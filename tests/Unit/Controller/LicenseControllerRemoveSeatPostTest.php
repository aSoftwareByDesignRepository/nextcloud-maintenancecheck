<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\LicenseController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/license/seats/remove (license#removeSeatPost) is the shared
 * license page's twin of DELETE /api/license/seats/{uid}: the pack posts
 * {userId} to a collection route. Validation must trim before the empty
 * check — a whitespace-only uid is a 422 uid_required, never a 404 or a
 * silent no-op.
 */
final class LicenseControllerRemoveSeatPostTest extends TestCase
{
	private function controller(IRequest $request, ?AccessControlService $access = null, ?LicenseService $license = null): LicenseController
	{
		return new LicenseController(
			$request,
			$access ?? $this->createMock(AccessControlService::class),
			$license ?? $this->createMock(LicenseService::class),
		);
	}

	private function adminAccess(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('admin1');
		$access->method('requireAppAdmin')->with('admin1');
		return $access;
	}

	private function request(array $params): IRequest
	{
		$req = $this->createMock(IRequest::class);
		$req->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return $params[$key] ?? $default;
			}
		);
		return $req;
	}

	public function testDeniesNonAdmin(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('requireAppAdmin')->with('tech1')->willThrowException(new PermissionDeniedException());
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('removeSeat');
		$this->expectException(PermissionDeniedException::class);
		$this->controller($this->request(['userId' => 'bob']), $access, $license)->removeSeatPost();
	}

	public function testMissingUidIsUidRequired(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('removeSeat');
		try {
			$this->controller($this->request([]), $this->adminAccess(), $license)->removeSeatPost();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('uid_required', $e->getErrorCode());
		}
	}

	public function testWhitespaceUidIsUidRequiredNot404(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('removeSeat');
		try {
			$this->controller($this->request(['userId' => "  \t "]), $this->adminAccess(), $license)->removeSeatPost();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('uid_required', $e->getErrorCode());
		}
	}

	public function testUidParamFallbackWorks(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())->method('removeSeat')->with('bob');
		$res = $this->controller($this->request(['uid' => 'bob']), $this->adminAccess(), $license)->removeSeatPost();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['ok' => true, 'deleted' => true], $res->getData());
	}

	public function testRemovesTrimmedUserId(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())->method('removeSeat')->with('bob');
		$res = $this->controller($this->request(['userId' => '  bob ']), $this->adminAccess(), $license)->removeSeatPost();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['ok' => true, 'deleted' => true], $res->getData());
	}
}
