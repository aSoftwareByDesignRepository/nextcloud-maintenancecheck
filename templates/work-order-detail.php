<?php

declare(strict_types=1);

/**
 * Work order detail — checklist, photos, status (W1 phone execute).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div id="mn-wo-detail" class="mn-wo-detail" aria-busy="true">
	<div class="mn-skeleton" aria-hidden="true">
		<div class="mn-skeleton__bar"></div>
		<div class="mn-skeleton__bar"></div>
		<div class="mn-skeleton__bar"></div>
	</div>
</div>
<?php require __DIR__ . '/common/page-end.php'; ?>
