<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §7.3 catalogs (equipment types + maintenance types). Reads P2,
 * writes P6 (office). No DELETE routes exist — deactivate only (S11).
 */
class CatalogController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly CatalogService $catalogs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function equipTypes(?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->catalogs->list('equip', $limit, $offset));
	}

	#[NoAdminRequired]
	public function createEquipType(): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->catalogs->create('equip', $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateEquipType(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->catalogs->update('equip', $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function maintTypes(?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->catalogs->list('maint', $limit, $offset));
	}

	#[NoAdminRequired]
	public function createMaintType(): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->catalogs->create('maint', $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateMaintType(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->catalogs->update('maint', $id, $this->jsonBody()));
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
