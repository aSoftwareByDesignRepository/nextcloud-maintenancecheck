<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * F9 MN→PC “Log hours” deep link (CHECK-SUITE flange F9 / WP-S2-MN-F9).
 *
 * Soft capability: when ProjectCheck is disabled or missing, returns null.
 * Never writes into ProjectCheck — only builds a URL with WO ref in the note.
 */
class ProjectCheckHoursDeepLinkService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function isAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('projectcheck');
	}

	/**
	 * Absolute URL to ProjectCheck time-entry create form with WO number
	 * prefilled into the description field.
	 */
	public function buildLogHoursUrl(string $workOrderNumber): ?string
	{
		$number = trim($workOrderNumber);
		if ($number === '' || !$this->isAvailable()) {
			return null;
		}

		$base = $this->urlGenerator->linkToRouteAbsolute('projectcheck.timeentry.create');
		$note = 'MaintenanceCheck WO ' . $number;
		$sep = str_contains($base, '?') ? '&' : '?';

		return $base . $sep . http_build_query(['description' => $note], '', '&', PHP_QUERY_RFC3986);
	}
}
