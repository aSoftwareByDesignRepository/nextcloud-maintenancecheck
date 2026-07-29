<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\ProcItemMapper;
use OCA\MaintenanceCheck\Db\WoChecklistItem;
use OCA\MaintenanceCheck\Db\WoChecklistMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * W1 checklist instances. Rows are snapshots taken when a procedure is
 * attached; template edits never touch running WOs.
 *
 * show_if evaluation is server-authoritative (§10.6): every result PUT
 * recomputes visibility and *clears* results of items that transitioned to
 * hidden, inside one transaction under the WO row lock.
 */
class WoChecklistService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly WoChecklistMapper $checklist,
		private readonly ProcItemMapper $procItems,
		private readonly WorkOrderMapper $workOrders,
		private readonly ShowIfEvaluator $showIf,
		private readonly ChecklistPolicy $policy,
		private readonly PolicyService $policies,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly WorkOrderAccessPolicy $woAccess,
	) {
	}

	/**
	 * Copy procedure items into the WO. Caller owns the transaction (WO
	 * create/update). Existing rows are replaced — callers must ensure no
	 * results exist yet (409 `checklist_started` upstream).
	 */
	public function snapshotProcedure(int $workOrderId, int $procedureId): void
	{
		$this->checklist->deleteForWorkOrder($workOrderId);
		foreach ($this->procItems->findByProcedure($procedureId) as $item) {
			$row = new WoChecklistItem();
			$row->setWorkOrderId($workOrderId);
			$row->setItemCode($item->getCode());
			$row->setLabel($item->getLabel());
			$row->setRequired($item->getRequired());
			$row->setSortOrder($item->getSortOrder());
			$row->setShowIfItemCode($item->getShowIfItemCode());
			$row->setShowIfResult($item->getShowIfResult());
			$row->setResult(null);
			$row->setNote(null);
			$row->setUpdatedAt($this->clock->now());
			$this->checklist->insert($row);
		}
	}

	public function clear(int $workOrderId): void
	{
		$this->checklist->deleteForWorkOrder($workOrderId);
	}

	public function hasAnyResult(int $workOrderId): bool
	{
		foreach ($this->checklist->findByWorkOrder($workOrderId) as $item) {
			if ($item->getResult() !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checklist payload with computed visibility for the WO page.
	 *
	 * @return array{items: list<array<string, mixed>>, assessment: array<string, mixed>}
	 */
	public function detail(int $workOrderId): array
	{
		$rows = $this->checklist->findByWorkOrder($workOrderId);
		$plain = $this->asArrays($rows);
		$results = [];
		foreach ($plain as $item) {
			$results[$item['code']] = $item['result'];
		}
		$visibility = $this->showIf->visibility($plain, $results);

		$items = [];
		foreach ($rows as $row) {
			$item = $row->toApi();
			$item['visible'] = $visibility[$row->getItemCode()] ?? true;
			$items[] = $item;
		}
		return ['items' => $items, 'assessment' => $this->assessDone($workOrderId)];
	}

	/**
	 * §10.3 done-gate assessment with the active org policy.
	 *
	 * @return array{allowed: bool, policy: string,
	 *               failedItems: list<array{code: string, label: string}>,
	 *               incompleteItems: list<array{code: string, label: string}>,
	 *               completedRequired: int, totalRequired: int}
	 */
	public function assessDone(int $workOrderId): array
	{
		$policy = $this->policies->checklistDonePolicy();
		$assessed = $this->policy->assess(
			$this->asArrays($this->checklist->findByWorkOrder($workOrderId)),
			$policy,
			$this->policies->checklistMinPercent(),
		);
		$assessed['policy'] = $policy;
		return $assessed;
	}

	/**
	 * PUT one item result. Only while the WO is `in_progress` (the state
	 * machine funnels execution through start).
	 *
	 * @param array<string, mixed> $body
	 * @return array{items: list<array<string, mixed>>, assessment: array<string, mixed>,
	 *               clearedItemCodes: list<string>}
	 */
	public function setResult(string $uid, int $workOrderId, string $itemCode, array $body): array
	{
		$result = $this->validatedResult($body);
		$note = $this->validator->boundedOptionalString($body, 'note', 1024, 'note_too_long');
		$now = $this->clock->now();
		$cleared = [];

		$this->db->beginTransaction();
		try {
			// WO lock serialises concurrent item PUTs so the hide-clearing
			// pass always sees a consistent result set.
			if (!$this->workOrders->lockRow($workOrderId)) {
				throw new NotFoundException();
			}
			$wo = $this->workOrders->findById($workOrderId);
			$this->woAccess->assertCanExecute($uid, $wo);
			if ($wo->getStatus() !== WorkOrder::STATUS_IN_PROGRESS) {
				throw new ConflictException('invalid_status', 'Checklist results can only be recorded while the work order is in progress.');
			}

			$rows = $this->checklist->findByWorkOrder($workOrderId);
			$target = null;
			foreach ($rows as $row) {
				if ($row->getItemCode() === $itemCode) {
					$target = $row;
					break;
				}
			}
			if ($target === null) {
				throw new NotFoundException();
			}

			$plain = $this->asArrays($rows);
			$results = [];
			foreach ($plain as $item) {
				$results[$item['code']] = $item['result'];
			}

			// §10.6: clients must not answer hidden items.
			$visibilityBefore = $this->showIf->visibility($plain, $results);
			if (!($visibilityBefore[$itemCode] ?? false)) {
				throw new ConflictException('item_hidden', 'This item is currently hidden by a visibility rule.');
			}

			$target->setResult($result);
			$target->setNote($note);
			$target->setUpdatedBy($uid);
			$target->setUpdatedAt($now);
			$this->checklist->update($target);
			$results[$itemCode] = $result;

			// Hide-transition pass: clear results of items that just became
			// hidden. Clearing can cascade (chains), so iterate to fixpoint.
			do {
				$changed = false;
				$visibility = $this->showIf->visibility($plain, $results);
				foreach ($rows as $row) {
					$code = $row->getItemCode();
					if (($results[$code] ?? null) !== null && !($visibility[$code] ?? false)) {
						$row->setResult(null);
						$row->setNote(null);
						$row->setUpdatedBy($uid);
						$row->setUpdatedAt($now);
						$this->checklist->update($row);
						$results[$code] = null;
						$cleared[] = $code;
						$changed = true;
					}
				}
			} while ($changed);

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$detail = $this->detail($workOrderId);
		$detail['clearedItemCodes'] = array_values(array_unique($cleared));
		return $detail;
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedResult(array $body): ?string
	{
		if (!array_key_exists('result', $body)) {
			throw new ValidationException('validation_failed', 'result is required (ok, fail, na, or null).', [
				['field' => 'result', 'code' => 'required'],
			]);
		}
		$result = $body['result'];
		if ($result === null) {
			return null;
		}
		if (!is_string($result) || !in_array($result, WoChecklistItem::RESULTS, true)) {
			throw new ValidationException('validation_failed', 'result must be ok, fail, na, or null.', [
				['field' => 'result', 'code' => 'invalid_value'],
			]);
		}
		return $result;
	}

	/**
	 * @param list<WoChecklistItem> $rows
	 * @return list<array{code: string, label: string, required: bool,
	 *               showIfItemCode: ?string, showIfResult: ?string,
	 *               result: ?string, note: ?string}>
	 */
	private function asArrays(array $rows): array
	{
		$out = [];
		foreach ($rows as $row) {
			$out[] = [
				'code' => $row->getItemCode(),
				'label' => $row->getLabel(),
				'required' => $row->getRequired(),
				'showIfItemCode' => $row->getShowIfItemCode(),
				'showIfResult' => $row->getShowIfResult(),
				'result' => $row->getResult(),
				'note' => $row->getNote(),
			];
		}
		return $out;
	}
}
