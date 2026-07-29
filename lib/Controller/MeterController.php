<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\MeterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W5 meters + readings. Meter definitions: office. Posting a reading:
 * every app user (UC-METER — the technician reads the counter on site).
 */
class MeterController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly MeterService $meters,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function indexForEquipment(int $equipmentId): JSONResponse
	{
		return new JSONResponse($this->meters->listForEquipment($equipmentId));
	}

	#[NoAdminRequired]
	public function create(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->meters->create($uid, $equipmentId, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->meters->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$this->meters->delete($id);
		return new JSONResponse(['deleted' => true]);
	}

	#[NoAdminRequired]
	public function readings(int $id, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->meters->listReadings($id, ['limit' => $limit, 'offset' => $offset]));
	}

	#[NoAdminRequired]
	public function addReading(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->meters->addReading($uid, $id, $this->jsonBody()), Http::STATUS_CREATED);
	}

	/**
	 * W5 contender: CSV import of readings for one equipment (SOURCE_IMPORT).
	 * Body: `{ "csv": "meter_code,value,read_on,note\\n…" }`
	 */
	#[NoAdminRequired]
	public function importCsv(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->jsonBody();
		$csv = is_string($body['csv'] ?? null) ? (string)$body['csv'] : '';
		if (trim($csv) === '') {
			throw new \OCA\MaintenanceCheck\Exception\ValidationException(
				'validation_failed',
				'csv is required.',
				[['field' => 'csv', 'code' => 'required']],
			);
		}
		return new JSONResponse($this->meters->importCsv($uid, $equipmentId, $csv), Http::STATUS_CREATED);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['equipmentId'], $params['_route']);
		return $params;
	}
}
