<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Support;

use OCP\IGroup;
use OCP\IGroupManager;

/**
 * Directory search for Nextcloud groups (display name + gid).
 */
final class GroupDirectorySearch
{
	private const MAX_FETCH = 200;

	private function __construct()
	{
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public static function search(IGroupManager $groupManager, string $pattern, int $limit = 25): array
	{
		$limit = max(1, min(50, $limit));
		$pattern = trim($pattern);
		if ($pattern === '') {
			return [];
		}
		$fetchCap = min(self::MAX_FETCH, max($limit * 4, 40));
		$groups = (array) $groupManager->search($pattern, $fetchCap, 0);

		/** @var array<string, IGroup> $merged */
		$merged = [];
		foreach ($groups as $group) {
			if (!$group instanceof IGroup) {
				continue;
			}
			$gid = (string) $group->getGID();
			if ($gid === '' || isset($merged[$gid])) {
				continue;
			}
			$merged[$gid] = $group;
		}

		$list = array_values($merged);
		usort(
			$list,
			static fn (IGroup $a, IGroup $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()),
		);

		$out = [];
		foreach (array_slice($list, 0, $limit) as $group) {
			$out[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
			];
		}
		return $out;
	}
}
