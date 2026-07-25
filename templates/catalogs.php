<?php

declare(strict_types=1);

/**
 * Catalogs: equipment types + maintenance types (S11 — deactivate only).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-columns">
	<section class="mn-section" aria-labelledby="mn-equip-types-title">
		<div class="mn-section__head">
			<h2 class="mn-section__title" id="mn-equip-types-title"><?php p($l->t('Equipment types')); ?></h2>
			<div id="mn-equip-types-actions"></div>
		</div>
		<div id="mn-equip-types" class="mn-listing" aria-busy="true"></div>
	</section>
	<section class="mn-section" aria-labelledby="mn-maint-types-title">
		<div class="mn-section__head">
			<h2 class="mn-section__title" id="mn-maint-types-title"><?php p($l->t('Maintenance types')); ?></h2>
			<div id="mn-maint-types-actions"></div>
		</div>
		<div id="mn-maint-types" class="mn-listing" aria-busy="true"></div>
	</section>
</div>
<?php require __DIR__ . '/common/page-end.php'; ?>
