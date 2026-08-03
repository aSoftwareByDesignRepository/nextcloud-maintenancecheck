<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * WCAG 1.4.3 gate: NC 30+ maps --color-error to a pale tint (#FFE7E7).
 * Solid danger buttons must use --color-element-error / --mn-danger-fill.
 * White-on-tint is the Cancel-visit illegible-pink failure mode.
 */
final class DesignSystemDangerContrastContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testTokensDefineDangerFillInkAndOnFill(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/tokens.css');
		foreach ([
			'--mn-danger-fill:',
			'--mn-danger-fill-hover:',
			'--mn-danger-on-fill:',
			'--mn-danger-ink:',
			'--mn-danger-border:',
		] as $needle) {
			$this->assertStringContainsString($needle, $css, $needle);
		}
		$this->assertMatchesRegularExpression(
			'/--mn-danger-fill:\s*var\(\s*--color-element-error\b/',
			$css,
			'--mn-danger-fill must resolve to solid --color-element-error, never the pale --color-error tint',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/--mn-danger-fill:\s*var\(\s*--color-error\b(?!-)/',
			$css,
		);
		$this->assertStringContainsString('Never put white', $css);
	}

	/**
	 * @return list<string>
	 */
	private function cssFiles(): array
	{
		return [
			'/css/app.css',
			'/css/common/shell-chrome.css',
			'/css/common/page-patterns.css',
			'/css/common/form-controls.css',
			'/css/common/dialogs.css',
			'/css/common/notification-surfaces.css',
			'/css/common/legacy-bridge.css',
		];
	}

	public function testDangerButtonRulesNeverUseColorErrorAsSolidFill(): void
	{
		$offenders = [];
		foreach ($this->cssFiles() as $rel) {
			$css = (string)file_get_contents($this->root . $rel);
			// Match .mn-btn--danger / .btn--danger rule blocks
			if (!preg_match_all(
				'/\.[\w-]*btn--danger[^{]*\{([^}]*)\}/s',
				$css,
				$blocks,
				PREG_SET_ORDER,
			)) {
				continue;
			}
			foreach ($blocks as $block) {
				$body = $block[1];
				if (preg_match('/background(?:-color)?\s*:\s*[^;]*var\(\s*--color-error\b/', $body)
					&& !preg_match('/background(?:-color)?\s*:\s*[^;]*var\(\s*--(?:mn-danger-fill|color-element-error)\b/', $body)) {
					$offenders[] = $rel . ': ' . trim(preg_replace('/\s+/', ' ', $block[0]));
				}
				if (preg_match('/background(?:-color)?\s*:\s*[^;]*var\(\s*--color-error-hover\b/', $body)
					&& !preg_match('/var\(\s*--mn-danger-fill-hover\b/', $body)) {
					$offenders[] = $rel . ' hover: ' . trim(preg_replace('/\s+/', ' ', $block[0]));
				}
			}
		}
		$this->assertSame([], $offenders, "Danger buttons must use --mn-danger-fill / --color-element-error, never --color-error tint:\n" . implode("\n", $offenders));
	}

	public function testDangerButtonsDeclareOnFillTextToken(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$app = (string)file_get_contents($this->root . '/css/app.css');
		foreach ([$chrome, $app] as $css) {
			$this->assertMatchesRegularExpression(
				'/\.mn-btn--danger[^{]*\{[^}]*color:\s*var\(--mn-danger-on-fill/s',
				$css,
			);
			$this->assertMatchesRegularExpression(
				'/\.mn-btn--danger[^{]*\{[^}]*background:\s*var\(--mn-danger-fill/s',
				$css,
			);
		}
	}

	public function testSemanticInkDoesNotUseBareColorErrorAsTextColor(): void
	{
		$offenders = [];
		foreach (['/css/app.css', '/css/common/page-patterns.css'] as $rel) {
			$css = (string)file_get_contents($this->root . $rel);
			$lines = preg_split('/\R/', $css) ?: [];
			foreach ($lines as $i => $line) {
				if (!preg_match('/^\s*color\s*:/', $line)) {
					continue;
				}
				// Bare --color-error as the ink (tint pink on white) — forbid.
				if (preg_match('/color:\s*var\(\s*--color-error\s*[,)]/', $line)
					&& !str_contains($line, '--color-error-text')
					&& !str_contains($line, '--mn-danger-ink')
					&& !str_contains($line, '--color-element-error')) {
					$offenders[] = $rel . ':L' . ($i + 1) . ': ' . trim($line);
				}
			}
		}
		$this->assertSame([], $offenders, "Text ink must use --mn-danger-ink / --color-error-text, not pale --color-error:\n" . implode("\n", $offenders));
	}

	public function testDesignSystemDocumentsNcElementErrorSplit(): void
	{
		$tokens = (string)file_get_contents($this->root . '/css/common/tokens.css');
		$this->assertStringContainsString('--color-element-error', $tokens);
		$this->assertStringContainsString('Never put white', $tokens);
		$this->assertStringContainsString('pale', strtolower($tokens));
	}

	public function testDarkThemesDarkenDangerFillForWhiteOnFillText(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/tokens.css');
		$this->assertMatchesRegularExpression(
			'/body\[data-theme-dark\][\s\S]{0,80}body\[data-theme-dark-highcontrast\]\s*\{[^}]*--mn-danger-fill:\s*color-mix\([^;]*--color-main-background\)/s',
			$css,
			'Dark themes must mix --mn-danger-fill toward main-background for white on-fill AA',
		);
	}
}
