<?php

declare(strict_types=1);

/**
 * Catalogs: equipment types, maintenance types, procedures, skills, kit templates.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<div class="mn-columns">
	<section class="mn-card" aria-labelledby="mn-equip-types-title">
		<header class="mn-card__header mn-card__header--with-actions">
			<h2 id="mn-equip-types-title" class="mn-card__title"><?php p($l->t('Equipment types')); ?></h2>
			<div id="mn-equip-types-actions" class="mn-card__actions"></div>
		</header>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-equip-types" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
	<section class="mn-card" aria-labelledby="mn-maint-types-title">
		<header class="mn-card__header mn-card__header--with-actions">
			<h2 id="mn-maint-types-title" class="mn-card__title"><?php p($l->t('Maintenance types')); ?></h2>
			<div id="mn-maint-types-actions" class="mn-card__actions"></div>
		</header>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-maint-types" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
	<section class="mn-card" aria-labelledby="mn-procedures-title">
		<header class="mn-card__header mn-card__header--with-actions">
			<div>
				<h2 id="mn-procedures-title" class="mn-card__title"><?php p($l->t('Procedures')); ?></h2>
				<p class="mn-card__lead"><?php p($l->t('Checklist templates for work orders. Create, fork, import and export packs from the actions.')); ?></p>
			</div>
			<div id="mn-procedures-actions" class="mn-card__actions"></div>
		</header>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-procedures" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
	<section class="mn-card" aria-labelledby="mn-skills-title">
		<header class="mn-card__header mn-card__header--with-actions">
			<div>
				<h2 id="mn-skills-title" class="mn-card__title"><?php p($l->t('Skills')); ?></h2>
				<p class="mn-card__lead"><?php p($l->t('Skill codes for work orders. Use Grant skills to assign them to technicians.')); ?></p>
			</div>
			<div id="mn-skills-actions" class="mn-card__actions"></div>
		</header>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-skills" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
	<section class="mn-card" aria-labelledby="mn-kit-templates-title">
		<header class="mn-card__header mn-card__header--with-actions">
			<div>
				<h2 id="mn-kit-templates-title" class="mn-card__title"><?php p($l->t('Kit templates')); ?></h2>
				<p class="mn-card__lead"><?php p($l->t('Reusable parts lists for packing vans before a job.')); ?></p>
			</div>
			<div id="mn-kit-templates-actions" class="mn-card__actions"></div>
		</header>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-kit-templates" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
</div>
<?php require __DIR__ . '/common/page-end.php'; ?>
