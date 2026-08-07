<?php

declare(strict_types=1);

/**
 * Settings underpage chrome — subnav + one section partial.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\MaintenanceCheck\Support\SettingsSections;

$sectionId = (string)($_['settingsSection'] ?? '');
$section = is_array($_['settingsSectionMeta'] ?? null)
	? $_['settingsSectionMeta']
	: SettingsSections::get($l, $sectionId);
if ($section === null) {
	return;
}

require __DIR__ . '/common/page-start.php';

$partial = __DIR__ . '/settings/' . $sectionId . '.php';
?>
<?php if (!$isAppAdmin): ?>
	<div class="mn-empty">
		<p class="mn-empty__title"><?php p($l->t('Settings are managed by your MaintenanceCheck administrator.')); ?></p>
		<p class="mn-empty__hint"><?php p($l->t('There is nothing to configure for your account here.')); ?></p>
	</div>
<?php else: ?>
	<div class="mn-settings mn-page-stack" data-mn-settings-section="<?php p($sectionId); ?>">
		<?php include __DIR__ . '/parts/settings-subnav.php'; ?>
		<?php
		$qsId = 'mn-settings-section-quickstart';
		$qsKey = 'settings_section_' . $sectionId . '_quickstart_v1';
		$qsLead = $l->t('Change only what you need on this page, then save. Other settings stay unchanged.');
		$qsSteps = [
			[
				'title' => $l->t('1. Read the explanation'),
				'body' => $l->t('The text under the title tells you what this area controls.'),
			],
			[
				'title' => $l->t('2. Make your changes'),
				'body' => $l->t('Use the search pickers for people and groups — never type raw ids.'),
			],
			[
				'title' => $l->t('3. Save this page'),
				'body' => $l->t('Saving updates only this area. Use the settings menu to open another area.'),
			],
		];
		require __DIR__ . '/parts/page-quickstart.php';
		?>
		<?php if (is_readable($partial)) {
			include $partial;
		} else { ?>
			<div class="mn-banner mn-banner--warn" role="alert"><?php p($l->t('This settings page is not available.')); ?></div>
		<?php } ?>
	</div>
<?php endif; ?>
<?php require __DIR__ . '/common/page-end.php'; ?>
