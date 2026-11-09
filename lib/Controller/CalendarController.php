<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CalendarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * FA-2 technician calendar (spec §9 `/api/calendar`). Read-only GET; the
 * L2 gate runs in AppAccessMiddleware, scoping in CalendarService.
 */
class CalendarController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly CalendarService $calendar,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $from = null, ?string $to = null): JSONResponse
	{
		// Filter params are read via getParam(): they accept CSV strings AND
		// `tech[]=…` arrays — a typed signature cannot express both shapes.
		return new JSONResponse($this->calendar->events($this->access->currentUserId(), [
			'from' => $from,
			'to' => $to,
			'tech' => $this->request->getParam('tech'),
			'kind' => $this->request->getParam('kind'),
			'status' => $this->request->getParam('status'),
		]));
	}
}
