<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * COMPANION-APP §9.2 / CORE §14.3 capability advertisement for `/mobile/v1/bootstrap`.
 *
 * Clients MUST hide surfaces whose flag is false — never assume W1+/W5 fields
 * exist on older servers. Flags here describe the **mobile API** surface, not
 * the web UI.
 */
final class MobileCapabilities
{
	/** Minimum official companion version that understands this map. */
	public const MIN_APP_VERSION = '1.0.0';

	/** Companion API floor — clients fail closed to app_outdated when missing/mismatched. */
	public const COMPANION_MIN = 1;

	/**
	 * @return array<string, bool|string|int>
	 */
	public static function current(): array
	{
		return [
			'visits' => true,
			'workOrders' => true,
			'tours' => true,
			'kits' => true,
			'qr' => true,
			'conditionalChecklist' => true,
			'serviceReport' => true,
			'meters' => true,
			'requestIntake' => true,
			'failureCodes' => true,
			'laborMinutes' => true,
			'woComments' => true,
			'equipmentDocs' => true,
			'opsAlerts' => true,
			'inspectionObligations' => true,
			'inspectionResults' => true,
			'defectFollowUp' => true,
			'inspectionEvidencePdf' => true,
			'minAppVersion' => self::MIN_APP_VERSION,
			// Clients that omit this key fail closed (resolveMobileAccess → app_outdated).
			'maintenancecheck.companion.min' => self::COMPANION_MIN,
		];
	}
}
