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
<nav class="mn-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
	<a id="mn-back-link" class="mn-breadcrumb__link" href="#"><?php p($l->t('All customers')); ?></a>
</nav>
<div id="mn-customer-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-section" aria-labelledby="mn-equipment-section-title">
	<div class="mn-section__head">
		<h2 class="mn-section__title" id="mn-equipment-section-title"><?php p($l->t('Equipment at this customer')); ?></h2>
		<div id="mn-equipment-section-actions"></div>
	</div>
	<div id="mn-customer-equipment" class="mn-listing" aria-busy="true"></div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
