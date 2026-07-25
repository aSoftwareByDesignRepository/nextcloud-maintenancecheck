<?php

declare(strict_types=1);

/**
 * Common page opening — ArbeitszeitCheck shell parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\MaintenanceCheck\Support\IconCatalog;

$pageId = (string)$_['pageId'];
$pageTitle = (string)$_['pageTitle'];
$pageHint = (string)$_['pageHint'];
$entityId = $_['entityId'] ?? null;
$currentUserId = (string)$_['currentUserId'];
$isAppAdmin = !empty($_['isAppAdmin']);
$isOffice = !empty($_['isOffice']);
$mobileAppStatus = (string)$_['mobileAppStatus'];
$serverToday = (string)($_['serverToday'] ?? '');
$urlsJson = (string)$_['urlsJson'];
$timezone = (string)($_['timezone'] ?? 'UTC');
$roleLabel = (string)($_['roleLabel'] ?? ($isAppAdmin ? $l->t('Administrator') : ($isOffice ? $l->t('Office') : $l->t('Technician'))));
$htmlLang = str_replace('_', '-', $l->getLanguageCode());

$pageIcons = [
	'due' => 'layout-grid',
	'visits' => 'calendar-check',
	'customers' => 'users',
	'customer-detail' => 'users',
	'equipment' => 'wrench',
	'equipment-detail' => 'wrench',
	'catalogs' => 'list-checks',
	'settings' => 'settings',
	'access-denied' => 'shield',
];
$headerIcon = $pageIcons[$pageId] ?? 'wrench';
$navUrls = json_decode($urlsJson, true)['pages'] ?? [];
$homeUrl = (string)($navUrls['due'] ?? '#');

require __DIR__ . '/navigation.php';
?>
<div id="app-content" class="mn-app mn-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-mn-page="<?php p($pageId); ?>"
	<?php if ($entityId !== null): ?>data-mn-entity-id="<?php p((string)$entityId); ?>"<?php endif; ?>
	data-mn-current-user="<?php p($currentUserId); ?>"
	data-mn-is-app-admin="<?php p($isAppAdmin ? '1' : '0'); ?>"
	data-mn-is-office="<?php p($isOffice ? '1' : '0'); ?>"
	data-mn-is-system-admin="<?php p(!empty($_['isSystemAdmin']) ? '1' : '0'); ?>"
	data-mn-mobile-app-status="<?php p($mobileAppStatus); ?>"
	data-mn-server-today="<?php p($serverToday); ?>"
	data-mn-timezone="<?php p($timezone); ?>"
	data-mn-urls="<?php p($urlsJson); ?>">
	<a class="mn-skip-link" href="#mn-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="mn-live-region" class="mn-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="mn-alert-region" class="mn-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="mn-toast-region" class="mn-toast-region" aria-label="<?php p($l->t('Notifications')); ?>"></div>
	<div id="app-content-wrapper" class="mn-shell">
		<header class="mn-page-header" aria-labelledby="mn-page-title">
			<nav class="mn-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="mn-breadcrumb__list">
					<li class="mn-breadcrumb__item">
						<a class="mn-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('MaintenanceCheck')); ?></a>
					</li>
					<li class="mn-breadcrumb__item mn-breadcrumb__item--current" aria-current="page">
						<span class="mn-breadcrumb__current"><?php p($pageTitle); ?></span>
					</li>
				</ol>
			</nav>
			<div class="mn-page-header__main">
				<div class="mn-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIcon, 'mn-page-header__icon-svg')); ?>
				</div>
				<div class="mn-page-header__text">
					<h1 id="mn-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHint !== ''): ?>
						<p class="mn-page-header__lead"><?php p($pageHint); ?></p>
					<?php endif; ?>
				</div>
				<div id="mn-page-actions" class="mn-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="mn-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="mn-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="mn-badge mn-badge--neutral mn-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="mn-scope-strip__sep" aria-hidden="true">·</span>
				<span class="mn-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="mn-scope-strip__value"><?php p($timezone); ?></span>
			</div>
		</header>
		<main id="mn-main-content" class="mn-main" tabindex="-1" aria-labelledby="mn-page-title">
