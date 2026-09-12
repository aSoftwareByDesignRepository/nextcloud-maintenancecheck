<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shell must ship in-page Menu + mobile-nav assets (Atlas ATLAS_MOBILE_NAV_CONTRACT).
 */
class MobileNavShellContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testPageStartShipsInPageMenuToggle(): void {
		$src = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		self::assertStringContainsString('id="mn-nav-toggle"', $src);
		self::assertStringContainsString('data-mn-nav-toggle', $src);
		self::assertStringContainsString('Open navigation menu', $src);
	}

	public function testNavigationRegistersMobileNavScript(): void {
		$src = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		self::assertMatchesRegularExpression(
			'/^\s*Util::addScript\(\s*\'maintenancecheck\'\s*,\s*\'common\/mobile-nav\'\s*\)\s*;/m',
			$src,
			'mobile-nav script must be registered via active Util::addScript (not commented out)'
		);
	}

	public function testMobileNavAssetsExistAndCssImported(): void {
		self::assertFileExists($this->root . '/js/common/mobile-nav.js');
		self::assertFileExists($this->root . '/css/common/mobile-nav.css');
		$cssImport = (string)file_get_contents($this->root . '/css/app.css');
		self::assertStringContainsString('mobile-nav.css', $cssImport);
	}
}
