<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Support\CoreWebUpgradeBypassPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Contract for the NC 34 web-upgrade acknowledgement bypass used when
 * upgrade.disable-web=true (docker/patches/apply-web-upgrade-bypass.*).
 */
final class CoreWebUpgradeBypassContractTest extends TestCase
{
	public function testCliWarningShownWhenDisableWebAndNoBypass(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, false, null));
	}

	public function testCliWarningSkippedWhenDisableWebAndBypassPresent(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, false, CoreWebUpgradeBypassPolicy::TOKEN));
	}

	public function testCliWarningShownWhenTooBigAndNoBypass(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, null));
	}

	public function testCliWarningSkippedWhenTooBigAndBypassPresent(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, CoreWebUpgradeBypassPolicy::TOKEN));
	}

	public function testCliWarningShownWhenBothNoBypass(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, true, null));
	}

	public function testCliWarningSkippedWhenBothWithBypass(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, true, CoreWebUpgradeBypassPolicy::TOKEN));
	}

	public function testCliWarningNotShownForNormalOperation(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, false, null));
	}

	public function testBypassWithWrongTokenDoesNotSkipWarning(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, false, 'hack'));
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, 'wrong'));
	}

	public function testEmptyTokenDoesNotBypass(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(true, false, ''));
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, ''));
	}

	public function testBypassLinkShownWhenTooBig(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowBypassLink(true, false));
	}

	public function testBypassLinkShownWhenDisableWeb(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowBypassLink(false, true));
	}

	public function testBypassLinkShownWhenBoth(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowBypassLink(true, true));
	}

	public function testBypassLinkHiddenForNormalOperation(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowBypassLink(false, false));
	}

	public function testUpdateEndpointBlocksWhenDisableWebAndNoBypass(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldBlockUpdateEndpoint(true, null));
	}

	public function testUpdateEndpointAllowsWhenDisableWebAndBypassPresent(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldBlockUpdateEndpoint(true, CoreWebUpgradeBypassPolicy::TOKEN));
	}

	public function testUpdateEndpointAllowsWhenDisableWebIsFalse(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldBlockUpdateEndpoint(false, null));
	}

	public function testUpdateEndpointBlocksWhenDisableWebAndWrongToken(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldBlockUpdateEndpoint(true, 'hack'));
	}

	public function testJsForwardsBypassParamToOcsUpdate(): void {
		$url = CoreWebUpgradeBypassPolicy::updateEndpointUrl(
			CoreWebUpgradeBypassPolicy::QUERY_KEY . '=' . CoreWebUpgradeBypassPolicy::TOKEN
		);
		$this->assertSame(CoreWebUpgradeBypassPolicy::UPDATE_ENDPOINT, strtok($url, '?'));
		$this->assertStringContainsString(
			CoreWebUpgradeBypassPolicy::QUERY_KEY . '=' . rawurlencode(CoreWebUpgradeBypassPolicy::TOKEN),
			$url
		);
	}

	public function testJsDoesNotAddParamWhenNotPresent(): void {
		$url = CoreWebUpgradeBypassPolicy::updateEndpointUrl('');
		$this->assertSame(CoreWebUpgradeBypassPolicy::UPDATE_ENDPOINT, $url);
		$this->assertStringNotContainsString(CoreWebUpgradeBypassPolicy::QUERY_KEY, $url);
	}

	public function testJsHandlesMultiplePageParamsCorrectly(): void {
		$q = 'keep=me&' . CoreWebUpgradeBypassPolicy::QUERY_KEY . '=' . CoreWebUpgradeBypassPolicy::TOKEN . '&also=this';
		$url = CoreWebUpgradeBypassPolicy::updateEndpointUrl($q);
		$this->assertStringContainsString(
			CoreWebUpgradeBypassPolicy::QUERY_KEY . '=' . rawurlencode(CoreWebUpgradeBypassPolicy::TOKEN),
			$url
		);
		$this->assertEquals(1, substr_count($url, '?'));
	}

	public function testJsSkipsParamWhenTokenDoesNotMatch(): void {
		$q = CoreWebUpgradeBypassPolicy::QUERY_KEY . '=someRandomValue';
		$url = CoreWebUpgradeBypassPolicy::updateEndpointUrl($q);
		$this->assertSame(CoreWebUpgradeBypassPolicy::UPDATE_ENDPOINT, $url);
	}

	public function testTokenConstantExactMatchRequired(): void {
		$this->assertTrue(CoreWebUpgradeBypassPolicy::isValidBypassToken(CoreWebUpgradeBypassPolicy::TOKEN));
		$this->assertFalse(CoreWebUpgradeBypassPolicy::isValidBypassToken(strtolower(CoreWebUpgradeBypassPolicy::TOKEN)));
		$this->assertFalse(CoreWebUpgradeBypassPolicy::isValidBypassToken(CoreWebUpgradeBypassPolicy::TOKEN . ' '));
	}

	public function testOriginalTooBigBypassBehaviorPreserved(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, CoreWebUpgradeBypassPolicy::TOKEN));
		$this->assertTrue(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, true, null));
	}

	public function testNormalOperationUnchanged(): void {
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowCliWarning(false, false, null));
		$this->assertFalse(CoreWebUpgradeBypassPolicy::shouldShowBypassLink(false, false));
	}

	public function testPatchApplicatorFixtureDocumentsNc34Needles(): void {
		$fixtureDir = dirname(__DIR__) . '/fixtures/web-upgrade-bypass';
		$script = $fixtureDir . '/apply-web-upgrade-bypass.php';
		$wrapper = $fixtureDir . '/apply-web-upgrade-bypass.sh';
		$this->assertFileExists($script);
		$this->assertFileExists($wrapper);
		$php = (string)file_get_contents($script);
		$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::QUERY_KEY, $php);
		$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::TOKEN, $php);
		$this->assertStringContainsString('SBD_WEB_UPGRADE_BYPASS', $php);
		$this->assertStringContainsString('/core/update', $php);
		$this->assertStringContainsString('ignoreTooBigWarning', $php);
		$this->assertStringContainsString('upgrade.disable-web', $php);
		$sh = (string)file_get_contents($wrapper);
		$this->assertStringContainsString('apply-web-upgrade-bypass.php', $sh);
		$this->assertStringContainsString('opcache_reset', $sh);

		// When the monorepo docker/ tree is visible (host PHPUnit), fixtures must match SSOT.
		$hostScript = dirname(__DIR__, 4) . '/docker/patches/apply-web-upgrade-bypass.php';
		if (is_file($hostScript)) {
			$this->assertSame(
				hash_file('sha256', $hostScript),
				hash_file('sha256', $script),
				'docker/patches applicator drifted from tests/fixtures mirror — re-copy both files'
			);
		}
	}
}
