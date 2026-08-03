<?php

declare(strict_types=1);

/**
 * Equipment detail: master data, maintenance plans, visit history.
 * Bachus: table-solo + compact toolbars (no soft-band lead glued onto tables).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-equipment-detail-quickstart';
$qsKey = 'equipment_detail_quickstart_v1';
$qsLead = $l->t('Plans schedule visits. Meters and inspections can also put work on the due board.');
$qsSteps = [
	[
		'title' => $l->t('1. Add a maintenance plan'),
		'body' => $l->t('Pick an interval or meter threshold. The first open visit lands on the due board.'),
	],
	[
		'title' => $l->t('2. Optional: meters and inspections'),
		'body' => $l->t('Meters open visits when a reading hits the threshold. Inspection obligations schedule Prüfungen.'),
	],
	[
		'title' => $l->t('3. Work the due board'),
		'body' => $l->t('When a visit is due, complete it from the due board or open a work order for checklists and photos.'),
		'ctaLabel' => $l->t('Open Due board'),
		'ctaLink' => 'due',
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div id="mn-equipment-detail" class="mn-detail" aria-busy="true"></div>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-meters-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-meters-section-title" class="mn-table-toolbar__title"><?php p($l->t('Meters')); ?></h2>
		<div id="mn-meters-section-actions" class="mn-table-toolbar__actions"></div>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-meters" class="mn-listing" aria-busy="true"></div>
	</div>
</section>

<section class="mn-card mn-card--table-solo" aria-labelledby="mn-obligations-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-obligations-section-title" class="mn-table-toolbar__title"><?php p($l->t('Inspection obligations')); ?></h2>
		<div id="mn-obligations-section-actions" class="mn-table-toolbar__actions"></div>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-obligations" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-plans-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-plans-section-title" class="mn-table-toolbar__title"><?php p($l->t('Maintenance plans')); ?></h2>
		<div id="mn-plans-section-actions" class="mn-table-toolbar__actions"></div>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-plans" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-history-section-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-history-section-title" class="mn-table-toolbar__title"><?php p($l->t('Visit history')); ?></h2>
	</div>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-equipment-history" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-history-pagination" class="mn-pagination" aria-label="<?php p($l->t('History pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
