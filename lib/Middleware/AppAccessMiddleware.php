<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Middleware;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\AppAccessDeniedException;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * L2 entry gate (SPEC §3 P1) + the single place that turns domain exceptions
 * into the SPEC §7.1 error envelope:
 *
 *   { "error": { "code": "<stable>", "message": "<localised>" } }
 *
 * Page routes render the access-denied template with HTTP 403 instead
 * (BudgetCheck pattern). All controllers stay envelope-free.
 */
class AppAccessMiddleware extends Middleware
{
	private const HTTP_PAYMENT_REQUIRED = 402;

	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\MaintenanceCheck\\Controller\\')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			// No session → Nextcloud auth middleware answers 401 (P1 row 1).
			return;
		}
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}
		throw new AppAccessDeniedException(
			$this->accessControl->denialReasonWhenCannotUseApp($user->getUID()),
		);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\MaintenanceCheck\\Controller\\')) {
			throw $exception;
		}

		$l = $this->l10nFactory->get(Application::APP_ID);

		if ($exception instanceof AppAccessDeniedException) {
			return $this->accessDeniedResponse($exception, $l);
		}
		if ($exception instanceof PermissionDeniedException) {
			return $this->envelope('permission_denied', $l->t('You do not have permission for this action.'), Http::STATUS_FORBIDDEN);
		}
		if ($exception instanceof NotFoundException) {
			return $this->envelope('not_found', $l->t('The requested entry does not exist.'), Http::STATUS_NOT_FOUND);
		}
		if ($exception instanceof ConflictException) {
			$body = [
				'error' => [
					'code' => $exception->getErrorCode(),
					'message' => $this->conflictMessage($exception->getErrorCode(), $l),
				],
			];
			if ($exception->getDetails() !== []) {
				$body['error']['details'] = $exception->getDetails();
			}
			return new JSONResponse($body, Http::STATUS_CONFLICT);
		}
		if ($exception instanceof ValidationException) {
			$body = [
				'error' => [
					'code' => $exception->getErrorCode(),
					'message' => $this->validationMessage($exception, $l),
				],
			];
			if ($exception->getDetails() !== []) {
				$body['error']['details'] = $exception->getDetails();
			}
			return new JSONResponse($body, Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if ($exception instanceof MobileGateException) {
			return $this->envelope(
				$exception->getErrorCode(),
				$this->gateMessage($exception->getErrorCode(), $l),
				self::HTTP_PAYMENT_REQUIRED,
			);
		}

		throw $exception;
	}

	private function isJsonRoute(): bool
	{
		$path = (string)($this->request->getPathInfo() ?? '');
		return str_contains($path, '/api/')
			|| str_contains($path, '/mobile/')
			|| $this->request->getMethod() !== 'GET';
	}

	private function envelope(string $code, string $message, int $status): JSONResponse
	{
		return new JSONResponse(['error' => ['code' => $code, 'message' => $message]], $status);
	}

	private function accessDeniedResponse(AppAccessDeniedException $exception, IL10N $l): JSONResponse|TemplateResponse
	{
		if ($this->isJsonRoute()) {
			return $this->envelope(
				'app_access_denied',
				$l->t('You are not allowed to use MaintenanceCheck.'),
				Http::STATUS_FORBIDDEN,
			);
		}

		[$message, $hint] = match ($exception->getDenialReason()) {
			AccessControlService::DENIAL_RESTRICTION => [
				$l->t('Your organisation restricts MaintenanceCheck access. You are not on the allow-list.'),
				$l->t('Ask a Nextcloud or MaintenanceCheck administrator to add you in Settings → Access.'),
			],
			default => [
				$l->t('You are not allowed to use MaintenanceCheck right now.'),
				$l->t('If you believe this is a mistake, contact your MaintenanceCheck administrator.'),
			],
		};
		$response = new TemplateResponse(
			Application::APP_ID,
			'access-denied',
			[
				'message' => $message,
				'hint' => $hint,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			],
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}

	private function conflictMessage(string $code, IL10N $l): string
	{
		return match ($code) {
			'visit_not_open' => $l->t('This visit was already closed.'),
			'visit_already_open' => $l->t('This plan already has an open visit.'),
			'visit_already_linked' => $l->t('This visit already has an open work order.'),
			'customer_has_equipment' => $l->t('This customer still has equipment. Confirm the cascade delete to proceed.'),
			'equipment_in_use' => $l->t('This equipment has plans or visits and cannot be deleted. Deactivate it instead.'),
			'code_exists' => $l->t('This code is already in use.'),
			'seat_limit_reached' => $l->t('All licensed seats are assigned. Remove a seat or upgrade the license.'),
			'license_busy' => $l->t('Another license update is in progress. Try again in a moment.'),
			'invalid_status' => $l->t('This action is not possible in the current status. Reload and try again.'),
			'checklist_incomplete' => $l->t('Required checklist items are still open. Finish them or confirm the exception.'),
			'checklist_started' => $l->t('The checklist already has results. Clear them before changing the procedure.'),
			'kit_incomplete' => $l->t('The kit is not fully packed yet.'),
			'kit_packing_started' => $l->t('Packing has already started for this kit.'),
			'item_hidden' => $l->t('This checklist item is currently hidden by its condition.'),
			'capacity_warning' => $l->t('This assignment would exceed the technician’s daily capacity. Confirm to assign anyway.'),
			'skills_warning' => $l->t('The assignee is missing required skills. Confirm to assign anyway.'),
			'procedure_in_use' => $l->t('This procedure is used by work orders. Fork it to make changes.'),
			'pack_exists' => $l->t('A pack with this code was already imported. Enable overwrite to replace it.'),
			'tour_locked' => $l->t('This tour is locked. Unlock it to change the order.'),
			'wo_in_tour' => $l->t('This work order is already part of a tour.'),
			'wo_not_done' => $l->t('The service report is available once the work order is done.'),
			'meter_in_use' => $l->t('An active plan is triggered by this meter. Change the plan first.'),
			'meter_inactive' => $l->t('This meter is deactivated.'),
			'site_in_use' => $l->t('Equipment is linked to this site. Move it first.'),
			default => $l->t('The action conflicts with the current state. Reload and try again.'),
		};
	}

	private function validationMessage(ValidationException $exception, IL10N $l): string
	{
		return match ($exception->getErrorCode()) {
			'invalid_interval' => $l->t('The maintenance interval is not valid.'),
			'invalid_due_date' => $l->t('The due date is not valid.'),
			'invalid_done_on' => $l->t('The completion date is not valid.'),
			'inactive_maint_type' => $l->t('This maintenance type is deactivated.'),
			'inactive_equip_type' => $l->t('This equipment type is deactivated.'),
			'unknown_user' => $l->t('This Nextcloud user does not exist.'),
			'invalid_query' => $l->t('The list parameters are not valid.'),
			'plan_inactive' => $l->t('This plan is inactive. Activate it before scheduling a visit.'),
			'license_invalid' => $l->t('This license key is not valid: %s', [$exception->getMessage()]),
			'invalid_code' => $l->t('Use lowercase letters, digits and underscores only.'),
			'equipment_required' => $l->t('Please select the equipment this work order is for.'),
			'procedure_required' => $l->t('Please select a checklist procedure.'),
			'show_if_cycle' => $l->t('The visibility conditions form a loop. Check the item references.'),
			'show_if_unknown' => $l->t('A visibility condition references an unknown checklist item.'),
			'skills_missing' => $l->t('The assignee does not have all required skills.'),
			'capacity_exceeded' => $l->t('This assignment exceeds the technician’s daily capacity limit.'),
			'invalid_photo' => $l->t('This file is not a supported image (JPEG, PNG or WebP).'),
			'photo_too_large' => $l->t('The photo is too large.'),
			'photo_limit_reached' => $l->t('The photo limit for this work order is reached.'),
			'invalid_signature' => $l->t('The signature image is not valid.'),
			'signature_too_large' => $l->t('The signature image is too large.'),
			'pack_invalid' => $l->t('The pack file is not a valid procedure pack.'),
			'pack_too_large' => $l->t('The pack file is too large.'),
			'invalid_meter_value' => $l->t('The meter value is not valid.'),
			'meter_not_monotonic' => $l->t('This meter only counts up — the value is lower than the last reading.'),
			'meter_threshold_required' => $l->t('A meter-triggered plan needs a threshold.'),
			default => $l->t('Please check the highlighted fields.'),
		};
	}

	private function gateMessage(string $code, IL10N $l): string
	{
		return match ($code) {
			'license_missing' => $l->t('No mobile license is stored on this server.'),
			'license_expired' => $l->t('The mobile license has expired.'),
			'seat_required' => $l->t('You do not have a mobile seat assigned.'),
			default => $l->t('Your mobile seat is above the licensed limit.'),
		};
	}
}
