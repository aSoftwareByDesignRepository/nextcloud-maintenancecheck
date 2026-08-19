<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\TourService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W3 day tours. Office reads every tour of the day. Technicians read only
 * their own tour (same envelope shape). Mutations: office (dispatch owns
 * the day plan).
 */
class TourController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly TourService $tours,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $date = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		if (!$this->access->isOffice($uid)) {
			$mine = $this->tours->todayForTech($uid, $date);
			return new JSONResponse([
				'data' => $mine['tour'] !== null ? [$mine['tour']] : [],
				'date' => $mine['date'],
			]);
		}
		return new JSONResponse($this->tours->forDate($date));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$tour = $this->tours->get($id);
		if (!$this->access->isOffice($uid) && (string)($tour['techUid'] ?? '') !== $uid) {
			throw new PermissionDeniedException();
		}
		return new JSONResponse($tour);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->tours->create($uid, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->tours->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$this->tours->delete($id);
		return new JSONResponse(['deleted' => true]);
	}

	#[NoAdminRequired]
	public function addStop(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->tours->addStop($id, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function removeStop(int $id, int $stopId): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->tours->removeStop($id, $stopId));
	}

	#[NoAdminRequired]
	public function reorder(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->tours->reorder($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function suggestOrder(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->tours->suggestOrder($id));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['stopId'], $params['_route']);
		return $params;
	}
}
