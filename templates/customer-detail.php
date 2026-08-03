<?php

declare(strict_types=1);

/**
 * Customer detail: master data + equipment of this customer.
 * Bachus: table-solo + compact toolbars (no soft-band lead glued onto tables).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-customer-detail-quickstart';
$qsKey = 'customer_detail_quickstart_v1';
$qsLead = $l->t('This page is the home for one organisation.');
$qsSteps = [
	[
		'title' => $l->t('1. Check master data'),
		'body' => $l->t('Edit name, contact and address if something changed.'),
	],
	[
		'title' => $l->t('2. Add sites when needed'),
		'body' => $l->t('Use sites when one customer has several addresses — then link equipment to the right site.'),
	],
	[
		'title' => $l->t('3. Add equipment'),
		'body' => $l->t('Every unit you maintain belongs here. Plans on those units feed the due board.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div id="mn-customer-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-sites-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-sites-section-title" class="mn-table-toolbar__title"><?php p($l->t('Sites')); ?></h2>
		<div id="mn-sites-section-actions" class="mn-table-toolbar__actions"></div>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-customer-sites" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-equipment-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-equipment-section-title" class="mn-table-toolbar__title"><?php p($l->t('Equipment at this customer')); ?></h2>
		<div id="mn-equipment-section-actions" class="mn-table-toolbar__actions"></div>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-customer-equipment" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
