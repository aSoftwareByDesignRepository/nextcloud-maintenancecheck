<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\ProjectCheckHoursDeepLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** F9 — Log hours deep link with WO ref in note. */
class ProjectCheckHoursDeepLinkServiceTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private IURLGenerator&MockObject $urls;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->urls = $this->createMock(IURLGenerator::class);
	}

	public function testReturnsNullWhenProjectCheckDisabled(): void
	{
		$this->apps->method('isEnabledForUser')->with('projectcheck')->willReturn(false);
		$svc = new ProjectCheckHoursDeepLinkService($this->apps, $this->urls);
		$this->assertNull($svc->buildLogHoursUrl('WO-2026-0001'));
	}

	public function testReturnsNullForBlankNumber(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$svc = new ProjectCheckHoursDeepLinkService($this->apps, $this->urls);
		$this->assertNull($svc->buildLogHoursUrl('  '));
	}

	public function testBuildsUrlWithEncodedNote(): void
	{
		$this->apps->method('isEnabledForUser')->willReturn(true);
		$this->urls->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('projectcheck.timeentry.create')
			->willReturn('https://nc.example/apps/projectcheck/time-entries/create');

		$svc = new ProjectCheckHoursDeepLinkService($this->apps, $this->urls);
		$url = $svc->buildLogHoursUrl('WO-2026-0042');

		$this->assertNotNull($url);
		$this->assertStringContainsString('description=', $url);
		$this->assertStringContainsString(rawurlencode('MaintenanceCheck WO WO-2026-0042'), $url);
	}
}
