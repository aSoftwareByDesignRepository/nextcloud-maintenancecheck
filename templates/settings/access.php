<?php

declare(strict_types=1);

/**
 * Access settings underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-access-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-access-title" class="mn-card__title"><?php p($l->t('Access')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('By default every logged-in user can open MaintenanceCheck. Turn on the restriction to limit access to the lists below. Administrators always keep access.')); ?></p>
		</div>
	</header>
	<div id="mn-settings-access" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
