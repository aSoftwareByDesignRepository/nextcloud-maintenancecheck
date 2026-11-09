<?php

declare(strict_types=1);

/**
 * Technician calendar (FA-2) — month/week grid, server-scoped events.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-cal-toolbar" role="group" aria-label="<?php p($l->t('Calendar controls')); ?>">
	<div class="mn-cal-toolbar__nav" role="group" aria-label="<?php p($l->t('Change period')); ?>">
		<button type="button" class="mn-btn mn-btn--tertiary" id="mn-cal-prev" aria-label="<?php p($l->t('Previous period')); ?>">‹</button>
		<button type="button" class="mn-btn mn-btn--secondary" id="mn-cal-today"><?php p($l->t('Today')); ?></button>
		<button type="button" class="mn-btn mn-btn--tertiary" id="mn-cal-next" aria-label="<?php p($l->t('Next period')); ?>">›</button>
	</div>
	<h2 id="mn-cal-period" class="mn-cal-period" aria-live="polite"></h2>
	<div class="mn-cal-toolbar__view" role="group" aria-label="<?php p($l->t('Calendar view')); ?>">
		<button type="button" class="mn-chip" id="mn-cal-view-month" data-mn-cal-view="month" aria-pressed="false"><?php p($l->t('Month')); ?></button>
		<button type="button" class="mn-chip" id="mn-cal-view-week" data-mn-cal-view="week" aria-pressed="false"><?php p($l->t('Week')); ?></button>
	</div>
</div>
<div id="mn-cal-filters" class="mn-cal-filters" hidden></div>
<section class="mn-card" aria-labelledby="mn-cal-title">
	<h2 id="mn-cal-title" class="mn-sr-only"><?php p($l->t('Calendar')); ?></h2>
	<div class="mn-card__body">
		<div id="mn-cal-board" class="mn-cal-board" aria-busy="true"></div>
		<div id="mn-cal-agenda" class="mn-cal-agenda"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
