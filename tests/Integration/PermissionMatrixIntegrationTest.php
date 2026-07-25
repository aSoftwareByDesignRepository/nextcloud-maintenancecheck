<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Controller\CatalogController;
use OCA\MaintenanceCheck\Controller\ConfigController;
use OCA\MaintenanceCheck\Controller\CustomerController;
use OCA\MaintenanceCheck\Controller\LicenseController;
use OCA\MaintenanceCheck\Controller\VisitController;
use OCA\MaintenanceCheck\Exception\AppAccessDeniedException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * AC-3 / SPEC §3 + §14.2-I2: one HTTP-shaped assertion per P-matrix row.
 *
 * Controllers throw domain exceptions; AppAccessMiddleware maps them to the
 * SPEC §7.1 envelope. Sessions are real; config is restored in tearDown.
 *
 * @group integration
 */
final class PermissionMatrixIntegrationTest extends TestCase
{
	private const PASSWORD = 'Mn-PMatrix-9xK!zz';

	private const SYS = 'mn_pm_sys';
	private const ADMIN = 'mn_pm_admin';
	private const OFFICE = 'mn_pm_office';
	private const TECH = 'mn_pm_tech';
	private const OUTSIDER = 'mn_pm_out';

	/** @var array<string, string> */
	private array $prevConfig = [];

