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
<section class="mn-card mn-filter-panel" aria-labelledby="mn-wo-filter-title">
	<header class="mn-filter-panel__head">
		<h2 id="mn-wo-filter-title"><?php p($l->t('Filter')); ?></h2>
		<p class="mn-filter-panel__intro"><?php p($l->t('Find planned and open jobs. Open one to run the checklist and add photos.')); ?></p>
	</header>
	<div class="mn-filter-panel__body">
		<form id="mn-wo-filters" class="mn-filter-panel__form mn-filterbar" aria-label="<?php p($l->t('Work order filters')); ?>">
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
						<input type="search" id="mn-wo-q" class="mn-input form-input" autocomplete="off" placeholder="<?php p($l->t('Number or title')); ?>">
					</div>
				</div>
				<div class="mn-filter-field">
					<span class="mn-filter-field__label"><?php p($l->t('Ownership')); ?></span>
					<div class="mn-filter-field__control">
						<label class="mn-switch mn-filterbar__switch">
							<input type="checkbox" id="mn-wo-mine" class="mn-switch__input">
							<span class="mn-switch__label"><?php p($l->t('Only my jobs')); ?></span>
						</label>
					</div>
				</div>
				<div class="mn-filter-actions">
					<button type="submit" class="mn-btn mn-btn--secondary button"><?php p($l->t('Apply filters')); ?></button>
					<button type="button" id="mn-wo-reset" class="mn-btn mn-btn--tertiary button"><?php p($l->t('Reset')); ?></button>
					<?php if (!empty($_['isOffice'])): ?>
					<button type="button" id="mn-wo-new" class="mn-btn mn-btn--primary button"><?php p($l->t('New work order')); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</form>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-wo-list-title">
	<header class="mn-card__header">
		<h2 id="mn-wo-list-title" class="mn-card__title"><?php p($l->t('Work orders')); ?></h2>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-wo-list" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-wo-pagination" class="mn-pagination" aria-label="<?php p($l->t('Work order pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
