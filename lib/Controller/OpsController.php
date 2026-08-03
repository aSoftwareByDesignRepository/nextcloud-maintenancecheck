<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W6 ops surfaces: KPI, exception board, failure codes, reminder dry-run.
 */
class OpsController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly KpiService $kpi,
		private readonly ExceptionBoardService $exceptions,
		private readonly FailureCodeService $failureCodes,
		private readonly OverdueReminderService $reminders,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function kpi(?string $days = null): JSONResponse
	{
		$window = 30;
		if ($days !== null && $days !== '' && preg_match('/^\d+$/', $days)) {
			$window = (int)$days;
		}
		if (!in_array($window, [30, 90], true)) {
			$window = 30;
		}
		return new JSONResponse($this->kpi->snapshot($window));
	}

	/**
	 * NoCSRFRequired is intentional: KPI CSV is opened via <a href> (no
	 * requesttoken). Session auth + office ACL still apply.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kpiCsv(?string $days = null): DataDownloadResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$window = 30;
		if ($days !== null && $days !== '' && preg_match('/^\d+$/', $days)) {
			$window = (int)$days;
		}
		$csv = $this->kpi->toCsv($window);
		return new DataDownloadResponse($csv, 'maintenancecheck-kpi.csv', 'text/csv');
	}

	#[NoAdminRequired]
	public function exceptions(?string $filter = null, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->exceptions->list($limit, $offset, $filter));
	}

	#[NoAdminRequired]
	public function failureCodes(?string $limit = null, ?string $offset = null, ?string $active = null): JSONResponse
	{
		return new JSONResponse($this->failureCodes->list($limit, $offset, $active === '1'));
	}

	#[NoAdminRequired]
	public function createFailureCode(): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->failureCodes->create($this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateFailureCode(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->failureCodes->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function reminderDryRun(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->reminders->run(true));
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
