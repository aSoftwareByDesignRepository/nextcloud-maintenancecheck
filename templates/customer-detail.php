<?php

declare(strict_types=1);

/**
 * Customer detail: master data + equipment of this customer.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div id="mn-customer-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-card" aria-labelledby="mn-sites-section-title">
	<header class="mn-card__header mn-card__header--with-actions">
		<div>
			<h2 id="mn-sites-section-title" class="mn-card__title"><?php p($l->t('Sites')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Optional address hubs under this customer — link equipment to a site for clearer tours.')); ?></p>
		</div>
		<div id="mn-sites-section-actions" class="mn-card__actions"></div>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-customer-sites" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-equipment-section-title">
	<header class="mn-card__header mn-card__header--with-actions">
		<h2 id="mn-equipment-section-title" class="mn-card__title"><?php p($l->t('Equipment at this customer')); ?></h2>
		<div id="mn-equipment-section-actions" class="mn-card__actions"></div>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-customer-equipment" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
