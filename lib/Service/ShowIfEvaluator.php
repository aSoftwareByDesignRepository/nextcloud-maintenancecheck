<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WoChecklistItem;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * CORE §10.6 light conditional checklist logic ("show_if").
 *
 *   visible(item) := show_if_item_code IS NULL
 *     OR (referenced item is visible AND (
 *          referenced.result = show_if_result
 *          OR (show_if_result = any_answered AND referenced.result IN (ok,fail,na))))
 *
 * Visibility is transitive: a hidden parent hides its whole chain (the
 * server also clears results on hide, so both guards agree).
 *
 * Authoring-time validation: self-reference and cycles → 422 `show_if_cycle`;
 * unknown reference → 422 `show_if_unknown`; a rule with only one half set,
 * or an invalid result value → 422 `validation_failed`.
 *
 * The runtime evaluator carries a visited-set so malformed persisted data
 * degrades to "hidden" instead of recursing forever.
 *
 * Pure service — no I/O, mutation-test target.
 */
class ShowIfEvaluator
{
	public const RESULT_ANY_ANSWERED = 'any_answered';

	public const RULE_RESULTS = [
		WoChecklistItem::RESULT_OK,
		WoChecklistItem::RESULT_FAIL,
		WoChecklistItem::RESULT_NA,
		self::RESULT_ANY_ANSWERED,
	];

	/**
	 * Validate authoring-time rules for an item list (any order).
	 *
	 * @param list<array{code: string, showIfItemCode: ?string, showIfResult: ?string}> $items
	 * @throws ValidationException `show_if_cycle`, `show_if_unknown`, `validation_failed`
	 */
	public function validateRules(array $items): void
	{
		$refs = [];
		foreach ($items as $index => $item) {
			$code = $item['code'];
			$refCode = $item['showIfItemCode'];
			$refResult = $item['showIfResult'];

			if (($refCode === null) !== ($refResult === null)) {
				throw new ValidationException('validation_failed', 'A visibility rule needs both the item and the result.', [
					['field' => 'items[' . $index . '].showIf', 'code' => 'incomplete_rule'],
				]);
			}
			if ($refCode === null) {
				$refs[$code] = null;
				continue;
			}
			if ($refCode === $code) {
				throw new ValidationException('show_if_cycle', 'An item cannot depend on itself.');
			}
			if (!in_array($refResult, self::RULE_RESULTS, true)) {
				throw new ValidationException('validation_failed', 'The visibility result must be ok, fail, na, or any_answered.', [
					['field' => 'items[' . $index . '].showIf', 'code' => 'invalid_result'],
				]);
			}
			$refs[$code] = $refCode;
		}

		foreach ($items as $item) {
			$refCode = $item['showIfItemCode'];
			if ($refCode !== null && !array_key_exists($refCode, $refs)) {
				throw new ValidationException('show_if_unknown', sprintf(
					'Visibility rule on "%s" references unknown item "%s".',
					$item['code'],
					$refCode,
				));
			}
		}

		// Cycle detection: follow each reference chain; revisiting a node
		// inside the current trail means A→…→A.
		foreach (array_keys($refs) as $start) {
			$trail = [];
			$cursor = $start;
			while ($cursor !== null) {
				if (isset($trail[$cursor])) {
					throw new ValidationException('show_if_cycle', 'Visibility rules must not form a cycle.');
				}
				$trail[$cursor] = true;
				$cursor = $refs[$cursor] ?? null;
			}
		}
	}

	/**
	 * Compute visibility for checklist instance items given current results.
	 *
	 * @param list<array{code: string, showIfItemCode: ?string, showIfResult: ?string}> $items
	 * @param array<string, ?string> $results item code => result (ok|fail|na|null)
	 * @return array<string, bool> item code => visible
	 */
	public function visibility(array $items, array $results): array
	{
		$byCode = [];
		foreach ($items as $item) {
			$byCode[$item['code']] = $item;
		}

		/** @var array<string, bool> $memo */
		$memo = [];
		$resolve = function (string $code, array $trail) use (&$resolve, &$memo, $byCode, $results): bool {
			if (isset($memo[$code])) {
				return $memo[$code];
			}
			// Defensive: cycles or dangling refs in persisted data → hidden.
			if (isset($trail[$code]) || !isset($byCode[$code])) {
				return $memo[$code] = false;
			}
			$item = $byCode[$code];
			if ($item['showIfItemCode'] === null) {
				return $memo[$code] = true;
			}
			$trail[$code] = true;
			if (!$resolve($item['showIfItemCode'], $trail)) {
				return $memo[$code] = false;
			}
			$parentResult = $results[$item['showIfItemCode']] ?? null;
			if ($item['showIfResult'] === self::RESULT_ANY_ANSWERED) {
				return $memo[$code] = in_array($parentResult, WoChecklistItem::RESULTS, true);
			}
			return $memo[$code] = ($parentResult !== null && $parentResult === $item['showIfResult']);
		};

		$visibility = [];
		foreach ($items as $item) {
			$visibility[$item['code']] = $resolve($item['code'], []);
		}
		return $visibility;
	}
}
