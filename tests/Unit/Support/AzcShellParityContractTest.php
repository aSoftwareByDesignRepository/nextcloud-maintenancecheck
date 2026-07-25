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

	public function testNavigationUsesCollapsibleRegisterAndAdmin(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		foreach (['nav-menu', 'nav-item-has-children', 'nav-submenu', 'mn-register-subnav', 'mn-admin-subnav', 'sidebar-header'] as $token) {
			$this->assertStringContainsString($token, $nav, $token);
		}
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

	public function testVisitsPageUsesFilterPanel(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/visits.php');
		$this->assertStringContainsString('mn-filter-panel', $tpl);
		$this->assertStringContainsString('mn-filter-grid', $tpl);
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
