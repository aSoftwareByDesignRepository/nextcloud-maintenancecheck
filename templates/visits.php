<?php

declare(strict_types=1);

/**
 * Visit history with live filters (S7) — Bachus: flat Due-style toolbar, date presets, no Filter card.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-visits-quickstart';
$qsKey = 'visits_quickstart_v3';
$qsLead = $l->t('Past and filtered visits — tap a status or due window and the list updates immediately.');
$qsSteps = [
	[
		'title' => $l->t('1. Narrow the list'),
		'body' => $l->t('Tap a status or a due window (this week, this month, or pick dates). Only my visits is one tap too.'),
	],
	[
		'title' => $l->t('2. Clear when done'),
		'body' => $l->t('Clear filters restores the full history in one tap.'),
	],
	[
		'title' => $l->t('3. Read the history'),
		'body' => $l->t('Completed, skipped and cancelled visits stay here for audit.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<form
	id="mn-visit-filters"
	class="mn-visits-toolbar"
	aria-label="<?php p($l->t('Visit filters')); ?>"
>
	<input type="hidden" id="mn-filter-status" value="" autocomplete="off">
	<input type="hidden" id="mn-filter-when" value="" autocomplete="off">

	<div class="mn-visits-toolbar__row">
		<div
			id="mn-filter-status-chips"
			class="mn-visits-toolbar__chips"
			role="group"
			aria-label="<?php p($l->t('Status')); ?>"
		>
			<button type="button" class="mn-chip is-active" data-mn-status="" aria-pressed="true"><?php p($l->t('All')); ?></button>
			<button type="button" class="mn-chip" data-mn-status="scheduled" aria-pressed="false"><?php p($l->t('Scheduled')); ?></button>
			<button type="button" class="mn-chip" data-mn-status="done" aria-pressed="false"><?php p($l->t('Done')); ?></button>
			<button type="button" class="mn-chip" data-mn-status="skipped" aria-pressed="false"><?php p($l->t('Skipped')); ?></button>
			<button type="button" class="mn-chip" data-mn-status="cancelled" aria-pressed="false"><?php p($l->t('Cancelled')); ?></button>
		</div>
		<label class="mn-switch mn-visits-toolbar__mine" for="mn-filter-mine">
			<input type="checkbox" id="mn-filter-mine" class="mn-switch__input">
			<span class="mn-switch__label"><?php p($l->t('Only my visits')); ?></span>
		</label>
		<button
			type="button"
			id="mn-filter-reset"
			class="mn-btn mn-btn--secondary button"
			disabled
			aria-disabled="true"
		><?php p($l->t('Clear filters')); ?></button>
	</div>

	<div class="mn-visits-toolbar__row">
		<div
			id="mn-filter-when-chips"
			class="mn-visits-toolbar__chips"
			role="group"
			aria-label="<?php p($l->t('Due when')); ?>"
		>
			<button type="button" class="mn-chip is-active" data-mn-when="" aria-pressed="true"><?php p($l->t('Any time')); ?></button>
			<button type="button" class="mn-chip" data-mn-when="week" aria-pressed="false"><?php p($l->t('This week')); ?></button>
			<button type="button" class="mn-chip" data-mn-when="month" aria-pressed="false"><?php p($l->t('This month')); ?></button>
			<button type="button" class="mn-chip" data-mn-when="custom" aria-pressed="false"><?php p($l->t('Pick dates')); ?></button>
		</div>
	</div>

	<div id="mn-filter-custom-dates" class="mn-visits-toolbar__dates" hidden>
		<div class="mn-date-range mn-date-range--compact" role="group" aria-label="<?php p($l->t('Due date range')); ?>">
			<input
				type="date"
				id="mn-filter-from"
				class="mn-input form-input"
				aria-label="<?php p($l->t('From')); ?>"
			>
			<span class="mn-date-range__sep" aria-hidden="true">–</span>
			<input
				type="date"
				id="mn-filter-to"
				class="mn-input form-input"
				aria-label="<?php p($l->t('To')); ?>"
			>
		</div>
		<p id="mn-filter-date-hint" class="mn-filter-field__hint" hidden></p>
	</div>
</form>

<section class="mn-card mn-card--table-solo" aria-labelledby="mn-visit-list-title">
	<h2 id="mn-visit-list-title" class="mn-sr-only"><?php p($l->t('Visits')); ?></h2>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-visit-list" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-visit-pagination" class="mn-pagination" aria-label="<?php p($l->t('Visit pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
