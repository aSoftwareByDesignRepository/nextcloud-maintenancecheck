<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Support\CoreWebUpgradeBypassPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Live volume check: NC 34/35 web-upgrade bypass markers must be present in
 * this docker image so “Upgrade via web on my own risk” is not a no-op.
 *
 * NC ≤34 keeps the gate in lib/base.php; NC 35+ moved it into
 * OC::printUpgradePage (lib/OC.php). This test checks whichever file
 * actually carries the gate.
 *
 * Re-apply with: bash docker/patches/apply-web-upgrade-bypass.sh
 */
final class CoreWebUpgradeBypassVolumeIntegrationTest extends TestCase
{
	private const MARKER = 'SBD_WEB_UPGRADE_BYPASS';

	public function testBasePhpHonoursDisableWebAck(): void {
		$src = null;
		foreach (['/var/www/html/lib/base.php', '/var/www/html/lib/OC.php'] as $path) {
			if (!is_file($path)) {
				continue;
			}
			$candidate = (string)file_get_contents($path);
			if (str_contains($candidate, 'ignoreTooBigWarning') || str_contains($candidate, self::MARKER)) {
				$src = $candidate;
				break;
			}
		}
		if ($src === null) {
			$this->markTestSkipped('Not running against a Nextcloud volume');
		}
		$this->assertStringContainsString(self::MARKER, $src);
		$this->assertStringContainsString('($disableWebUpdater && !$ignoreWarning)', $src);
		$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::QUERY_KEY, $src);
		$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::TOKEN, $src);
		$this->assertStringNotContainsString(
			'if ($disableWebUpdater || ($tooBig && !$ignoreTooBigWarning))',
			$src,
			'Stock disable-web hard-block must be replaced'
		);
	}

	public function testUpdateControllerHonoursAck(): void {
		$path = '/var/www/html/core/Controller/UpdateController.php';
		if (!is_file($path)) {
			$this->markTestSkipped('Not running against a Nextcloud volume');
		}
		$src = (string)file_get_contents($path);
		$this->assertStringContainsString(self::MARKER, $src);
		$this->assertStringContainsString("getParam('" . CoreWebUpgradeBypassPolicy::QUERY_KEY . "')", $src);
		$this->assertStringContainsString(
			"getSystemValueBool('upgrade.disable-web', false) && !\$ignoreWarning",
			$src
		);
	}

	public function testUpdaterAdminChunkForwardsAck(): void {
		$files = glob('/var/www/html/dist/*-*.js') ?: [];
		if ($files === []) {
			$this->markTestSkipped('No dist chunks on this volume');
		}
		$found = false;
		foreach ($files as $file) {
			$src = (string)file_get_contents($file);
			if (!str_contains($src, '/core/update')) {
				continue;
			}
			if (str_contains($src, self::MARKER)) {
				$found = true;
				$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::QUERY_KEY, $src);
				$this->assertStringContainsString(CoreWebUpgradeBypassPolicy::TOKEN, $src);
				break;
			}
		}
		$this->assertTrue($found, 'UpdaterAdmin /core/update chunk missing SBD_WEB_UPGRADE_BYPASS marker');
	}
}
