<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * DutyCheck-parity page guidance: every user-facing page ships a dismissible
 * Quick start card, actionable page leads, and nav hint copy.
 */
final class PageGuidanceContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	/** @return list<string> */
	private function guidedTemplates(): array
	{
		return [
			'due.php',
			'customers.php',
			'customer-detail.php',
			'equipment.php',
			'equipment-detail.php',
			'visits.php',
			'catalogs.php',
			'work-orders.php',
			'work-order-detail.php',
			'dispatch.php',
			'tours.php',
			'kpi.php',
			'exceptions.php',
			'settings-section.php',
		];
	}

	public function testQuickstartPartialExists(): void
	{
		$path = $this->root . '/templates/parts/page-quickstart.php';
		$this->assertFileExists($path);
		$src = (string)file_get_contents($path);
		$this->assertStringContainsString('mn-quickstart-card', $src);
		$this->assertStringContainsString('mn-section__header', $src);
		$this->assertStringContainsString('mn-section__sub', $src);
		$this->assertStringContainsString('data-mn-dismiss-hint', $src);
		$this->assertStringContainsString('mn-hint-dismiss', $src);
		$this->assertStringContainsString('mn-quickstart', $src);
		$this->assertStringContainsString('Hide tips', $src);
		$this->assertStringContainsString('Quick start', $src);
		$this->assertStringContainsString('mn-btn mn-btn--secondary', $src);
		$this->assertStringContainsString('mn-quickstart__cta', $src);
		$this->assertStringNotContainsString('mn-card__header', $src);
		$this->assertStringNotContainsString('mn-empty--quickstart', $src);
	}

	public function testEveryPageIncludesQuickstart(): void
	{
		foreach ($this->guidedTemplates() as $file) {
			$src = (string)file_get_contents($this->root . '/templates/' . $file);
			$this->assertStringContainsString(
				'page-quickstart.php',
				$src,
				$file . ' must include the quickstart partial',
			);
			$this->assertStringContainsString(
				'$qsKey',
				$src,
				$file . ' must set a dismissible storage key',
			);
		}
	}

	public function testPageControllerHintsAreActionable(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		foreach ([
			'Tap Complete on overdue and today cards',
			'Add organisations you service',
			'Create new equipment on a customer page',
			'One job sheet — status, checklist, evidence',
			'search and pick, never type an id',
		] as $snippet) {
			$this->assertStringContainsString($snippet, $src, $snippet);
		}
		// Old descriptive-only leads must not remain as the primary hint.
		$this->assertStringNotContainsString(
			"l->t('Everything that needs a visit — overdue first.')",
			$src,
		);
	}

	public function testNavigationExposesVisibleHints(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$this->assertStringContainsString('mn-nav__hint', $nav);
		$this->assertStringContainsString('mn-nav__label', $nav);
		$this->assertStringContainsString('What needs a visit — overdue first', $nav);
		$this->assertStringContainsString('History and filters', $nav);
	}

	public function testAppJsWiresDismissibleHintsAndMnLinks(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('function wireDismissibleHint', $js);
		$this->assertStringContainsString('data-mn-dismiss-hint', $js);
		$this->assertStringContainsString('mn:hint:', $js);
		$this->assertStringContainsString('wireAllDismissibleHints', $js);
		$this->assertStringContainsString('data-mn-link', $js);
		$this->assertStringContainsString('wireMnLinks', $js);
	}

	public function testQuickstartCssMeetsTouchAndContrastBasics(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-quickstart-card', $css);
		$this->assertStringContainsString('.mn-section__header', $css);
		$this->assertStringContainsString('.mn-section__sub', $css);
		$this->assertMatchesRegularExpression(
			'/\.mn-hint-dismiss\s*\{[^}]*min-height:\s*44px/s',
			$css,
		);
		$this->assertStringContainsString('.mn-quickstart-card[hidden]', $css);
		$this->assertMatchesRegularExpression(
			'/\.mn-quickstart__item\s*\{[^}]*background:\s*var\(--mn-bg-card/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.mn-quickstart__item p\s*\{[^}]*color:\s*var\(--mn-muted/s',
			$css,
		);
		// Design-system: quickstart step CTAs are centered (not left-ragged).
		$this->assertMatchesRegularExpression(
			'/\.mn-quickstart__item\s+\.mn-btn[\s\S]{0,200}?align-self:\s*center/s',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.mn-quickstart__item\s+\.(?:mn-btn|button)[\s\S]{0,120}?align-self:\s*flex-start/s',
			$css,
		);
		// Muted ink on tinted/primary active rows fails AA — hints inherit.
		$this->assertStringContainsString(
			'#app-navigation .nav-menu > li.active > a .mn-nav__hint',
			$css,
		);
	}
}
