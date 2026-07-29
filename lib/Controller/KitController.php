<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\KitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W2 kits. Template CRUD + kit structure (attach/add/remove lines): office.
 * Packing line quantities: assigned technician / pool / office.
 */
class KitController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly KitService $kits,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// ── Templates ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function indexTemplates(?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->kits->listTemplates($limit, $offset));
	}

	#[NoAdminRequired]
	public function showTemplate(int $id): JSONResponse
	{
		return new JSONResponse($this->kits->getTemplate($id));
	}

	#[NoAdminRequired]
	public function createTemplate(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->kits->createTemplate($uid, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateTemplate(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->kits->updateTemplate($uid, $id, $this->jsonBody()));
	}

	// ── WO kit instance ─────────────────────────────────────────────────

	#[NoAdminRequired]
	public function attach(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->kits->attachKit($uid, $id, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function addLine(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->kits->addLine($uid, $id, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function packLine(int $id, int $lineId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->kits->packLine($uid, $id, $lineId, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function removeLine(int $id, int $lineId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->kits->removeLine($uid, $id, $lineId));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['lineId'], $params['_route']);
		return $params;
	}
}
