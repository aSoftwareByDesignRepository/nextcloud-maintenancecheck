<?php

declare(strict_types=1);

use OCA\MaintenanceCheck\Support\IconCatalog;

/**
 * Rendered with HTTP 403 when the L2 gate denies a page request
 * (SPEC §3 denial behaviour).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

\OCP\Util::addStyle('maintenancecheck', 'app');
?>
<div id="app-content" class="mn-app mn-app--denied">
	<main class="mn-denied" aria-labelledby="mn-denied-title">
		<div class="mn-denied__card">
			<span class="mn-denied__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('shield', 'mn-icon--xl')); ?>
			</span>
			<h1 id="mn-denied-title" class="mn-denied__title"><?php p($l->t('No access to MaintenanceCheck')); ?></h1>
			<p class="mn-denied__message"><?php p((string)($_['message'] ?? '')); ?></p>
			<p class="mn-denied__hint"><?php p((string)($_['hint'] ?? '')); ?></p>
			<a class="mn-btn mn-btn--primary" href="<?php p((string)($_['homeUrl'] ?? '/')); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
		</div>
	</main>
</div>
