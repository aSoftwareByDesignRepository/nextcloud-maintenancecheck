<?php

declare(strict_types=1);

/**
 * Work order detail — one job sheet (W1 phone execute).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-wo-detail-quickstart';
$qsKey = 'work_order_detail_quickstart_v3';
$qsLead = $l->t('Big button moves the job. Checklist when you start. More for kit and comments.');
$qsSteps = [
	[
		'title' => $l->t('Tip'),
		'body' => $l->t('Hide this tip anytime — the job sheet below is the whole page.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div id="mn-wo-detail" class="mn-wo-detail" aria-busy="true">
	<div class="mn-skeleton" aria-hidden="true">
		<div class="mn-skeleton__bar"></div>
		<div class="mn-skeleton__bar"></div>
		<div class="mn-skeleton__bar"></div>
	</div>
</div>
<?php require __DIR__ . '/common/page-end.php'; ?>
