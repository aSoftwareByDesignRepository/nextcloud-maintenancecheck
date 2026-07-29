<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * CORE §14.1c `mn_procedure_pack_v1` schema validation.
 *
 * Hard caps: 2 MB raw JSON, ≤ 50 procedures, ≤ 200 items per procedure
 * (422 `pack_too_large`). Any structural violation → 422 `pack_invalid`.
 * show_if rules are re-validated per procedure via {@see ShowIfEvaluator}
 * (`show_if_cycle` / `show_if_unknown` bubble up unchanged).
 *
 * JSON only — no code blobs, no expressions (§19 risk register).
 *
 * Pure service — no I/O, mutation-test target.
 */
class PackSchema
{
	public const FORMAT = 'mn_procedure_pack_v1';
	public const MAX_RAW_BYTES = 2 * 1024 * 1024;
	public const MAX_PROCEDURES = 50;
	public const MAX_ITEMS = 200;

	private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
	private const VERTICAL_PATTERN = '/^[a-z0-9_-]{1,32}$/';
	private const LOCALE_PATTERN = '/^[a-z]{2}(?:[_-][A-Za-z]{2})?$/';

	public function __construct(
		private readonly ShowIfEvaluator $showIf,
	) {
	}

	/**
	 * Parse and validate raw pack JSON.
	 *
	 * @return array{packCode: string, vertical: ?string, locale: string, version: int,
	 *               procedures: list<array{code: string, title: string,
	 *                 items: list<array{code: string, label: string, required: bool,
	 *                   sortOrder: int, showIfItemCode: ?string, showIfResult: ?string}>}>}
	 * @throws ValidationException `pack_too_large`, `pack_invalid`, `show_if_*`
	 */
	public function parse(string $rawJson): array
	{
		if (strlen($rawJson) > self::MAX_RAW_BYTES) {
			throw new ValidationException('pack_too_large', 'Pack exceeds the 2 MB limit.');
		}
		$decoded = json_decode($rawJson, true);
		if (!is_array($decoded)) {
			throw new ValidationException('pack_invalid', 'Pack is not valid JSON.');
		}
		return $this->validate($decoded);
	}

	/**
	 * @param array<string, mixed> $pack decoded JSON
	 * @return array{packCode: string, vertical: ?string, locale: string, version: int,
	 *               procedures: list<array{code: string, title: string,
	 *                 items: list<array{code: string, label: string, required: bool,
	 *                   sortOrder: int, showIfItemCode: ?string, showIfResult: ?string}>}>}
	 */
	public function validate(array $pack): array
	{
		if (($pack['format'] ?? null) !== self::FORMAT) {
			throw new ValidationException('pack_invalid', 'Unsupported pack format; expected ' . self::FORMAT . '.');
		}
		$packCode = $pack['pack_code'] ?? null;
		if (!is_string($packCode) || !preg_match(self::CODE_PATTERN, $packCode)) {
			throw new ValidationException('pack_invalid', 'pack_code must match ' . self::CODE_PATTERN . '.');
		}

		$vertical = $pack['vertical'] ?? null;
		if ($vertical !== null && (!is_string($vertical) || !preg_match(self::VERTICAL_PATTERN, $vertical))) {
			throw new ValidationException('pack_invalid', 'vertical must be a short lowercase identifier.');
		}

		$locale = $pack['locale'] ?? 'en';
		if (!is_string($locale) || !preg_match(self::LOCALE_PATTERN, $locale)) {
			throw new ValidationException('pack_invalid', 'locale must look like "de" or "de_DE".');
		}

		$version = $pack['version'] ?? 1;
		if (!is_int($version) || $version < 1 || $version > 1000000) {
			throw new ValidationException('pack_invalid', 'version must be a positive integer.');
		}

		$procedures = $pack['procedures'] ?? null;
		if (!is_array($procedures) || $procedures === [] || !array_is_list($procedures)) {
			throw new ValidationException('pack_invalid', 'procedures must be a non-empty list.');
		}
		if (count($procedures) > self::MAX_PROCEDURES) {
			throw new ValidationException('pack_too_large', 'A pack may contain at most ' . self::MAX_PROCEDURES . ' procedures.');
		}

		$normalizedProcedures = [];
		$seenProcCodes = [];
		foreach ($procedures as $procIndex => $procedure) {
			if (!is_array($procedure)) {
				throw new ValidationException('pack_invalid', 'procedures[' . $procIndex . '] must be an object.');
			}
			$normalized = $this->validateProcedure($procedure, (string)$procIndex);
			if (isset($seenProcCodes[$normalized['code']])) {
				throw new ValidationException('pack_invalid', 'Duplicate procedure code "' . $normalized['code'] . '".');
			}
			$seenProcCodes[$normalized['code']] = true;
			$normalizedProcedures[] = $normalized;
		}

		return [
			'packCode' => $packCode,
			'vertical' => $vertical,
			'locale' => strtolower(str_replace('-', '_', $locale)),
			'version' => $version,
			'procedures' => $normalizedProcedures,
		];
	}

