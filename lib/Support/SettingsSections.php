<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Support;

use OCP\IL10N;

/**
 * Catalog of Settings underpages (sidebar + horizontal subnav — no hub overview).
 *
 * Each id maps to GET /settings/{id} and templates/settings/{id}.php.
 * GET /settings redirects to DEFAULT.
 *
 * @psalm-type SectionDef = array{
 *   id: string,
 *   title: string,
 *   hint: string,
 *   icon: string,
 *   hasJsHost: bool
 * }
 */
final class SettingsSections
{
	/** Canonical landing section when /settings is opened or an id is invalid. */
	public const DEFAULT = 'access';

	/** @return list<string> */
	public static function ids(): array
	{
		return [
			'access',
			'roles',
			'inventory',
			'policies',
			'capacity',
			'license',
			'support',
		];
	}

	public static function isValid(string $section): bool
	{
		return in_array($section, self::ids(), true);
	}

	/**
	 * @return list<SectionDef>
	 */
	public static function all(IL10N $l): array
	{
		return [
			[
				'id' => 'access',
				'title' => $l->t('Access'),
				'hint' => $l->t('Who may open MaintenanceCheck — restriction, allow-lists, and app admins.'),
				'icon' => 'users',
				'hasJsHost' => true,
			],
			[
				'id' => 'roles',
				'title' => $l->t('Roles'),
				'hint' => $l->t('Office members manage master data; others complete visits as technicians.'),
				'icon' => 'award',
				'hasJsHost' => true,
			],
			[
				'id' => 'inventory',
				'title' => $l->t('Inventory stock issue'),
				'hint' => $l->t('Optional stock deduction when a compatible inventory app is installed. Off by default.'),
				'icon' => 'package',
				'hasJsHost' => true,
			],
			[
				'id' => 'policies',
				'title' => $l->t('Work policies'),
				'hint' => $l->t('How strictly checklists, skills and daily capacity apply.'),
				'icon' => 'clipboard-list',
				'hasJsHost' => true,
			],
			[
				'id' => 'capacity',
				'title' => $l->t('Daily capacity'),
				'hint' => $l->t('Minutes each technician can take in a day for dispatch.'),
				'icon' => 'gauge',
				'hasJsHost' => true,
			],
			[
				'id' => 'license',
				'title' => $l->t('License & mobile'),
				'hint' => $l->t('Apply a mobile license key and assign named seats.'),
				'icon' => 'smartphone',
				'hasJsHost' => true,
			],
			[
				'id' => 'support',
				'title' => $l->t('Support us'),
				'hint' => $l->t('Sponsoring, bookable help, and how to keep development going.'),
				'icon' => 'heart',
				'hasJsHost' => false,
			],
		];
	}

	/**
	 * @return SectionDef|null
	 */
	public static function get(IL10N $l, string $id): ?array
	{
		foreach (self::all($l) as $section) {
			if ($section['id'] === $id) {
				return $section;
			}
		}
		return null;
	}

	/** Route requirement fragment for appinfo/routes.php (kept in sync by contract tests). */
	public static function routeRequirement(): string
	{
		return implode('|', self::ids());
	}
}
