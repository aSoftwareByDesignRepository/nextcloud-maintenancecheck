<?php

declare(strict_types=1);

/**
 * Support & us underpage (SSR include — no JS host).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array $navUrls
 */

$licenseUrl = (string)(($_['settingsSectionUrls']['license'] ?? null)
	?: (($navUrls['settingsSections']['license'] ?? null)
	?: ((string)($navUrls['settings'] ?? '/apps/maintenancecheck/settings') . '/license')));

$supportUsLinks = new \OCA\MaintenanceCheck\Support\SupportUsLinks(
	'MaintenanceCheck',
	true,
	$licenseUrl,
);
$supportUsCssPrefix = 'mn';
$supportUsShellPrefix = 'mn';
$supportUsBtnPrimaryClass = 'mn-btn mn-btn--primary';
$supportUsBtnSecondaryClass = 'mn-btn mn-btn--secondary';
require __DIR__ . '/../parts/support-us-section.php';
