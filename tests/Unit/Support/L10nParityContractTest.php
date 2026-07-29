<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * N9 — every tr() key in app.js must exist in EN and DE catalogs with parity.
 */
final class L10nParityContractTest extends TestCase
{
	public function testAppJsTrKeysExistInEnAndDe(): void
	{
		$root = dirname(__DIR__, 3);
		$keys = [];
		foreach (['/js/app.js', '/js/work-order-pages.js'] as $rel) {
			$js = (string)file_get_contents($root . $rel);
			preg_match_all("/\\btr\\('((?:\\\\'|[^'])*)'\\)/", $js, $m);
			foreach ($m[1] as $raw) {
				$keys[] = str_replace("\\'", "'", $raw);
			}
		}
		$keys = array_values(array_unique($keys));
		self::assertNotEmpty($keys);

		$en = json_decode((string)file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$de = json_decode((string)file_get_contents($root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$enT = $en['translations'] ?? [];
		$deT = $de['translations'] ?? [];

		$missingEn = [];
		$missingDe = [];
		foreach ($keys as $key) {
			if (!array_key_exists($key, $enT)) {
				$missingEn[] = $key;
			}
			if (!array_key_exists($key, $deT)) {
				$missingDe[] = $key;
			}
		}
		self::assertSame([], $missingEn, 'Missing EN keys: ' . implode(' | ', $missingEn));
		self::assertSame([], $missingDe, 'Missing DE keys: ' . implode(' | ', $missingDe));
	}

	public function testEnDeCatalogKeyParity(): void
	{
		$root = dirname(__DIR__, 3);
		$en = json_decode((string)file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$de = json_decode((string)file_get_contents($root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$enKeys = array_keys($en['translations'] ?? []);
		$deKeys = array_keys($de['translations'] ?? []);
		sort($enKeys);
		sort($deKeys);
		self::assertSame($enKeys, $deKeys, 'EN/DE translation key sets must be identical (N9)');
	}

	public function testNoComingSoonPlaceholderStringsInCatalogs(): void
	{
		$root = dirname(__DIR__, 3);
		foreach (['en', 'de'] as $lang) {
			$catalog = json_decode((string)file_get_contents($root . '/l10n/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR);
			$translations = $catalog['translations'] ?? [];
			foreach ($translations as $key => $value) {
				self::assertStringNotContainsString(
					'TODO',
					(string)$key,
					$lang . ' catalog key must not contain TODO',
				);
				self::assertStringNotContainsString(
					'FIXME',
					(string)$value,
					$lang . ' catalog value must not contain FIXME',
				);
			}
		}
	}
}
