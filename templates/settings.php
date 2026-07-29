<?php

declare(strict_types=1);

/**
 * Settings: access (P7), roles (P7), license & seats (P8, Track L),
 * Support & us (family standard, admin-only surface).
 *
 * The page route itself is reachable by all app users, but the middleware +
 * controllers enforce P7/P8 on every API call; this template renders the
 * admin sections only when the shell says the user is an app admin.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';

if (!$isAppAdmin): ?>
	<div class="mn-empty">
		<p class="mn-empty__title"><?php p($l->t('Settings are managed by your MaintenanceCheck administrator.')); ?></p>
		<p class="mn-empty__hint"><?php p($l->t('There is nothing to configure for your account here.')); ?></p>
	</div>
<?php else: ?>
<div class="mn-settings">
	<section class="mn-card" aria-labelledby="mn-access-title">
		<header class="mn-card__header">
			<h2 id="mn-access-title" class="mn-card__title"><?php p($l->t('Access')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('By default every logged-in user can open MaintenanceCheck. Turn on the restriction to limit access to the lists below. Administrators always keep access.')); ?></p>
		</header>
		<div id="mn-settings-access" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-card" aria-labelledby="mn-roles-title">
		<header class="mn-card__header">
			<h2 id="mn-roles-title" class="mn-card__title"><?php p($l->t('Roles')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Office members manage customers, equipment and plans. Everyone else can view everything and complete or skip visits (technicians).')); ?></p>
		</header>
		<div id="mn-settings-roles" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-card" aria-labelledby="mn-inventory-flange-title">
		<header class="mn-card__header">
			<h2 id="mn-inventory-flange-title" class="mn-card__title"><?php p($l->t('Inventory stock issue')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Optional: when a work order is finished, deduct kit parts from InventoryCheck. Off by default so upgrades never move stock alone.')); ?></p>
		</header>
		<div id="mn-settings-inventory-flange" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-card" aria-labelledby="mn-policies-title">
		<header class="mn-card__header">
			<h2 id="mn-policies-title" class="mn-card__title"><?php p($l->t('Work policies')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('How strictly checklists, skills and daily capacity apply when finishing or assigning work.')); ?></p>
		</header>
		<div id="mn-settings-policies" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-card" aria-labelledby="mn-capacity-title">
		<header class="mn-card__header">
			<h2 id="mn-capacity-title" class="mn-card__title"><?php p($l->t('Daily capacity')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Minutes each technician can take in a day. Dispatch warns or blocks when the day is full.')); ?></p>
		</header>
		<div id="mn-settings-capacity" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-card" aria-labelledby="mn-license-title">
		<header class="mn-card__header">
			<h2 id="mn-license-title" class="mn-card__title"><?php p($l->t('License & mobile')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('The web app is free forever. A license key adds named seats for the official mobile app.')); ?></p>
		</header>
		<div id="mn-settings-license" class="mn-card__body mn-card--form" aria-busy="true"></div>
	</section>

	<?php
	$supportUsLinks = new \OCA\MaintenanceCheck\Support\SupportUsLinks(
		'MaintenanceCheck',
		true,
		(string)($navUrls['settings'] ?? '/apps/maintenancecheck/settings') . '#mn-license-title',
	);
	$supportUsCssPrefix = 'mn';
	$supportUsShellPrefix = 'mn';
	$supportUsBtnPrimaryClass = 'mn-btn mn-btn--primary';
	$supportUsBtnSecondaryClass = 'mn-btn mn-btn--secondary';
	require __DIR__ . '/parts/support-us-section.php';
	?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/common/page-end.php'; ?>
