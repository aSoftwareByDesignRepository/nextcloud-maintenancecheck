<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Dispatch board UX contracts (Bachus flatten + display names).
 */
final class DispatchBoardContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testDispatchServiceEnrichesOwnerDisplayNames(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/DispatchService.php');
		$this->assertStringContainsString('IUserManager', $src);
		$this->assertStringContainsString('primaryUserDisplayName', $src);
		$this->assertStringContainsString("'displayName'", $src);
		$this->assertStringContainsString('withDisplayNames', $src);
		$app = (string)file_get_contents($this->root . '/lib/AppInfo/Application.php');
		$this->assertMatchesRegularExpression(
			'/registerService\(DispatchService::class[\s\S]*?IUserManager::class/s',
			$app,
			'Application factory must inject IUserManager into DispatchService',
		);
	}

	public function testDispatchTemplateUsesFlatToolbarNotNestedDayCards(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/dispatch.php');
		$this->assertStringContainsString('mn-dispatch-toolbar', $tpl);
		$this->assertStringContainsString('mn-card--table-solo', $tpl);
		$this->assertStringContainsString('dispatch_quickstart_v2', $tpl);
		$this->assertStringNotContainsString('mn-card__header', $tpl);
		$js = (string)file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringContainsString('openDispatchAssign', $js);
		$this->assertStringContainsString('mn-bucket', $js);
		$this->assertStringNotContainsString('mn-dispatch-lane', $js);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.mn-dispatch-toolbar', $css);
		$this->assertDoesNotMatchRegularExpression('/\.mn-dispatch-lane\s*\{/', $css);
	}
}
