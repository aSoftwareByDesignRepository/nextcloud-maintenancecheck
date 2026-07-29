<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Design-system checklist gates for MaintenanceCheck feature CSS.
 * @see planning/design-system/checklist.md
 */
final class DesignSystemTokensContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testTokensDefinePrintSurfaceAndSpacingScale(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/tokens.css');
		foreach ([
			'--mn-space-1:',
			'--mn-space-8:',
			'--mn-radius-sm:',
			'--mn-fs-2xl:',
			'--mn-print-surface:',
			'--mn-signature-ink:',
			'--mn-scrim:',
			'--mn-shadow-lg:',
			'--mn-signature-surface:',
			'--mn-overlay-top:',
		] as $token) {
			$this->assertStringContainsString($token, $css, $token);
		}
	}

	public function testFeatureCssHasNoBareHexFills(): void
	{
		$files = [
			'/css/app.css',
			'/css/common/shell-chrome.css',
			'/css/common/page-patterns.css',
			'/css/common/form-controls.css',
			'/css/common/dialogs.css',
			'/css/common/notification-surfaces.css',
			'/css/common/app-layout.css',
			'/css/navigation.css',
		];
		$offenders = [];
		foreach ($files as $rel) {
			$css = (string)file_get_contents($this->root . $rel);
			$lines = preg_split('/\R/', $css) ?: [];
			foreach ($lines as $i => $line) {
				if (str_contains($line, 'var(')) {
					continue;
				}
				// Intentional print/signature ink tokens live in tokens.css only
				if (preg_match('/(?:background|color|border(?:-color)?|fill|stroke)\s*:\s*#[0-9a-fA-F]{3,8}\b/', $line)) {
					$offenders[] = $rel . ':L' . ($i + 1) . ': ' . trim($line);
				}
			}
		}
		$this->assertSame([], $offenders, "Bare hex fills violate design-system theme-first rule:\n" . implode("\n", $offenders));
	}

	public function testFeatureCssHasNoRawBlackRgbaOverlays(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertDoesNotMatchRegularExpression(
			'/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,/',
			$css,
			'Overlays/shadows must use --mn-scrim / --mn-shadow-* tokens, not raw black rgba'
		);
	}

	public function testSignatureCanvasUsesThemeTokensNotInlineJsHex(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-signature-canvas\s*\{[^}]*background:\s*var\(--mn-signature-surface/s',
			$css
		);
		$js = (string)file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringNotContainsString("background = '#fff'", $js);
		$this->assertStringNotContainsString("border = '1px solid var(--color-border, #888)'", $js);
		$this->assertStringNotContainsString("strokeStyle = '#111'", $js);
		$this->assertStringContainsString('--mn-signature-ink', $js);
		$this->assertStringContainsString('mn-signature-canvas', $js);
	}

	public function testFeatureCssAvoidsOffGridSpacingLiterals(): void
	{
		$files = [
			'/css/app.css',
			'/css/common/shell-chrome.css',
			'/css/common/page-patterns.css',
			'/css/common/notification-surfaces.css',
		];
		$offenders = [];
		foreach ($files as $rel) {
			$css = (string)file_get_contents($this->root . $rel);
			$lines = preg_split('/\R/', $css) ?: [];
			foreach ($lines as $i => $line) {
				if (!preg_match('/(?:margin|padding|gap|row-gap|column-gap)\s*:/', $line)) {
					continue;
				}
				if (preg_match('/\b(?:6|10|14|18|20)px\b/', $line)) {
					$offenders[] = $rel . ':L' . ($i + 1) . ': ' . trim($line);
				}
			}
		}
		$this->assertSame([], $offenders, "Off-grid spacing (6/10/14/18/20px) must use --mn-space-* tokens:\n" . implode("\n", $offenders));
	}

	public function testToastRegionUsesOverlayToken(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-toast-region\s*\{[^}]*top:\s*calc\(\s*var\(--mn-overlay-top/s',
			$css
		);
	}

	public function testPageHeaderGeometryOwnedByShellChrome(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-page-header\s*\{[^}]*flex-direction:\s*column/s',
			$chrome
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-page-header__main\s*\{[^}]*grid-template-columns:\s*56px minmax\(0,\s*1fr\) auto/s',
			$chrome
		);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		// Later app.css must not undo the column stack with space-between flex wrap
		$this->assertDoesNotMatchRegularExpression(
			'/\.mn-page-header\s*\{[^}]*justify-content:\s*space-between/s',
			$app
		);
	}

	public function testSmallButtonsMeetTouchTarget(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertDoesNotMatchRegularExpression(
			'/\.mn-btn--sm[^{]*\{[^}]*min-height:\s*36px/s',
			$chrome
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-btn--sm\s*\{[^}]*min-height:\s*44px/s',
			$chrome
		);
	}

	public function testQrStickerUsesPrintSurfaceToken(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-qr-sticker\s+svg[^{]*\{[^}]*background:\s*var\(--mn-print-surface/s',
			$css
		);
	}

	public function testVisitActionsDefaultToOverflowMenu(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('options.overflow === false', $js);
		$this->assertStringContainsString('granny/toddler', $js);
		$this->assertStringContainsString('MutationObserver', $js);
		$this->assertDoesNotMatchRegularExpression('/addEventListener\(\s*[\'"]DOMNodeRemoved[\'"]/', $js);
	}

	public function testDetailGridNeutralisesNextcloudCoreDtChrome(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-detail__grid\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
			'Detail grids must force text-align: start against core dt end-align',
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-detail__grid\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$css,
		);
	}
}
