<?php

declare(strict_types=1);

/**
 * Due board (S8): overdue / today / next 7 days / later (≤ 30 days).
 *
 * Bachus: flat toolbar + design-system §3.7 data tables per bucket (no card stacks).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-due-quickstart';
$qsKey = 'due_quickstart_v1';
$qsLead = $l->t('New to MaintenanceCheck? Three short steps — then the due board fills itself.');
$qsSteps = [
	[
		'title' => $l->t('1. Add a customer'),
		'body' => $l->t('Create the organisations you service. Equipment and plans live under each customer.'),
		'ctaLabel' => $l->t('Open Customers'),
		'ctaLink' => 'customers',
	],
	[
		'title' => $l->t('2. Add equipment and a plan'),
		'body' => $l->t('Open a customer, add a unit, then create a maintenance plan. The first visit appears on this board automatically.'),
		'ctaLabel' => $l->t('Open Customers'),
		'ctaLink' => 'customers',
	],
	[
		'title' => $l->t('3. Complete visits here'),
		'body' => $l->t('Tap Complete on a row when the job is done. Overdue is always at the top.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div id="mn-due-toolbar" class="mn-due-toolbar" role="group" aria-label="<?php p($l->t('Due board filter')); ?>">
	<label class="mn-switch" for="mn-due-mine">
		<input type="checkbox" id="mn-due-mine" class="mn-switch__input">
		<span class="mn-switch__label"><?php p($l->t('Only my visits')); ?></span>
	</label>
	<div class="mn-due-toolbar__chips" role="group" aria-label="<?php p($l->t('Work type')); ?>">
		<button type="button" class="mn-chip is-active" id="mn-due-kind-all" data-mn-due-kind="all" aria-pressed="true"><?php p($l->t('All')); ?></button>
		<button type="button" class="mn-chip" id="mn-due-kind-inspection" data-mn-due-kind="inspection" aria-pressed="false"><?php p($l->t('Inspections')); ?></button>
	</div>
	<p class="mn-toolbar__meta" id="mn-due-today"></p>
</div>
<div id="mn-due-board" class="mn-board" aria-busy="true">
	<section class="mn-bucket mn-bucket--overdue" aria-labelledby="mn-bucket-title-overdue" data-bucket="overdue">
		<h2 class="mn-bucket__title" id="mn-bucket-title-overdue"></h2>
		<div class="mn-bucket__list mn-card__body--table" data-bucket-list="overdue"></div>
	</section>
	<section class="mn-bucket mn-bucket--today" aria-labelledby="mn-bucket-title-today" data-bucket="today">
		<h2 class="mn-bucket__title" id="mn-bucket-title-today"></h2>
		<div class="mn-bucket__list mn-card__body--table" data-bucket-list="today"></div>
	</section>
	<section class="mn-bucket mn-bucket--next7" aria-labelledby="mn-bucket-title-next7" data-bucket="next7">
		<h2 class="mn-bucket__title" id="mn-bucket-title-next7"></h2>
		<div class="mn-bucket__list mn-card__body--table" data-bucket-list="next7"></div>
	</section>
	<section class="mn-bucket mn-bucket--later" aria-labelledby="mn-bucket-title-later" data-bucket="later">
		<h2 class="mn-bucket__title" id="mn-bucket-title-later"></h2>
		<div class="mn-bucket__list mn-card__body--table" data-bucket-list="later"></div>
	</section>
</div>
<div id="mn-due-empty" class="mn-empty" hidden></div>
<?php require __DIR__ . '/common/page-end.php'; ?>
