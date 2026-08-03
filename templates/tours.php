<?php

declare(strict_types=1);

/**
 * Day tours (W3) — Bachus: date toolbar outside the list; one bucket per tech.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-tours-quickstart';
$qsKey = 'tours_quickstart_v3';
$qsLead = $l->t('Pick the day, create a tour, add stops — then open a stop to work.');
$qsSteps = [
	[
		'title' => $l->t('1. Pick the day'),
		'body' => $l->t('Use Today or the arrows. The list updates immediately.'),
	],
	[
		'title' => $l->t('2. Create a tour'),
		'body' => $l->t('Pick a technician. Then tap Add stop.'),
	],
	[
		'title' => $l->t('3. Open a stop'),
		'body' => $l->t('Tap Open on a stop to run the work order.'),
		'ctaLabel' => $l->t('Open Work orders'),
		'ctaLink' => 'workOrders',
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div
	id="mn-tours-toolbar"
	class="mn-tours-toolbar"
	role="group"
	aria-label="<?php p($l->t('Tour date')); ?>"
></div>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-tours-title">
	<h2 id="mn-tours-title" class="mn-sr-only"><?php p($l->t('Day tours')); ?></h2>
	<div class="mn-card__body mn-card__body--table">
		<div id="mn-tours-board" class="mn-board mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
