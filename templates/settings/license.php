<?php

declare(strict_types=1);

/**
 * License & mobile underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-license-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-license-title" class="mn-card__title"><?php p($l->t('License & mobile')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('The web app is free forever. A license key adds named seats for the official mobile app.')); ?></p>
		</div>
	</header>
	<div id="mn-settings-license" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
