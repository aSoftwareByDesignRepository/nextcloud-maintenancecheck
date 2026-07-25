<?php

declare(strict_types=1);

/**
 * Equipment register across all customers.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-toolbar">
	<div class="mn-search">
		<label class="mn-sr-only" for="mn-equipment-search"><?php p($l->t('Search equipment')); ?></label>
		<input type="search" id="mn-equipment-search" class="mn-input mn-search__input"
			placeholder="<?php p($l->t('Search by label, manufacturer, model or serial …')); ?>"
			autocomplete="off">
	</div>
</div>
<div id="mn-equipment-list" class="mn-listing" aria-busy="true"></div>
<nav id="mn-equipment-pagination" class="mn-pagination" aria-label="<?php p($l->t('Equipment pages')); ?>"></nav>
<?php require __DIR__ . '/common/page-end.php'; ?>
