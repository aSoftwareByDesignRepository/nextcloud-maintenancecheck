<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Catalogs Bachus flatten — chip tabs, one panel visible.
 */
final class CatalogsBoardContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testCatalogsTemplateUsesChipToolbarNotPairs(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/catalogs.php');
		$this->assertStringContainsString('mn-catalogs-toolbar', $tpl);
		$this->assertStringContainsString('catalogs_quickstart_v2', $tpl);
		$this->assertStringContainsString('role="tablist"', $tpl);
		$this->assertStringContainsString('data-mn-catalog="equip"', $tpl);
		$this->assertStringContainsString('mn-catalog-panel', $tpl);
		$this->assertStringNotContainsString('mn-catalogs__pair', $tpl);
		$this->assertStringNotContainsString('mn-catalogs__wide', $tpl);
	}

	public function testCatalogsJsWiresPanelSwitchAndDropsLoadSkills(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('showCatalogPanel', $js);
		$this->assertStringContainsString('Skills load when you pick a person.', $js);
		$this->assertStringNotContainsString("tr('Load skills')", $js);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-catalogs-toolbar', $css);
		$this->assertStringContainsString('.mn-catalog-panel[hidden]', $css);
	}
}
