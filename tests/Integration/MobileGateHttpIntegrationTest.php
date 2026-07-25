<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Controller\LicenseController;
use OCA\MaintenanceCheck\Controller\MobileController;
use OCA\MaintenanceCheck\Db\LicenseStateMapper;
use OCA\MaintenanceCheck\Db\MobileSeatMapper;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\LicenseService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Tests\Support\Mn2TestSigning;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

/**
 * AC-15 / AC-16: license seat HTTP codes + full mobile gate ladder via
 * controller + middleware envelope (all six rungs).
 *
 * @group integration
 */
final class MobileGateHttpIntegrationTest extends IntegrationTestCase
{
	private const ADMIN = 'mn_gate_admin';
	private const UID = 'mn_gate_http';
	private const PASS = 'Mn-Gate-Http-9xK!';

	/** @var list<string> */
	private array $testUsers = [];

	/** @var list<int> */
	private array $customerIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		putenv('MN_VENDOR_PUBLIC_KEY_B64=' . Mn2TestSigning::publicKeyB64());
		\OC_User::setIncognitoMode(false);
		$this->wipeLicense();
		$this->ensureUser(self::UID);
		$this->ensureUser(self::ADMIN);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		\OC::$server->get(IUserSession::class)->setUser(null);
		$this->wipeLicense();
		foreach ($this->customerIds as $id) {
			try {
				Server::get(CustomerService::class)->delete($id, true);
			} catch (\Throwable) {
			}
		}
		$this->customerIds = [];
		$um = \OC::$server->get(IUserManager::class);
		foreach ($this->testUsers as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
		}
		$this->testUsers = [];
		putenv('MN_VENDOR_PUBLIC_KEY_B64');
		\OC_User::setIncognitoMode(false);
	}

	private function wipeLicense(): void
	{
		Server::get(LicenseStateMapper::class)->deleteAll();
		$seats = Server::get(MobileSeatMapper::class);
		foreach ($seats->findAllRanked() as $seat) {
			$seats->delete($seat);
		}
	}

	private function ensureUser(string $uid): void
	{
		$um = \OC::$server->get(IUserManager::class);
		if ($um->userExists($uid)) {
			$um->get($uid)?->delete();
		}
		$um->createUser($uid, self::PASS);
		$this->testUsers[] = $uid;
	}

	private function loginAs(string $uid): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);
	}

	/**
	 * Mobile mutations require Authorization (app password) or a CSRF
	 * requesttoken (N5). Container-resolved IRequest has neither in PHPUnit,
	 * so build a controller whose request presents a Bearer channel — the
	 * same shape the official app sends after NC session auth.
	 */
	private function mobileControllerWithMutationAuth(): MobileController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $name): string {
			return strcasecmp($name, 'Authorization') === 0 ? 'Bearer mn-integration-test' : '';
		});
		$request->method('getParam')->willReturn(null);
		$request->method('getParams')->willReturn([]);

		return new MobileController(
			$request,
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			\OC::$server->get(MobileGateService::class),
			\OC::$server->get(VisitService::class),
			\OC::$server->get(EquipmentService::class),
			\OC::$server->get(CustomerService::class),
		);
	}

	private function middleware(string $path, string $method = 'GET'): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn($method);
		return new AppAccessMiddleware(
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
		);
	}

	/**
	 * @param callable(): JSONResponse $call
	 */
	private function assertGateHttp(string $code, callable $call): void
	{
		$controller = \OC::$server->get(MobileController::class);
		$middleware = $this->middleware('/apps/maintenancecheck/mobile/v1/due');
		$middleware->beforeController($controller, 'due');
		try {
			$call();
			$this->fail('Expected MobileGateException ' . $code);
		} catch (MobileGateException $e) {
			$this->assertSame($code, $e->getErrorCode());
			$response = $middleware->afterException($controller, 'due', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(402, $response->getStatus());
			$this->assertSame($code, $response->getData()['error']['code']);
		}
	}

	private function signedKey(array $overrides = []): string
	{
		return Mn2TestSigning::signPayload(array_merge([
			'v' => 2,
			'product' => 'maintenancecheck',
			'customerId' => 'gate-http',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 2,
		], $overrides));
	}

	public function testDueWithoutLicenseReturns402LicenseMissing(): void
	{
		$this->loginAs(self::UID);
		$this->assertGateHttp('license_missing', static function (): JSONResponse {
			return \OC::$server->get(MobileController::class)->due(null);
		});
	}

	public function testDueWithExpiredLicenseReturns402LicenseExpired(): void
	{
		$license = Server::get(LicenseService::class);
		$license->apply(self::ADMIN, $this->signedKey([
			'issuedAt' => '2020-01-01',
			'validUntil' => '2020-12-31',
		]));
		$this->loginAs(self::UID);
		$this->assertGateHttp('license_expired', static function (): JSONResponse {
			return \OC::$server->get(MobileController::class)->due(null);
		});
	}

	public function testDueWithoutSeatReturns402SeatRequired(): void
	{
		Server::get(LicenseService::class)->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));
		$this->loginAs(self::UID);
		$this->assertGateHttp('seat_required', static function (): JSONResponse {
			return \OC::$server->get(MobileController::class)->due(null);
		});
	}

	public function testDueWithOverLimitSeatReturns402SeatLimitExceeded(): void
	{
		$license = Server::get(LicenseService::class);
		$license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 2]));
		$older = $this->ensureExtraUser('mn_gate_old');
		$license->assignSeat(self::ADMIN, $older);
		$license->assignSeat(self::ADMIN, self::UID);
		$license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));

		$this->loginAs(self::UID);
		$this->assertGateHttp('seat_limit_exceeded', static function (): JSONResponse {
			return \OC::$server->get(MobileController::class)->due(null);
		});
	}

	public function testBootstrapDoesNotRequireLicense(): void
	{
		$this->loginAs(self::UID);
		$controller = \OC::$server->get(MobileController::class);
		$response = $controller->bootstrap();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertNull($data['licensing']);
		$this->assertFalse($data['seatAssigned']);
		$this->assertSame(self::UID, $data['user']['uid']);
	}

	public function testMobileDomainRoutesWorkWhenGated(): void
	{
		$license = Server::get(LicenseService::class);
		$license->apply(self::ADMIN, $this->signedKey(['mobileSeats' => 1]));
		$license->assignSeat(self::ADMIN, self::UID);

		$catalogs = Server::get(CatalogService::class);
		$equipType = $catalogs->create('equip', [
			'code' => 'mn_gate_et_' . bin2hex(random_bytes(3)),
			'name' => 'Gate Equip',
		]);
		$maintType = $catalogs->create('maint', [
			'code' => 'mn_gate_mt_' . bin2hex(random_bytes(3)),
			'name' => 'Gate Maint',
		]);
		$customer = Server::get(CustomerService::class)->create(self::ADMIN, ['name' => 'Gate Customer']);
		$this->customerIds[] = (int)$customer['id'];
		$equipment = Server::get(EquipmentService::class)->create(self::ADMIN, [
			'label' => 'Gate Unit',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => (int)$equipType['id'],
		]);
		$today = Server::get(\OCA\MaintenanceCheck\Service\Clock::class)->today();
		$plan = Server::get(PlanService::class)->create(self::ADMIN, (int)$equipment['id'], [
			'maintTypeId' => (int)$maintType['id'],
			'intervalUnit' => 'month',
			'intervalCount' => 1,
			'firstDueOn' => $today,
		]);
		$visitId = (int)$plan['openVisit']['id'];

		$this->loginAs(self::UID);
		// Reads work with the container controller; mutations need N5 channel auth.
		$controller = \OC::$server->get(MobileController::class);
		$mutating = $this->mobileControllerWithMutationAuth();

		$due = $controller->due(null);
		$this->assertSame(Http::STATUS_OK, $due->getStatus());
		$this->assertArrayHasKey('overdue', $due->getData());

		$summary = $controller->equipment((int)$equipment['id']);
		$this->assertSame(Http::STATUS_OK, $summary->getStatus());
		$this->assertSame('Gate Unit', $summary->getData()['label']);
		$this->assertNotEmpty($summary->getData()['activePlans']);
		$this->assertArrayHasKey('recentVisits', $summary->getData());

		$customers = $controller->customers('Gate', '50', '0');
		$this->assertSame(1, $customers->getData()['total']);

		$visits = $controller->visits(null, null, 'scheduled', null, null, (string)$equipment['id'], null, '50', '0');
		$this->assertGreaterThanOrEqual(1, $visits->getData()['total']);

		$complete = $mutating->complete($visitId);
		$this->assertSame(Http::STATUS_OK, $complete->getStatus());
		$this->assertSame('done', $complete->getData()['visit']['status']);
		$this->assertNotNull($complete->getData()['nextVisit']);

		$nextId = (int)$complete->getData()['nextVisit']['id'];
		$skip = $mutating->skip($nextId);
		$this->assertSame(Http::STATUS_OK, $skip->getStatus());
		$this->assertSame('skipped', $skip->getData()['visit']['status']);

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

	public function testAssignSeatHttpStatuses(): void
	{
		// L0 (NC admin group) always passes requireAppAdmin — avoids relying on
		// app_admin_user_ids config that other tests may mutate.
		$um = \OC::$server->get(IUserManager::class);
		$sys = 'mn_gate_sys';
		if ($um->userExists($sys)) {
			$um->get($sys)?->delete();
		}
		$um->createUser($sys, self::PASS);
		$this->testUsers[] = $sys;
		$groupManager = \OC::$server->get(\OCP\IGroupManager::class);
		$adminGroup = $groupManager->get('admin') ?? $groupManager->createGroup('admin');
		$adminGroup->addUser($um->get($sys));

		$license = Server::get(LicenseService::class);
		$license->apply($sys, $this->signedKey(['mobileSeats' => 2]));

		$this->loginAs($sys);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'userId' ? self::UID : null);
		$request->method('getParams')->willReturn(['userId' => self::UID]);
		$request->method('getPathInfo')->willReturn('/apps/maintenancecheck/api/license/seats');
		$request->method('getMethod')->willReturn('POST');

		$controller = new LicenseController(
			$request,
			\OC::$server->get(AccessControlService::class),
			$license,
		);
		$created = $controller->assignSeat();
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame(self::UID, $created->getData()['uid']);

		$again = $controller->assignSeat();
		$this->assertSame(Http::STATUS_OK, $again->getStatus(), 'idempotent re-assign must be 200');
		$this->assertSame(self::UID, $again->getData()['uid']);
	}

	private function ensureExtraUser(string $uid): string
	{
		$this->ensureUser($uid);
		return $uid;
	}
}
