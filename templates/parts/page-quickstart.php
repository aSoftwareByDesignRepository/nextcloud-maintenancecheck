<?php

declare(strict_types=1);

/**
 * Dismissible Quick start card — DutyCheck markup/CSS parity.
 *
 * Expected locals:
 *  - string $qsId
 *  - string $qsKey
 *  - string $qsLead
 *  - list<array{title:string,body:string,ctaLabel?:string,ctaLink?:string}> $qsSteps
 *
 * @var \OCP\IL10N $l
 * @var string $qsId
 * @var string $qsKey
 * @var string $qsLead
 * @var list<array<string, string>> $qsSteps
 */

$qsId = (string)($qsId ?? '');
$qsKey = (string)($qsKey ?? '');
$qsLead = (string)($qsLead ?? '');
$qsSteps = is_array($qsSteps ?? null) ? $qsSteps : [];
if ($qsId === '' || $qsKey === '' || $qsSteps === []) {
	return;
}
$titleId = $qsId . '-title';
?>
<section class="mn-card mn-quickstart-card" id="<?php p($qsId); ?>" hidden aria-labelledby="<?php p($titleId); ?>">
	<header class="mn-section__header">
		<div>
			<h2 id="<?php p($titleId); ?>"><?php p($l->t('Quick start')); ?></h2>
			<?php if ($qsLead !== ''): ?>
				<p class="mn-section__sub"><?php p($qsLead); ?></p>
			<?php endif; ?>
		</div>
		<button type="button" class="mn-hint-dismiss" data-mn-dismiss-hint="<?php p($qsKey); ?>" aria-describedby="<?php p($titleId); ?>">
			<?php p($l->t('Hide tips')); ?>
		</button>
	</header>
	<ol class="mn-quickstart">
		<?php foreach ($qsSteps as $step):
			$title = (string)($step['title'] ?? '');
			$body = (string)($step['body'] ?? '');
			$ctaLabel = (string)($step['ctaLabel'] ?? '');
			$ctaLink = (string)($step['ctaLink'] ?? '');
			?>
			<li class="mn-quickstart__item">
				<strong><?php p($title); ?></strong>
				<p><?php p($body); ?></p>
				<?php if ($ctaLabel !== '' && $ctaLink !== ''): ?>
					<a class="mn-btn mn-btn--secondary button mn-quickstart__cta" href="#" data-mn-link="<?php p($ctaLink); ?>"><?php p($ctaLabel); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
