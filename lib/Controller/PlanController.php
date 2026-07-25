<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §7.3 plans. Reads P2; create/update/deactivate/schedule are P5
 * (office). `schedule` (S14) is the recovery path after cancel.
 */
class PlanController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly PlanService $plans,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function indexForEquipment(int $id): JSONResponse
	{
		return new JSONResponse($this->plans->listForEquipment($id));
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse(
			$this->plans->create($uid, $id, $this->jsonBody()),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->plans->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function deactivate(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->plans->deactivate($id));
	}

	#[NoAdminRequired]
	public function schedule(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse(
			$this->plans->schedule($id, $this->jsonBody()),
			Http::STATUS_CREATED,
		);
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
