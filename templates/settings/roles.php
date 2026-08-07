<?php

declare(strict_types=1);

/**
 * Roles settings underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-roles-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-roles-title" class="mn-card__title"><?php p($l->t('Roles')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Office members manage customers, equipment and plans. Everyone else can view everything and complete or skip visits (technicians).')); ?></p>
		</div>
	</header>
	<div id="mn-settings-roles" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
