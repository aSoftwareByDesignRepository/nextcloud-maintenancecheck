<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §7.3 customers. Reads are P2 (L2 gate in middleware); writes are P5
 * (office). Domain exceptions are translated by AppAccessMiddleware.
 */
class CustomerController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly CustomerService $customers,
		private readonly \OCA\MaintenanceCheck\Public\CrmFieldCustomerFacade $fieldIdentity,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $q = null, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->customers->list($q, $limit, $offset));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->customers->get($id));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse(
			$this->customers->create($uid, $this->jsonBody()),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->customers->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function destroy(int $id, ?string $force = null): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->customers->delete($id, $force === '1'));
	}

	/**
	 * @return array<string, mixed>
	 */

	#[NoAdminRequired]
	public function ensureLink(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->jsonBody();
		$pc = array_key_exists('pcCustomerId', $body) ? (int)$body['pcCustomerId'] : null;
		$crm = array_key_exists('crmCompanyId', $body) ? (int)$body['crmCompanyId'] : null;
		$updatedAt = isset($body['updatedAt']) ? (int)$body['updatedAt'] : null;
		return new JSONResponse($this->fieldIdentity->ensureLink($id, $uid, $pc, $crm, $updatedAt));
	}

	#[NoAdminRequired]
	public function unlinkIdentity(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->jsonBody();
		$clearPc = !empty($body['clearPc']) || !empty($body['unlinkPc']);
		$clearCrm = !empty($body['clearCrm']) || !empty($body['unlinkCrm']);
		if (!$clearPc && !$clearCrm) {
			$clearPc = true;
			$clearCrm = true;
		}
		$updatedAt = isset($body['updatedAt']) ? (int)$body['updatedAt'] : null;
		return new JSONResponse($this->fieldIdentity->unlink($id, $uid, $clearPc, $clearCrm, $updatedAt));
	}

	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['_route']);
		return $params;
	}
}
