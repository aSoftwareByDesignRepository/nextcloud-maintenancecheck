<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Public;

use OCA\MaintenanceCheck\Db\Site;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Public\FieldIssueDraftFacade;
use OCA\MaintenanceCheck\Service\Clock;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F18 owner side: dedupe on (ref_type, ref_id), site-name → customer
 * resolution, draft-only creation, unique-number retry.
 */
class FieldIssueDraftFacadeTest extends TestCase
{
	private function clock(): Clock
	{
		$c = $this->createMock(Clock::class);
		$c->method('now')->willReturn(1_760_000_000);
		$c->method('today')->willReturn('2026-10-19');
		return $c;
	}

	private function facade(?WorkOrder $existing, array $sites, ?callable $onInsert = null): FieldIssueDraftFacade
	{
		$wos = $this->createMock(WorkOrderMapper::class);
		$wos->method('findByRef')->willReturn($existing);
		$wos->method('maxNumberForYear')->willReturn(4);
		if ($onInsert !== null) {
			$wos->method('insert')->willReturnCallback($onInsert);
		}
		$siteMapper = $this->createMock(SiteMapper::class);
		$siteMapper->method('findByName')->willReturn($sites);
		return new FieldIssueDraftFacade(
			$this->createMock(IDBConnection::class),
			$wos,
			$siteMapper,
			$this->clock(),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function site(int $id, int $customerId): Site
	{
		$s = new Site();
		$s->setId($id);
		$s->setCustomerId($customerId);
		return $s;
	}

	public function testInvalidPayloadRejected(): void
	{
		$f = $this->facade(null, []);
		$this->assertSame(['ok' => false, 'code' => 'invalid_payload'], $f->createDraft([]));
		$this->assertSame(['ok' => false, 'code' => 'invalid_payload'],
			$f->createDraft(['refType' => 'tck_incident', 'refId' => '12'])); // no title
	}

	public function testExistingRefReturnsAlready(): void
	{
		$wo = new WorkOrder();
		$wo->setId(9);
		$wo->setNumber('WO-2026-00005');
		$f = $this->facade($wo, []);
		$r = $f->createDraft(['refType' => 'tck_incident', 'refId' => '12', 'title' => 'Defect']);
		$this->assertTrue($r['ok']);
		$this->assertTrue($r['already']);
		$this->assertSame(9, $r['woId']);
		$this->assertSame('WO-2026-00005', $r['number']);
	}

	public function testUnresolvedSiteSoftFails(): void
	{
		$f = $this->facade(null, []); // no matching site
		$r = $f->createDraft(['refType' => 'tck_incident', 'refId' => '12', 'siteLabel' => 'Depot Ost', 'title' => 'Defect']);
		$this->assertFalse($r['ok']);
		$this->assertSame('site_unresolved', $r['code']);
	}

	public function testAmbiguousSiteSoftFails(): void
	{
		$f = $this->facade(null, [$this->site(1, 10), $this->site(2, 20)]);
		$r = $f->createDraft(['refType' => 'tck_incident', 'refId' => '12', 'siteLabel' => 'Depot Ost', 'title' => 'Defect']);
		$this->assertSame('site_unresolved', $r['code']);
	}

	public function testDraftCreatedWithSiteAndRefs(): void
	{
		$inserted = null;
		$f = $this->facade(null, [$this->site(7, 42)], function (WorkOrder $wo) use (&$inserted) {
			$wo->setId(15);
			$inserted = $wo;
			return $wo;
		});
		$r = $f->createDraft([
			'refType' => 'tck_incident', 'refId' => '12', 'siteLabel' => 'Depot Ost',
			'title' => 'Door closer broken', 'description' => 'Slams shut.',
			'checkpointLabel' => 'Back entrance', 'photoFileId' => 777,
			'actorUid' => 'guard1',
		]);
		$this->assertTrue($r['ok']);
		$this->assertSame(15, $r['woId']);
		$this->assertSame('WO-2026-00005', $r['number']);
		$this->assertSame(WorkOrder::STATUS_DRAFT, $inserted->getStatus());
		$this->assertSame(WorkOrder::KIND_CORRECTIVE, $inserted->getKind());
		$this->assertSame(42, $inserted->getCustomerId());
		$this->assertSame(7, $inserted->getSiteId());
		$this->assertSame('tck_incident', $inserted->getRefType());
		$this->assertSame('12', $inserted->getRefId());
		$this->assertSame('guard1', $inserted->getCreatedBy());
		$this->assertStringContainsString('Checkpoint: Back entrance', (string)$inserted->getDescription());
		$this->assertStringContainsString('Source: tck_incident #12', (string)$inserted->getDescription());
		$this->assertStringContainsString('Slams shut.', (string)$inserted->getDescription());
	}

	public function testTitleIsBounded(): void
	{
		$inserted = null;
		$f = $this->facade(null, [$this->site(7, 42)], function (WorkOrder $wo) use (&$inserted) {
			$inserted = $wo;
			$wo->setId(1);
			return $wo;
		});
		$f->createDraft(['refType' => 'tck_incident', 'refId' => '9', 'siteLabel' => 'Depot Ost', 'title' => str_repeat('x', 400)]);
		$this->assertSame(255, mb_strlen($inserted->getTitle()));
	}

	public function testUniqueRefRaceReturnsExisting(): void
	{
		// insert throws unique-violation; ref lookup then finds the winner.
		$winner = new WorkOrder();
		$winner->setId(3);
		$winner->setNumber('WO-2026-00009');
		$wos = $this->createMock(WorkOrderMapper::class);
		$wos->method('findByRef')->willReturnOnConsecutiveCalls(null, $winner);
		$wos->method('maxNumberForYear')->willReturn(8);
		$wos->method('insert')->willThrowException(new class extends \OCP\DB\Exception {
			public function getReason(): ?int
			{
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		});
		$siteMapper = $this->createMock(SiteMapper::class);
		$siteMapper->method('findByName')->willReturn([$this->site(7, 42)]);
		$f = new FieldIssueDraftFacade(
			$this->createMock(IDBConnection::class),
			$wos,
			$siteMapper,
			$this->clock(),
			$this->createMock(LoggerInterface::class),
		);
		$r = $f->createDraft(['refType' => 'tck_incident', 'refId' => '12', 'siteLabel' => 'Depot Ost', 'title' => 'Defect']);
		$this->assertTrue($r['ok']);
		$this->assertTrue($r['already']);
		$this->assertSame('WO-2026-00009', $r['number']);
	}
}
