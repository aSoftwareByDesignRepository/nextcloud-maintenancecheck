<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use OCA\MaintenanceCheck\Support\IconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogTest extends TestCase
{
	public function testEveryNamedIconRendersAccessibleSvg(): void
	{
		$names = IconCatalog::names();
		$this->assertNotEmpty($names);
		$this->assertContains('wrench', $names);
		$this->assertContains('layout-grid', $names);

		foreach ($names as $name) {
			$svg = IconCatalog::render($name);
			$this->assertStringStartsWith('<svg ', $svg, $name);
			$this->assertStringContainsString('aria-hidden="true"', $svg, $name);
			$this->assertStringContainsString('focusable="false"', $svg, $name);
			$this->assertStringContainsString('class="mn-icon"', $svg, $name);
			$this->assertStringContainsString('stroke="currentColor"', $svg, $name);
		}
	}

	public function testUnknownIconReturnsEmptyString(): void
	{
		$this->assertSame('', IconCatalog::render('not-a-real-icon'));
	}

	public function testExtraClassIsEscaped(): void
	{
		$svg = IconCatalog::render('plus', 'mn-icon--lg" onload="alert(1)');
		$this->assertStringContainsString('class="mn-icon mn-icon--lg&quot; onload=&quot;alert(1)"', $svg);
		$this->assertStringNotContainsString('onload="alert', $svg);
	}

	public function testEmptyExtraClassIsIgnored(): void
	{
		$this->assertStringContainsString('class="mn-icon"', IconCatalog::render('plus', ''));
		$this->assertStringContainsString('class="mn-icon"', IconCatalog::render('plus', null));
	}
}
