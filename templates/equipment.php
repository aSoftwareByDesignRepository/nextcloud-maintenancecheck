<?php

declare(strict_types=1);

/**
 * Equipment register across all customers — AZ filter-panel parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card mn-filter-panel" aria-labelledby="mn-equipment-filter-title">
	<header class="mn-filter-panel__head">
		<h2 id="mn-equipment-filter-title"><?php p($l->t('Filter')); ?></h2>
		<p class="mn-filter-panel__intro"><?php p($l->t('Find equipment by label, manufacturer, model, or serial.')); ?></p>
	</header>
	<div class="mn-filter-panel__body">
		<form id="mn-equipment-filters" class="mn-filter-panel__form mn-filterbar" role="search" aria-label="<?php p($l->t('Search equipment')); ?>" onsubmit="return false;">
			<div class="mn-filter-grid">
				<div class="mn-filter-field">
					<label class="mn-filter-field__label" for="mn-equipment-search"><?php p($l->t('Search')); ?></label>
					<div class="mn-filter-field__control">
						<input type="search" id="mn-equipment-search" class="mn-input form-input mn-search__input"
							placeholder="<?php p($l->t('Search by label, manufacturer, model or serial …')); ?>"
							autocomplete="off">
					</div>
				</div>
			</div>
		</form>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-equipment-list-title">
	<header class="mn-card__header">
		<h2 id="mn-equipment-list-title" class="mn-card__title"><?php p($l->t('Equipment')); ?></h2>
	</header>
	<div class="mn-card__body">
		<div id="mn-equipment-list" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-equipment-pagination" class="mn-pagination" aria-label="<?php p($l->t('Equipment pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
