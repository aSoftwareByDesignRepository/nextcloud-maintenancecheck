<?php

declare(strict_types=1);

/**
 * Due board (S8): overdue / today / next 7 days / later (≤ 30 days).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-toolbar" role="group" aria-label="<?php p($l->t('Due board filter')); ?>">
	<label class="mn-switch">
		<input type="checkbox" id="mn-due-mine" class="mn-switch__input">
		<span class="mn-switch__label"><?php p($l->t('Only my visits')); ?></span>
	</label>
	<p class="mn-toolbar__meta" id="mn-due-today"></p>
</div>
<div id="mn-due-board" class="mn-board" aria-busy="true">
	<section class="mn-bucket mn-bucket--overdue" aria-labelledby="mn-bucket-title-overdue" data-bucket="overdue">
		<h2 class="mn-bucket__title" id="mn-bucket-title-overdue"></h2>
		<div class="mn-bucket__list" data-bucket-list="overdue"></div>
	</section>
	<section class="mn-bucket mn-bucket--today" aria-labelledby="mn-bucket-title-today" data-bucket="today">
		<h2 class="mn-bucket__title" id="mn-bucket-title-today"></h2>
		<div class="mn-bucket__list" data-bucket-list="today"></div>
	</section>
	<section class="mn-bucket mn-bucket--next7" aria-labelledby="mn-bucket-title-next7" data-bucket="next7">
		<h2 class="mn-bucket__title" id="mn-bucket-title-next7"></h2>
		<div class="mn-bucket__list" data-bucket-list="next7"></div>
	</section>
	<section class="mn-bucket mn-bucket--later" aria-labelledby="mn-bucket-title-later" data-bucket="later">
		<h2 class="mn-bucket__title" id="mn-bucket-title-later"></h2>
		<div class="mn-bucket__list" data-bucket-list="later"></div>
	</section>
</div>
<div id="mn-due-empty" class="mn-empty" hidden></div>
<?php require __DIR__ . '/common/page-end.php'; ?>
