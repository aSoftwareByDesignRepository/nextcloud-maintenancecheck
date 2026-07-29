<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\ArbeitszeitCheckDeepLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AC-F2 — Record time deep link with WO ref in note. */
final class ArbeitszeitCheckDeepLinkServiceTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private IURLGenerator&MockObject $urls;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->urls = $this->createMock(IURLGenerator::class);
	}

	public function testReturnsNullWhenArbeitszeitCheckDisabled(): void
	{
		$this->apps->method('isEnabledForUser')->with('arbeitszeitcheck')->willReturn(false);
		$svc = new ArbeitszeitCheckDeepLinkService($this->apps, $this->urls);
		$this->assertNull($svc->buildRecordTimeUrl('WO-2026-0001'));
	}

	public function testReturnsNullForBlankNumber(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$svc = new ArbeitszeitCheckDeepLinkService($this->apps, $this->urls);
		$this->assertNull($svc->buildRecordTimeUrl('  '));
	}

	public function testBuildsUrlWithEncodedNote(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->urls->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('arbeitszeitcheck.time_entry.create')
			->willReturn('https://nc.example/apps/arbeitszeitcheck/time-entries/create');

		$svc = new ArbeitszeitCheckDeepLinkService($this->apps, $this->urls);
		$url = $svc->buildRecordTimeUrl('WO-2026-0042');

		$this->assertNotNull($url);
		$this->assertStringContainsString('description=', $url);
		$this->assertStringContainsString(rawurlencode('MaintenanceCheck WO WO-2026-0042'), $url);
	}

	public function testReturnsNullWhenRouteMissing(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->urls->method('linkToRouteAbsolute')->willThrowException(new \RuntimeException('missing'));
		$svc = new ArbeitszeitCheckDeepLinkService($this->apps, $this->urls);
		$this->assertNull($svc->buildRecordTimeUrl('WO-1'));
	}
}
