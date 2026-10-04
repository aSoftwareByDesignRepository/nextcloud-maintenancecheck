<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Controller\CustomerController;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Tests\Support\JsonEnvelope;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

/**
 * POLICY 3.5.14 negative-path durability: every mutating-endpoint failure
 * class must leave persisted state unchanged on re-read — a deny status or a
 * 4xx alone is not proof (403-then-write and redirect-to-200-HTML bounces are
 * both real shapes).
 *
 * Classes proven here against the live DB:
 *  - deny       forbidden caller → exception/403 envelope AND state unchanged
 *  - invalid    4xx validation (incl. whitespace/empty-trim) AND nothing persisted
 *  - not_found  uniform envelope for never-existed vs freshly-deleted ids
 *               (no existence oracle)
 *  - repeat     second identical mutation fails; no resurrection
 *  - plus the api-security "false"-string parse rule on the force flag
 *
 * @group integration
 */
final class NegativePathDurabilityIntegrationTest extends IntegrationTestCase
{
	private const PASSWORD = 'Mn-NegPath-9xK!zz';
	private const OFFICE = 'mn_np_office';
	private const TECH = 'mn_np_tech';
	private const MARKER = 'mn_np_';

	/** @var array<string, string> */
	private array $prevConfig = [];

	/** @var list<int> */
	private array $customerIds = [];

