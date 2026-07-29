<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SiteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W1 sites under a customer. Reads: every app user. Mutations: office.
 */
class SiteController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly SiteService $sites,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function indexForCustomer(int $customerId): JSONResponse
	{
		return new JSONResponse($this->sites->listForCustomer($customerId));
	}

	#[NoAdminRequired]
	public function create(int $customerId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->sites->create($uid, $customerId, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->sites->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$this->sites->delete($id);
		return new JSONResponse(['deleted' => true]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['customerId'], $params['_route']);
		return $params;
	}
}
