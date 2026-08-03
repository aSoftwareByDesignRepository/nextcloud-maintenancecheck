<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Config\VendorPublicKey;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\License\Mn2Codec;
use OCP\App\IAppManager;

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
		private readonly IAppManager $appManager,
		private readonly PolicyService $policies,
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
	 * Bootstrap payload (SPEC §9.2 / COMPANION §9.2) — reports instead of gating
	 * so the official app can render LicenseGate with accurate copy.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrapPayload(string $uid, string $displayName, bool $isOffice): array
	{
		$state = $this->license->gateState($uid);
		$enabledForUser = $state['seatAssigned'] && $state['seatWithinLimit'] && $state['licenseValid'];
		$status = $this->license->status();
		$expiresAt = is_array($status['state'] ?? null) ? ($status['state']['validUntil'] ?? null) : null;
		$pushAvailable = $this->appManager->isEnabledForUser('notifications');

		$licensing = null;
		if ($state['hasLicense'] && $state['payloadB64'] !== null && $state['signatureB64'] !== null) {
			$licensing = [
				'format' => Mn2Codec::FORMAT,
				'payloadB64' => $state['payloadB64'],
				'signatureB64' => $state['signatureB64'],
				'envelope' => [
					'format' => Mn2Codec::FORMAT,
					'payloadB64' => $state['payloadB64'],
					'signatureB64' => $state['signatureB64'],
				],
				'vendorPublicKeyB64' => VendorPublicKey::publicKeyB64(),
				'mobile' => [
					'enabledForUser' => $enabledForUser,
					'expiresAt' => is_string($expiresAt) ? $expiresAt : null,
				],
			];
		}

		$capabilities = MobileCapabilities::current();
		$capabilities['push'] = $pushAvailable;

		return [
			'appId' => Application::APP_ID,
			'apiVersion' => 1,
			'capabilities' => $capabilities,
			'policies' => [
				'failureCodeOnCorrective' => $this->policies->failureCodeOnCorrective(),
				'defectFollowUp' => $this->policies->defectFollowUp(),
				'inspectionResultRequired' => $this->policies->inspectionResultRequired(),
			],
			'licensing' => $licensing,
			'seatAssigned' => $state['seatAssigned'],
			'seatWithinLimit' => $state['seatWithinLimit'],
			'pushAvailable' => $pushAvailable,
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'user' => [
				'userId' => $uid,
				'uid' => $uid,
				'displayName' => $displayName,
				'isOffice' => $isOffice,
			],
		];
	}
}
