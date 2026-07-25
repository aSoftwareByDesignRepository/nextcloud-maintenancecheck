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
<nav class="mn-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
	<a id="mn-back-link" class="mn-breadcrumb__link" href="#"><?php p($l->t('All equipment')); ?></a>
</nav>
<div id="mn-equipment-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-section" aria-labelledby="mn-plans-section-title">
	<div class="mn-section__head">
		<h2 class="mn-section__title" id="mn-plans-section-title"><?php p($l->t('Maintenance plans')); ?></h2>
		<div id="mn-plans-section-actions"></div>
	</div>
	<div id="mn-equipment-plans" class="mn-listing" aria-busy="true"></div>
</section>
<section class="mn-section" aria-labelledby="mn-history-section-title">
	<div class="mn-section__head">
		<h2 class="mn-section__title" id="mn-history-section-title"><?php p($l->t('Visit history')); ?></h2>
	</div>
	<div id="mn-equipment-history" class="mn-listing" aria-busy="true"></div>
	<nav id="mn-history-pagination" class="mn-pagination" aria-label="<?php p($l->t('History pages')); ?>"></nav>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
