<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\DueQueryKind;
use OCA\MaintenanceCheck\Service\EquipDocService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\ExceptionBoardService;
use OCA\MaintenanceCheck\Service\FailureCodeService;
use OCA\MaintenanceCheck\Service\KitService;
use OCA\MaintenanceCheck\Service\MeterService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\TourService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Server;

/**
 * Mobile API v1 (SPEC §9 + COMPANION §9.2). Rungs 1–2 of the gate are enforced
 * by NC auth + AppAccessMiddleware; license/seat rungs 3–6 by MobileGateService.
 * `bootstrap` skips 3–6 and reports state + capabilities instead.
 *
 * CSRF posture (N5): mutations reject cookie-only requests that lack a valid
 * requesttoken; app-password Authorization is accepted.
 */
class MobileController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly MobileGateService $gate,
		private readonly VisitService $visits,
		private readonly EquipmentService $equipment,
		private readonly CustomerService $customers,
		private readonly WorkOrderService $workOrders,
		private readonly WoChecklistService $checklist,
		private readonly WoEvidenceService $evidence,
		private readonly WoPdfService $pdf,
		private readonly KitService $kits,
		private readonly TourService $tours,
		private readonly MeterService $meters,
		private readonly WoCommentService $comments,
		private readonly EquipDocService $equipDocs,
		private readonly FailureCodeService $failureCodes,
		private readonly ExceptionBoardService $exceptions,
		private readonly \OCA\MaintenanceCheck\Service\InspectionObligationService $obligations,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$displayName = $this->userSession->getUser()?->getDisplayName() ?? $uid;
		return new JSONResponse($this->gate->bootstrapPayload($uid, $displayName, $this->access->isOffice($uid)));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function due(?string $mine = null, ?string $kind = null, ?string $filter = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->due(
			$uid,
			$mine === '1',
			DueQueryKind::resolve($kind, $filter),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipment(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->equipment->mobileSummary($id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentByQr(string $token): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->equipment->resolveByQr($token));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function visits(
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
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->list($uid, [
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
	#[NoCSRFRequired]
	public function visit(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->get($uid, $id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->complete($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function skip(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->visits->skip($uid, $id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function customers(?string $q = null, ?string $limit = null, ?string $offset = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->customers->list($q, $limit, $offset));
	}

	// ── Work orders (COMPANION S1) ──────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrders(
		?string $status = null,
		?string $open = null,
		?string $mine = null,
		?string $limit = null,
		?string $offset = null,
	): JSONResponse {
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->workOrders->list($uid, [
			'status' => $status,
			'open' => $open ?? '1',
			'mine' => $mine ?? '1',
			'limit' => $limit,
			'offset' => $offset,
		]));
	}

	/**
	 * UC-PRUEF — create or open the inspection (or preventive) WO for a visit.
	 * Idempotent: concurrent field taps return the same work order.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createWorkOrderFromVisit(int $visitId): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$created = $this->workOrders->openOrCreateFromVisit($uid, $visitId, $this->jsonBody());
		return new JSONResponse($created, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrder(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->workOrders->get($id, $uid));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderTransition(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->workOrders->transition(
			$uid,
			$id,
			$this->jsonBody(),
			$this->access->isOffice($uid),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderChecklist(int $id, string $itemCode): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->checklist->setResult($uid, $id, $itemCode, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderPhotos(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse(['data' => $this->evidence->listPhotos($id, $uid)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderAddPhoto(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$content = $this->readUploadedBinary();
		$name = $this->request->getUploadedFile('file')['name']
			?? $this->request->getParam('fileName');
		return new JSONResponse(
			$this->evidence->addPhoto($uid, $id, $content, is_string($name) ? $name : null),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderKit(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$this->workOrders->get($id, $uid);
		$kit = $this->kits->kitFor($id);
		return new JSONResponse(['kit' => $kit, 'readiness' => $this->kits->readinessFor($id)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderPackLine(int $id, int $lineId): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->kits->packLine($uid, $id, $lineId, $this->jsonBody()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderSignature(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$body = $this->jsonBody();
		$raw = $body['imageBase64'] ?? null;
		if (!is_string($raw) || $raw === '') {
			throw new ValidationException('invalid_signature', 'Send the signature PNG as base64 in "imageBase64".');
		}
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
	#[NoCSRFRequired]
	public function servicebericht(int $id): DataDownloadResponse|JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$this->workOrders->get($id, $uid);
		$pdf = $this->pdf->servicebericht($id);
		return new DataDownloadResponse($pdf['content'], $pdf['filename'] ?? $pdf['name'], $pdf['mime'] ?? $pdf['contentType']);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inspectionEvidence(int $id): DataDownloadResponse|JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$this->workOrders->get($id, $uid);
		$pdf = $this->pdf->inspectionEvidence($id);
		return new DataDownloadResponse($pdf['content'], $pdf['filename'] ?? $pdf['name'], $pdf['mime'] ?? $pdf['contentType']);
	}

	/** COMP §9.2 path alias. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inspectionEvidenceAlias(int $id): DataDownloadResponse|JSONResponse
	{
		return $this->inspectionEvidence($id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentObligations(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse([
			'data' => $this->obligations->listForEquipment($uid, $equipmentId),
		]);
	}

	// ── Tours + meters ──────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tourToday(?string $date = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->tours->todayForTech($uid, $date));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentMeters(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->meters->listForEquipment($equipmentId));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function addMeterReading(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->meters->addReading($uid, $id, $this->jsonBody()), Http::STATUS_CREATED);
	}

	/**
	 * N5: mutations accept app-password Authorization OR a valid CSRF
	 * requesttoken. Cookie-only posts without a token are rejected.
	 */
	private function assertSafeMutationChannel(): void
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if ($auth !== '' && preg_match('/^(Basic|Bearer)\s+\S+/i', $auth) === 1) {
			return;
		}

		$token = trim((string)(
			$this->request->getHeader('requesttoken')
			?: $this->request->getParam('requesttoken')
			?: ''
		));
		if ($token !== '' && $this->isRequestTokenValid($token)) {
			return;
		}

		throw new PermissionDeniedException();
	}

	private function isRequestTokenValid(string $token): bool
	{
		try {
			/** @var \OC\Security\CSRF\CsrfTokenManager $manager */
			$manager = Server::get(\OC\Security\CSRF\CsrfTokenManager::class);
			return $manager->isTokenValid(new \OC\Security\CSRF\CsrfToken($token));
		} catch (\Throwable) {
			return false;
		}
	}

	private function readUploadedBinary(): string
	{
		$file = $this->request->getUploadedFile('file');
		if (is_array($file) && isset($file['tmp_name']) && is_uploaded_file((string)$file['tmp_name'])) {
			$content = file_get_contents((string)$file['tmp_name']);
			if ($content !== false && $content !== '') {
				return $content;
			}
		}
		$raw = (string)$this->request->getParam('contentBase64', '');
		if ($raw !== '') {
			$decoded = base64_decode($raw, true);
			if ($decoded !== false && $decoded !== '') {
				return $decoded;
			}
		}
		throw new ValidationException('validation_failed', 'A photo file is required.', [
			['field' => 'file', 'code' => 'required'],
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderComments(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$this->workOrders->get($id, $uid);
		return new JSONResponse($this->comments->list($id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderAddComment(int $id): JSONResponse
	{
		$this->assertSafeMutationChannel();
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$isOffice = $this->access->isOffice($uid);
		return new JSONResponse(
			$this->comments->create($uid, $id, $this->jsonBody(), $isOffice),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentDocs(int $equipmentId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->equipDocs->listForEquipment($equipmentId));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadEquipDoc(int $id): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$file = $this->equipDocs->readFileForDownload($uid, $id);
		return new DataDownloadResponse($file['content'], $file['name'], $file['mime']);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function failureCodes(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		return new JSONResponse($this->failureCodes->list(null, null, true));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exceptions(?string $filter = null, ?string $limit = null, ?string $offset = null, ?string $mine = null): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->gate->assertGatePassed($uid);
		$assignee = $mine === '0' && $this->access->isOffice($uid) ? null : $uid;
		return new JSONResponse($this->exceptions->list($limit, $offset, $filter, $assignee));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset(
			$params['id'],
			$params['visitId'],
			$params['token'],
			$params['itemCode'],
			$params['lineId'],
			$params['equipmentId'],
			$params['_route'],
			$params['file'],
			$params['contentBase64'],
			$params['fileName'],
		);
		return $params;
	}
}
