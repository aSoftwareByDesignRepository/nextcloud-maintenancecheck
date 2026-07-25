<?php

declare(strict_types=1);

/**
 * Customer list with S13 search and S7 pagination.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-toolbar">
	<div class="mn-search">
		<label class="mn-sr-only" for="mn-customer-search"><?php p($l->t('Search customers')); ?></label>
		<input type="search" id="mn-customer-search" class="mn-input mn-search__input"
			placeholder="<?php p($l->t('Search by name, number or city …')); ?>"
			autocomplete="off">
	</div>
</div>
<div id="mn-customer-list" class="mn-listing" aria-busy="true"></div>
<nav id="mn-customer-pagination" class="mn-pagination" aria-label="<?php p($l->t('Customer pages')); ?>"></nav>
<?php require __DIR__ . '/common/page-end.php'; ?>
