<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WoChecklistItem;

/**
 * CORE §10.3 checklist completion policy for WO `done`.
 *
 * Org setting `checklist_done_policy`:
 *   all_required (default) — every required_effective item is `ok`, or `na`
 *                            with a note
 *   percent                — completed_required / total_required ≥ min%
 *   off                    — checklist advisory only
 *
 * A `fail` result on a *visible* item always blocks `done` regardless of
 * policy, unless office force-closes with a reason ≥ 20 chars (handled by
 * the caller; this class only reports).
 *
 * required_effective(item) := item.required AND visible(item) (§10.6).
 *
 * Pure service — no I/O, mutation-test target.
 */
class ChecklistPolicy
{
	public const POLICY_ALL_REQUIRED = 'all_required';
	public const POLICY_PERCENT = 'percent';
	public const POLICY_OFF = 'off';

	public const POLICIES = [self::POLICY_ALL_REQUIRED, self::POLICY_PERCENT, self::POLICY_OFF];

	public const DEFAULT_MIN_PERCENT = 100;
	public const FORCE_CLOSE_MIN_REASON = 20;

	public function __construct(
		private readonly ShowIfEvaluator $showIf,
	) {
	}

	/**
	 * Assess whether the checklist allows `done`.
	 *
	 * @param list<array{code: string, label: string, required: bool,
	 *                    showIfItemCode: ?string, showIfResult: ?string,
	 *                    result: ?string, note: ?string}> $items
	 * @return array{allowed: bool, failedItems: list<array{code: string, label: string}>,
	 *               incompleteItems: list<array{code: string, label: string}>,
	 *               completedRequired: int, totalRequired: int}
	 */
	public function assess(array $items, string $policy, int $minPercent): array
	{
		$results = [];
		foreach ($items as $item) {
			$results[$item['code']] = $item['result'];
		}
		$visibility = $this->showIf->visibility($items, $results);

		$failed = [];
		$incomplete = [];
		$totalRequired = 0;
		$completedRequired = 0;

		foreach ($items as $item) {
			if (!($visibility[$item['code']] ?? false)) {
				continue;
			}
			// fail on any visible item blocks done regardless of policy.
			if ($item['result'] === WoChecklistItem::RESULT_FAIL) {
				$failed[] = ['code' => $item['code'], 'label' => $item['label']];
			}
			if (!$item['required']) {
				continue;
			}
			$totalRequired++;
			if ($this->isSatisfied($item['result'], $item['note'])) {
				$completedRequired++;
			} else {
				$incomplete[] = ['code' => $item['code'], 'label' => $item['label']];
			}
		}

		$policyOk = match ($policy) {
			self::POLICY_OFF => true,
			self::POLICY_PERCENT => $totalRequired === 0
				|| ($completedRequired * 100) >= ($totalRequired * $this->clampPercent($minPercent)),
			default => $incomplete === [],
		};

		return [
			'allowed' => $policyOk && $failed === [],
			'failedItems' => $failed,
			'incompleteItems' => $incomplete,
			'completedRequired' => $completedRequired,
			'totalRequired' => $totalRequired,
		];
	}

	/**
	 * §10.3 all_required: a required item is satisfied by `ok`, or by `na`
	 * with a non-empty note. `fail` never satisfies.
	 */
	private function isSatisfied(?string $result, ?string $note): bool
	{
		if ($result === WoChecklistItem::RESULT_OK) {
			return true;
		}
		if ($result === WoChecklistItem::RESULT_NA) {
			return $note !== null && trim($note) !== '';
		}
		return false;
	}

	private function clampPercent(int $minPercent): int
	{
		return max(0, min(100, $minPercent));
	}
}
