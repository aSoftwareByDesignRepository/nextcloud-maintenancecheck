<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Server;

/**
 * Mobile API v1 (SPEC §9). Rungs 1–2 of the gate are enforced by NC auth +
 * AppAccessMiddleware; the license/seat rungs 3–6 by MobileGateService.
 * `bootstrap` skips 3–6 and reports state instead (CLIENT-TRUST model).
 *
 * CSRF posture (N5): app routes cannot rely on OCS CSRF exemption for Login
 * Flow / app passwords, so routes are NoCSRFRequired. Mutations still reject
 * cookie-only requests that lack a valid requesttoken — the official app
 * always sends `Authorization: Basic` (app password), which cannot be forged
 * by a cross-site form. Cookie sessions must present the CSRF requesttoken.
 *
 * No office CRUD on mobile v1 — complete/skip only for mutations.
 */
class MobileController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly MobileGateService $gate,
		private readonly VisitService $visits,
		private readonly EquipmentService $equipment,
		private readonly CustomerService $customers,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$displayName = $this->userSession->getUser()?->getDisplayName() ?? $uid;
		return new JSONResponse($this->gate->bootstrapPayload($uid, $displayName, $this->access->isOffice($uid)));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function due(?string $mine = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->due($uid, $mine === '1'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipment(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->equipment->mobileSummary($id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function visits(
		?string $from = null,
		?string $to = null,
		?string $status = null,
		?string $mine = null,
		?string $customerId = null,
		?string $equipmentId = null,
		?string $planId = null,
		?string $limit = null,
		?string $offset = null,
	): JSONResponse {
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->list($uid, [
			'from' => $from,
			'to' => $to,
			'status' => $status,
			'mine' => $mine,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'planId' => $planId,
			'limit' => $limit,
			'offset' => $offset,
		]));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->complete($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function skip(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->skip($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function customers(?string $q = null, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->customers->list($q, $limit, $offset));
	}

	/**
	 * N5: mutations accept app-password Authorization OR a valid CSRF
	 * requesttoken. Cookie-only posts without a token are rejected.
	 */
	private function assertSafeMutationChannel(): void
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if ($auth !== '' && preg_match('/^(Basic|Bearer)\s+\S+/i', $auth) === 1) {
			return;
		}

		$token = trim((string)(
			$this->request->getHeader('requesttoken')
			?: $this->request->getParam('requesttoken')
			?: ''
		));
		if ($token !== '' && $this->isRequestTokenValid($token)) {
			return;
		}

		throw new PermissionDeniedException();
	}

	private function isRequestTokenValid(string $token): bool
	{
		try {
			/** @var \OC\Security\CSRF\CsrfTokenManager $manager */
			$manager = Server::get(\OC\Security\CSRF\CsrfTokenManager::class);
			return $manager->isTokenValid(new \OC\Security\CSRF\CsrfToken($token));
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['_route']);
		return $params;
	}
}
