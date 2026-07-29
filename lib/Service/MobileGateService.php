<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\MobileGateException;

/**
 * SPEC §9.1 gate ladder for /mobile/v1/* routes.
 *
 * Rungs 1 (NC auth) and 2 (canUseApp) are enforced by Nextcloud auth and the
 * AppAccessMiddleware respectively. This service enforces rungs 3–6, in order:
 *   3 license row exists           → 402 license_missing
 *   4 today ≤ valid_until          → 402 license_expired
 *   5 caller has a seat            → 402 seat_required
 *   6 seat within limit (§8.4)     → 402 seat_limit_exceeded
 *
 * `bootstrap` skips 3–6 and *reports* the state instead.
 */
class MobileGateService
{
	public function __construct(
		private readonly LicenseService $license,
	) {
	}

	public function assertGatePassed(string $uid): void
	{
		$state = $this->license->gateState($uid);
		if (!$state['hasLicense']) {
			throw new MobileGateException('license_missing');
		}
		if (!$state['licenseValid']) {
			throw new MobileGateException('license_expired');
		}
		if (!$state['seatAssigned']) {
			throw new MobileGateException('seat_required');
		}
		if (!$state['seatWithinLimit']) {
			throw new MobileGateException('seat_limit_exceeded');
		}
	}

	/**
	 * Bootstrap payload (SPEC §9.2) — reports instead of gating so the app
	 * can render the LicenseGate screen with accurate copy.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrapPayload(string $uid, string $displayName, bool $isOffice): array
	{
		$state = $this->license->gateState($uid);
		return [
			'licensing' => $state['hasLicense'] ? [
				'format' => 'MN2',
				'payloadB64' => $state['payloadB64'],
				'signatureB64' => $state['signatureB64'],
			] : null,
			'seatAssigned' => $state['seatAssigned'],
			'seatWithinLimit' => $state['seatWithinLimit'],
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'capabilities' => MobileCapabilities::current(),
			'user' => [
				'uid' => $uid,
				'displayName' => $displayName,
				'isOffice' => $isOffice,
			],
		];
	}
}
