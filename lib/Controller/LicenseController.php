<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §8.2 — all endpoints P8 (app admin).
 */
class LicenseController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly LicenseService $license,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->license->status());
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$key = $this->request->getParam('key');
		if (!is_string($key) || trim($key) === '') {
			throw new ValidationException('license_invalid', 'A license key is required.');
		}
		return new JSONResponse($this->license->apply($uid, $key));
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->license->remove());
	}

	#[NoAdminRequired]
	public function seats(?string $limit = null, ?string $offset = null): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->license->listSeats($limit, $offset));
	}

	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$result = $this->license->assignSeat($uid, $this->request->getParam('userId'));
		// SPEC §8.2: new seat → 201; idempotent re-assign of an existing seat → 200.
		return new JSONResponse(
			$result['seat'],
			$result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK,
		);
	}

	#[NoAdminRequired]
	public function removeSeat(string $uid): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$this->license->removeSeat($uid);
		return new JSONResponse(['deleted' => true]);
	}
}
