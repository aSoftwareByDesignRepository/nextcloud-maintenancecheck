<?php

declare(strict_types=1);

/**
 * Inventory flange settings underpage host (filled by pageSettings() in js/app.js).
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="mn-card" aria-labelledby="mn-inventory-flange-title">
	<header class="mn-card__header">
		<div class="mn-card__header-text">
			<h2 id="mn-inventory-flange-title" class="mn-card__title"><?php p($l->t('Inventory stock issue')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('Optional: when a work order is finished, deduct kit parts from InventoryCheck. Off by default so upgrades never move stock alone.')); ?></p>
		</div>
	</header>
	<div id="mn-settings-inventory-flange" class="mn-card__body mn-card--form" aria-busy="true"></div>
</section>
