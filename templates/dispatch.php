<?php

declare(strict_types=1);

/**
 * Dispatch board shell (W3 planning) — office assigns open work orders.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card" aria-labelledby="mn-dispatch-title">
	<header class="mn-card__header">
		<h2 id="mn-dispatch-title" class="mn-card__title"><?php p($l->t('Dispatch')); ?></h2>
		<p class="mn-card__lead"><?php p($l->t('Assign open work orders to technicians. Open a job to change status or add details.')); ?></p>
	</header>
	<div class="mn-card__body">
		<div id="mn-dispatch-board" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
