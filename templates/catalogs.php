<?php

declare(strict_types=1);

/**
 * Catalogs — Bachus: one list at a time via chips (no five-card wall).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>
<?php
$qsId = 'mn-catalogs-quickstart';
$qsKey = 'catalogs_quickstart_v2';
$qsLead = $l->t('Pick a list, then add or edit — set once, reuse everywhere.');
$qsSteps = [
	[
		'title' => $l->t('1. Tap a chip'),
		'body' => $l->t('Equipment types, maintenance types, procedures, skills, or kits.'),
	],
	[
		'title' => $l->t('2. Add or edit'),
		'body' => $l->t('Use New in the toolbar, or tap a name to edit.'),
	],
	[
		'title' => $l->t('3. Reuse on jobs'),
		'body' => $l->t('Plans and work orders pick from these shared lists.'),
	],
];
require __DIR__ . '/parts/page-quickstart.php';
?>
<div class="mn-catalogs">
	<div
		id="mn-catalogs-toolbar"
		class="mn-catalogs-toolbar"
		role="tablist"
		aria-label="<?php p($l->t('Catalog lists')); ?>"
	>
		<button type="button" class="mn-chip is-active" role="tab" id="mn-catalog-tab-equip" data-mn-catalog="equip" aria-selected="true" aria-controls="mn-catalog-panel-equip"><?php p($l->t('Equipment types')); ?></button>
		<button type="button" class="mn-chip" role="tab" id="mn-catalog-tab-maint" data-mn-catalog="maint" aria-selected="false" aria-controls="mn-catalog-panel-maint"><?php p($l->t('Maintenance types')); ?></button>
		<button type="button" class="mn-chip" role="tab" id="mn-catalog-tab-procedures" data-mn-catalog="procedures" aria-selected="false" aria-controls="mn-catalog-panel-procedures"><?php p($l->t('Procedures')); ?></button>
		<button type="button" class="mn-chip" role="tab" id="mn-catalog-tab-skills" data-mn-catalog="skills" aria-selected="false" aria-controls="mn-catalog-panel-skills"><?php p($l->t('Skills')); ?></button>
		<button type="button" class="mn-chip" role="tab" id="mn-catalog-tab-kits" data-mn-catalog="kits" aria-selected="false" aria-controls="mn-catalog-panel-kits"><?php p($l->t('Kit templates')); ?></button>
	</div>

	<section
		id="mn-catalog-panel-equip"
		class="mn-card mn-card--table-solo mn-catalog-panel"
		role="tabpanel"
		aria-labelledby="mn-equip-types-title"
		data-mn-catalog-panel="equip"
	>
		<div class="mn-table-toolbar">
			<h2 id="mn-equip-types-title" class="mn-table-toolbar__title"><?php p($l->t('Equipment types')); ?></h2>
			<div id="mn-equip-types-actions" class="mn-table-toolbar__actions"></div>
		</div>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-equip-types" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>

	<section
		id="mn-catalog-panel-maint"
		class="mn-card mn-card--table-solo mn-catalog-panel"
		role="tabpanel"
		aria-labelledby="mn-maint-types-title"
		data-mn-catalog-panel="maint"
		hidden
	>
		<div class="mn-table-toolbar">
			<h2 id="mn-maint-types-title" class="mn-table-toolbar__title"><?php p($l->t('Maintenance types')); ?></h2>
			<div id="mn-maint-types-actions" class="mn-table-toolbar__actions"></div>
		</div>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-maint-types" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>

	<section
		id="mn-catalog-panel-procedures"
		class="mn-card mn-card--table-solo mn-catalog-panel"
		role="tabpanel"
		aria-labelledby="mn-procedures-title"
		data-mn-catalog-panel="procedures"
		hidden
	>
		<div class="mn-table-toolbar">
			<h2 id="mn-procedures-title" class="mn-table-toolbar__title"><?php p($l->t('Procedures')); ?></h2>
			<div id="mn-procedures-actions" class="mn-table-toolbar__actions"></div>
		</div>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-procedures" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>

	<section
		id="mn-catalog-panel-skills"
		class="mn-card mn-card--table-solo mn-catalog-panel"
		role="tabpanel"
		aria-labelledby="mn-skills-title"
		data-mn-catalog-panel="skills"
		hidden
	>
		<div class="mn-table-toolbar">
			<h2 id="mn-skills-title" class="mn-table-toolbar__title"><?php p($l->t('Skills')); ?></h2>
			<div id="mn-skills-actions" class="mn-table-toolbar__actions"></div>
		</div>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-skills" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>

	<section
		id="mn-catalog-panel-kits"
		class="mn-card mn-card--table-solo mn-catalog-panel"
		role="tabpanel"
		aria-labelledby="mn-kit-templates-title"
		data-mn-catalog-panel="kits"
		hidden
	>
		<div class="mn-table-toolbar">
			<h2 id="mn-kit-templates-title" class="mn-table-toolbar__title"><?php p($l->t('Kit templates')); ?></h2>
			<div id="mn-kit-templates-actions" class="mn-table-toolbar__actions"></div>
		</div>
		<div class="mn-card__body mn-card__body--table">
			<div id="mn-kit-templates" class="mn-listing" aria-busy="true"></div>
		</div>
	</section>
</div>
<?php require __DIR__ . '/common/page-end.php'; ?>
