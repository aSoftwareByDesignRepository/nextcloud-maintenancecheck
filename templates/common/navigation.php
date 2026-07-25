<?php

declare(strict_types=1);

use OCA\MaintenanceCheck\Support\IconCatalog;
use OCP\Util;

/**
 * Sidebar navigation — ArbeitszeitCheck shell parity.
 *
 * @var \OCP\IL10N $l
 * @var string $pageId
 * @var bool $isAppAdmin
 * @var bool $isOffice
 * @var string $urlsJson
 * @var string $roleLabel
 */

Util::addScript('maintenancecheck', 'common/navigation');

$navUrls = json_decode($urlsJson, true)['pages'] ?? [];
$mnNavIcon = static function (string $name): string {
	return IconCatalog::render($name, 'mn-nav__icon-svg');
};

$activeNavId = match ($pageId) {
	'customer-detail' => 'customers',
	'equipment-detail' => 'equipment',
	default => $pageId,
};

$isDue = $activeNavId === 'due';
$isVisits = $activeNavId === 'visits';
$isCustomers = $activeNavId === 'customers';
$isEquipment = $activeNavId === 'equipment';
$isCatalogs = $activeNavId === 'catalogs';
$isSettings = $activeNavId === 'settings';
$isAdmin = $isSettings;
?>
<div id="maintenancecheck-app" class="maintenancecheck-app">
	<a href="#app-navigation" class="skip-link mn-skip-link--nav"><?php p($l->t('Skip to app navigation')); ?></a>

	<div id="app-navigation" class="mn-nav" role="navigation" aria-label="<?php p($l->t('Main navigation')); ?>">
		<div class="sidebar-header">
			<div class="app-brand">
				<div class="app-icon" aria-hidden="true">
					<span class="mn-nav__icon"><?php print_unescaped($mnNavIcon('wrench')); ?></span>
				</div>
				<div class="app-info">
					<h3><?php p($l->t('MaintenanceCheck')); ?></h3>
					<p class="app-brand__subtitle"><?php p($l->t('Recurring maintenance')); ?></p>
					<p class="app-brand__role"><?php p($roleLabel); ?></p>
				</div>
			</div>
		</div>

		<ul class="nav-menu">
			<li class="<?php p($isDue ? 'active' : ''); ?>" <?php if ($isDue): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['due'] ?? '#')); ?>"
					title="<?php p($l->t('Due board: What needs a visit')); ?>"
					aria-label="<?php p($l->t('Go to due board')); ?>">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('layout-grid')); ?></span>
					<span><?php p($l->t('Due board')); ?></span>
				</a>
			</li>
			<li class="<?php p($isVisits ? 'active' : ''); ?>" <?php if ($isVisits): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['visits'] ?? '#')); ?>"
					title="<?php p($l->t('Visits: History and filters')); ?>"
					aria-label="<?php p($l->t('Go to visits')); ?>">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('calendar-check')); ?></span>
					<span><?php p($l->t('Visits')); ?></span>
				</a>
			</li>
			<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
			<li class="nav-item-has-children <?php p(($isCustomers || $isEquipment || $isCatalogs) ? 'is-open' : ''); ?>">
				<button class="nav-parent-toggle" type="button"
					aria-expanded="<?php p(($isCustomers || $isEquipment || $isCatalogs) ? 'true' : 'false'); ?>"
					aria-controls="mn-register-subnav">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('list-checks')); ?></span>
					<span><?php p($l->t('Register')); ?></span>
					<span class="nav-parent-chevron" aria-hidden="true"></span>
				</button>
				<ul id="mn-register-subnav" class="nav-submenu" <?php p(($isCustomers || $isEquipment || $isCatalogs) ? '' : 'hidden'); ?>>
					<li class="<?php p($isCustomers ? 'active' : ''); ?>" <?php if ($isCustomers): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['customers'] ?? '#')); ?>"
							title="<?php p($l->t('Customers: Sites you service')); ?>"
							aria-label="<?php p($l->t('Go to customers')); ?>">
							<span><?php p($l->t('Customers')); ?></span>
						</a>
					</li>
					<li class="<?php p($isEquipment ? 'active' : ''); ?>" <?php if ($isEquipment): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['equipment'] ?? '#')); ?>"
							title="<?php p($l->t('Equipment: Units you maintain')); ?>"
							aria-label="<?php p($l->t('Go to equipment')); ?>">
							<span><?php p($l->t('Equipment')); ?></span>
						</a>
					</li>
					<li class="<?php p($isCatalogs ? 'active' : ''); ?>" <?php if ($isCatalogs): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['catalogs'] ?? '#')); ?>"
							title="<?php p($l->t('Catalogs: Types and intervals')); ?>"
							aria-label="<?php p($l->t('Go to catalogs')); ?>">
							<span><?php p($l->t('Catalogs')); ?></span>
						</a>
					</li>
				</ul>
			</li>
			<?php if ($isAppAdmin): ?>
				<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
				<li class="nav-item-has-children <?php p($isAdmin ? 'is-open' : ''); ?>">
					<button class="nav-parent-toggle" type="button"
						aria-expanded="<?php p($isAdmin ? 'true' : 'false'); ?>"
						aria-controls="mn-admin-subnav">
						<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('shield')); ?></span>
						<span><?php p($l->t('Administration')); ?></span>
						<span class="nav-parent-chevron" aria-hidden="true"></span>
					</button>
					<ul id="mn-admin-subnav" class="nav-submenu" <?php p($isAdmin ? '' : 'hidden'); ?>>
						<li class="<?php p($isSettings ? 'active' : ''); ?>" <?php if ($isSettings): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['settings'] ?? '#')); ?>"
								title="<?php p($l->t('Access, license, support')); ?>"
								aria-label="<?php p($l->t('Open settings')); ?>">
								<span><?php p($l->t('Settings')); ?></span>
							</a>
						</li>
					</ul>
				</li>
			<?php endif; ?>
		</ul>
	</div>
