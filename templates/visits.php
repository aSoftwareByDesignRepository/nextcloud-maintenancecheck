<?php

declare(strict_types=1);

/**
 * Visit history with filters (S7) — ArbeitszeitCheck filter-panel parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card mn-filter-panel" aria-labelledby="mn-visit-filter-title">
	<header class="mn-filter-panel__head">
		<h2 id="mn-visit-filter-title"><?php p($l->t('Filter')); ?></h2>
		<p class="mn-filter-panel__intro"><?php p($l->t('Narrow visits by status, due date, or ownership.')); ?></p>
	</header>
	<div class="mn-filter-panel__body">
		<form id="mn-visit-filters" class="mn-filter-panel__form mn-filterbar" aria-label="<?php p($l->t('Visit filters')); ?>">
			<div class="mn-filter-grid mn-filter-grid--extended">
				<div class="mn-filter-field">
					<label class="mn-filter-field__label" for="mn-filter-status"><?php p($l->t('Status')); ?></label>
					<div class="mn-filter-field__control">
						<select id="mn-filter-status" class="mn-input form-select">
							<option value=""><?php p($l->t('All statuses')); ?></option>
							<option value="scheduled"><?php p($l->t('Scheduled')); ?></option>
							<option value="done"><?php p($l->t('Done')); ?></option>
							<option value="skipped"><?php p($l->t('Skipped')); ?></option>
							<option value="cancelled"><?php p($l->t('Cancelled')); ?></option>
						</select>
					</div>
				</div>
				<div class="mn-filter-field mn-filter-field--dates">
					<span class="mn-filter-field__label"><?php p($l->t('Due date range')); ?></span>
					<div class="mn-filter-field__control">
						<div class="mn-date-range">
							<div class="mn-date-range__part">
								<label class="mn-date-range__sublabel" for="mn-filter-from"><?php p($l->t('From')); ?></label>
								<input type="date" id="mn-filter-from" class="mn-input form-input">
							</div>
							<span class="mn-date-range__sep" aria-hidden="true">–</span>
							<div class="mn-date-range__part">
								<label class="mn-date-range__sublabel" for="mn-filter-to"><?php p($l->t('To')); ?></label>
								<input type="date" id="mn-filter-to" class="mn-input form-input">
							</div>
						</div>
					</div>
				</div>
				<div class="mn-filter-field">
					<span class="mn-filter-field__label"><?php p($l->t('Ownership')); ?></span>
					<div class="mn-filter-field__control">
						<label class="mn-switch mn-filterbar__switch">
							<input type="checkbox" id="mn-filter-mine" class="mn-switch__input">
							<span class="mn-switch__label"><?php p($l->t('Only my visits')); ?></span>
						</label>
					</div>
				</div>
				<div class="mn-filter-actions">
					<button type="submit" class="mn-btn mn-btn--secondary button"><?php p($l->t('Apply filters')); ?></button>
					<button type="button" id="mn-filter-reset" class="mn-btn mn-btn--tertiary button"><?php p($l->t('Reset')); ?></button>
				</div>
			</div>
		</form>
	</div>
</section>
<div id="mn-visit-list" class="mn-listing" aria-busy="true"></div>
<nav id="mn-visit-pagination" class="mn-pagination" aria-label="<?php p($l->t('Visit pages')); ?>"></nav>
<?php require __DIR__ . '/common/page-end.php'; ?>
