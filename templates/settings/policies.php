<?php

declare(strict_types=1);

/**
 * Work policies underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-policies-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-policies-title" class="mn-card__title"><?php p($l->t('Work policies')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('How strictly checklists, skills and daily capacity apply when finishing or assigning work.')); ?></p>
		</div>
	</header>
	<div id="mn-settings-policies" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
