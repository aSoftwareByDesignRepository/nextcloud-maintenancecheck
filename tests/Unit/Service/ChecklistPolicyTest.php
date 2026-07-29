<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WoChecklistItem;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\ShowIfEvaluator;
use PHPUnit\Framework\TestCase;

/** AC-W1-3 / §10.3 / §10.6 required_effective. */
final class ChecklistPolicyTest extends TestCase
{
	private ChecklistPolicy $policy;

	protected function setUp(): void
	{
		$this->policy = new ChecklistPolicy(new ShowIfEvaluator());
	}

	/**
	 * @param list<array{code: string, label: string, required: bool, showIfItemCode: ?string, showIfResult: ?string, result: ?string, note: ?string}> $items
	 * @return array{allowed: bool, failedItems: list<array{code: string, label: string}>, incompleteItems: list<array{code: string, label: string}>, completedRequired: int, totalRequired: int}
	 */
	private function assess(array $items, string $policy = ChecklistPolicy::POLICY_ALL_REQUIRED, int $minPercent = 100): array
	{
		return $this->policy->assess($items, $policy, $minPercent);
	}

	public function testFailOnVisibleItemBlocksDone(): void
	{
		$items = [
			$this->item('a', true, null, null, WoChecklistItem::RESULT_FAIL, null),
		];
		$result = $this->assess($items);
		$this->assertFalse($result['allowed']);
		$this->assertSame([['code' => 'a', 'label' => 'a']], $result['failedItems']);
	}

	public function testFailStillBlocksUnderOffPolicy(): void
	{
		$items = [
			$this->item('a', true, null, null, WoChecklistItem::RESULT_FAIL, null),
		];
		$result = $this->assess($items, ChecklistPolicy::POLICY_OFF);
		$this->assertFalse($result['allowed']);
		$this->assertNotEmpty($result['failedItems']);
	}

	public function testAllRequiredPassesWhenRequiredOk(): void
	{
		$items = [
			$this->item('a', true, null, null, WoChecklistItem::RESULT_OK, null),
			$this->item('b', false, null, null, null, null),
		];
		$result = $this->assess($items);
		$this->assertTrue($result['allowed']);
		$this->assertSame(1, $result['totalRequired']);
		$this->assertSame(1, $result['completedRequired']);
	}

	public function testNaRequiresNote(): void
	{
		$items = [
			$this->item('a', true, null, null, WoChecklistItem::RESULT_NA, null),
		];
		$this->assertFalse($this->assess($items)['allowed']);

		$items[0]['note'] = 'not applicable today';
		$this->assertTrue($this->assess($items)['allowed']);
	}

	public function testHiddenShowIfChildNotRequiredEffective(): void
	{
		// AC-W1-6: when parent=ok, child with show_if fail is hidden and not required.
		$items = [
			$this->item('leak', true, null, null, WoChecklistItem::RESULT_OK, null),
			$this->item('leak_detail', true, 'leak', 'fail', null, null),
		];
		$result = $this->assess($items);
		$this->assertTrue($result['allowed']);
		$this->assertSame(1, $result['totalRequired']);
		$this->assertSame([], $result['incompleteItems']);
	}

	public function testVisibleShowIfChildIsRequired(): void
	{
		$items = [
			$this->item('leak', true, null, null, WoChecklistItem::RESULT_FAIL, null),
			$this->item('leak_detail', true, 'leak', 'fail', null, null),
		];
		$result = $this->assess($items);
		$this->assertFalse($result['allowed']);
		$this->assertSame([['code' => 'leak', 'label' => 'leak']], $result['failedItems']);
		$this->assertContains(['code' => 'leak_detail', 'label' => 'leak_detail'], $result['incompleteItems']);
		$this->assertSame(2, $result['totalRequired']);
	}

	public function testPercentPolicy(): void
	{
		$items = [
			$this->item('a', true, null, null, WoChecklistItem::RESULT_OK, null),
			$this->item('b', true, null, null, null, null),
		];
		$this->assertFalse($this->assess($items, ChecklistPolicy::POLICY_PERCENT, 100)['allowed']);
		$this->assertTrue($this->assess($items, ChecklistPolicy::POLICY_PERCENT, 50)['allowed']);
	}

	public function testOffPolicyIgnoresIncomplete(): void
	{
		$items = [
			$this->item('a', true, null, null, null, null),
		];
		$this->assertTrue($this->assess($items, ChecklistPolicy::POLICY_OFF)['allowed']);
	}

	/**
	 * @return array{code: string, label: string, required: bool, showIfItemCode: ?string, showIfResult: ?string, result: ?string, note: ?string}
	 */
	private function item(
		string $code,
		bool $required,
		?string $showIfCode,
		?string $showIfResult,
		?string $result,
		?string $note,
	): array {
		return [
			'code' => $code,
			'label' => $code,
			'required' => $required,
			'showIfItemCode' => $showIfCode,
			'showIfResult' => $showIfResult,
			'result' => $result,
			'note' => $note,
		];
	}
}
