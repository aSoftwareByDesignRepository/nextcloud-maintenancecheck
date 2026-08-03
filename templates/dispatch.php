<?php

declare(strict_types=1);

/**
 * Dispatch board (W3) — Bachus: flat chips + day buckets (no nested day cards / lane boxes).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-dispatch-quickstart';
$qsKey = 'dispatch_quickstart_v2';
$qsLead = $l->t('Jobs without an owner — tap Assign, pick a person, done.');
$qsSteps = [
	[
		'title' => $l->t('1. Start with Needs owner'),
		'body' => $l->t('Only jobs waiting for a technician show first.'),
	],
	[
		'title' => $l->t('2. Tap Assign'),
		'body' => $l->t('Search and pick a person — never type a raw user id.'),
	],
	[
		'title' => $l->t('3. They work the job'),
		'body' => $l->t('Technicians open the work order to run the checklist and finish.'),
		'ctaLabel' => $l->t('Open Work orders'),
		'ctaLink' => 'workOrders',
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div
	id="mn-dispatch-toolbar"
	class="mn-dispatch-toolbar"
	role="group"
	aria-label="<?php p($l->t('Dispatch filters')); ?>"
>
	<button type="button" class="mn-chip is-active" data-mn-dispatch-filter="unassigned" aria-pressed="true"><?php p($l->t('Needs owner')); ?></button>
	<button type="button" class="mn-chip" data-mn-dispatch-filter="all" aria-pressed="false"><?php p($l->t('All open')); ?></button>
</div>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-dispatch-title">
	<h2 id="mn-dispatch-title" class="mn-sr-only"><?php p($l->t('Dispatch')); ?></h2>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-dispatch-board" class="mn-board mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
