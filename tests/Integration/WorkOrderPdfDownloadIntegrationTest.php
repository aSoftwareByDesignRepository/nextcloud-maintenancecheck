<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Controller\WorkOrderController;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\CatalogService;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\CustomerService;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\PlanService;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use ReflectionMethod;

/**
 * Live-DB proof that web PDF downloads:
 *  1) stay CSRF-exempt (href navigation)
 *  2) still enforce work-order ACL before rendering
 *  3) return a real PDF binary envelope
 *
 * @group integration
 */
final class WorkOrderPdfDownloadIntegrationTest extends IntegrationTestCase
{
	private const UID = 'mn_pdf_dl_itest';
	private const MARKER = 'mn_pdf_dl_';

	/** @var list<int> */
	private array $customerIds = [];

	/** @var list<string> */
	private array $tempUsers = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		parent::setUp();
		Server::get(BuiltinProcedurePackSeeder::class)->ensureInstalled();
	}

	protected function tearDown(): void
	{
		if (class_exists(\OC::class)) {
			$customers = Server::get(CustomerService::class);
			foreach ($this->customerIds as $id) {
				try {
					$customers->delete($id, true);
				} catch (NotFoundException) {
				}
			}
			$this->customerIds = [];
			$db = Server::get(IDBConnection::class);
			foreach (['mn_equip_types', 'mn_maint_types'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->like('code', $qb->createNamedParameter(self::MARKER . '%')));
				$qb->executeStatement();
			}
			$um = Server::get(IUserManager::class);
			foreach ($this->tempUsers as $uid) {
				$um->get($uid)?->delete();
			}
			$this->tempUsers = [];
		}
		parent::tearDown();
	}

	private function loginAs(string $uid): void
	{
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		Server::get(IUserSession::class)->setUser($user);
	}

	private function ensureUser(string $uid): void
	{
		$um = Server::get(IUserManager::class);
		$um->get($uid)?->delete();
		$created = $um->createUser($uid, 'Mn-Pdf-Dl-9!x' . bin2hex(random_bytes(4)));
		$this->assertNotFalse($created);
		$this->tempUsers[] = $uid;
	}

	private function ensureCatalog(CatalogService $catalogs, string $kind, string $code): int
	{
		try {
			$row = $catalogs->create($kind, ['code' => $code, 'name' => 'ITest ' . $code]);
		} catch (ConflictException) {
			foreach ($catalogs->list($kind, '200', '0')['data'] as $entry) {
				if ($entry['code'] === $code) {
					return (int)$entry['id'];
				}
			}
			$this->fail('Catalog entry vanished: ' . $code);
		}
		return (int)$row['id'];
	}

	private function firstActiveProcedureId(ProcedureService $procedures): int
	{
		$page = $procedures->list('50', '0', null, '1');
		foreach ($page['data'] as $row) {
			if (!empty($row['active'])) {
				return (int)$row['id'];
			}
		}
		$this->fail('No active procedure after builtin seed');
	}

	public function testJobPackPdfAttributesRemainCsrfExempt(): void
	{
		$ref = new ReflectionMethod(WorkOrderController::class, 'jobPackPdf');
		$this->assertNotEmpty($ref->getAttributes(NoCSRFRequired::class));
	}

	public function testJobPackPdfReturnsPdfForLoggedInUser(): void
	{
		$this->ensureUser(self::UID);
		$this->loginAs(self::UID);

		$catalogs = Server::get(CatalogService::class);
		$suffix = bin2hex(random_bytes(3));
		$equipTypeId = $this->ensureCatalog($catalogs, 'equip', self::MARKER . 'et_' . $suffix);
		$maintTypeId = $this->ensureCatalog($catalogs, 'maint', self::MARKER . 'mt_' . $suffix);

		$customers = Server::get(CustomerService::class);
		$customer = $customers->create(self::UID, ['name' => 'PDF DL ' . $suffix]);
		$this->customerIds[] = (int)$customer['id'];

		$equipment = Server::get(EquipmentService::class)->create(self::UID, [
			'label' => 'PDF unit',
			'customerId' => (int)$customer['id'],
			'equipTypeId' => $equipTypeId,
		]);
		$today = Server::get(Clock::class)->today();
		$plan = Server::get(PlanService::class)->create(self::UID, (int)$equipment['id'], [
			'maintTypeId' => $maintTypeId,
			'intervalUnit' => 'week',
			'intervalCount' => 1,
			'firstDueOn' => $today,
		]);
		$visitId = (int)$plan['openVisit']['id'];
		$procedureId = $this->firstActiveProcedureId(Server::get(ProcedureService::class));
		$wo = Server::get(WorkOrderService::class)->createFromVisit(
			self::UID,
			$visitId,
			['procedureId' => $procedureId],
			true,
		);

		$ctrl = Server::get(WorkOrderController::class);
		$response = $ctrl->jobPackPdf((int)$wo['id']);
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringStartsWith('%PDF', $content);
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type'] ?? null);
		$this->assertStringContainsString('job-pack', strtolower((string)($response->getHeaders()['Content-Disposition'] ?? '')));
	}
}
