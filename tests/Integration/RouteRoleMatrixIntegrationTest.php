<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every office / app-admin / office-page route must reject a technician.
 * Dummy ids are enough: the gate must fire before resource lookup.
 *
 * @group integration
 */
final class RouteRoleMatrixIntegrationTest extends IntegrationTestCase
{
	private const PASSWORD = 'Mn-RouteMatrix-9xK!';
	private const TECH = 'mn_rm_tech';
	private const OFFICE = 'mn_rm_office';

	/** @var array<string, string> */
	private array $prevConfig = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);
		$config = \OC::$server->get(IConfig::class);
		$keys = [
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_APP_ADMINS,
		];
		foreach ($keys as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		$um = \OC::$server->get(IUserManager::class);
		foreach ([self::TECH, self::OFFICE] as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
			$um->createUser($uid, self::PASSWORD);
		}
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, json_encode([self::OFFICE]));
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
	}

	protected function tearDown(): void
	{
		if (isset(\OC::$server)) {
			\OC::$server->get(IUserSession::class)->setUser(null);
			$config = \OC::$server->get(IConfig::class);
			foreach ($this->prevConfig as $key => $value) {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
			$um = \OC::$server->get(IUserManager::class);
			foreach ([self::TECH, self::OFFICE] as $uid) {
				if ($um->userExists($uid)) {
					$um->get($uid)?->delete();
				}
			}
		}
		parent::tearDown();
	}

	public function testTechnicianIsDeniedEveryOfficeAndAdminApiAndPage(): void
	{
		$this->loginAs(self::TECH);
		$checked = 0;
		foreach (RouteAuthInventory::gates() as $name => $gate) {
			if (!RouteAuthInventory::technicianMustBeDenied($gate)) {
				continue;
			}
			if (str_starts_with($name, 'mobile#')) {
				continue;
			}
			$checked++;
			$this->assertTechnicianDenied($name, $gate);
		}
		$this->assertGreaterThan(40, $checked, 'office/admin inventory must not shrink silently');
	}

	public function testTechnicianMobileRoutesAreGatedExceptBootstrap(): void
	{
		$this->loginAs(self::TECH);
		$checked = 0;
		foreach (RouteAuthInventory::gates() as $name => $gate) {
			if (!str_starts_with($name, 'mobile#')) {
				continue;
			}
			$checked++;
			$method = explode('#', $name, 2)[1];
			$controller = \OC::$server->get(\OCA\MaintenanceCheck\Controller\MobileController::class);
			$ref = new ReflectionMethod($controller, $method);
			$args = $this->dummyArgs($ref);
			if ($gate === 'mobile_bootstrap') {
				$result = $ref->invokeArgs($controller, $args);
				$this->assertInstanceOf(JSONResponse::class, $result, 'bootstrap reports license without throwing');
				continue;
			}
			try {
				$ref->invokeArgs($controller, $args);
				$this->fail($name . ' must not succeed without a mobile license/seat or CSRF channel');
			} catch (MobileGateException|PermissionDeniedException $e) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertGreaterThan(20, $checked, 'mobile inventory must not shrink silently');
	}

	public function testOfficePassesFunctionGateOnOfficeApiSample(): void
	{
		$this->loginAs(self::OFFICE);
		$ops = \OC::$server->get(\OCA\MaintenanceCheck\Controller\OpsController::class);
		$res = $ops->kpi('30');
		$this->assertSame(200, $res->getStatus());
	}

	private function loginAs(string $uid): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);
	}

	private function assertTechnicianDenied(string $routeName, string $gate): void
	{
		$method = explode('#', $routeName, 2)[1];
		$class = RouteAuthInventory::controllerClass($routeName);
		$controller = \OC::$server->get($class);
		$ref = new ReflectionMethod($controller, $method);
		$args = $this->dummyArgs($ref);
		try {
			$result = $ref->invokeArgs($controller, $args);
		} catch (PermissionDeniedException $e) {
			$this->addToAssertionCount(1);
			return;
		} catch (\Throwable $e) {
			$this->fail(
				$routeName . ' (' . $gate . ') must deny a technician at the auth gate, got '
				. $e::class . ': ' . $e->getMessage()
			);
		}
		if ($gate === 'page_office') {
			$this->assertInstanceOf(
				RedirectResponse::class,
				$result,
				$routeName . ' must redirect technicians away from the office page',
			);
			return;
		}
		$this->fail($routeName . ' returned ' . get_debug_type($result) . ' instead of denying the technician');
	}

	/**
	 * @return list<mixed>
	 */
	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
				$args[] = 1;
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
				$args[] = $param->getName() === 'uid' ? 'someone-else' : '1';
				continue;
			}
			if ($type !== null && $type->allowsNull()) {
				$args[] = null;
				continue;
			}
			$args[] = '1';
		}
		return $args;
	}
}
