<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule: settings and assign surfaces must use directory search, never raw UIDs.
 */
final class DirectoryPickerContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testSettingsIdListsUseDirectorySearchNotFreeTextAdd(): void
	{
		$js = (string) file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString("kind === 'group' ? 'groupsSearch' : 'usersSearch'", $js);
		$this->assertStringContainsString('Never type a raw user id', $js);
		$this->assertStringContainsString('Never type a raw group id', $js);
		$this->assertStringNotContainsString('Nextcloud user IDs with access', $js);
		$this->assertStringNotContainsString("placeholder: tr('Nextcloud user ID')", $js);
		$this->assertDoesNotMatchRegularExpression(
			'/function idListEditor\(options\) \{[\s\S]{0,400}type:\s*[\'"]text[\'"]/',
			$js,
		);
	}

	public function testVisitAssignAndCapacityUseUserPicker(): void
	{
		$js = (string) file_get_contents($this->root . '/js/app.js');
		$this->assertStringNotContainsString("field(tr('Nextcloud user ID')", $js);
		$this->assertStringContainsString('function assignDialog', $js);
		$this->assertMatchesRegularExpression(
			'/function assignDialog\([\s\S]{0,400}attachUserPicker\(/',
			$js,
		);
		$this->assertMatchesRegularExpression(
			'/function renderCapacity\([\s\S]{0,1200}attachUserPicker\(/',
			$js,
		);
	}

	public function testWorkOrderAssignUsesPickersNotCommaSeparatedHelpers(): void
	{
		$js = (string) file_get_contents($this->root . '/js/work-order-pages.js');
		$this->assertStringContainsString('Never type a raw user id', $js);
		$this->assertStringNotContainsString('Optional helper user IDs, comma-separated', $js);
		$this->assertStringNotContainsString('Technician UID', $js);
		$this->assertStringContainsString('Add helper', $js);
	}

	public function testGroupSearchRouteAndControllerExist(): void
	{
		$routes = (string) file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString("config#searchGroups", $routes);
		$this->assertStringContainsString('/api/groups/search', $routes);
		$ctrl = (string) file_get_contents($this->root . '/lib/Controller/ConfigController.php');
		$this->assertStringContainsString('function searchGroups', $ctrl);
		$this->assertStringContainsString('GroupDirectorySearch::search', $ctrl);
		$page = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'groupsSearch'", $page);
	}
}
