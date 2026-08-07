<?php

declare(strict_types=1);

/**
 * Shared chrome for Settings underpages (horizontal section tabs — no Overview hub).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array $navUrls from page-start (optional)
 */

use OCA\MaintenanceCheck\Support\IconCatalog;
use OCA\MaintenanceCheck\Support\SettingsSections;

$settingsSection = (string)($_['settingsSection'] ?? '');
$settingsSections = is_array($_['settingsSections'] ?? null) ? $_['settingsSections'] : SettingsSections::all($l);
$sectionNav = is_array($_['settingsSectionUrls'] ?? null) ? $_['settingsSectionUrls'] : [];
if ($sectionNav === [] && isset($navUrls) && is_array($navUrls['settingsSections'] ?? null)) {
	$sectionNav = $navUrls['settingsSections'];
}
?>
<nav class="mn-settings-subnav" aria-label="<?php p($l->t('Settings sections')); ?>">
	<?php foreach ($settingsSections as $sec) {
		$sid = (string)($sec['id'] ?? '');
		if ($sid === '') {
			continue;
		}
		$href = (string)($sectionNav[$sid] ?? '#');
		$active = $settingsSection === $sid;
		?>
		<a class="mn-settings-subnav__link<?php if ($active) { ?> is-active<?php } ?>"
			href="<?php p($href); ?>"
			<?php if ($active) { ?>aria-current="page"<?php } ?>>
			<span class="mn-settings-subnav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)($sec['icon'] ?? 'settings'), 'mn-nav__icon-svg')); ?></span>
			<span><?php p((string)($sec['title'] ?? $sid)); ?></span>
		</a>
	<?php } ?>
</nav>
