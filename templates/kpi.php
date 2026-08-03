<?php

declare(strict_types=1);

/**
 * W6 Ops KPI snapshot — PM compliance, overdue, MTTR (CORE §20 AC-W6-8).
 * Bachus: compact toolbar (no soft-band title/lead glued onto the content).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-kpi-quickstart';
$qsKey = 'kpi_quickstart_v1';
$qsLead = $l->t('Glanceable health of preventive and inspection work.');
$qsSteps = [
	[
		'title' => $l->t('1. Pick a window'),
		'body' => $l->t('Switch between 30 and 90 days.'),
	],
	[
		'title' => $l->t('2. Read the tiles'),
		'body' => $l->t('Compliance, overdue counts and the status table update when you reload.'),
	],
	[
		'title' => $l->t('3. Export if needed'),
		'body' => $l->t('Office can download a CSV for reporting.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<section class="mn-card mn-card--table-solo" aria-labelledby="mn-kpi-title">
	<div class="mn-table-toolbar">
		<h2 id="mn-kpi-title" class="mn-table-toolbar__title"><?php p($l->t('Ops KPI')); ?></h2>
		<div class="mn-table-toolbar__actions mn-kpi-actions">
			<div class="mn-kpi-actions__chips" role="group" aria-label="<?php p($l->t('KPI window')); ?>">
				<button type="button" class="mn-chip is-active" data-mn-kpi-days="30" aria-pressed="true"><?php p($l->t('30 days')); ?></button>
				<button type="button" class="mn-chip" data-mn-kpi-days="90" aria-pressed="false"><?php p($l->t('90 days')); ?></button>
			</div>
			<?php if (!empty($isOffice) || !empty($isAppAdmin)): ?>
				<a class="mn-btn mn-btn--secondary button" id="mn-kpi-csv" href="#"
					aria-label="<?php p($l->t('Download KPI as CSV')); ?>">
					<?php p($l->t('Download CSV')); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<div class="mn-card__body">
		<div id="mn-kpi-snapshot" class="mn-kpi-grid" aria-busy="true" role="region" aria-live="polite"></div>
	</div>
</section>
<?php require __DIR__ . '/common/page-end.php'; ?>
