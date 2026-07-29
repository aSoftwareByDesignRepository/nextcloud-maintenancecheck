<?php

declare(strict_types=1);

/**
 * Day tours shell (W3) — stop-by-stop plans.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card" aria-labelledby="mn-tours-title">
	<header class="mn-card__header">
		<h2 id="mn-tours-title" class="mn-card__title"><?php p($l->t('Day tours')); ?></h2>
		<p class="mn-card__lead"><?php p($l->t('Stop-by-stop plans for each day. Open a work order from a stop to execute it.')); ?></p>
	</header>
	<div class="mn-card__body">
		<div id="mn-tours-board" class="mn-listing" aria-busy="true"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
