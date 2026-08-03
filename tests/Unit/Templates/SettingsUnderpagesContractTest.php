<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Templates;

use OCA\MaintenanceCheck\Support\SettingsSections;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Settings underpages: no hub overview, full-width sections, subnav + JS hosts.
 */
final class SettingsUnderpagesContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testCatalogIdsMatchRouteRequirementAndPartials(): void
	{
		$ids = SettingsSections::ids();
		$this->assertSame(
			['access', 'roles', 'inventory', 'policies', 'capacity', 'license', 'support'],
			$ids
		);
		$this->assertSame('access', SettingsSections::DEFAULT);
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString("page#settingsSection", $routes);
		$this->assertStringContainsString("page#settings", $routes);
		$hubPos = strpos($routes, "page#settings'");
		$sectionPos = strpos($routes, "page#settingsSection");
		$this->assertNotFalse($hubPos);
		$this->assertNotFalse($sectionPos);
		$this->assertLessThan($sectionPos, $hubPos, '/settings redirect route must register before /settings/{section}');
		$this->assertStringContainsString("[a-z0-9-]+", $routes);
		$this->assertFileDoesNotExist($this->root . '/templates/settings.php');

		foreach ($ids as $id) {
			$partial = $this->root . '/templates/settings/' . $id . '.php';
			$this->assertFileExists($partial, 'missing settings partial for ' . $id);
		}
		$this->assertSame(SettingsSections::routeRequirement(), implode('|', $ids));
	}

	public function testCatalogIsValidRejectsUnknown(): void
	{
		$this->assertTrue(SettingsSections::isValid('access'));
		$this->assertTrue(SettingsSections::isValid('support'));
		$this->assertFalse(SettingsSections::isValid(''));
		$this->assertFalse(SettingsSections::isValid('numbering'));
		$this->assertFalse(SettingsSections::isValid('ACCESS'));
		$this->assertFalse(SettingsSections::isValid('../access'));
	}

	public function testSectionTemplateUsesSharedChromeWithoutHub(): void
	{
		$section = (string)file_get_contents($this->root . '/templates/settings-section.php');
		$subnav = (string)file_get_contents($this->root . '/templates/parts/settings-subnav.php');
		$this->assertStringContainsString('parts/settings-subnav.php', $section);
		$this->assertStringContainsString("/settings/' . \$sectionId . '.php", $section);
		$this->assertStringContainsString('mn-settings-subnav__link', $subnav);
		$this->assertStringNotContainsString("t('Overview')", $subnav);
		$this->assertStringNotContainsString('mn-settings-hub', $subnav);
		$this->assertStringContainsString('aria-current="page"', $subnav);
		$this->assertStringContainsString('min-height: 44px', (string)file_get_contents($this->root . '/css/common/page-patterns.css'));
	}

	public function testJsHostsExistOnFormSections(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$hostMap = [
			'access' => 'mn-settings-access',
			'roles' => 'mn-settings-roles',
			'inventory' => 'mn-settings-inventory-flange',
			'policies' => 'mn-settings-policies',
			'capacity' => 'mn-settings-capacity',
			'license' => 'mn-settings-license',
		];
		foreach (SettingsSections::all($l) as $meta) {
			$id = $meta['id'];
			$html = (string)file_get_contents($this->root . '/templates/settings/' . $id . '.php');
			if (!empty($meta['hasJsHost'])) {
				$this->assertStringContainsString('mn-card', $html, $id);
				$this->assertArrayHasKey($id, $hostMap);
				$this->assertStringContainsString('id="' . $hostMap[$id] . '"', $html, $id . ' missing JS host');
				$this->assertStringContainsString('aria-busy="true"', $html, $id);
			} else {
				$this->assertStringContainsString('support-us-section.php', $html, 'support must include Support & us');
				$supportUs = (string)file_get_contents($this->root . '/templates/parts/support-us-section.php');
				$this->assertStringContainsString('-card', $supportUs);
			}
		}
	}

	public function testNavigationNestsSettingsWithoutOverviewOrAdminWrapper(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$this->assertStringContainsString('id="mn-settings-subnav"', $nav);
		$this->assertStringContainsString("t('Settings')", $nav);
		$this->assertStringNotContainsString("t('Overview')", $nav);
		$this->assertStringNotContainsString('Go to settings overview', $nav);
		$this->assertStringContainsString('settingsSections', $nav);
		$this->assertStringContainsString('Open settings', $nav);
		$this->assertStringNotContainsString('mn-admin-subnav', $nav);
	}

	public function testPagePatternsDefineSubnavAndFullWidthSettings(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		foreach ([
			'.mn-settings-subnav',
			'.mn-settings-subnav__link',
			'min-height: 44px',
			'prefers-reduced-motion',
		] as $token) {
			$this->assertStringContainsString($token, $css);
		}
		$this->assertStringNotContainsString('.mn-settings-hub', $css);
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.mn-settings\s*\{[^}]*max-width:\s*none/s',
			$appCss,
			'settings underpages must use full shell width',
		);
	}

	public function testSettingsJsBindsUnderpagesAndGuardsMissingHosts(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString("page.indexOf('settings-') === 0", $js);
		$this->assertStringContainsString('if (!accessBox)', $js);
		$this->assertStringContainsString('if (!rolesBox)', $js);
		$this->assertStringContainsString('if (!licenseBox)', $js);
		$this->assertStringContainsString('if (!capacityBox)', $js);
		$this->assertStringContainsString('if (!flangeBox)', $js);
		$this->assertStringContainsString('if (!policiesBox)', $js);
		$this->assertStringContainsString("function pageSettings()", $js);
	}

	public function testBreadcrumbUsesSettingsParentOnUnderpages(): void
	{
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString("str_starts_with(\$pageId, 'settings-')", $start);
		$this->assertStringContainsString('mn-back-settings', $start);
		$this->assertStringContainsString("t('Settings')", $start);
	}

	public function testPageControllerRedirectsHubAndInvalidToDefault(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('function settingsSection', $src);
		$this->assertStringContainsString('function settings()', $src);
		$this->assertMatchesRegularExpression('/SettingsSections::isValid\s*\(/', $src);
		$this->assertStringNotContainsString('SettingsSections::isValidXxx', $src);
		$this->assertMatchesRegularExpression('/SettingsSections::DEFAULT\b/', $src);
		$this->assertStringContainsString('linkToRoute', $src);
		$this->assertStringContainsString('RedirectResponse', $src);
		$this->assertStringContainsString('settings-section', $src);
		$this->assertStringContainsString('settingsSectionUrls', $src);
		$this->assertMatchesRegularExpression(
			'/function settings\(\):\s*RedirectResponse/s',
			$src,
			'/settings must redirect — never render a hub template',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/function settings\(\)[^{]*\{[^}]*linkToRouteAbsolute/s',
			$src,
		);
	}

	public function testSupportPartialPointsAtLicenseUnderpage(): void
	{
		$support = (string)file_get_contents($this->root . '/templates/settings/support.php');
		$this->assertStringContainsString("settingsSectionUrls']['license']", $support);
		$this->assertStringNotContainsString('#mn-license-title', $support);
	}
}
