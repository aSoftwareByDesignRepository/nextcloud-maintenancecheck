<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\DutyCheckOnDutyService;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** AC-F3 — DutyCheck soft on-duty read. */
final class DutyCheckOnDutyServiceTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testDegradesWhenDutyCheckDisabled(): void
	{
		$this->apps->method('isEnabledForUser')->with('dutycheck')->willReturn(false);
		$svc = new DutyCheckOnDutyService($this->apps, $this->logger, null, static fn () => [
			['linkedUserId' => 'tech1'],
		]);
		$snap = $svc->forDay('2026-07-26');
		$this->assertFalse($snap['available']);
		$this->assertSame([], $snap['onDutyUids']);
		$this->assertNull($svc->isOnDuty('tech1', '2026-07-26'));
	}

	public function testReturnsOnDutyUidsWhenAvailable(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$svc = new DutyCheckOnDutyService(
			$this->apps,
			$this->logger,
			$this->createMock(IUserSession::class),
			static fn () => [
				['linkedUserId' => 'tech-b'],
				['linkedUserId' => 'tech-a'],
				['linkedUserId' => ''],
				['linkedUserId' => 'tech-a'],
			],
		);
		$snap = $svc->forDay('2026-07-26');
		$this->assertTrue($snap['available']);
		$this->assertSame(['tech-a', 'tech-b'], $snap['onDutyUids']);
		$this->assertTrue($svc->isOnDuty('tech-a', '2026-07-26'));
		$this->assertFalse($svc->isOnDuty('ghost', '2026-07-26'));
	}

	public function testDegradesOnInvokerException(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->logger->expects($this->once())->method('warning');
		$svc = new DutyCheckOnDutyService(
			$this->apps,
			$this->logger,
			null,
			static function (): array {
				throw new \RuntimeException('boom');
			},
		);
		$snap = $svc->forDay();
		$this->assertFalse($snap['available']);
		$this->assertSame([], $snap['onDutyUids']);
	}
}
