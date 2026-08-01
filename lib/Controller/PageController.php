<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Page shell (Check-family, MobilityCheck pattern). The L2 gate runs in
 * AppAccessMiddleware; here we only compute role flags for role-aware
 * rendering (§11.4 — server stays the enforcement authority).
 */
class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly EquipmentService $equipment,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function due(): TemplateResponse
	{
		return $this->page('due', $this->l->t('Due board'), $this->l->t('Everything that needs a visit — overdue first.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dueAlias(): TemplateResponse
	{
		return $this->due();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function customers(): TemplateResponse
	{
		return $this->page('customers', $this->l->t('Customers'), $this->l->t('The organisations and sites you service.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function customer(int $id): TemplateResponse
	{
		return $this->page('customer-detail', $this->l->t('Customer'), $this->l->t('Master data and equipment of this customer.'), $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipment(): TemplateResponse
	{
		return $this->page('equipment', $this->l->t('Equipment'), $this->l->t('Every unit you maintain, across all customers.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentShow(int $id): TemplateResponse
	{
		return $this->page('equipment-detail', $this->l->t('Equipment'), $this->l->t('Plans and visit history for this unit.'), $id);
	}

	/**
	 * Deep-link target for printed stickers (`mn-eq:{token}` / absolute URL).
	 * Resolves under the normal app ACL; unknown tokens land on the equipment list.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentByQr(string $token): RedirectResponse
	{
		try {
			$summary = $this->equipment->resolveByQr($token);
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('maintenancecheck.page.equipmentShow', [
					'id' => (int)$summary['id'],
				]),
			);
		} catch (NotFoundException | ValidationException) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('maintenancecheck.page.equipment'),
			);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function visits(): TemplateResponse
	{
		return $this->page('visits', $this->l->t('Visits'), $this->l->t('Complete history with filters.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catalogs(): TemplateResponse
	{
		return $this->page('catalogs', $this->l->t('Catalogs'), $this->l->t('Equipment types, maintenance types, procedures, kits and skills.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		return $this->page('settings', $this->l->t('Settings'), $this->l->t('Access, roles, license and support.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrders(): TemplateResponse
	{
		return $this->page('work-orders', $this->l->t('Work orders'), $this->l->t('Planned and corrective work with checklists and photos.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderShow(int $id): TemplateResponse
	{
		return $this->page('work-order-detail', $this->l->t('Work order'), $this->l->t('Checklist, kit, photos and report for this job.'), $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dispatch(): TemplateResponse
	{
		return $this->page('dispatch', $this->l->t('Dispatch'), $this->l->t('Who does what, and when — assign open work orders.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tours(): TemplateResponse
	{
		return $this->page('tours', $this->l->t('Day tours'), $this->l->t('Stop-by-stop plans for each technician’s day.'));
	}

	private function page(string $pageId, string $title, string $hint, ?int $entityId = null): TemplateResponse
	{
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'app');
		if (in_array($pageId, ['work-orders', 'work-order-detail', 'dispatch', 'tours'], true)) {
			Util::addScript(Application::APP_ID, 'work-order-pages');
		}

		$uid = $this->access->currentUserId();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isOffice = $this->access->isOffice($uid);

		return new TemplateResponse(Application::APP_ID, $pageId, [
			'pageId' => $pageId,
			'pageTitle' => $title,
			'pageHint' => $hint,
			'entityId' => $entityId,
			'currentUserId' => $uid,
			'isAppAdmin' => $isAppAdmin,
			'isOffice' => $isOffice,
			'isSystemAdmin' => $this->access->isSystemAdmin($uid),
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			// S1: server calendar "today" — JS must prefer this over the browser clock.
			'serverToday' => $this->clock->today(),
			'timezone' => $this->config->getUserValue($uid, 'core', 'timezone', $this->config->getSystemValueString('default_timezone', 'UTC')) ?: 'UTC',
			'roleLabel' => $isAppAdmin
				? $this->l->t('Administrator')
				: ($isOffice ? $this->l->t('Office') : $this->l->t('Technician')),
			'urlsJson' => json_encode($this->urls(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
		]);
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function urls(): array
	{
		$route = fn (string $name, array $args = []): string => $this->urlGenerator->linkToRoute('maintenancecheck.' . $name, $args);
		return [
			'pages' => [
				'due' => $route('page.due'),
				'customers' => $route('page.customers'),
				'equipment' => $route('page.equipment'),
				'visits' => $route('page.visits'),
				'catalogs' => $route('page.catalogs'),
				'settings' => $route('page.settings'),
				'workOrders' => $route('page.workOrders'),
				'dispatch' => $route('page.dispatch'),
				'tours' => $route('page.tours'),
			],
			'api' => [
				'customers' => $route('customer.index'),
				'equipment' => $route('equipment.index'),
				'visits' => $route('visit.index'),
				'visitsDue' => $route('visit.due'),
				// Item routes: JS appends "/{id}" (and "/plans", "/schedule", …).
				'plans' => preg_replace('#/0$#', '', $route('plan.update', ['id' => 0])),
				'equipTypes' => $route('catalog.equipTypes'),
				'maintTypes' => $route('catalog.maintTypes'),
				'config' => $route('config.index'),
				'configAccess' => $route('config.saveAccess'),
				'configOffice' => $route('config.saveOffice'),
				'configInventoryFlange' => $route('config.saveInventoryFlange'),
				'configPolicies' => $route('config.savePolicies'),
				'userAccess' => $route('config.userAccess'),
				'usersSearch' => $route('config.searchUsers'),
				'groupsSearch' => $route('config.searchGroups'),
				'license' => $route('license.show'),
				'licenseSeats' => $route('license.seats'),
				'workOrders' => $route('work_order.index'),
				'procedures' => $route('procedure.index'),
				'proceduresPack' => $route('procedure.exportPack'),
				'skills' => $route('skill.index'),
				'capacity' => $route('capacity.index'),
				'dispatch' => $route('dispatch.board'),
				'tours' => $route('tour.index'),
				'kitTemplates' => $route('kit.indexTemplates'),
				// Item routes: JS appends "/{id}…".
				'sites' => preg_replace('#/0$#', '', $route('site.update', ['id' => 0])),
				'meters' => preg_replace('#/0$#', '', $route('meter.update', ['id' => 0])),
				'users' => preg_replace('#/x/skills$#', '', $route('skill.userSkills', ['uid' => 'x'])),
			],
		];
	}
}
