<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Portfolio §2.1 — user-delete scrub + delegated App Admin list writes. */
final class UserLifecyclePurgeContractTest extends TestCase
{
	public function testAccessControlExposesPurgeUser(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$this->assertStringContainsString('public function purgeUser(string $userId): void', $src);
		$this->assertStringContainsString('KEY_APP_ADMINS', $src);
	}

	public function testUserDeletedListenerRegistered(): void
	{
		$listener = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Listener/UserDeletedListener.php');
		$app = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$this->assertStringContainsString('purgeUser', $listener);
		$this->assertStringContainsString('UserDeletedListener', $app);
	}

	public function testDedicatedAppAdminsMayRewriteAppAdminList(): void
	{
		$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ConfigController.php');
		$this->assertStringContainsString('cannot_remove_self', $ctrl);
		$this->assertStringNotContainsString('requireSystemAdmin($this->access->currentUserId());', $ctrl);
	}
}
