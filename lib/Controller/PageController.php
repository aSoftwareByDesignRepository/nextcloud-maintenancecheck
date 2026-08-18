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
use OCA\MaintenanceCheck\Support\SettingsSections;
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
 * Page shell (Check-family pattern). The L2 gate runs in
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
		return $this->page('due', $this->l->t('Due board'), $this->l->t('Tap Complete on overdue and today cards. Use More for details or skip.'));
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
		return $this->page('customers', $this->l->t('Customers'), $this->l->t('Add organisations you service — then open one to add equipment and plans.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function customer(int $id): TemplateResponse
	{
		return $this->page('customer-detail', $this->l->t('Customer'), $this->l->t('Master data, sites and equipment for this organisation.'), $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipment(): TemplateResponse
	{
		return $this->page('equipment', $this->l->t('Equipment'), $this->l->t('Search every unit. Create new equipment on a customer page.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function equipmentShow(int $id): TemplateResponse
	{
		return $this->page('equipment-detail', $this->l->t('Equipment'), $this->l->t('Add a plan so visits appear on the due board. Meters and inspections are optional.'), $id);
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
		return $this->page('visits', $this->l->t('Visits'), $this->l->t('Filter history by status or date. Open a row for details.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catalogs(): TemplateResponse
	{
		return $this->page('catalogs', $this->l->t('Catalogs'), $this->l->t('Pick a list, then add or edit — set once, reuse everywhere.'));
	}

	/**
	 * Legacy /settings entry — always land on the first real underpage (no hub).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(
				'maintenancecheck.page.settingsSection',
				['section' => SettingsSections::DEFAULT],
			),
		);
	}

	/**
	 * Settings underpage (access, roles, inventory, policies, capacity, license, support).
	 * Invalid section ids redirect to the default underpage — never render an empty shell.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|RedirectResponse
	{
		$section = strtolower(trim($section));
		if (!SettingsSections::isValid($section)) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute(
					'maintenancecheck.page.settingsSection',
					['section' => SettingsSections::DEFAULT],
				),
			);
		}
		$meta = SettingsSections::get($this->l, $section);
		$title = (string)($meta['title'] ?? $section);
		$hint = (string)($meta['hint'] ?? '');
		return $this->page(
			'settings-section',
			$title,
			$hint,
			null,
			array_merge($this->settingsPageParams(), [
				'pageId' => 'settings-' . $section,
				'settingsSection' => $section,
				'settingsSectionMeta' => $meta,
			]),
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrders(): TemplateResponse
	{
		return $this->page('work-orders', $this->l->t('Work orders'), $this->l->t('Open a job to run the checklist and add photos. Office can create new jobs here.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workOrderShow(int $id): TemplateResponse
	{
		return $this->page('work-order-detail', $this->l->t('Work order'), $this->l->t('One job sheet — status, checklist, evidence.'), $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dispatch(): TemplateResponse|RedirectResponse
	{
		return $this->officePage('dispatch', $this->l->t('Dispatch'), $this->l->t('Tap Assign on a job — search and pick, never type an id.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tours(): TemplateResponse
	{
		return $this->page('tours', $this->l->t('Day tours'), $this->l->t('Pick the day, create a tour, add stops — then open a stop to work.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kpi(): TemplateResponse|RedirectResponse
	{
		return $this->officePage('kpi', $this->l->t('Ops KPI'), $this->l->t('Compliance, overdue work and MTTR — pick 30 or 90 days.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exceptions(): TemplateResponse|RedirectResponse
	{
		return $this->officePage('exceptions', $this->l->t('Exceptions'), $this->l->t('Open a job and clear the blocker.'));
	}

	/**
	 * Planning pages (dispatch / KPI / exceptions) are office-only in the
	 * nav. Direct URLs must not render the shell for technicians — the API
	 * already 403s; sending them back to the due board avoids a dead page.
	 */
	private function officePage(string $template, string $title, string $hint): TemplateResponse|RedirectResponse
	{
		if (!$this->access->isOffice($this->access->currentUserId())) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('maintenancecheck.page.due'),
			);
		}
		return $this->page($template, $title, $hint);
	}

	/**
	 * @param array<string, mixed> $extra Extra template params (may override pageId for underpages).
	 */
	private function page(string $template, string $title, string $hint, ?int $entityId = null, array $extra = []): TemplateResponse
	{
		$pageId = array_key_exists('pageId', $extra) ? (string)$extra['pageId'] : $template;
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		if (in_array($pageId, ['work-orders', 'work-order-detail', 'dispatch', 'tours', 'kpi', 'exceptions'], true)) {
			Util::addScript(Application::APP_ID, 'work-order-pages');
		}

		$uid = $this->access->currentUserId();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isOffice = $this->access->isOffice($uid);

		$params = array_merge([
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
			'settingsSections' => SettingsSections::all($this->l),
			'settingsSection' => '',
			'settingsSectionUrls' => $this->settingsSectionUrls(),
		], $extra);
		$params['pageId'] = $pageId;

		return new TemplateResponse(Application::APP_ID, $template, $params);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settingsPageParams(): array
	{
		return [
			'settingsSections' => SettingsSections::all($this->l),
			'settingsSection' => '',
			'settingsSectionUrls' => $this->settingsSectionUrls(),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function settingsSectionUrls(): array
	{
		$urls = [];
		foreach (SettingsSections::ids() as $sectionId) {
			$urls[$sectionId] = $this->urlGenerator->linkToRoute(
				'maintenancecheck.page.settingsSection',
				['section' => $sectionId],
			);
		}
		return $urls;
	}

	/**
	 * @return array{pages: array<string, mixed>, api: array<string, string>}
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
				'settings' => $route('page.settingsSection', ['section' => SettingsSections::DEFAULT]),
				'settingsSections' => $this->settingsSectionUrls(),
				'workOrders' => $route('page.workOrders'),
				'dispatch' => $route('page.dispatch'),
				'tours' => $route('page.tours'),
				'kpi' => $route('page.kpi'),
				'exceptions' => $route('page.exceptions'),
			],
			'api' => [
				'customers' => $route('customer.index'),
				'equipment' => $route('equipment.index'),
				'visits' => $route('visit.index'),
				'visitsDue' => $route('visit.due'),
				'equipmentClasses' => $route('inspection_obligation.classes'),
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
				'kpi' => $route('ops.kpi'),
				'kpiCsv' => $route('ops.kpiCsv'),
				'exceptions' => $route('ops.exceptions'),
				'failureCodes' => $route('ops.failureCodes'),
				// Prefix: JS appends "/{id}/download" (W6-R2 materialised blobs — never /f/{fileId}).
				'equipDocs' => preg_replace('#/0/download$#', '', $route('equip_doc.download', ['id' => 0])),
				// Item routes: JS appends "/{id}…".
				'sites' => preg_replace('#/0$#', '', $route('site.update', ['id' => 0])),
				'meters' => preg_replace('#/0$#', '', $route('meter.update', ['id' => 0])),
				'users' => preg_replace('#/x/skills$#', '', $route('skill.userSkills', ['uid' => 'x'])),
			],
		];
	}
}
