<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Support;

use OCP\IUser;
use OCP\IUserManager;

/**
 * Directory search by user id OR display name (merged, de-duplicated).
 *
 * IUserManager::search() matches ids only — admins type names. Same pattern
 * as ArbeitszeitCheck UserDirectorySearch (issue #14 class of bugs).
 */
final class UserDirectorySearch
{
	private const MAX_FETCH = 200;

	private function __construct()
	{
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public static function search(IUserManager $userManager, string $pattern, int $limit = 25): array
	{
		$limit = max(1, min(50, $limit));
		$pattern = trim($pattern);
		$fetchCap = min(self::MAX_FETCH, max($limit * 4, 40));

		$byId = (array)$userManager->search($pattern, $fetchCap, 0);
		$byName = (array)$userManager->searchDisplayName($pattern, $fetchCap, 0);

		/** @var array<string, IUser> $merged */
		$merged = [];
		foreach ([$byId, $byName] as $batch) {
			foreach ($batch as $user) {
				if (!$user instanceof IUser || !$user->isEnabled()) {
					continue;
				}
				$uid = (string)$user->getUID();
				if ($uid === '' || isset($merged[$uid])) {
					continue;
				}
				$merged[$uid] = $user;
			}
		}

		$users = array_values($merged);
		usort(
			$users,
			static fn (IUser $a, IUser $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()),
		);

		$out = [];
		foreach (array_slice($users, 0, $limit) as $user) {
			$out[] = [
				'id' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}
		return $out;
	}
}
