<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** ArbeitszeitCheck shell parity contract for MaintenanceCheck. */
final class AzcShellParityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testShellFilesExist(): void
	{
		foreach ([
			'/css/common/tokens.css',
			'/css/common/app-layout.css',
			'/css/common/page-patterns.css',
			'/css/navigation.css',
			'/js/common/navigation.js',
			'/templates/common/page-start.php',
			'/templates/common/navigation.php',
			'/templates/common/page-end.php',
		] as $rel) {
			$this->assertFileExists($this->root . $rel, $rel);
		}
	}

	public function testNavigationUsesCollapsibleRegisterAndSettingsUnderpages(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		foreach (['nav-menu', 'nav-item-has-children', 'nav-submenu', 'mn-register-subnav', 'sidebar-header', 'Open settings', 'mn-settings-subnav'] as $token) {
			$this->assertStringContainsString($token, $nav, $token);
		}
		// Bachus: Settings is nested under itself (Access…Support), never Administration.
		$this->assertStringNotContainsString('mn-admin-subnav', $nav);
		$this->assertStringContainsString("t('Access')", $nav);
		$this->assertStringContainsString("t('License & mobile')", $nav);
	}

	public function testPageChromeHasBreadcrumbIconScopeStrip(): void
	{
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		foreach (['mn-breadcrumb', 'mn-page-header__icon', 'mn-scope-strip', 'mn-live-region', 'mn-alert-region'] as $token) {
			$this->assertStringContainsString($token, $start, $token);
		}
	}

	public function testAppLayoutUsesAzcFlexShell(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		$this->assertStringContainsString('#content.app-maintenancecheck', $layout);
		$this->assertMatchesRegularExpression('/flex:\s*0 0 280px/', $layout);
	}

	public function testNoPillRadiiRemainInAppCss(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringNotContainsString('border-radius-pill', $css);
		$this->assertStringContainsString('AZ-PARITY-RADIUS-ENFORCER', $css);
		$this->assertFileExists($this->root . '/css/common/notification-surfaces.css');
	}

	public function testVisitsPageUsesFlatLiveToolbar(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/visits.php');
		$this->assertStringContainsString('mn-visits-toolbar', $tpl);
		$this->assertStringContainsString('mn-filter-status-chips', $tpl);
		$this->assertStringContainsString('mn-filter-when-chips', $tpl);
		$this->assertStringContainsString('mn-card--table-solo', $tpl);
		$this->assertStringContainsString('mn-sr-only', $tpl);
		$this->assertStringNotContainsString('mn-filter-panel', $tpl);
		$this->assertStringNotContainsString('mn-card__header', $tpl);
		$this->assertStringNotContainsString('mn-card__lead', $tpl);
		$this->assertStringNotContainsString('Apply filters', $tpl);
	}

	public function testListAndBoardTablesAreTableSolo(): void
	{
		foreach ([
			'templates/customers.php',
			'templates/equipment.php',
			'templates/work-orders.php',
			'templates/dispatch.php',
			'templates/tours.php',
			'templates/exceptions.php',
			'templates/catalogs.php',
			'templates/kpi.php',
			'templates/customer-detail.php',
			'templates/equipment-detail.php',
		] as $rel) {
			$tpl = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringContainsString('mn-card--table-solo', $tpl, $rel);
			$this->assertStringNotContainsString('mn-card__header', $tpl, $rel);
			$this->assertStringNotContainsString('mn-card__lead', $tpl, $rel);
		}
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-table-toolbar', $css);
		$this->assertStringContainsString('.mn-exceptions-toolbar', $css);
	}

	/**
	 * Headed table cards keep CustomerCheck inset; table-solo is a single frame
	 * (no box-in-a-box — DESIGN-SYSTEM principle 14 / §3.3 / §3.7).
	 */
	public function testStructuredCardsMatchFilterPanelChromeSpecificity(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertMatchesRegularExpression(
			'/#content\.app-maintenancecheck\s+#app-content\s+\.mn-card:has\(\.mn-card__header\)[\s\S]*?padding:\s*0/s',
			$chrome,
			'Structured list cards need padding:0 at #content specificity (Filter parity)'
		);
		$this->assertMatchesRegularExpression(
			'/#content\.app-maintenancecheck\s+#app-content\s+\.mn-card__header\s*\{[^}]*background:\s*var\(--mn-bg-soft/s',
			$chrome,
			'List card headers must share Filter soft-band chrome'
		);
		$this->assertMatchesRegularExpression(
			'/#content\.app-maintenancecheck\s+#app-content\s+\.mn-card__body--table\s*\{[^}]*padding:\s*var\(--mn-space-5/s',
			$chrome,
			'Default/headed table bodies keep space-5 inset'
		);
	}

	public function testTableSoloIsSingleFrameNotBoxInBox(): void
	{
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-card\.mn-card--table-solo\s*>\s*\.mn-card__body--table[\s\S]*?padding:\s*0/s',
			$app,
			'table-solo body must be flush (card is the frame)'
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-card\.mn-card--table-solo\s+\.mn-table-wrap[\s\S]*?border:\s*none/s',
			$app,
			'table-solo must strip wrap border to avoid box-in-a-box'
		);
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertMatchesRegularExpression(
			'/#content\.app-maintenancecheck\s+#app-content\s+\.mn-table-wrap\s*\{[^}]*border:\s*1px/s',
			$chrome
		);
	}

	public function testFilterPanelFormsStretchLtrNeverLegacyFilterbar(): void
	{
		$patterns = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-filter-panel__form\s*\{[^}]*align-items:\s*stretch/s',
			$patterns,
			'Filter forms must stretch LTR (never flex-end right-shove)'
		);
		$this->assertStringContainsString('mn-filter-panel__form.mn-filterbar', $patterns);

		foreach ($this->filterListTemplates() as $rel) {
			$tpl = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringNotContainsString(
				'mn-filter-panel__form mn-filterbar',
				$tpl,
				$rel . ' must not combine filter-panel form with legacy mn-filterbar'
			);
			$this->assertStringContainsString('mn-filter-panel__form', $tpl, $rel);
		}

		$customers = (string)file_get_contents($this->root . '/templates/customers.php');
		$equipment = (string)file_get_contents($this->root . '/templates/equipment.php');
		$this->assertStringContainsString('mn-filter-grid--search', $customers);
		$this->assertStringContainsString('mn-filter-grid--search', $equipment);
	}

	/**
	 * @return list<string>
	 */
	private function filterListTemplates(): array
	{
		return [
			'templates/customers.php',
			'templates/equipment.php',
			'templates/work-orders.php',
		];
	}

	public function testAllListFiltersUseFilterPanelHeadChrome(): void
	{
		foreach ($this->filterListTemplates() as $rel) {
			$tpl = (string)file_get_contents($this->root . '/' . $rel);
			preg_match_all('/<section[^>]*\bmn-filter-panel\b[^>]*>[\s\S]*?<\/section>/', $tpl, $blocks);
			$this->assertNotEmpty($blocks[0], $rel . ' missing filter panel');
			foreach ($blocks[0] as $i => $block) {
				$label = $rel . ' panel#' . $i;
				$this->assertStringContainsString('mn-filter-panel__head', $block, $label);
				$this->assertStringContainsString('mn-filter-panel__intro', $block, $label);
				$this->assertStringContainsString('mn-filter-panel__body', $block, $label);
				$this->assertStringNotContainsString('mn-card__header', $block, $label);
				$this->assertStringNotContainsString('mn-card__title', $block, $label);
				$this->assertStringNotContainsString('mn-card__lead', $block, $label);
			}
		}
	}

	public function testToastUsesAzcSemanticClasses(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString("toast--' + azcKind", $js);
		$this->assertStringContainsString('modal-backdrop', $js);
		$this->assertMatchesRegularExpression("/azcKind === 'error'|kind === 'error'/", $js);
	}


	public function testNavigationActiveIsSolidPrimaryPillLikeAzc(): void
	{
		$css = (string)file_get_contents($this->root . '/css/navigation.css');
		$this->assertStringContainsString('solid primary pill', $css);
		$this->assertStringContainsString('color-primary-element-text', $css);
		$this->assertStringContainsString('background-color: var(--color-primary-element)', $css);
		// Legacy inset bar (pre-AZ sync) must not define active rows.
		$this->assertDoesNotMatchRegularExpression(
			'/li\.active > a[^{]*\{[^}]*inset 4px 0 0/s',
			$css,
			'Active nav must be solid primary fill, not inset bar'
		);
		$this->assertStringContainsString('nav-item-inset', $css);
	}

	public function testLayoutCssPinsMidDesktopNavWidthLikeAzc(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		$this->assertStringContainsString('max-width: 1280px', $layout);
		$this->assertMatchesRegularExpression('/flex:\s*0 0 240px/', $layout);
	}

	public function testShellHasNoArtificalContentMaxWidth(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('max-width: none', $chrome);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertDoesNotMatchRegularExpression(
			'/#app-content-wrapper\.[a-z]+-shell[^{]*\{[^}]*max-width:\s*12[08]0px/s',
			$app
		);
	}


	public function testSkipLinkIsAbsolutelyPositionedOffscreen(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('Skip links (AZ parity) — CRITICAL', $chrome);
		$this->assertMatchesRegularExpression(
			'/\.skip-link[^{]*\{[^}]*position:\s*absolute\s*!important/s',
			$chrome,
			'Nav skip-link MUST be absolute or it becomes a flex column and ruins layout'
		);
		$this->assertMatchesRegularExpression(
			'/\.skip-link[^{]*\{[^}]*left:\s*-9999px/s',
			$chrome
		);
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$this->assertStringContainsString('skip-link', $nav);
		$this->assertStringContainsString('Skip to app navigation', $nav);
	}

	public function testTableChromeMatchesAzcBaseStyles(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('AZ table parity', $chrome);
		$this->assertMatchesRegularExpression(
			'/th[^{]*\{[^}]*font-weight:\s*600/s',
			$chrome
		);
		$this->assertMatchesRegularExpression(
			'/th[^{]*\{[^}]*padding:\s*var\(/s',
			$chrome
		);
		// Wrap must scroll — never clip overflow for sticky headers / mobile.
		$this->assertDoesNotMatchRegularExpression(
			'/\.mn-table-wrap[^{]*\{[^}]*overflow:\s*hidden/s',
			$chrome,
			'mn-table-wrap must use overflow-x:auto, not overflow:hidden'
		);
	}

	public function testListTemplatesUseCardBodyTableChrome(): void
	{
		foreach (['/templates/customers.php', '/templates/equipment.php', '/templates/visits.php', '/templates/work-orders.php'] as $rel) {
			$tpl = (string)file_get_contents($this->root . $rel);
			$this->assertStringContainsString('mn-card__body--table', $tpl, $rel);
		}
	}

	public function testEveryCardHeaderUsesHeaderTextWrapper(): void
	{
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root . '/templates', \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$tpl = (string)file_get_contents($file->getPathname());
			if (!str_contains($tpl, 'mn-card__header')) {
				continue;
			}
			preg_match_all('/<header class="mn-card__header[^"]*">([\s\S]*?)<\/header>/', $tpl, $blocks);
			foreach ($blocks[1] as $i => $inner) {
				$label = $file->getFilename() . ' header#' . $i;
				$this->assertStringContainsString('mn-card__header-text', $inner, $label);
			}
		}
	}

	public function testAppJsShipsTableOrCardsHelper(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('function tableOrCards(', $js);
		$this->assertStringContainsString('mn-table table table--hover mn-table--responsive', $js);
		$this->assertStringContainsString('tableOrCards: tableOrCards', $js);
		$this->assertStringContainsString('var loadSeq = 0', $js);
		$this->assertStringContainsString('function announceResults(', $js);
		$wo = (string)file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringContainsString('tableOrCards', $wo);
		$this->assertStringContainsString('mn-dispatch-job', $wo);
		$this->assertStringContainsString('var loadSeq = 0', $wo);
	}

	public function testCatalogsUseCardChromeNotBareSections(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/catalogs.php');
		$this->assertStringContainsString('mn-card', $tpl);
		$this->assertStringContainsString('mn-card--table-solo', $tpl);
		$this->assertStringContainsString('mn-table-toolbar', $tpl);
		$this->assertStringContainsString('mn-card__body--table', $tpl);
		$this->assertStringContainsString('mn-catalogs', $tpl);
		$this->assertStringContainsString('mn-catalogs-toolbar', $tpl);
		$this->assertStringContainsString('data-mn-catalog=', $tpl);
		$this->assertStringContainsString('mn-catalog-panel', $tpl);
		$this->assertStringNotContainsString('mn-catalogs__pair', $tpl);
		$this->assertStringNotContainsString('mn-section', $tpl);
		$this->assertStringNotContainsString('class="mn-columns"', $tpl);
		$this->assertStringNotContainsString('mn-card__header', $tpl);
		$this->assertStringNotContainsString('mn-card__lead', $tpl);
	}

	public function testDetailAndSettingsUseCardChromeWithoutDuplicateBreadcrumbs(): void
	{
		foreach (['customer-detail.php', 'equipment-detail.php'] as $file) {
			$tpl = (string)file_get_contents($this->root . '/templates/' . $file);
			$this->assertStringContainsString('mn-card', $tpl, $file);
			$this->assertStringContainsString('mn-card--table-solo', $tpl, $file);
			$this->assertStringNotContainsString('mn-section', $tpl, $file);
			$this->assertStringNotContainsString('<nav class="mn-breadcrumb"', $tpl, $file . ' must not duplicate shell breadcrumb');
		}
		$section = (string)file_get_contents($this->root . '/templates/settings-section.php');
		$this->assertStringContainsString('mn-settings', $section);
		$this->assertStringContainsString('parts/settings-subnav.php', $section);
		$this->assertStringNotContainsString('<nav class="mn-breadcrumb"', $section);
		$this->assertFileDoesNotExist($this->root . '/templates/settings.php');
		$access = (string)file_get_contents($this->root . '/templates/settings/access.php');
		$this->assertStringContainsString('mn-card', $access);
		$wo = (string)file_get_contents($this->root . '/templates/work-order-detail.php');
		$this->assertStringNotContainsString('<nav class="mn-breadcrumb"', $wo);
		$shell = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString("pageId === 'customer-detail'", $shell);
		$this->assertStringContainsString("pageId === 'equipment-detail'", $shell);
		$this->assertStringContainsString("pageId === 'work-order-detail'", $shell);
		$this->assertStringContainsString("str_starts_with(\$pageId, 'settings-')", $shell);
		$this->assertStringContainsString('mn-back-link', $shell);
		$this->assertStringContainsString('mn-back-settings', $shell);
	}

	public function testRequiredFieldsUseAriaRequired(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString("input.setAttribute('aria-required', 'true')", $js);
		$this->assertStringContainsString("input.setAttribute('required', '')", $js);
		$this->assertStringContainsString('{label} (required)', $js);
		$this->assertStringNotContainsString('form-label--required mn-field__label--required', $js);
	}

	public function testDueBoardGuardsStaleLoads(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertMatchesRegularExpression(
			'/function pageDue\(\)[\s\S]*?var loadSeq = 0;[\s\S]*?if \(seq !== loadSeq\)/',
			$js
		);
		$this->assertStringContainsString('function dueBucketTable(', $js);
		$this->assertStringNotContainsString('function visitCard(', $js);
		$this->assertStringNotContainsString('mn-visit-card', $js);
		$tpl = (string)file_get_contents($this->root . '/templates/due.php');
		$this->assertStringContainsString('mn-card__body--table', $tpl);
		$this->assertStringContainsString('data-bucket-list="overdue"', $tpl);
	}

	public function testOpsBoardsUseTableChrome(): void
	{
		$wo = (string)file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringContainsString("caption: tr('Exceptions')", $wo);
		$this->assertStringContainsString("caption: tr('Open work orders by status')", $wo);
		$this->assertStringContainsString("caption: tr('Stops')", $wo);
		$this->assertStringNotContainsString('mn-tour-stops', $wo);
		$this->assertDoesNotMatchRegularExpression("/class:\\s*'mn-list'/", $wo);
		foreach (['exceptions.php', 'tours.php', 'dispatch.php'] as $file) {
			$tpl = (string)file_get_contents($this->root . '/templates/' . $file);
			$this->assertStringContainsString('mn-card__body--table', $tpl, $file);
		}
	}

	public function testBadgesDefineNeutralAndUseWeight600(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-badge--neutral', $css);
		$this->assertMatchesRegularExpression(
			'/\.mn-badge\s*\{[^}]*font-weight:\s*600/s',
			$css
		);
		$this->assertStringContainsString('.mn-badge:not(:has(.mn-badge__dot)):not(:has(.mn-badge__icon))::before', $css);
	}


	public function testFormControlsCssIsImportedAndScoped(): void
	{
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('form-controls.css', $appCss);
		$path = $this->root . '/css/common/form-controls.css';
		$this->assertFileExists($path);
		$css = (string)file_get_contents($path);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('accent-color: var(--color-primary-element)', $css);
		$this->assertStringContainsString('input[type="checkbox"]', $css);
		$this->assertStringContainsString('select', $css);
		$this->assertStringContainsString('box-shadow: 0 0 0 3px', $css);
		$this->assertStringContainsString('[role="combobox"]', $css);
		// Must stay scoped — never style NC global chrome
		$this->assertStringContainsString('#content.app-', $css);
		$this->assertDoesNotMatchRegularExpression('/^input\\s*,/m', $css);
	}


	public function testContentSurfacesMatchAzcCardsAndButtons(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('AZ content surfaces', $chrome);
		$this->assertStringContainsString('-section', $chrome);
		$this->assertStringContainsString('-filter-panel', $chrome);
		$this->assertMatchesRegularExpression('/border-radius:\s*var\(--[a-z]+-radius-md,\s*12px\)/', $chrome);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('AZ-PARITY-RADIUS-ENFORCER', $app);
		// Primary buttons must use md (12px) like azc-btn — not sm.
		$this->assertMatchesRegularExpression(
			'/\.button[^{]*\{[^}]*border-radius:\s*var\(--[a-z]+-radius-md,\s*12px\)\s*!important/s',
			$app
		);
		// Body-mounted dialogs must use lg (16px) — #content-scoped rules never apply.
		$this->assertMatchesRegularExpression(
			'/body\s*>\s*\.modal-backdrop\s*>\s*\.modal[^{]*\{[^}]*border-radius:\s*var\(--[a-z]+-radius-lg,\s*16px\)\s*!important/s',
			$app,
			'Dialogs mount on body; radius enforcer must cover modal-backdrop > .modal'
		);
	}

}
