<?php

declare(strict_types=1);

/**
 * W6 exception board — Bachus: flat chips only (no soft-band lead glued onto filters).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-exceptions-quickstart';
$qsKey = 'exceptions_quickstart_v2';
$qsLead = $l->t('Jobs stuck for any reason — tap a chip, then open the job.');
$qsSteps = [
	[
		'title' => $l->t('1. Tap a chip'),
		'body' => $l->t('All, or one problem type. The list updates immediately.'),
	],
	[
		'title' => $l->t('2. Open the job'),
		'body' => $l->t('Fix the blocker on the work order.'),
		'ctaLabel' => $l->t('Open Work orders'),
		'ctaLink' => 'workOrders',
	],
	[
		'title' => $l->t('3. Clear the board'),
		'body' => $l->t('When the issue is gone, the job leaves this list.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div
	id="mn-exceptions-toolbar"
	class="mn-exceptions-toolbar"
	role="group"
	aria-label="<?php p($l->t('Exception filters')); ?>"
>
	<button type="button" class="mn-chip is-active" data-mn-exception-filter="all" aria-pressed="true"><?php p($l->t('All')); ?></button>
	<button type="button" class="mn-chip" data-mn-exception-filter="blocked" aria-pressed="false"><?php p($l->t('Blocked')); ?></button>
	<button type="button" class="mn-chip" data-mn-exception-filter="overdue" aria-pressed="false"><?php p($l->t('Overdue')); ?></button>
	<button type="button" class="mn-chip" data-mn-exception-filter="kit" aria-pressed="false"><?php p($l->t('Kit incomplete')); ?></button>
	<button type="button" class="mn-chip" data-mn-exception-filter="skills" aria-pressed="false"><?php p($l->t('Skills missing')); ?></button>
</div>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-exceptions-title">
	<h2 id="mn-exceptions-title" class="mn-sr-only"><?php p($l->t('Exceptions')); ?></h2>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-exceptions-board" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
