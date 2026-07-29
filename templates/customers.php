<?php

declare(strict_types=1);

/**
 * Customer list with S13 search and S7 pagination — AZ filter-panel parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card mn-filter-panel" aria-labelledby="mn-customer-filter-title">
	<header class="mn-filter-panel__head">
		<h2 id="mn-customer-filter-title"><?php p($l->t('Filter')); ?></h2>
		<p class="mn-filter-panel__intro"><?php p($l->t('Find customers by name, number, or city.')); ?></p>
	</header>
	<div class="mn-filter-panel__body">
		<form id="mn-customer-filters" class="mn-filter-panel__form mn-filterbar" role="search" aria-label="<?php p($l->t('Search customers')); ?>" onsubmit="return false;">
			<div class="mn-filter-grid">
				<div class="mn-filter-field">
					<label class="mn-filter-field__label" for="mn-customer-search"><?php p($l->t('Search')); ?></label>
					<div class="mn-filter-field__control">
						<input type="search" id="mn-customer-search" class="mn-input form-input mn-search__input"
							placeholder="<?php p($l->t('Search by name, number or city …')); ?>"
							autocomplete="off">
					</div>
				</div>
			</div>
		</form>
	</div>
</section>
<section class="mn-card" aria-labelledby="mn-customer-list-title">
	<header class="mn-card__header">
		<h2 id="mn-customer-list-title" class="mn-card__title"><?php p($l->t('Customers')); ?></h2>
	</header>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-customer-list" class="mn-listing" aria-busy="true"></div>
		<nav id="mn-customer-pagination" class="mn-pagination" aria-label="<?php p($l->t('Customer pages')); ?>"></nav>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
