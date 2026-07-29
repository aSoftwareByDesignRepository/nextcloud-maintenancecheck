<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CapacityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W4 per-user daily capacity. Office manages; assessment is available to
 * every app user (the dispatch UI shows it read-only).
 */
class CapacityController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly CapacityService $capacity,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse($this->capacity->list());
	}

	#[NoAdminRequired]
	public function set(string $uid): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireOffice($actor);
		$body = $this->request->getParams();
		unset($body['uid'], $body['_route']);
		return new JSONResponse($this->capacity->set($actor, $uid, $body));
	}
}
