<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * AC-F2 MN→AZC “Record time” deep link (CORE §11.1).
 *
 * Soft capability: when ArbeitszeitCheck is disabled or missing, returns null.
 * Never writes into AZC — only builds a create-form URL with WO ref in the note.
 */
class ArbeitszeitCheckDeepLinkService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function isAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('arbeitszeitcheck');
	}

	/**
	 * Absolute URL to AZC manual time-entry create form with WO number
	 * prefilled into the description query string (JS prefills the textarea).
	 */
	public function buildRecordTimeUrl(string $workOrderNumber): ?string
	{
		$number = trim($workOrderNumber);
		if ($number === '' || !$this->isAvailable()) {
			return null;
		}

		try {
			$base = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.time_entry.create');
		} catch (\Throwable) {
			return null;
		}
		$note = 'MaintenanceCheck WO ' . $number;
		$sep = str_contains($base, '?') ? '&' : '?';

		return $base . $sep . http_build_query(['description' => $note], '', '&', PHP_QUERY_RFC3986);
	}
}