	private const KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
		AccessControlService::KEY_OFFICE_USER_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
	];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);

		$config = \OC::$server->get(IConfig::class);
		foreach (self::KEYS as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}

		$this->deleteUsers();
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::TECH, self::OUTSIDER] as $uid) {
			$userManager->createUser($uid, self::PASSWORD);
		}

		$groupManager = \OC::$server->get(\OCP\IGroupManager::class);
		$adminGroup = $groupManager->get('admin') ?? $groupManager->createGroup('admin');
		$adminGroup->addUser($userManager->get(self::SYS));

		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ADMIN], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_OFFICE_USER_IDS,
			json_encode([self::OFFICE], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		$config = \OC::$server->get(IConfig::class);
		foreach ($this->prevConfig as $key => $value) {
			if ($value === '') {
				$config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		$this->deleteUsers();
		\OC::$server->get(IUserSession::class)->setUser(null);
		\OC_User::setIncognitoMode(false);
	}

	private function deleteUsers(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::TECH, self::OUTSIDER] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	private function loginAs(string $uid): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($uid);
		$this->assertNotNull($user, $uid . ' must exist');
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($user);
		$this->assertSame($uid, $session->getUser()?->getUID());
	}

	private function apiMiddleware(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/maintenancecheck/api/customers');
		$request->method('getMethod')->willReturn('GET');
		return new AppAccessMiddleware(
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
		);
	}

	private function pageMiddleware(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/maintenancecheck/');
		$request->method('getMethod')->willReturn('GET');
		return new AppAccessMiddleware(
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
		);
	}

	/**
	 * @param callable(): mixed $call
	 */
	private function invokeExpectingPermissionDenied(object $controller, string $method, callable $call): JSONResponse
	{
		$middleware = $this->apiMiddleware();
		$middleware->beforeController($controller, $method);
		try {
			$call();
			$this->fail('Expected PermissionDeniedException for ' . get_class($controller) . '::' . $method);
		} catch (PermissionDeniedException $e) {
			$response = $middleware->afterException($controller, $method, $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('permission_denied', $response->getData()['error']['code']);
			return $response;
		}
	}

	// ── P1 open app ─────────────────────────────────────────────────────

	public function testP1AnonymousHasNoSessionUser(): void
	{
		\OC::$server->get(IUserSession::class)->setUser(null);
		$controller = \OC::$server->get(CustomerController::class);
		// Middleware leaves anonymous to Nextcloud auth (401) — no AppAccessDenied.
		$this->apiMiddleware()->beforeController($controller, 'index');
		$this->assertNull(\OC::$server->get(IUserSession::class)->getUser());
		$this->assertFalse(\OC::$server->get(AccessControlService::class)->canUseApp(''));
	}

	public function testP1AllRolesCanOpenWhenUnrestricted(): void
	{
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::TECH] as $uid) {
			$this->loginAs($uid);
			$controller = \OC::$server->get(CustomerController::class);
			$this->apiMiddleware()->beforeController($controller, 'index');
			$this->addToAssertionCount(1);
		}
	}

	public function testP1RestrictionBlocksOutsiderWithJsonAndPageTemplate(): void
	{
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::TECH], JSON_THROW_ON_ERROR),
		);

		$this->loginAs(self::OUTSIDER);
		$controller = \OC::$server->get(CustomerController::class);
		try {
			$this->apiMiddleware()->beforeController($controller, 'index');
			$this->fail('Outsider must be denied on API');
		} catch (AppAccessDeniedException $e) {
			$response = $this->apiMiddleware()->afterException($controller, 'index', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('app_access_denied', $response->getData()['error']['code']);
		}

		$pageController = \OC::$server->get(\OCA\MaintenanceCheck\Controller\PageController::class);
		try {
			$this->pageMiddleware()->beforeController($pageController, 'due');
			$this->fail('Outsider must be denied on page');
		} catch (AppAccessDeniedException $e) {
			$response = $this->pageMiddleware()->afterException($pageController, 'due', $e);
			$this->assertInstanceOf(TemplateResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}

		// Allow-listed technician still passes under restriction.
		$this->loginAs(self::TECH);
		$this->apiMiddleware()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	// ── P2 reads ────────────────────────────────────────────────────────

	public function testP2TechnicianCanReadCustomersVisitsAndCatalogs(): void
	{
		$this->loginAs(self::TECH);
		$customers = \OC::$server->get(CustomerController::class);
		$visits = \OC::$server->get(VisitController::class);
		$catalogs = \OC::$server->get(CatalogController::class);
		$mw = $this->apiMiddleware();
		$mw->beforeController($customers, 'index');
		$this->assertInstanceOf(JSONResponse::class, $customers->index(null, '10', '0'));
		$mw->beforeController($visits, 'due');
		$this->assertInstanceOf(JSONResponse::class, $visits->due(null));
		$mw->beforeController($catalogs, 'equipTypes');
		$this->assertInstanceOf(JSONResponse::class, $catalogs->equipTypes('50', '0'));
	}

	public function testP3TechnicianCanCompleteAndSkip(): void
	{
		$customers = \OC::$server->get(\OCA\MaintenanceCheck\Service\CustomerService::class);
		$equipment = \OC::$server->get(\OCA\MaintenanceCheck\Service\EquipmentService::class);
		$catalogs = \OC::$server->get(\OCA\MaintenanceCheck\Service\CatalogService::class);
		$plans = \OC::$server->get(\OCA\MaintenanceCheck\Service\PlanService::class);
		$visits = \OC::$server->get(\OCA\MaintenanceCheck\Service\VisitService::class);
		$clock = \OC::$server->get(\OCA\MaintenanceCheck\Service\Clock::class);

		$equipType = $catalogs->create('equip', [
			'code' => 'mn_pm_p3_et_' . bin2hex(random_bytes(2)),
			'name' => 'P3 equip',
		]);
		$maintType = $catalogs->create('maint', [
			'code' => 'mn_pm_p3_mt_' . bin2hex(random_bytes(2)),
			'name' => 'P3 maint',
		]);
		$customer = $customers->create(self::OFFICE, ['name' => 'P3 customer ' . bin2hex(random_bytes(2))]);
		$unit = $equipment->create(self::OFFICE, [
			'label' => 'P3 unit',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => (int)$equipType['id'],
		]);
		$planA = $plans->create(self::OFFICE, (int)$unit['id'], [
			'maintTypeId' => (int)$maintType['id'],
			'intervalUnit' => 'month',
			'intervalCount' => 1,
			'firstDueOn' => $clock->today(),
		]);
		$planB = $plans->create(self::OFFICE, (int)$unit['id'], [
			'maintTypeId' => (int)$maintType['id'],
			'intervalUnit' => 'week',
			'intervalCount' => 1,
			'firstDueOn' => $clock->today(),
		]);

		$this->loginAs(self::TECH);
		$ctrl = \OC::$server->get(VisitController::class);
		$mw = $this->apiMiddleware();
		$mw->beforeController($ctrl, 'complete');
		$done = $ctrl->complete((int)$planA['openVisit']['id']);
		$this->assertSame(Http::STATUS_OK, $done->getStatus());
		$this->assertSame('done', $done->getData()['visit']['status']);

		$mw->beforeController($ctrl, 'skip');
		$skipped = $ctrl->skip((int)$planB['openVisit']['id']);
		$this->assertSame(Http::STATUS_OK, $skipped->getStatus());
		$this->assertSame('skipped', $skipped->getData()['visit']['status']);

		$customers->delete((int)$customer['id'], true);
		foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
			$db = \OC::$server->get(\OCP\IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('id', $qb->createNamedParameter(
				$table === 'mn_equip_types' ? (int)$equipType['id'] : (int)$maintType['id'],
				\PDO::PARAM_INT,
			)));
			$qb->executeStatement();
		}
	}

	// ── P4 / P5 / P6 office writes vs technician 403 ────────────────────

	public function testP4TechnicianDeniedCancelAssignReschedule(): void
	{
		$this->loginAs(self::TECH);
		$visits = \OC::$server->get(VisitController::class);
		$this->invokeExpectingPermissionDenied($visits, 'cancel', fn () => $visits->cancel(1));
		$this->invokeExpectingPermissionDenied($visits, 'assign', fn () => $visits->assign(1));
		$this->invokeExpectingPermissionDenied($visits, 'update', fn () => $visits->update(1));
	}

	public function testP5TechnicianDeniedCustomerCreate(): void
	{
		$this->loginAs(self::TECH);
		$customers = \OC::$server->get(CustomerController::class);
		$this->invokeExpectingPermissionDenied($customers, 'create', fn () => $customers->create());
	}

	public function testP5TechnicianDeniedPlanScheduleEquipmentAndPlanWrite(): void
	{
		$this->loginAs(self::TECH);
		$plans = \OC::$server->get(\OCA\MaintenanceCheck\Controller\PlanController::class);
		$equipment = \OC::$server->get(\OCA\MaintenanceCheck\Controller\EquipmentController::class);
		$customers = \OC::$server->get(CustomerController::class);

		$this->invokeExpectingPermissionDenied($plans, 'schedule', fn () => $plans->schedule(1));
		$this->invokeExpectingPermissionDenied($plans, 'update', fn () => $plans->update(1));
		$this->invokeExpectingPermissionDenied($plans, 'create', fn () => $plans->create(1));
		$this->invokeExpectingPermissionDenied($equipment, 'create', fn () => $equipment->create());
		$this->invokeExpectingPermissionDenied($equipment, 'update', fn () => $equipment->update(1));
		$this->invokeExpectingPermissionDenied($equipment, 'destroy', fn () => $equipment->destroy(1));
		$this->invokeExpectingPermissionDenied($customers, 'destroy', fn () => $customers->destroy(1));
	}

	public function testP5OfficeCanCreateCustomer(): void
	{
		$this->loginAs(self::OFFICE);
		$acl = \OC::$server->get(AccessControlService::class);
		$acl->requireOffice(self::OFFICE);
		$created = \OC::$server->get(\OCA\MaintenanceCheck\Service\CustomerService::class)
			->create(self::OFFICE, ['name' => 'P-matrix office customer']);
		$this->assertArrayHasKey('id', $created);
		\OC::$server->get(\OCA\MaintenanceCheck\Service\CustomerService::class)
			->delete((int)$created['id'], true);
	}

	public function testP6TechnicianDeniedCatalogCreate(): void
	{
		$this->loginAs(self::TECH);
		$catalogs = \OC::$server->get(CatalogController::class);
		$this->invokeExpectingPermissionDenied(
			$catalogs,
			'createEquipType',
			fn () => $catalogs->createEquipType(),
		);
	}

	// ── P7 / P8 admin-only ──────────────────────────────────────────────

	public function testP7OfficeDeniedConfigEdit(): void
	{
		$this->loginAs(self::OFFICE);
		$config = \OC::$server->get(ConfigController::class);
		$this->invokeExpectingPermissionDenied($config, 'index', fn () => $config->index());
		$this->invokeExpectingPermissionDenied($config, 'saveAccess', fn () => $config->saveAccess());
		$this->invokeExpectingPermissionDenied($config, 'saveOffice', fn () => $config->saveOffice());
	}

	public function testP7AppAdminCanReadConfig(): void
	{
		$this->loginAs(self::ADMIN);
		$config = \OC::$server->get(ConfigController::class);
		$this->apiMiddleware()->beforeController($config, 'index');
		$response = $config->index();
		$this->assertArrayHasKey('accessRestrictionEnabled', $response->getData());
	}

	public function testP8OfficeDeniedLicense(): void
	{
		$this->loginAs(self::OFFICE);
		$license = \OC::$server->get(LicenseController::class);
		$this->invokeExpectingPermissionDenied($license, 'show', fn () => $license->show());
		$this->invokeExpectingPermissionDenied($license, 'apply', fn () => $license->apply());
	}

	public function testP8AppAdminCanReadLicenseStatus(): void
	{
		$this->loginAs(self::ADMIN);
		$license = \OC::$server->get(LicenseController::class);
		$this->apiMiddleware()->beforeController($license, 'show');
		$response = $license->show();
		$this->assertArrayHasKey('mobileAppStatus', $response->getData());
	}

	public function testP8SystemAdminPassesLicenseGate(): void
	{
		$this->loginAs(self::SYS);
		$license = \OC::$server->get(LicenseController::class);
		$this->apiMiddleware()->beforeController($license, 'show');
		$this->assertInstanceOf(JSONResponse::class, $license->show());
	}

	// ── P9 Support & us not rendered for non-admin ──────────────────────

	public function testP9SettingsFlagsHideSupportForTechnician(): void
	{
		$this->loginAs(self::TECH);
		$page = \OC::$server->get(\OCA\MaintenanceCheck\Controller\PageController::class);
		$response = $page->settings();
		$params = $response->getParams();
		$this->assertFalse($params['isAppAdmin'], 'technician must not see admin settings / Support & us');
		$this->assertFalse($params['isOffice']);
	}

	public function testP9SettingsFlagsShowSupportForAppAdmin(): void
	{
		$this->loginAs(self::ADMIN);
		$page = \OC::$server->get(\OCA\MaintenanceCheck\Controller\PageController::class);
		$response = $page->settings();
		$params = $response->getParams();
		$this->assertTrue($params['isAppAdmin']);
		$this->assertTrue($params['isOffice'], 'app admin is always office');
	}

	// ── S12 access preview ──────────────────────────────────────────────

	public function testS12UserAccessPreviewOfficeOnly(): void
	{
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::TECH, self::OFFICE], JSON_THROW_ON_ERROR),
		);

		$this->loginAs(self::TECH);
		$ctrl = \OC::$server->get(ConfigController::class);
		$this->invokeExpectingPermissionDenied(
			$ctrl,
			'userAccess',
			fn () => $ctrl->userAccess(self::OUTSIDER),
		);

		$this->loginAs(self::OFFICE);
		$mw = $this->apiMiddleware();
		$mw->beforeController($ctrl, 'userAccess');
		$preview = $ctrl->userAccess(self::OUTSIDER)->getData();
		$this->assertTrue($preview['exists']);
		$this->assertFalse($preview['canUseApp'], 'outsider fails canUseApp under restriction');

		$ok = $ctrl->userAccess(self::TECH)->getData();
		$this->assertTrue($ok['canUseApp']);
	}
}
