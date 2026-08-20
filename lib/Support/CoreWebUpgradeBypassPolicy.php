<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Support;

/**
 * Policy mirror of the NC 34+ “Upgrade via web on my own risk” acknowledgement.
 *
 * Stock Nextcloud honours the ack only for oversized instances. Our docker
 * volume patch also honours it when upgrade.disable-web=true, and the Vue
 * UpdaterAdmin EventSource must forward the same query key to /core/update.
 *
 * This class is the testable contract for that behaviour (unit + mutation).
 */
final class CoreWebUpgradeBypassPolicy
{
	public const QUERY_KEY = 'IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup';
	public const TOKEN = 'IAmSuperSureToDoThis';

	/** NC 34 OCS EventSource path (generateOcsUrl('/core/update')). */
	public const UPDATE_ENDPOINT = '/core/update';

	public static function isValidBypassToken(?string $bypassToken): bool {
		return $bypassToken === self::TOKEN;
	}

	/**
	 * Show the CLI interstitial (updaterView=adminCli).
	 */
	public static function shouldShowCliWarning(bool $disableWebUpdater, bool $tooBig, ?string $bypassToken): bool {
		$ignoreWarning = self::isValidBypassToken($bypassToken);
		return ($disableWebUpdater && !$ignoreWarning) || ($tooBig && !$ignoreWarning);
	}

	/**
	 * NC 34 UpdaterAdminCli always offers the risk card when adminCli is shown.
	 * Keep the predicate explicit for tooBig OR disable-web.
	 */
	public static function shouldShowBypassLink(bool $tooBig, bool $disableWebUpdater): bool {
		return $tooBig || $disableWebUpdater;
	}

	/**
	 * UpdateController must refuse Start update unless ack is present.
	 */
	public static function shouldBlockUpdateEndpoint(bool $disableWebUpdater, ?string $bypassToken): bool {
		return $disableWebUpdater && !self::isValidBypassToken($bypassToken);
	}

	/**
	 * Append the ack to the OCS update EventSource URL when the page carried it.
	 */
	public static function updateEndpointUrl(string $pageQueryString): string {
		$updateUrl = self::UPDATE_ENDPOINT;
		parse_str($pageQueryString, $params);
		if (isset($params[self::QUERY_KEY]) && $params[self::QUERY_KEY] === self::TOKEN) {
			$updateUrl .= '?' . self::QUERY_KEY . '=' . rawurlencode((string)$params[self::QUERY_KEY]);
		}
		return $updateUrl;
	}
}
