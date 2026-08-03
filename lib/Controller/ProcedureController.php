<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\PackSchema;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W1 procedures (checklist templates) + W1 pack export / W3 pack import.
 * Reads: every app user. Mutations and packs: office.
 */
class ProcedureController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly ProcedureService $procedures,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(
		?string $limit = null,
		?string $offset = null,
		?string $vertical = null,
		?string $active = null,
	): JSONResponse {
		return new JSONResponse($this->procedures->list($limit, $offset, $vertical, $active));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->procedures->get($id));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->procedures->create($uid, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->procedures->update($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function fork(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->procedures->fork($uid, $id), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$this->procedures->delete($id);
		return new JSONResponse(['deleted' => true]);
	}

	/**
	 * NoCSRFRequired is intentional: pack export opens via window.open /
	 * <a href> (no requesttoken). Session auth + office ACL still apply.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportPack(?string $pack = null, ?string $vertical = null): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$packData = $this->procedures->exportPack($pack, $vertical);
		$json = json_encode($packData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$name = preg_replace('/[^A-Za-z0-9\-_.]/', '_', (string)($packData['pack_code'] ?? 'procedures')) ?: 'procedures';
		return new DataDownloadResponse((string)$json, $name . '.json', 'application/json');
	}

	#[NoAdminRequired]
	public function importPack(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);

		$overwrite = false;
		$params = $this->request->getParams();
		if (($params['overwrite'] ?? null) === '1' || ($params['overwrite'] ?? null) === true) {
			$overwrite = true;
		}

		// Pack arrives as an uploaded JSON file or as the raw `packJson` body
		// field — both capped by PackSchema::MAX_RAW_BYTES.
		$file = $this->request->getUploadedFile('pack');
		if ($file !== null && is_string($file['tmp_name'] ?? null) && $file['tmp_name'] !== ''
			&& (int)($file['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_OK) {
			if ((int)($file['size'] ?? 0) > PackSchema::MAX_RAW_BYTES) {
				throw new ValidationException('pack_too_large', 'The pack file is too large.');
			}
			$raw = (string)file_get_contents($file['tmp_name']);
		} else {
			$raw = is_string($params['packJson'] ?? null) ? $params['packJson'] : '';
		}
		if ($raw === '') {
			throw new ValidationException('pack_invalid', 'Upload a pack file or send packJson.');
		}
		return new JSONResponse($this->procedures->importPack($uid, $raw, $overwrite));
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
