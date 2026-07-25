<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §7.3 equipment. Reads P2, writes P5 (office). Delete blocks with
 * 409 `equipment_in_use` when plans/visits exist (S10).
 */
class EquipmentController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly EquipmentService $equipment,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $customerId = null, ?string $q = null, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->equipment->list($customerId, $q, $limit, $offset));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->equipment->get($id));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse(
			$this->equipment->create($uid, $this->jsonBody()),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->equipment->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$this->equipment->delete($id);
		return new JSONResponse(['deleted' => true]);
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
