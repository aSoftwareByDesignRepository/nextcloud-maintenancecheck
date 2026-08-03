<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\DueQueryKind;
use OCA\MaintenanceCheck\Service\VisitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SPEC §7.3 visits. Permission split per §3:
 *  - P2 reads: due board + list (all app users)
 *  - P3 complete/skip: all app users (technicians work on site)
 *  - P4 reschedule (S15) / cancel / assign: office only
 */
class VisitController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly VisitService $visits,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(
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
		return new JSONResponse($this->visits->list($this->access->currentUserId(), [
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
	public function due(?string $mine = null, ?string $kind = null, ?string $filter = null): JSONResponse
	{
		return new JSONResponse($this->visits->due(
			$this->access->currentUserId(),
			$mine === '1',
			DueQueryKind::resolve($kind, $filter),
		));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->visits->get($this->access->currentUserId(), $id));
	}

	#[NoAdminRequired]
	public function complete(int $id): JSONResponse
	{
		return new JSONResponse($this->visits->complete($this->access->currentUserId(), $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function skip(int $id): JSONResponse
	{
		return new JSONResponse($this->visits->skip($this->access->currentUserId(), $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->visits->cancel($id));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->visits->reschedule($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function assign(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->visits->assign($id, $this->jsonBody()));
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
