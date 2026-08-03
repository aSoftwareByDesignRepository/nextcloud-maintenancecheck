<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\BuiltinProcedurePackSeeder;
use OCA\MaintenanceCheck\Service\EquipmentClassService;
use OCA\MaintenanceCheck\Service\InspectionObligationService;
use OCA\MaintenanceCheck\Service\KpiService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\ProcedureService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\VisitService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\Server;

/**
 * AC-W7-1…12 behavioural integration (MySQL via Docker).
 */
final class W7FieldOpsIntegrationTest extends IntegrationTestCase
{
	private WorkOrderService $workOrders;
	private InspectionObligationService $obligations;
	private EquipmentClassService $classes;
	private PolicyService $policies;
	private VisitService $visits;
	private KpiService $kpi;
	private WoPdfService $pdf;
	private SkillService $skills;

	protected function setUp(): void
	{
		parent::setUp();
		$this->workOrders = Server::get(WorkOrderService::class);
		$this->obligations = Server::get(InspectionObligationService::class);
		$this->classes = Server::get(EquipmentClassService::class);
		$this->policies = Server::get(PolicyService::class);
		$this->visits = Server::get(VisitService::class);
		$this->kpi = Server::get(KpiService::class);
		$this->pdf = Server::get(WoPdfService::class);
		$this->skills = Server::get(SkillService::class);
		Server::get(BuiltinProcedurePackSeeder::class)->ensureInstalled();
		$this->classes->seedIfEmpty();
	}

	public function testSeedPacksAndClasses(): void
	{
		$list = $this->classes->list();
		self::assertGreaterThanOrEqual(6, $list['total']);
		$codes = array_column($list['data'], 'code');
		self::assertContains('portable_electrical', $codes);
		self::assertContains('ladder', $codes);
		self::assertContains('fire_extinguisher', $codes);

		$procs = Server::get(ProcedureService::class);
		$found = [];
		foreach (['de-portable-electrical', 'de-ladders', 'de-fire-extinguisher', 'en-portable-electrical', 'en-ladders', 'en-fire-extinguisher'] as $pack) {
			$rows = Server::get(\OCA\MaintenanceCheck\Db\ProcedureMapper::class)->findBySourcePack($pack);
			if ($rows !== []) {
				$found[] = $pack;
			}
		}
		self::assertCount(6, $found, 'W7 seed packs must install DE+EN: ' . implode(',', $found));
	}

	public function testObligationCreatesDueVisit(): void
	{
		$customerId = $this->seedCustomer('W7 Ladder Co');
		$equipmentId = $this->seedEquipment($customerId, 'Ladder A', [
			'equipmentClass' => 'ladder',
		]);
		$obl = $this->obligations->create('admin', $equipmentId, [
			'classCode' => 'ladder',
			'firstDueOn' => '2020-01-01',
		]);
		self::assertNotNull($obl['openVisit']);
		self::assertSame('2020-01-01', $obl['openVisit']['dueOn']);

		$due = $this->visits->due('admin', false, 'inspection');
		$all = array_merge($due['overdue'], $due['today'], $due['next7'], $due['later']);
		$hit = false;
		foreach ($all as $row) {
			if (($row['isInspection'] ?? false) === true) {
				$hit = true;
				break;
			}
		}
		self::assertTrue($hit, 'Prüfungen filter must surface obligation visit');
	}

