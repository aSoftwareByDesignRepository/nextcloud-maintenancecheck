<?php

declare(strict_types=1);

/**
 * W6 Ops KPI snapshot — PM compliance, overdue, MTTR (CORE §20 AC-W6-8).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<section class="mn-card" aria-labelledby="mn-kpi-title">
	<header class="mn-card__header mn-card__header--split">
		<div>
			<h2 id="mn-kpi-title" class="mn-card__title"><?php p($l->t('Ops KPI')); ?></h2>
			<p class="mn-card__lead"><?php p($l->t('A simple snapshot of preventive maintenance health for the last 30 days.')); ?></p>
		</div>
		<?php if (!empty($isOffice) || !empty($isAppAdmin)): ?>
			<a class="mn-btn mn-btn--secondary" id="mn-kpi-csv" href="#"
				aria-label="<?php p($l->t('Download KPI as CSV')); ?>">
				<?php p($l->t('Download CSV')); ?>
			</a>
		<?php endif; ?>
	</header>
	<div class="mn-card__body">
		<div id="mn-kpi-snapshot" class="mn-kpi-grid" aria-busy="true" role="region" aria-live="polite"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
