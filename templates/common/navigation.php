<?php

declare(strict_types=1);

use OCA\MaintenanceCheck\Support\IconCatalog;
use OCP\Util;

/**
 * Sidebar navigation — ArbeitszeitCheck shell parity + DutyCheck-style hints.
 *
 * @var \OCP\IL10N $l
 * @var string $pageId
 * @var bool $isAppAdmin
 * @var bool $isOffice
 * @var string $urlsJson
 * @var string $roleLabel
 */

Util::addScript('maintenancecheck', 'common/navigation');
// Soft keyboard: keep focused notes/inputs above the IME on phones.
Util::addScript('maintenancecheck', 'common/keep-focused-visible');


$navUrls = json_decode($urlsJson, true)['pages'] ?? [];
$mnNavIcon = static function (string $name): string {
	return IconCatalog::render($name, 'mn-nav__icon-svg');
};

$activeNavId = match ($pageId) {
	'customer-detail' => 'customers',
	'equipment-detail' => 'equipment',
	'work-order-detail' => 'work-orders',
	default => str_starts_with($pageId, 'settings-') ? 'settings' : $pageId,
};

$isDue = $activeNavId === 'due';
$isVisits = $activeNavId === 'visits';
$isWorkOrders = $activeNavId === 'work-orders';
$isDispatch = $activeNavId === 'dispatch';
$isTours = $activeNavId === 'tours';
$isKpi = $activeNavId === 'kpi';
$isExceptions = $activeNavId === 'exceptions';
$isCustomers = $activeNavId === 'customers';
$isEquipment = $activeNavId === 'equipment';
$isCatalogs = $activeNavId === 'catalogs';
$isSettings = $activeNavId === 'settings' || str_starts_with((string)$pageId, 'settings-');
$activeSettingsSection = str_starts_with((string)$pageId, 'settings-')
	? substr((string)$pageId, strlen('settings-'))
	: '';
$settingsSectionNav = is_array($navUrls['settingsSections'] ?? null) ? $navUrls['settingsSections'] : [];
$isAdmin = $isSettings;
$isPlanning = $isDispatch || $isTours || $isKpi || $isExceptions;