	public function testInspectionDoneRequiresResultAndDefects(): void
	{
		$this->policies->save(['inspectionResultRequired' => true, 'defectFollowUp' => 'off']);
		try {
			$wo = $this->seedInspectionWo('W7 Result Co', 'Panel X');
			$id = (int)$wo['id'];
			$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			try {
				$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_DONE], true);
				self::fail('Expected inspection_result_required');
			} catch (ValidationException $e) {
				self::assertSame('inspection_result_required', $e->getErrorCode());
			}
			try {
				$this->workOrders->transition('admin', $id, [
					'to' => WorkOrder::STATUS_DONE,
					'result' => WorkOrder::RESULT_FAIL,
					'inspectorName' => 'SiFa Test',
				], true);
				self::fail('Expected inspection_defects_required');
			} catch (ValidationException $e) {
				self::assertSame('inspection_defects_required', $e->getErrorCode());
			}
			$done = $this->workOrders->transition('admin', $id, [
				'to' => WorkOrder::STATUS_DONE,
				'result' => WorkOrder::RESULT_FAIL,
				'inspectorName' => 'SiFa Test',
				'defects' => [['code' => 'crack', 'body' => 'Rung cracked near base']],
			], true);
			self::assertSame(WorkOrder::STATUS_DONE, $done['status']);
			self::assertSame(WorkOrder::RESULT_FAIL, $done['result']);
			self::assertNotEmpty($done['defects']);
			self::assertArrayNotHasKey('correctiveWorkOrderId', $done);
			$followOff = Server::get(\OCA\MaintenanceCheck\Db\WorkOrderMapper::class)->findBySourceWoId($id);
			self::assertNull($followOff, 'defectFollowUp=off must not open a corrective WO');
		} finally {
			$this->policies->save(['defectFollowUp' => 'warn']);
		}
	}

	public function testAutoCorrectiveOnFail(): void
	{
		$this->policies->save(['defectFollowUp' => 'auto', 'inspectionResultRequired' => true]);
		try {
			$wo = $this->seedInspectionWo('W7 Auto Co', 'Extinguisher 1');
			$id = (int)$wo['id'];
			$this->workOrders->assign('admin', $id, ['primaryUserId' => 'admin', 'force' => true]);
			$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			$done = $this->workOrders->transition('admin', $id, [
				'to' => WorkOrder::STATUS_DONE,
				'result' => WorkOrder::RESULT_FAIL,
				'inspectorName' => 'Prüfer',
				'inspectorNote' => 'SiFa desk',
				'defects' => [
					['code' => 'seal', 'body' => 'Seal broken'],
					['code' => 'gauge', 'body' => 'Gauge in red'],
				],
			], true);
			self::assertSame(WorkOrder::STATUS_DONE, $done['status']);
			self::assertSame('auto', $done['defectFollowUp'] ?? null);
			self::assertArrayHasKey('correctiveWorkOrderId', $done);
			$follow = Server::get(\OCA\MaintenanceCheck\Db\WorkOrderMapper::class)->findBySourceWoId($id);
			self::assertNotNull($follow);
			self::assertSame(WorkOrder::KIND_CORRECTIVE, $follow->getKind());
			self::assertSame((int)$done['correctiveWorkOrderId'], (int)$follow->getId());
			self::assertSame('admin', $follow->getPrimaryUserId());
			self::assertSame('SiFa desk', $done['inspectorNote'] ?? null);
			self::assertCount(2, $done['defects']);
			self::assertSame('Seal broken', $follow->getSymptom());
		} finally {
			$this->policies->save(['defectFollowUp' => 'warn']);
		}
	}

	public function testDefectPhotoMustBelongToWorkOrder(): void
	{
		$this->policies->save(['inspectionResultRequired' => true, 'defectFollowUp' => 'off']);
		try {
			$wo = $this->seedInspectionWo('W7 Photo Co', 'Photo Asset');
			$id = (int)$wo['id'];
			$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			try {
				$this->workOrders->transition('admin', $id, [
					'to' => WorkOrder::STATUS_DONE,
					'result' => WorkOrder::RESULT_FAIL,
					'inspectorName' => 'Foto Prüfer',
					'defects' => [['code' => 'x', 'body' => 'Crack', 'photoFileId' => 999999]],
				], true);
				self::fail('Expected photo_not_found');
			} catch (ValidationException $e) {
				self::assertSame('validation_failed', $e->getErrorCode());
				$codes = array_column($e->getDetails(), 'code');
				self::assertContains('photo_not_found', $codes);
			}
		} finally {
			$this->policies->save(['defectFollowUp' => 'warn']);
		}
	}

	public function testDefectPhotoWrongWorkOrderRejected(): void
	{
		$this->policies->save(['inspectionResultRequired' => true, 'defectFollowUp' => 'off']);
		try {
			$donor = $this->seedInspectionWo('W7 Photo Donor', 'Donor Asset');
			$target = $this->seedInspectionWo('W7 Photo Target', 'Target Asset');
			$donorId = (int)$donor['id'];
			$targetId = (int)$target['id'];
			$this->workOrders->transition('admin', $donorId, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			$this->workOrders->transition('admin', $targetId, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);

			$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
			self::assertNotFalse($png);
			$photo = Server::get(\OCA\MaintenanceCheck\Service\WoEvidenceService::class)
				->addPhoto('admin', $donorId, $png, 'crack.png');
			$photoId = (int)$photo['id'];
			self::assertGreaterThan(0, $photoId);

			try {
				$this->workOrders->transition('admin', $targetId, [
					'to' => WorkOrder::STATUS_DONE,
					'result' => WorkOrder::RESULT_FAIL,
					'inspectorName' => 'Cross WO Prüfer',
					'defects' => [['code' => 'x', 'body' => 'Crack', 'photoFileId' => $photoId]],
				], true);
				self::fail('Expected photo_wrong_work_order');
			} catch (ValidationException $e) {
				self::assertSame('validation_failed', $e->getErrorCode());
				$codes = array_column($e->getDetails(), 'code');
				self::assertContains('photo_wrong_work_order', $codes);
			}
		} finally {
			$this->policies->save(['defectFollowUp' => 'warn']);
		}
	}

	public function testObligationVisitCompleteRequiresWorkOrder(): void
	{
		$customerId = $this->seedCustomer('W7 Visit Gate Co');
		$equipmentId = $this->seedEquipment($customerId, 'Gate Ladder', [
			'equipmentClass' => 'ladder',
		]);
		$obl = $this->obligations->create('admin', $equipmentId, [
			'classCode' => 'ladder',
			'firstDueOn' => '2020-01-01',
		]);
		$visitId = (int)$obl['openVisit']['id'];
		try {
			$this->visits->complete('admin', $visitId, []);
			self::fail('Expected inspection_requires_work_order');
		} catch (\OCA\MaintenanceCheck\Exception\ConflictException $e) {
			self::assertSame('inspection_requires_work_order', $e->getErrorCode());
		}
		try {
			$this->visits->skip('admin', $visitId, ['notes' => 'would bypass']);
			self::fail('Expected inspection_requires_work_order on skip');
		} catch (\OCA\MaintenanceCheck\Exception\ConflictException $e) {
			self::assertSame('inspection_requires_work_order', $e->getErrorCode());
		}
		$visit = Server::get(\OCA\MaintenanceCheck\Db\VisitMapper::class)->findById($visitId);
		self::assertSame(\OCA\MaintenanceCheck\Db\Visit::STATUS_SCHEDULED, $visit->getStatus());
	}

	public function testSourceWoIdUniqueBlocksDuplicateFollowUp(): void
	{
		$a = $this->seedInspectionWo('W7 Unique A', 'Asset A');
		$bCustomer = $this->seedCustomer('W7 Unique B Cust');
		$bEquip = $this->seedEquipment($bCustomer, 'Asset B');
		$first = $this->workOrders->create('admin', [
			'title' => 'Corrective 1',
			'kind' => WorkOrder::KIND_CORRECTIVE,
			'customerId' => $bCustomer,
			'equipmentId' => $bEquip,
			'sourceWoId' => (int)$a['id'],
			'status' => WorkOrder::STATUS_PLANNED,
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Unique index fixture',
		], true);
		self::assertGreaterThan(0, (int)$first['id']);
		try {
			$this->workOrders->create('admin', [
				'title' => 'Corrective 2',
				'kind' => WorkOrder::KIND_CORRECTIVE,
				'customerId' => $bCustomer,
				'equipmentId' => $bEquip,
				'sourceWoId' => (int)$a['id'],
				'status' => WorkOrder::STATUS_PLANNED,
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Unique index fixture duplicate',
			], true);
			self::fail('Expected unique source_wo_id violation');
		} catch (\Throwable $e) {
			self::assertTrue(
				str_contains(strtolower($e->getMessage()), 'unique')
				|| str_contains(strtolower($e->getMessage()), 'duplicate')
				|| $e instanceof \OCP\DB\Exception,
				'Expected unique constraint failure, got: ' . $e::class . ' ' . $e->getMessage()
			);
		}
	}

	public function testWarnFollowUpFlagsWithoutCorrective(): void
	{
		$this->policies->save(['defectFollowUp' => 'warn', 'inspectionResultRequired' => true]);
		$wo = $this->seedInspectionWo('W7 Warn Co', 'Ladder Warn');
		$id = (int)$wo['id'];
		$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
		$done = $this->workOrders->transition('admin', $id, [
			'to' => WorkOrder::STATUS_DONE,
			'result' => WorkOrder::RESULT_FAIL,
			'inspectorName' => 'Warn Prüfer',
			'defects' => [['code' => 'crack', 'body' => 'Crack']],
		], true);
		self::assertSame('warn', $done['defectFollowUp'] ?? null);
		self::assertArrayNotHasKey('correctiveWorkOrderId', $done);
		self::assertNull(Server::get(\OCA\MaintenanceCheck\Db\WorkOrderMapper::class)->findBySourceWoId($id));
	}

	public function testFailBlocksRollSkipsNextDueOnFail(): void
	{
		$this->policies->save([
			'failBlocksRoll' => true,
			'inspectionResultRequired' => true,
			'defectFollowUp' => 'off',
		]);
		try {
			$customerId = $this->seedCustomer('W7 Block Roll Co');
			$equipmentId = $this->seedEquipment($customerId, 'Ladder Block', [
				'equipmentClass' => 'ladder',
			]);
			$obl = $this->obligations->create('admin', $equipmentId, [
				'classCode' => 'ladder',
				'firstDueOn' => '2020-01-01',
			]);
			$visitId = (int)$obl['openVisit']['id'];
			$wo = $this->workOrders->createFromVisit('admin', $visitId, [
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Fail-blocks-roll fixture without pack',
			]);
			$id = (int)$wo['id'];
			$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
			$this->workOrders->transition('admin', $id, [
				'to' => WorkOrder::STATUS_DONE,
				'result' => WorkOrder::RESULT_FAIL,
				'inspectorName' => 'Block Roll Prüfer',
				'defects' => [['code' => 'crack', 'body' => 'Broken']],
			], true);
			$visit = Server::get(\OCA\MaintenanceCheck\Db\VisitMapper::class)->findById($visitId);
			self::assertSame(\OCA\MaintenanceCheck\Db\Visit::STATUS_DONE, $visit->getStatus());
			$list = $this->obligations->listForEquipment('admin', $equipmentId);
			$row = null;
			foreach ($list as $item) {
				if ((int)($item['id'] ?? 0) === (int)$obl['id']) {
					$row = $item;
					break;
				}
			}
			self::assertNotNull($row);
			self::assertNull($row['openVisit'] ?? null, 'fail_blocks_roll must not schedule the next due');
		} finally {
			$this->policies->save(['failBlocksRoll' => false, 'defectFollowUp' => 'warn']);
		}
	}

	public function testInspectionEvidencePdfAndKpi(): void
	{
		$wo = $this->seedInspectionWo('W7 Pdf Co', 'BM Tool');
		$id = (int)$wo['id'];
		$this->workOrders->transition('admin', $id, ['to' => WorkOrder::STATUS_IN_PROGRESS], true);
		$this->workOrders->transition('admin', $id, [
			'to' => WorkOrder::STATUS_DONE,
			'result' => WorkOrder::RESULT_PASS,
			'inspectorName' => 'Inspector',
		], true);
		$pdf = $this->pdf->inspectionEvidence($id);
		self::assertStringContainsString('pruefnachweis', strtolower($pdf['filename'] ?? $pdf['name'] ?? ''));
		self::assertStringNotContainsStringIgnoringCase('zertifikat', $pdf['filename'] ?? '');
		$content = (string)($pdf['content'] ?? '');
		self::assertNotSame('', $content);
		self::assertStringStartsWith('%PDF', $content);
		self::assertStringNotContainsStringIgnoringCase('Zertifikat', $content);

		$snap = $this->kpi->snapshot(30);
		self::assertArrayHasKey('inspectionOverdueCount', $snap);
		self::assertArrayHasKey('inspectionCompliancePercent', $snap);
		self::assertIsInt($snap['inspectionOverdueCount']);
		self::assertIsNumeric($snap['inspectionCompliancePercent']);
	}

	public function testElectroPortableSkillBlocksReady(): void
	{
		$this->policies->save(['skillsEnforcement' => PolicyService::ENFORCEMENT_BLOCK]);
		try {
			$skillId = $this->skills->ensureByCode('electro_portable', 'Portable electrical inspection');
			$customerId = $this->seedCustomer('W7 Skill Co');
			$equipmentId = $this->seedEquipment($customerId, 'Drill', [
				'equipmentClass' => 'portable_electrical',
			]);
			$obl = $this->obligations->create('admin', $equipmentId, [
				'classCode' => 'portable_electrical',
				'firstDueOn' => date('Y-m-d'),
			]);
			$visitId = (int)$obl['openVisit']['id'];
			$wo = $this->workOrders->createFromVisit('admin', $visitId, [
				'procedureSkipped' => true,
				'procedureSkipReason' => 'Skill gate fixture without pack',
			]);
			self::assertSame(WorkOrder::KIND_INSPECTION, $wo['kind']);
			$required = array_column($wo['requiredSkills'] ?? [], 'id');
			self::assertContains($skillId, $required);
			$id = (int)$wo['id'];
			// Already planned from createFromVisit when procedure is skipped.
			self::assertSame(WorkOrder::STATUS_PLANNED, $wo['status']);
			try {
				$this->workOrders->assign('admin', $id, ['primaryUserId' => 'admin']);
				self::fail('Expected skills_missing on assign');
			} catch (ValidationException $e) {
				self::assertSame('skills_missing', $e->getErrorCode());
			}
		} finally {
			$this->policies->save(['skillsEnforcement' => PolicyService::ENFORCEMENT_WARN]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seedInspectionWo(string $customerName, string $equipmentLabel): array
	{
		$customerId = $this->seedCustomer($customerName);
		$equipmentId = $this->seedEquipment($customerId, $equipmentLabel);
		return $this->workOrders->create('admin', [
			'title' => 'Inspection ' . $equipmentLabel,
			'kind' => WorkOrder::KIND_INSPECTION,
			'customerId' => $customerId,
			'equipmentId' => $equipmentId,
			'dueOn' => '2020-06-01',
			'status' => WorkOrder::STATUS_PLANNED,
			'procedureSkipped' => true,
			'procedureSkipReason' => 'Fixture inspection without pack',
		], true);
	}

	private function seedCustomer(string $name): int
	{
		$customers = Server::get(\OCA\MaintenanceCheck\Service\CustomerService::class);
		$row = $customers->create('admin', ['name' => $name, 'active' => true]);
		return (int)$row['id'];
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function seedEquipment(int $customerId, string $label, array $extra = []): int
	{
		$equipment = Server::get(\OCA\MaintenanceCheck\Service\EquipmentService::class);
		$catalog = Server::get(\OCA\MaintenanceCheck\Service\CatalogService::class);
		$types = $catalog->list('equip', '10', '0');
		$typeId = (int)($types['data'][0]['id'] ?? 0);
		self::assertGreaterThan(0, $typeId);
		$body = array_merge([
			'customerId' => $customerId,
			'equipTypeId' => $typeId,
			'label' => $label,
			'active' => true,
		], $extra);
		$row = $equipment->create('admin', $body);
		return (int)$row['id'];
	}
}
