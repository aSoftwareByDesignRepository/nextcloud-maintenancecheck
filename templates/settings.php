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
	<section class="mn-section" aria-labelledby="mn-access-title">
		<div class="mn-section__head">
			<h2 class="mn-section__title" id="mn-access-title"><?php p($l->t('Access')); ?></h2>
		</div>
		<p class="mn-section__hint"><?php p($l->t('By default every logged-in user can open MaintenanceCheck. Turn on the restriction to limit access to the lists below. Administrators always keep access.')); ?></p>
		<div id="mn-settings-access" class="mn-card mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-section" aria-labelledby="mn-roles-title">
		<div class="mn-section__head">
			<h2 class="mn-section__title" id="mn-roles-title"><?php p($l->t('Roles')); ?></h2>
		</div>
		<p class="mn-section__hint"><?php p($l->t('Office members manage customers, equipment and plans. Everyone else can view everything and complete or skip visits (technicians).')); ?></p>
		<div id="mn-settings-roles" class="mn-card mn-card--form" aria-busy="true"></div>
	</section>

	<section class="mn-section" aria-labelledby="mn-license-title">
		<div class="mn-section__head">
			<h2 class="mn-section__title" id="mn-license-title"><?php p($l->t('License & mobile')); ?></h2>
		</div>
		<p class="mn-section__hint"><?php p($l->t('The web app is free forever. A license key adds named seats for the official mobile app.')); ?></p>
		<div id="mn-settings-license" class="mn-card mn-card--form" aria-busy="true"></div>
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
