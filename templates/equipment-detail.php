<?php

declare(strict_types=1);

/**
 * Equipment detail: master data, maintenance plans, visit history.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div id="mn-equipment-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-card" aria-labelledby="mn-meters-section-title">
	<header class="mn-card__header mn-card__header--with-actions">
		<div>
			<h2 id="mn-meters-section-title" class="mn-card__title"><?php p($l->t('Meters')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Operating hours, cycles or kilometres. Readings can open a visit when a plan’s threshold is reached.')); ?></p>
		</div>
		<div id="mn-meters-section-actions" class="mn-card__actions"></div>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-meters" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-plans-section-title">
	<header class="mn-card__header mn-card__header--with-actions">
		<h2 id="mn-plans-section-title" class="mn-card__title"><?php p($l->t('Maintenance plans')); ?></h2>
		<div id="mn-plans-section-actions" class="mn-card__actions"></div>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-plans" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-history-section-title">
	<header class="mn-card__header">
		<h2 id="mn-history-section-title" class="mn-card__title"><?php p($l->t('Visit history')); ?></h2>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-history" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-history-pagination" class="mn-pagination" aria-label="<?php p($l->t('History pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
