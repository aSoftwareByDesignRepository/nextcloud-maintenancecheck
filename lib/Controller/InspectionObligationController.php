<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\EquipmentClassService;
use OCA\MaintenanceCheck\Service\InspectionObligationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W7 equipment classes + inspection obligations (CORE §21 AC-W7-1/2).
 */
class InspectionObligationController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly InspectionObligationService $obligations,
		private readonly EquipmentClassService $classes,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function classes(): JSONResponse
	{
		$this->access->currentUserId();
		return new JSONResponse($this->classes->list());
	}

	#[NoAdminRequired]
	public function index(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse([
			'data' => $this->obligations->listForEquipment($uid, $equipmentId),
		]);
	}

	#[NoAdminRequired]
	public function create(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$body = $this->request->getParams();
		unset($body['equipmentId'], $body['_route']);
		return new JSONResponse(
			$this->obligations->create($uid, $equipmentId, $body),
			Http::STATUS_CREATED,
		);
	}
}