	/**
	 * Serialise procedures back into pack JSON (export side of UC-PACK-LIB).
	 *
	 * @param list<array{code: string, title: string,
	 *          items: list<array{code: string, label: string, required: bool,
	 *            sortOrder: int, showIfItemCode: ?string, showIfResult: ?string}>}> $procedures
	 * @return array<string, mixed>
	 */
	public function build(string $packCode, ?string $vertical, string $locale, int $version, array $procedures): array
	{
		$out = [];
		foreach ($procedures as $procedure) {
			$items = [];
			foreach ($procedure['items'] as $item) {
				$row = [
					'code' => $item['code'],
					'label' => $item['label'],
					'required' => $item['required'],
					'sort_order' => $item['sortOrder'],
				];
				if ($item['showIfItemCode'] !== null) {
					$row['show_if_item_code'] = $item['showIfItemCode'];
					$row['show_if_result'] = $item['showIfResult'];
				}
				$items[] = $row;
			}
			$out[] = ['code' => $procedure['code'], 'title' => $procedure['title'], 'items' => $items];
		}
		return [
			'format' => self::FORMAT,
			'pack_code' => $packCode,
			'vertical' => $vertical,
			'locale' => $locale,
			'version' => $version,
			'procedures' => $out,
		];
	}

	/**
	 * @param array<string, mixed> $procedure
	 * @return array{code: string, title: string,
	 *               items: list<array{code: string, label: string, required: bool,
	 *                 sortOrder: int, showIfItemCode: ?string, showIfResult: ?string}>}
	 */
	private function validateProcedure(array $procedure, string $path): array
	{
		$code = $procedure['code'] ?? null;
		if (!is_string($code) || !preg_match(self::CODE_PATTERN, $code)) {
			throw new ValidationException('pack_invalid', 'procedures[' . $path . '].code must match ' . self::CODE_PATTERN . '.');
		}
		$title = $procedure['title'] ?? null;
		if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 255) {
			throw new ValidationException('pack_invalid', 'procedures[' . $path . '].title must be 1–255 characters.');
		}

		$items = $procedure['items'] ?? null;
		if (!is_array($items) || $items === [] || !array_is_list($items)) {
			throw new ValidationException('pack_invalid', 'procedures[' . $path . '].items must be a non-empty list.');
		}
		if (count($items) > self::MAX_ITEMS) {
			throw new ValidationException('pack_too_large', 'A procedure may contain at most ' . self::MAX_ITEMS . ' items.');
		}

		$normalizedItems = [];
		$seenItemCodes = [];
		foreach ($items as $itemIndex => $item) {
			if (!is_array($item)) {
				throw new ValidationException('pack_invalid', 'procedures[' . $path . '].items[' . $itemIndex . '] must be an object.');
			}
			$itemCode = $item['code'] ?? null;
			if (!is_string($itemCode) || !preg_match(self::CODE_PATTERN, $itemCode)) {
				throw new ValidationException('pack_invalid', 'Item code must match ' . self::CODE_PATTERN . '.');
			}
			if (isset($seenItemCodes[$itemCode])) {
				throw new ValidationException('pack_invalid', 'Duplicate item code "' . $itemCode . '" in procedure "' . $code . '".');
			}
			$seenItemCodes[$itemCode] = true;

			$label = $item['label'] ?? null;
			if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > 255) {
				throw new ValidationException('pack_invalid', 'Item label must be 1–255 characters.');
			}

			$required = $item['required'] ?? true;
			if (!is_bool($required)) {
				throw new ValidationException('pack_invalid', 'Item required must be a boolean.');
			}

			$sortOrder = $item['sort_order'] ?? count($normalizedItems);
			if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 100000) {
				throw new ValidationException('pack_invalid', 'Item sort_order must be a non-negative integer.');
			}

			$showIfCode = $item['show_if_item_code'] ?? null;
			if ($showIfCode !== null && (!is_string($showIfCode) || !preg_match(self::CODE_PATTERN, $showIfCode))) {
				throw new ValidationException('pack_invalid', 'show_if_item_code must be a valid item code.');
			}
			$showIfResult = $item['show_if_result'] ?? null;
			if ($showIfResult !== null && !is_string($showIfResult)) {
				throw new ValidationException('pack_invalid', 'show_if_result must be a string.');
			}

			$normalizedItems[] = [
				'code' => $itemCode,
				'label' => trim($label),
				'required' => $required,
				'sortOrder' => $sortOrder,
				'showIfItemCode' => $showIfCode,
				'showIfResult' => $showIfResult,
			];
		}

		usort($normalizedItems, static fn (array $a, array $b): int => [$a['sortOrder'], $a['code']] <=> [$b['sortOrder'], $b['code']]);

		// show_if semantics (cycles, unknown refs, result values).
		$this->showIf->validateRules($normalizedItems);

		return ['code' => $code, 'title' => trim($title), 'items' => $normalizedItems];
	}
}
