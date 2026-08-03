<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** W6 equipment documents (CORE §20 AC-W6-4). */
class EquipDocController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly EquipDocService $docs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $equipmentId): JSONResponse
	{
		return new JSONResponse($this->docs->listForEquipment($equipmentId));
	}

	#[NoAdminRequired]
	public function create(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->request->getParams();
		unset($body['equipmentId'], $body['_route']);
		return new JSONResponse($this->docs->create($uid, $equipmentId, $body), Http::STATUS_CREATED);
	}

	/**
	 * NoCSRFRequired is intentional: document links are plain <a href>
	 * navigation (no requesttoken). Session auth + EquipDoc ACL still apply.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$file = $this->docs->readFileForDownload($uid, $id);
		return new DataDownloadResponse($file['content'], $file['name'], $file['mime']);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$this->docs->delete($id);
		return new JSONResponse(['ok' => true]);
	}
}
