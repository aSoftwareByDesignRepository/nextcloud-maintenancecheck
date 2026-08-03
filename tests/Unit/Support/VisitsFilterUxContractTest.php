<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Bachus visits filter contract: flat Due-style toolbar, live chips, no Apply click.
 */
final class VisitsFilterUxContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testVisitsTemplateIsFlatLiveToolbar(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/visits.php');
		$this->assertStringContainsString('mn-visits-toolbar', $tpl);
		$this->assertStringContainsString('mn-filter-status-chips', $tpl);
		$this->assertStringContainsString('mn-filter-when-chips', $tpl);
		$this->assertStringContainsString('id="mn-filter-reset"', $tpl);
		$this->assertStringContainsString('Clear filters', $tpl);
		$this->assertStringContainsString('disabled', $tpl);
		$this->assertStringContainsString('mn-card--table-solo', $tpl);
		$this->assertStringNotContainsString('Apply filters', $tpl);
		$this->assertStringNotContainsString('mn-filter-panel', $tpl);
		$this->assertStringNotContainsString('mn-card__header', $tpl);
		$this->assertStringNotContainsString('mn-card__lead', $tpl);
		$this->assertStringNotContainsString('<select id="mn-filter-status"', $tpl);
		$this->assertStringContainsString('data-mn-status="done"', $tpl);
		$this->assertStringContainsString('data-mn-when="week"', $tpl);
		$this->assertStringContainsString('aria-pressed', $tpl);
		$this->assertStringContainsString('mn-filter-date-hint', $tpl);
	}

	public function testVisitsPageJsAppliesFiltersLive(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('function pageVisits()', $js);
		$this->assertStringContainsString('mn-filter-status-chips', $js);
		$this->assertStringContainsString('mn-filter-when-chips', $js);
		$this->assertStringContainsString('function setWhen(', $js);
		$this->assertStringContainsString('Date range was swapped so From is before To.', $js);
		$this->assertStringContainsString('function applyFilters()', $js);
		$this->assertStringContainsString('function normalizeDates()', $js);
		$this->assertStringContainsString('function syncReset()', $js);
		$this->assertStringContainsString('visitSubjectTitle', $js);
		$this->assertStringContainsString('beginCreateWorkOrderFromVisit', $js);
		$this->assertMatchesRegularExpression(
			"/mineToggle\.addEventListener\(\s*'change',\s*applyFilters\s*\)/",
			$js
		);
	}

	public function testWorkOrdersTemplateDropsApplyButton(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/work-orders.php');
		$this->assertStringContainsString('mn-filter-panel', $tpl);
		$this->assertStringNotContainsString('Apply filters', $tpl);
		$this->assertStringContainsString('mn-wo-filter-live-hint', $tpl);
		$this->assertStringContainsString('Filters update as you type or change a control.', $tpl);
	}

	public function testFilterGridCssHasNoOrphanExtendedTrack(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('.mn-filter-status-chips', $css);
		$this->assertStringContainsString('.mn-date-range--compact', $css);
		// extended must be 4 tracks (3 fields + actions), not 5 with an empty column
		$this->assertMatchesRegularExpression(
			'/\.mn-filter-grid--extended\s*\{[^}]*grid-template-columns:\s*'
			. 'minmax\(0,\s*1fr\)\s+'
			. 'minmax\(0,\s*1\.35fr\)\s+'
			. 'minmax\(0,\s*0\.9fr\)\s+'
			. 'auto/s',
			$css
		);
	}

	public function testVisitsToolbarCssSharesDueChipTargets(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-visits-toolbar', $css);
		$this->assertStringContainsString('.mn-due-toolbar', $css);
		$this->assertMatchesRegularExpression(
			'/\.mn-visits-toolbar\s+\.mn-chip[\s,{]/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-visits-toolbar\s+\.mn-chip[\s\S]{0,200}?min-height:\s*44px/s',
			$css
		);
	}
}
