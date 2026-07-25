<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Controller\CustomerController;
use OCA\MaintenanceCheck\Exception\AppAccessDeniedException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * L2 gate + SPEC §7.1 error envelope against live config and sessions
 * (P1 rows 2–4, P5 office-only writes).
 *
 * @group integration
 */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'mn_gate_allowed';
	private const DENIED = 'mn_gate_denied';
	private const PASSWORD = 'Mn-gate-pass-9xK!';

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
		// Earlier upgrade-backup / share tests can leave incognito mode on;
		// IUserSession::getUser() then always returns null (Session.php).
		\OC_User::setIncognitoMode(false);
		$config = \OC::$server->get(IConfig::class);
		foreach (self::KEYS as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		$this->deleteUsers();
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
	}

	private function deleteUsers(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	public function testRestrictionBlocksUnlistedUserWithJsonEnvelope(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		$config = \OC::$server->get(IConfig::class);
		// Isolate from leftover suite state (app-admin list, office lists).
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');

		$denied = $userManager->get(self::DENIED);
		$this->assertNotNull($denied, 'denied fixture user must exist');
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($denied);
		$this->assertSame(self::DENIED, $session->getUser()?->getUID());

		$acl = \OC::$server->get(AccessControlService::class);
		$this->assertFalse($acl->canUseApp(self::DENIED), 'precondition: denied user is gated');
		$this->assertFalse($acl->isAppAdmin(self::DENIED));

		$controller = \OC::$server->get(CustomerController::class);
		$middleware = $this->middlewareWithApiRequest();

		try {
			$middleware->beforeController($controller, 'index');
			$this->fail('Expected AppAccessDeniedException for restricted user');
		} catch (AppAccessDeniedException $exception) {
			$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $exception->getDenialReason());
		}

		$response = $middleware->afterException(
			$controller,
			'index',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('app_access_denied', $data['error']['code']);
		$this->assertNotSame('', (string)$data['error']['message']);
	}

	public function testAllowedUserAndAppAdminPassTheGate(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);

		$session = \OC::$server->get(IUserSession::class);
		$session->setUser($userManager->get(self::ALLOWED));

		$controller = \OC::$server->get(CustomerController::class);
		$this->middlewareWithApiRequest()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	public function testTechnicianGetsPermissionDeniedEnvelopeForOfficeActions(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		// Unrestricted app, but user has no office role (P5).
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');

		$acl = \OC::$server->get(AccessControlService::class);
		$this->assertTrue($acl->canUseApp(self::DENIED), 'technician can read (P2)');
		$this->assertFalse($acl->isOffice(self::DENIED));

		try {
			$acl->requireOffice(self::DENIED);
			$this->fail('Technician must not pass office checks');
		} catch (PermissionDeniedException $e) {
			$controller = \OC::$server->get(CustomerController::class);
			$response = $this->middlewareWithApiRequest()->afterException($controller, 'create', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('permission_denied', $response->getData()['error']['code']);
		}
	}

	public function testOfficeGroupMemberGetsOfficeRole(): void
	{
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		$groupManager = \OC::$server->get(\OCP\IGroupManager::class);
		$gid = 'mn_gate_office_group';
		$group = $groupManager->get($gid) ?? $groupManager->createGroup($gid);
		$this->assertNotNull($group);
		$group->addUser($userManager->get(self::ALLOWED));

		try {
			$config = \OC::$server->get(IConfig::class);
			$config->setAppValue(
				Application::APP_ID,
				AccessControlService::KEY_OFFICE_GROUP_IDS,
				json_encode([$gid], JSON_THROW_ON_ERROR),
			);
			$acl = \OC::$server->get(AccessControlService::class);
			$this->assertTrue($acl->isOffice(self::ALLOWED));
			$this->assertFalse($acl->isAppAdmin(self::ALLOWED), 'office role must not imply admin');
		} finally {
			$groupManager->get($gid)?->delete();
		}
	}

	private function middlewareWithApiRequest(): AppAccessMiddleware
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
}
