<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Day tours Bachus flatten contracts (toolbar outside list, tech buckets).
 */
final class ToursBoardContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testToursTemplateKeepsToolbarOutsideTableSolo(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/tours.php');
		$this->assertStringContainsString('id="mn-tours-toolbar"', $tpl);
		$this->assertStringContainsString('mn-card--table-solo', $tpl);
		$this->assertStringContainsString('tours_quickstart_v3', $tpl);
		$this->assertStringContainsString('mn-board mn-listing', $tpl);
		$this->assertStringNotContainsString('mn-card__header', $tpl);
		$posToolbar = strpos($tpl, 'id="mn-tours-toolbar"');
		$posCard = strpos($tpl, 'mn-card--table-solo');
		$this->assertNotFalse($posToolbar);
		$this->assertNotFalse($posCard);
		$this->assertLessThan($posCard, $posToolbar, 'Toolbar must precede the list card');
	}

	public function testToursJsUsesBucketsNotNestedTourCards(): void
	{
		$js = (string)file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringContainsString('mn-bucket mn-tour', $js);
		$this->assertStringContainsString('mn-tour__actions', $js);
		$this->assertStringContainsString('renderToolbar', $js);
		$this->assertStringNotContainsString('mn-tour-card', $js);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-tour__title', $css);
		$this->assertDoesNotMatchRegularExpression('/\.mn-tour-card\s*\{/', $css);
	}
}
