<?php

declare(strict_types=1);

/**
 * Daily capacity underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-capacity-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-capacity-title" class="mn-card__title"><?php p($l->t('Daily capacity')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Minutes each technician can take in a day. Dispatch warns or blocks when the day is full.')); ?></p>
		</div>
	</header>
	<div id="mn-settings-capacity" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
