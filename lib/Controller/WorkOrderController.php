<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W1–W3 work-order HTTP surface. Permission split (CORE §7):
 *  - reads + execute (transition / checklist / photos / signature): assigned
 *    tech, helpers, unassigned pool, or office
 *  - create corrective (draft): technicians; create any kind / from-visit /
 *    update / assign / required skills: office
 * Errors are mapped centrally by AppAccessMiddleware — controllers stay
 * envelope-free.
 */
class WorkOrderController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly WorkOrderService $workOrders,
		private readonly WoChecklistService $checklist,
		private readonly WoEvidenceService $evidence,
		private readonly SkillService $skills,
		private readonly WoPdfService $pdf,
		private readonly WoCommentService $comments,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// ── Core ────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function index(
		?string $status = null,
		?string $open = null,
		?string $kind = null,
		?string $priority = null,
		?string $customerId = null,
		?string $equipmentId = null,
		?string $mine = null,
		?string $from = null,
		?string $to = null,
		?string $q = null,
		?string $limit = null,
		?string $offset = null,
	): JSONResponse {
		return new JSONResponse($this->workOrders->list($this->access->currentUserId(), [
			'status' => $status,
			'open' => $open,
			'kind' => $kind,
			'priority' => $priority,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'mine' => $mine,
			'from' => $from,
			'to' => $to,
			'q' => $q,
			'limit' => $limit,
			'offset' => $offset,
		]));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->workOrders->get($id, $uid));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$isOffice = $this->access->isOffice($uid);
		return new JSONResponse(
			$this->workOrders->create($uid, $this->jsonBody(), $isOffice),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function createFromVisit(int $visitId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->workOrders->createFromVisit($uid, $visitId, $this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->workOrders->update($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function assign(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->workOrders->assign($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function transition(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->workOrders->transition($uid, $id, $this->jsonBody(), $this->access->isOffice($uid)));
	}

	// ── Checklist (W1) ──────────────────────────────────────────────────

	#[NoAdminRequired]
	public function setChecklistResult(int $id, string $itemCode): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$body = $this->jsonBody();
		unset($body['itemCode']);
		return new JSONResponse($this->checklist->setResult($uid, $id, $itemCode, $body));
	}

	// ── Required skills (W2, office) ────────────────────────────────────

	#[NoAdminRequired]
	public function setSkills(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->skills->setWoSkills($id, $this->jsonBody()));
	}

	// ── Photos (W1) ─────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listPhotos(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse(['data' => $this->evidence->listPhotos($id, $uid)]);
	}

	#[NoAdminRequired]
	public function addPhoto(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$file = $this->request->getUploadedFile('photo');
		if ($file === null || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === ''
			|| (int)($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			throw new ValidationException('invalid_photo', 'Upload a photo as multipart field "photo".');
		}
		$content = (string)file_get_contents($file['tmp_name']);
		$originalName = is_string($file['name'] ?? null) ? $file['name'] : null;
		return new JSONResponse($this->evidence->addPhoto($uid, $id, $content, $originalName), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function downloadPhoto(int $id, int $photoId): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->workOrders->get($id, $uid);
		$photo = $this->evidence->readPhoto($id, $photoId);
		return new DataDownloadResponse($photo['content'], $photo['name'], $photo['mime']);
	}

	#[NoAdminRequired]
	public function deletePhoto(int $id, int $photoId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->evidence->deletePhoto($id, $photoId, $uid);
		return new JSONResponse(['deleted' => true]);
	}

	// ── Signature (W3) ──────────────────────────────────────────────────

	#[NoAdminRequired]
	public function setSignature(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$body = $this->jsonBody();
		$raw = $body['imageBase64'] ?? null;
		if (!is_string($raw) || $raw === '') {
			throw new ValidationException('invalid_signature', 'Send the signature PNG as base64 in "imageBase64".');
		}
		// Accept both raw base64 and data-URI form from the canvas.
		if (str_starts_with($raw, 'data:')) {
			$comma = strpos($raw, ',');
			$raw = $comma !== false ? substr($raw, $comma + 1) : '';
		}
		$png = base64_decode($raw, true);
		if ($png === false || $png === '') {
			throw new ValidationException('invalid_signature', 'The signature image is not valid base64.');
		}
		$signerName = is_string($body['signerName'] ?? null) ? $body['signerName'] : null;
		return new JSONResponse($this->evidence->setSignature($uid, $id, $png, $signerName));
	}

	#[NoAdminRequired]
	public function downloadSignature(int $id): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->workOrders->get($id, $uid);
		$signature = $this->evidence->readSignature($id);
		return new DataDownloadResponse($signature['content'], $signature['name'], $signature['mime']);
	}

	// ── Comments (W6) ───────────────────────────────────────────────────

	#[NoAdminRequired]
	public function comments(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->workOrders->get($id, $uid);
		return new JSONResponse($this->comments->list($id));
	}

	#[NoAdminRequired]
	public function addComment(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$isOffice = $this->access->isOffice($uid);
		return new JSONResponse(
			$this->comments->create($uid, $id, $this->jsonBody(), $isOffice),
			Http::STATUS_CREATED,
		);
	}

	// ── PDFs (W3) ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function jobPackPdf(int $id): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->workOrders->get($id, $uid);
		$pdf = $this->pdf->jobPack($id);
		return new DataDownloadResponse($pdf['content'], $pdf['filename'] ?? $pdf['name'], $pdf['mime'] ?? $pdf['contentType']);
	}

	#[NoAdminRequired]
	public function serviceberichtPdf(int $id): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->workOrders->get($id, $uid);
		$pdf = $this->pdf->servicebericht($id);
		return new DataDownloadResponse($pdf['content'], $pdf['filename'] ?? $pdf['name'], $pdf['mime'] ?? $pdf['contentType']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['visitId'], $params['photoId'], $params['_route']);
		return $params;
	}
}