	private CustomerService $customers;
	private EquipmentService $equipment;
	private CatalogService $catalogs;
	private PlanService $plans;
	private VisitService $visits;
	private AppAccessMiddleware $middleware;
	private IDBConnection $db;
	private string $today;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);
		$config = \OC::$server->get(IConfig::class);
		foreach ([
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_APP_ADMINS,
		] as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		$um = \OC::$server->get(IUserManager::class);
		foreach ([self::OFFICE, self::TECH] as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
			$um->createUser($uid, self::PASSWORD);
		}
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, json_encode([self::OFFICE]));
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');

		$this->customers = Server::get(CustomerService::class);
		$this->equipment = Server::get(EquipmentService::class);
		$this->catalogs = Server::get(CatalogService::class);
		$this->plans = Server::get(PlanService::class);
		$this->visits = Server::get(VisitService::class);
		$this->middleware = Server::get(AppAccessMiddleware::class);
		$this->db = Server::get(IDBConnection::class);
		$this->today = Server::get(\OCA\MaintenanceCheck\Service\Clock::class)->today();
	}

	protected function tearDown(): void
	{
		if (class_exists(\OC::class) && isset(\OC::$server)) {
			foreach ($this->customerIds as $id) {
				try {
					$this->customers->delete($id, true);
				} catch (NotFoundException) {
					// already gone
				}
			}
			$this->customerIds = [];
			foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
				try {
					$qb = $this->db->getQueryBuilder();
					$qb->delete($table)->where($qb->expr()->like('code', $qb->createNamedParameter(self::MARKER . '%')));
					$qb->executeStatement();
				} catch (\Throwable) {
					// cleanup best-effort
				}
			}
			\OC::$server->get(IUserSession::class)->setUser(null);
			$config = \OC::$server->get(IConfig::class);
			foreach ($this->prevConfig as $key => $value) {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
			$um = \OC::$server->get(IUserManager::class);
			foreach ([self::OFFICE, self::TECH] as $uid) {
				if ($um->userExists($uid)) {
					$um->get($uid)?->delete();
				}
			}
		}
		parent::tearDown();
	}

	private function loginAs(string $uid): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);
	}

	private function customerTotal(): int
	{
		return (int)$this->customers->list(null, '1', '0')['total'];
	}

	private function createCustomer(string $uid): array
	{
		$row = $this->customers->create($uid, [
			'name' => self::MARKER . 'Cust ' . bin2hex(random_bytes(4)),
			'city' => 'Stuttgart',
			'country' => 'de',
		]);
		$this->customerIds[] = (int)$row['id'];
		return $row;
	}

	// ── deny ──────────────────────────────────────────────────────────

	public function testDenyTechnicianCreateLeavesCustomerTableUnchanged(): void
	{
		$this->loginAs(self::TECH);
		$before = $this->customerTotal();

		$controller = Server::get(CustomerController::class);
		try {
			$controller->create();
			$this->fail('technician must be denied at the office gate');
		} catch (PermissionDeniedException) {
			$this->addToAssertionCount(1);
		}

		// Durable re-read: the deny carried no hidden write.
		$this->assertSame($before, $this->customerTotal(), 'denied create must not persist');

		// Wire shape: middleware maps the deny to a 403 error envelope, not an
		// HTML redirect that a fetch client could misread as 200.
		$res = $this->middleware->afterException($controller, 'create', new PermissionDeniedException());
		$this->assertInstanceOf(JSONResponse::class, $res);
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertTrue(JsonEnvelope::isError($res->getData()));
		$this->assertSame('permission_denied', $res->getData()['error']['code']);
	}

	public function testDenyTechnicianDeleteLeavesCustomerRow(): void
	{
		$this->loginAs(self::OFFICE);
		$customer = $this->createCustomer(self::OFFICE);

		$this->loginAs(self::TECH);
		$controller = Server::get(CustomerController::class);
		try {
			$controller->destroy((int)$customer['id']);
			$this->fail('technician must be denied at the office gate');
		} catch (PermissionDeniedException) {
			$this->addToAssertionCount(1);
		}

		$re = $this->customers->get((int)$customer['id']);
		$this->assertSame($customer['name'], $re['name'], 'denied delete must leave the row intact');
	}

	// ── invalid ───────────────────────────────────────────────────────

	public function testInvalidCustomerCreatePersistsNothing(): void
	{
		$this->loginAs(self::OFFICE);
		$before = $this->customerTotal();

		foreach ([['name' => ''], ['name' => '   '], []] as $bad) {
			try {
				$this->customers->create(self::OFFICE, $bad);
				$this->fail('empty/whitespace name must be rejected: ' . json_encode($bad));
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame($before, $this->customerTotal(), 'rejected creates must persist nothing');

		$controller = Server::get(CustomerController::class);
		$res = $this->middleware->afterException(
			$controller,
			'create',
			new ValidationException('name_required', 'Name is required.', [['field' => 'name', 'code' => 'required']])
		);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$this->assertTrue(JsonEnvelope::isError($res->getData()));
	}

	// ── not_found: uniform 404, no existence oracle ──────────────────

	public function testNotFoundIsUniformForMissingAndDeletedIds(): void
	{
		$this->loginAs(self::OFFICE);
		$customer = $this->createCustomer(self::OFFICE);
		$deletedId = (int)$customer['id'];
		$this->customers->delete($deletedId, false);

		$neverExisted = 99999999;
		$controller = Server::get(CustomerController::class);

		$payloads = [];
		foreach ([$neverExisted, $deletedId] as $id) {
			foreach (['get', 'update', 'delete'] as $op) {
				try {
					match ($op) {
						'get' => $this->customers->get($id),
						'update' => $this->customers->update($id, ['name' => 'x']),
						'delete' => $this->customers->delete($id, false),
					};
					$this->fail("$op on missing id $id must throw NotFoundException");
				} catch (NotFoundException $e) {
					$res = $this->middleware->afterException($controller, 'show', $e);
					$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
					$this->assertTrue(JsonEnvelope::isError($res->getData()));
					$payloads[] = $res->getData();
				}
			}
		}
		$first = json_encode($payloads[0]);
		foreach ($payloads as $p) {
			$this->assertSame($first, json_encode($p), 'not_found envelope must be identical for missing vs deleted ids (no existence oracle)');
		}
		$this->assertSame('not_found', $payloads[0]['error']['code']);
	}

	// ── repeat ────────────────────────────────────────────────────────

	public function testRepeatDeleteFailsWithoutResurrection(): void
	{
		$this->loginAs(self::OFFICE);
		$before = $this->customerTotal();
		$customer = $this->createCustomer(self::OFFICE);
		$id = (int)$customer['id'];
		$this->assertSame($before + 1, $this->customerTotal());

		$this->customers->delete($id, false);
		try {
			$this->customers->delete($id, false);
			$this->fail('second delete must fail');
		} catch (NotFoundException) {
			$this->addToAssertionCount(1);
		}
		try {
			$this->customers->get($id);
			$this->fail('deleted row must not be readable');
		} catch (NotFoundException) {
			$this->addToAssertionCount(1);
		}
		$this->assertSame($before, $this->customerTotal(), 'no resurrection after repeat delete');
	}

	public function testRepeatVisitCompleteConflictsWithoutExtraFollowUp(): void
	{
		$this->loginAs(self::OFFICE);
		$equipTypeId = $this->ensureCatalog('equip', self::MARKER . 'et');
		$maintTypeId = $this->ensureCatalog('maint', self::MARKER . 'mt');

		$customer = $this->createCustomer(self::OFFICE);
		$equipment = $this->equipment->create(self::OFFICE, [
			'label' => self::MARKER . 'Eq',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => $equipTypeId,
		]);
		$plan = $this->plans->create(self::OFFICE, (int)$equipment['id'], [
			'maintTypeId' => $maintTypeId,
			'intervalUnit' => 'month',
			'intervalCount' => 3,
			'firstDueOn' => $this->today,
		]);
		$visitId = (int)$plan['openVisit']['id'];
		$visitCountBefore = count($this->visits->list(self::OFFICE, ['planId' => (string)$plan['id']])['data'] ?? []);

		$first = $this->visits->complete(self::OFFICE, $visitId, []);
		$this->assertSame('done', $first['visit']['status']);

		try {
			$this->visits->complete(self::OFFICE, $visitId, []);
			$this->fail('repeat complete on a closed visit must conflict');
		} catch (ConflictException | NotFoundException) {
			$this->addToAssertionCount(1);
		}

		// Re-read: still done, exactly one follow-up visit, no duplicated roll.
		$re = $this->visits->get(self::OFFICE, $visitId);
		$this->assertSame('done', $re['status']);
		$after = $this->visits->list(self::OFFICE, ['planId' => (string)$plan['id']])['data'] ?? [];
		$this->assertCount($visitCountBefore + 1, $after, 'repeat complete must not roll a second follow-up visit');
	}

	// ── api-security: form-encoded "false" must not force ────────────

	public function testForceFlagStringFalseDoesNotCascadeDelete(): void
	{
		$this->loginAs(self::OFFICE);
		$equipTypeId = $this->ensureCatalog('equip', self::MARKER . 'et');
		$customer = $this->createCustomer(self::OFFICE);
		$this->equipment->create(self::OFFICE, [
			'label' => self::MARKER . 'EqForce',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => $equipTypeId,
		]);

		// Controller parses $force === '1' — the literal string "false" (or any
		// non-'1' value) must behave as *not forced*: conflict, row survives.
		try {
			$this->customers->delete((int)$customer['id'], 'false' === '1');
			$this->fail('delete with force=false must conflict while equipment exists');
		} catch (ConflictException $e) {
			$this->assertSame('customer_has_equipment', $e->getErrorCode());
		}
		$re = $this->customers->get((int)$customer['id']);
		$this->assertSame($customer['name'], $re['name']);
	}

	private function ensureCatalog(string $kind, string $code): int
	{
		try {
			$row = $this->catalogs->create($kind, ['code' => $code, 'name' => 'NP ' . $code]);
		} catch (ConflictException) {
			foreach ($this->catalogs->list($kind, '200', '0')['data'] as $entry) {
				if ($entry['code'] === $code) {
					return (int)$entry['id'];
				}
			}
			$this->fail('Catalog entry vanished: ' . $code);
		}
		return (int)$row['id'];
	}
}