$mnNavLabel = static function (string $name, string $hint) use ($l): void {
	?>
	<span class="mn-nav__label">
		<span class="mn-nav__name"><?php p($name); ?></span>
		<span class="mn-nav__hint"><?php p($hint); ?></span>
	</span>
	<?php
};
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
					<?php $mnNavLabel($l->t('Due board'), $l->t('What needs a visit — overdue first')); ?>
				</a>
			</li>
			<li class="<?php p($isVisits ? 'active' : ''); ?>" <?php if ($isVisits): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['visits'] ?? '#')); ?>"
					title="<?php p($l->t('Visits: History and filters')); ?>"
					aria-label="<?php p($l->t('Go to visits')); ?>">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('calendar-check')); ?></span>
					<?php $mnNavLabel($l->t('Visits'), $l->t('History and filters')); ?>
				</a>
			</li>
			<li class="<?php p($isWorkOrders ? 'active' : ''); ?>" <?php if ($isWorkOrders): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['workOrders'] ?? '#')); ?>"
					title="<?php p($l->t('Work orders: Planned and corrective work')); ?>"
					aria-label="<?php p($l->t('Go to work orders')); ?>">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('clipboard-list')); ?></span>
					<?php $mnNavLabel($l->t('Work orders'), $l->t('Planned and corrective work')); ?>
				</a>
			</li>
			<?php if ($isOffice || $isAppAdmin): ?>
				<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
				<li class="nav-item-has-children <?php p($isPlanning ? 'is-open' : ''); ?>">
					<button class="nav-parent-toggle" type="button"
						aria-expanded="<?php p($isPlanning ? 'true' : 'false'); ?>"
						aria-controls="mn-planning-subnav">
						<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('kanban')); ?></span>
						<?php $mnNavLabel($l->t('Planning'), $l->t('Assign jobs and day tours')); ?>
						<span class="nav-parent-chevron" aria-hidden="true"></span>
					</button>
					<ul id="mn-planning-subnav" class="nav-submenu" <?php p($isPlanning ? '' : 'hidden'); ?>>
						<li class="<?php p($isDispatch ? 'active' : ''); ?>" <?php if ($isDispatch): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['dispatch'] ?? '#')); ?>"
								title="<?php p($l->t('Dispatch: Assign open work orders')); ?>"
								aria-label="<?php p($l->t('Go to dispatch')); ?>">
								<?php $mnNavLabel($l->t('Dispatch'), $l->t('Assign open jobs')); ?>
							</a>
						</li>
						<li class="<?php p($isTours ? 'active' : ''); ?>" <?php if ($isTours): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['tours'] ?? '#')); ?>"
								title="<?php p($l->t('Day tours: Stop-by-stop plans')); ?>"
								aria-label="<?php p($l->t('Go to day tours')); ?>">
								<?php $mnNavLabel($l->t('Day tours'), $l->t('Stop-by-stop day plans')); ?>
							</a>
						</li>
						<li class="<?php p($isExceptions ? 'active' : ''); ?>" <?php if ($isExceptions): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['exceptions'] ?? '#')); ?>"
								title="<?php p($l->t('Exceptions: Blocked and overdue work')); ?>"
								aria-label="<?php p($l->t('Go to exceptions')); ?>">
								<?php $mnNavLabel($l->t('Exceptions'), $l->t('Blocked and overdue work')); ?>
							</a>
						</li>
						<li class="<?php p($isKpi ? 'active' : ''); ?>" <?php if ($isKpi): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['kpi'] ?? '#')); ?>"
								title="<?php p($l->t('Ops KPI: Compliance and overdue snapshot')); ?>"
								aria-label="<?php p($l->t('Go to ops KPI')); ?>">
								<?php $mnNavLabel($l->t('Ops KPI'), $l->t('Compliance snapshot')); ?>
							</a>
						</li>
					</ul>
				</li>
			<?php else: ?>
				<li class="<?php p($isTours ? 'active' : ''); ?>" <?php if ($isTours): ?>aria-current="page"<?php endif; ?>>
					<a href="<?php p((string)($navUrls['tours'] ?? '#')); ?>"
						title="<?php p($l->t('Day tours: Stop-by-stop plans')); ?>"
						aria-label="<?php p($l->t('Go to day tours')); ?>">
						<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('route')); ?></span>
						<?php $mnNavLabel($l->t('My tour'), $l->t('Your stops today')); ?>
					</a>
				</li>
			<?php endif; ?>
			<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
			<li class="nav-item-has-children <?php p(($isCustomers || $isEquipment || $isCatalogs) ? 'is-open' : ''); ?>">
				<button class="nav-parent-toggle" type="button"
					aria-expanded="<?php p(($isCustomers || $isEquipment || $isCatalogs) ? 'true' : 'false'); ?>"
					aria-controls="mn-register-subnav">
					<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('list-checks')); ?></span>
					<?php $mnNavLabel($l->t('Register'), $l->t('Customers, equipment, catalogs')); ?>
					<span class="nav-parent-chevron" aria-hidden="true"></span>
				</button>
				<ul id="mn-register-subnav" class="nav-submenu" <?php p(($isCustomers || $isEquipment || $isCatalogs) ? '' : 'hidden'); ?>>
					<li class="<?php p($isCustomers ? 'active' : ''); ?>" <?php if ($isCustomers): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['customers'] ?? '#')); ?>"
							title="<?php p($l->t('Customers: Sites you service')); ?>"
							aria-label="<?php p($l->t('Go to customers')); ?>">
							<?php $mnNavLabel($l->t('Customers'), $l->t('Sites you service')); ?>
						</a>
					</li>
					<li class="<?php p($isEquipment ? 'active' : ''); ?>" <?php if ($isEquipment): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['equipment'] ?? '#')); ?>"
							title="<?php p($l->t('Equipment: Units you maintain')); ?>"
							aria-label="<?php p($l->t('Go to equipment')); ?>">
							<?php $mnNavLabel($l->t('Equipment'), $l->t('Units you maintain')); ?>
						</a>
					</li>
					<li class="<?php p($isCatalogs ? 'active' : ''); ?>" <?php if ($isCatalogs): ?>aria-current="page"<?php endif; ?>>
						<a href="<?php p((string)($navUrls['catalogs'] ?? '#')); ?>"
							title="<?php p($l->t('Catalogs: Types and intervals')); ?>"
							aria-label="<?php p($l->t('Go to catalogs')); ?>">
							<?php $mnNavLabel($l->t('Catalogs'), $l->t('Types and checklists')); ?>
						</a>
					</li>
				</ul>
			</li>
			<?php if ($isAppAdmin): ?>
				<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
				<li class="nav-item-has-children <?php p($isSettings ? 'is-open' : ''); ?>">
					<button class="nav-parent-toggle" type="button"
						aria-expanded="<?php p($isSettings ? 'true' : 'false'); ?>"
						aria-controls="mn-settings-subnav"
						aria-label="<?php p($l->t('Open settings')); ?>">
						<span class="mn-nav__icon" aria-hidden="true"><?php print_unescaped($mnNavIcon('shield')); ?></span>
						<?php $mnNavLabel($l->t('Settings'), $l->t('Access and license')); ?>
						<span class="nav-parent-chevron" aria-hidden="true"></span>
					</button>
					<ul id="mn-settings-subnav" class="nav-submenu" <?php p($isSettings ? '' : 'hidden'); ?>>
						<?php
						$settingsLabels = [
							'access' => [$l->t('Access'), $l->t('Who may open the app')],
							'roles' => [$l->t('Roles'), $l->t('Office vs technicians')],
							'inventory' => [$l->t('Inventory stock issue'), $l->t('Stock on finish')],
							'policies' => [$l->t('Work policies'), $l->t('Checklist and capacity rules')],
							'capacity' => [$l->t('Daily capacity'), $l->t('Minutes per technician')],
							'license' => [$l->t('License & mobile'), $l->t('Keys and seats')],
							'support' => [$l->t('Support us'), $l->t('Sponsoring and help')],
						];
						foreach ($settingsLabels as $sid => $pair):
							$href = (string)($settingsSectionNav[$sid] ?? '#');
							$active = $activeSettingsSection === $sid;
							?>
							<li class="<?php p($active ? 'active' : ''); ?>" <?php if ($active): ?>aria-current="page"<?php endif; ?>>
								<a href="<?php p($href); ?>">
									<?php $mnNavLabel($pair[0], $pair[1]); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endif; ?>
		</ul>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
	</div>
