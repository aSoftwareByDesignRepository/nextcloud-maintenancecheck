<?php

declare(strict_types=1);

/**
 * Work order list — phone-first execute surface (W1).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-wo-quickstart';
$qsKey = 'work_orders_quickstart_v1';
$qsLead = $l->t('Planned and corrective jobs with checklists and photos.');
$qsSteps = [
	[
		'title' => $l->t('1. Find your job'),
		'body' => $l->t('Filter by status or search the number — the list updates as you change it. Use Only my jobs if you are a technician.'),
	],
	[
		'title' => $l->t('2. Open the job'),
		'body' => $l->t('Run the checklist, add photos, then move the status forward with the big buttons.'),
	],
	[
		'title' => $l->t('3. Office: create work'),
		'body' => $l->t('Office can create corrective jobs here, or turn a due visit into a work order from the due board.'),
		'ctaLabel' => $l->t('Open Due board'),
		'ctaLink' => 'due',
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<section class="mn-card mn-filter-panel" aria-labelledby="mn-wo-filter-title">
	<header class="mn-filter-panel__head">
		<h2 id="mn-wo-filter-title"><?php p($l->t('Filter')); ?></h2>
		<p class="mn-filter-panel__intro"><?php p($l->t('Find planned and open jobs. Open one to run the checklist and add photos.')); ?></p>
	</header>
	<div class="mn-filter-panel__body">
		<form id="mn-wo-filters" class="mn-filter-panel__form" aria-label="<?php p($l->t('Work order filters')); ?>">
			<div class="mn-filter-grid mn-filter-grid--extended">
				<div class="mn-filter-field">
					<label class="mn-filter-field__label" for="mn-wo-status"><?php p($l->t('Status')); ?></label>
					<div class="mn-filter-field__control">
						<select id="mn-wo-status" class="mn-input form-select">
							<option value=""><?php p($l->t('All open')); ?></option>
							<option value="draft"><?php p($l->t('Draft')); ?></option>
							<option value="planned"><?php p($l->t('Planned')); ?></option>
							<option value="ready"><?php p($l->t('Ready')); ?></option>
							<option value="in_progress"><?php p($l->t('In progress')); ?></option>
							<option value="blocked"><?php p($l->t('Blocked')); ?></option>
							<option value="done"><?php p($l->t('Done')); ?></option>
							<option value="cancelled"><?php p($l->t('Cancelled')); ?></option>
						</select>
					</div>
				</div>
				<div class="mn-filter-field">
					<label class="mn-filter-field__label" for="mn-wo-q"><?php p($l->t('Search')); ?></label>
					<div class="mn-filter-field__control">
						<input type="search" id="mn-wo-q" class="mn-input form-input" autocomplete="off" placeholder="<?php p($l->t('Number or title')); ?>" aria-describedby="mn-wo-filter-live-hint">
					</div>
				</div>
				<div class="mn-filter-field mn-filter-field--switch">
					<span class="mn-filter-field__label mn-filter-field__label--spacer" aria-hidden="true">&nbsp;</span>
					<div class="mn-filter-field__control">
						<label class="mn-switch mn-filterbar__switch" for="mn-wo-mine">
							<input type="checkbox" id="mn-wo-mine" class="mn-switch__input">
							<span class="mn-switch__label"><?php p($l->t('Only my jobs')); ?></span>
						</label>
					</div>
				</div>
				<div class="mn-filter-actions">
					<p id="mn-wo-filter-live-hint" class="mn-sr-only"><?php p($l->t('Filters update as you type or change a control.')); ?></p>
					<button type="button" id="mn-wo-reset" class="mn-btn mn-btn--tertiary button"><?php p($l->t('Reset')); ?></button>
					<?php if (!empty($_['isOffice'])): ?>
					<button type="button" id="mn-wo-new" class="mn-btn mn-btn--primary button"><?php p($l->t('New work order')); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</form>
	</div>
</section>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-wo-list-title">
	<h2 id="mn-wo-list-title" class="mn-sr-only"><?php p($l->t('Work orders')); ?></h2>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-wo-list" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-wo-pagination" class="mn-pagination" aria-label="<?php p($l->t('Work order pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
