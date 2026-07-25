<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use OCA\MaintenanceCheck\Support\UserDirectorySearch;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class UserDirectorySearchTest extends TestCase
{
	public function testMergesIdAndDisplayNameMatchesAndDedupes(): void
	{
		$alice = $this->user('alice', 'Alice Admin', true);
		$bob = $this->user('bob', 'Bob Builder', true);
		$disabled = $this->user('ghost', 'Ghost', false);

		$um = $this->createMock(IUserManager::class);
		$um->method('search')->with('al', $this->anything(), 0)->willReturn([$alice, $disabled]);
		$um->method('searchDisplayName')->with('al', $this->anything(), 0)->willReturn([$alice, $bob]);

		$result = UserDirectorySearch::search($um, 'al', 25);
		$this->assertCount(2, $result);
		$ids = array_column($result, 'id');
		$this->assertSame(['alice', 'bob'], $ids);
		$this->assertSame('Alice Admin', $result[0]['displayName']);
	}

	public function testClampsLimit(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('search')->willReturn([]);
		$um->method('searchDisplayName')->willReturn([]);
		$this->assertSame([], UserDirectorySearch::search($um, '', 0));
		$this->assertSame([], UserDirectorySearch::search($um, 'x', 999));
	}

	private function user(string $uid, string $dn, bool $enabled): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($dn);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}
}
