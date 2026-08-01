<?php

declare(strict_types=1);

/**
 * W6 exception board — blocked / overdue / kit incomplete (CORE §20 AC-W6-10).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card" aria-labelledby="mn-exceptions-title">
	<header class="mn-card__header">
		<h2 id="mn-exceptions-title" class="mn-card__title"><?php p($l->t('Exceptions')); ?></h2>
		<p class="mn-card__lead"><?php p($l->t('Work that is blocked, overdue, or missing a packed kit.')); ?></p>
	</header>
	<div class="mn-card__body">
		<div class="mn-filter-bar" role="group" aria-label="<?php p($l->t('Exception filters')); ?>">
			<button type="button" class="mn-chip is-active" data-mn-exception-filter="all"><?php p($l->t('All')); ?></button>
			<button type="button" class="mn-chip" data-mn-exception-filter="blocked"><?php p($l->t('Blocked')); ?></button>
			<button type="button" class="mn-chip" data-mn-exception-filter="overdue"><?php p($l->t('Overdue')); ?></button>
			<button type="button" class="mn-chip" data-mn-exception-filter="kit"><?php p($l->t('Kit incomplete')); ?></button>
		</div>
		<div id="mn-exceptions-board" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
