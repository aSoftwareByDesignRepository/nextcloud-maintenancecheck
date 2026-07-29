<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\ProcItem;
use OCA\MaintenanceCheck\Db\ProcItemMapper;
use OCA\MaintenanceCheck\Db\Procedure;
use OCA\MaintenanceCheck\Db\ProcedureMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * W1 checklist templates (CORE §14.1, UC-PACK-LIB).
 *
 * In-use protection: a procedure referenced by any work order never has its
 * *items* mutated or the row deleted (409 `procedure_in_use`) — running WOs
 * carry snapshots, but the template must stay auditable. Header edits
 * (title, vertical, active) stay allowed; structural change goes through
 * fork() which allocates a `-vN` code suffix.
 *
 * Import (W3) is idempotent by `pack_code`: overwrite=0 → 409 `pack_exists`;
 * overwrite=1 replaces unused procedures in place and forks in-use ones
 * aside before re-creating the canonical code.
 */
class ProcedureService
{
	private const MAX_FORK_PROBES = 500;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ProcedureMapper $procedures,
		private readonly ProcItemMapper $items,
		private readonly WorkOrderMapper $workOrders,
		private readonly ShowIfEvaluator $showIf,
		private readonly PackSchema $packSchema,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	// ── Queries ─────────────────────────────────────────────────────────

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $limit, ?string $offset, ?string $vertical, ?string $active): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$vertical = trim((string)$vertical);
		if (mb_strlen($vertical) > 32) {
			throw new ValidationException('invalid_query', 'vertical must be at most 32 characters.');
		}
		$result = $this->procedures->listAll(
			$page['limit'],
			$page['offset'],
			$vertical !== '' ? $vertical : null,
			($active ?? '') === '1' ? true : null,
		);
		$data = [];
		foreach ($result['data'] as $procedure) {
			$row = $procedure->toApi();
			$row['inUse'] = $this->workOrders->countForProcedure((int)$procedure->getId()) > 0;
			$data[] = $row;
		}
		return [
			'data' => $data,
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * @return array<string, mixed> procedure incl. ordered items
	 */
	public function get(int $id): array
	{
		$procedure = $this->procedures->findById($id);
		return $this->toDetail($procedure);
	}

	// ── Mutations ───────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body): array
	{
		$code = $this->validator->catalogCode($body);
		$header = $this->validatedHeader($body);
		$items = $this->validatedItems($body);

		if ($this->procedures->findByCode($code) !== null) {
			throw new ConflictException('code_exists', 'A procedure with this code already exists.');
		}

		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			$procedure = $this->insertProcedure($code, $header, $items, null, 1, $uid, $now);
			$this->db->commit();
		} catch (\OCP\DB\Exception $e) {
			$this->db->rollBack();
			// Unique index on code: concurrent create surfaces as 409, not 500.
			if ($this->procedures->findByCode($code) !== null) {
				throw new ConflictException('code_exists', 'A procedure with this code already exists.');
			}
			throw $e;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->toDetail($procedure);
	}

	/**
	 * Header edits always allowed; item edits only while not in use.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(string $uid, int $id, array $body): array
	{
		$procedure = $this->procedures->findById($id);
		$now = $this->clock->now();

		if (array_key_exists('title', $body)) {
			$procedure->setTitle($this->validator->requiredString($body, 'title', 'title_required', 255, 'title_too_long'));
		}
		if (array_key_exists('vertical', $body)) {
			$procedure->setVertical($this->validatedVertical($body));
		}
		if (array_key_exists('active', $body)) {
			$procedure->setActive($this->validator->boolOrDefault($body, 'active', $procedure->getActive()));
		}

		$newItems = null;
		if (array_key_exists('items', $body)) {
			if ($this->workOrders->countForProcedure($id) > 0) {
				throw new ConflictException('procedure_in_use', 'This procedure is used by work orders. Fork it to change its items.');
			}
			$newItems = $this->validatedItems($body);
		}

		$procedure->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$this->procedures->update($procedure);
			if ($newItems !== null) {
				$procedure->setVersion($procedure->getVersion() + 1);
				$this->procedures->update($procedure);
				$this->items->deleteForProcedure($id);
				$this->insertItems($id, $newItems);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->toDetail($procedure);
	}

	/**
	 * UC-PACK-LIB fork: copy with `-vN` code suffix, version + 1.
	 *
	 * @return array<string, mixed>
	 */
	public function fork(string $uid, int $id): array
	{
		$source = $this->procedures->findById($id);
		$items = $this->itemsAsArrays($id);
		$now = $this->clock->now();

		$this->db->beginTransaction();
		try {
			$forkCode = $this->nextForkCode($source->getCode());
			$fork = $this->insertProcedure(
				$forkCode,
				[
					'title' => $source->getTitle(),
					'vertical' => $source->getVertical(),
					'locale' => $source->getLocale(),
					'active' => true,
				],
				$items,
				$source->getSourcePack(),
				$source->getVersion() + 1,
				$uid,
				$now,
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->toDetail($fork);
	}

	/**
	 * Hard delete only when never referenced (§12.5: in use → 409, use
	 * deactivate instead).
	 */
	public function delete(int $id): void
	{
		$this->procedures->findById($id);
		if ($this->workOrders->countForProcedure($id) > 0) {
			throw new ConflictException('procedure_in_use', 'This procedure is used by work orders. Deactivate it instead.');
		}
		$this->db->beginTransaction();
		try {
			$this->items->deleteForProcedure($id);
			$qb = $this->db->getQueryBuilder();
			$qb->delete(ProcedureMapper::TABLE)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	// ── Packs (W1 export / W3 import) ───────────────────────────────────

	/**
	 * Export procedures as `mn_procedure_pack_v1` JSON. Selection: by
	 * source pack code, by vertical, or everything active.
	 *
	 * @return array<string, mixed>
	 */
	public function exportPack(?string $pack, ?string $vertical): array
	{
		$pack = trim((string)$pack);
		$vertical = trim((string)$vertical);

		if ($pack !== '') {
			$procedures = $this->procedures->findBySourcePack($pack);
			$packCode = $pack;
		} else {
			$procedures = $this->procedures->listAll(PackSchema::MAX_PROCEDURES, 0, $vertical !== '' ? $vertical : null, true)['data'];
			$packCode = 'export-' . ($vertical !== '' ? $vertical : 'all') . '-' . $this->clock->today();
		}
		if ($procedures === []) {
			throw new \OCA\MaintenanceCheck\Exception\NotFoundException();
		}

		$locale = 'en';
		$version = 1;
		$exportable = [];
		foreach ($procedures as $procedure) {
			$locale = $procedure->getLocale();
			$version = max($version, $procedure->getVersion());
			$exportable[] = [
				'code' => $procedure->getCode(),
				'title' => $procedure->getTitle(),
				'items' => $this->itemsAsArrays((int)$procedure->getId()),
			];
		}
		return $this->packSchema->build(
			$packCode,
			$vertical !== '' ? $vertical : ($procedures[0]->getVertical()),
			$locale,
			$version,
			$exportable,
		);
	}

	/**
	 * W3 pack import (UC-PACK-LIB, AC-W3-4).
	 *
	 * @return array{packCode: string, imported: int, replaced: int, forkedAside: int}
	 */
	public function importPack(string $uid, string $rawJson, bool $overwrite): array
	{
		$pack = $this->packSchema->parse($rawJson);
		$existing = $this->procedures->findBySourcePack($pack['packCode']);
		if ($existing !== [] && !$overwrite) {
			throw new ConflictException('pack_exists', 'This pack is already installed. Enable overwrite to replace it.', [
				'packCode' => $pack['packCode'],
			]);
		}

		$now = $this->clock->now();
		$imported = 0;
		$replaced = 0;
		$forkedAside = 0;

		$this->db->beginTransaction();
		try {
			foreach ($pack['procedures'] as $incoming) {
				$current = $this->procedures->findByCode($incoming['code']);
				if ($current !== null) {
					$currentId = (int)$current->getId();
					if ($this->workOrders->countForProcedure($currentId) > 0) {
						// Never mutate an in-use template: move it aside
						// under a fork code, deactivated, then recreate the
						// canonical code fresh.
						$current->setCode($this->nextForkCode($incoming['code']));
						$current->setActive(false);
						$current->setUpdatedAt($now);
						$this->procedures->update($current);
						$forkedAside++;
					} else {
						$this->items->deleteForProcedure($currentId);
						$qb = $this->db->getQueryBuilder();
						$qb->delete(ProcedureMapper::TABLE)
							->where($qb->expr()->eq('id', $qb->createNamedParameter($currentId, \PDO::PARAM_INT)));
						$qb->executeStatement();
						$replaced++;
					}
				}
				$this->insertProcedure(
					$incoming['code'],
					[
						'title' => $incoming['title'],
						'vertical' => $pack['vertical'],
						'locale' => $pack['locale'],
						'active' => true,
					],
					$incoming['items'],
					$pack['packCode'],
					$pack['version'],
					$uid,
					$now,
				);
				$imported++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		return [
			'packCode' => $pack['packCode'],
			'imported' => $imported,
			'replaced' => $replaced,
			'forkedAside' => $forkedAside,
		];
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array{title: string, vertical: ?string, locale: string, active: bool} $header
	 * @param list<array{code: string, label: string, required: bool, sortOrder: int,
	 *               showIfItemCode: ?string, showIfResult: ?string}> $items
	 */
	private function insertProcedure(string $code, array $header, array $items, ?string $sourcePack, int $version, string $uid, int $now): Procedure
	{
		$procedure = new Procedure();
		$procedure->setCode($code);
		$procedure->setTitle($header['title']);
		$procedure->setVertical($header['vertical']);
		$procedure->setLocale($header['locale']);
		$procedure->setVersion($version);
		$procedure->setActive($header['active']);
		$procedure->setSourcePack($sourcePack);
		$procedure->setCreatedAt($now);
		$procedure->setUpdatedAt($now);
		$procedure->setCreatedBy($uid);
		$procedure = $this->procedures->insert($procedure);
		$this->insertItems((int)$procedure->getId(), $items);
		return $procedure;
	}

	/**
	 * @param list<array{code: string, label: string, required: bool, sortOrder: int,
	 *               showIfItemCode: ?string, showIfResult: ?string}> $items
	 */
	private function insertItems(int $procedureId, array $items): void
	{
		foreach ($items as $item) {
			$row = new ProcItem();
			$row->setProcedureId($procedureId);
			$row->setCode($item['code']);
			$row->setLabel($item['label']);
			$row->setRequired($item['required']);
			$row->setSortOrder($item['sortOrder']);
			$row->setShowIfItemCode($item['showIfItemCode']);
			$row->setShowIfResult($item['showIfResult']);
			$this->items->insert($row);
		}
	}

	/**
	 * First free `-vN` suffix for a code, N ≥ 2.
	 */
	private function nextForkCode(string $baseCode): string
	{
		$base = preg_replace('/-v\d+$/', '', $baseCode) ?? $baseCode;
		for ($n = 2; $n < self::MAX_FORK_PROBES; $n++) {
			$candidate = $base . '-v' . $n;
			if (mb_strlen($candidate) > 64) {
				$base = mb_substr($base, 0, 64 - mb_strlen('-v' . $n));
				$candidate = $base . '-v' . $n;
			}
			if ($this->procedures->findByCode($candidate) === null) {
				return $candidate;
			}
		}
		throw new ConflictException('code_exists', 'No free fork code available for ' . $baseCode . '.');
	}

	/**
	 * Validate body items via the pack item rules + show_if semantics.
	 *
	 * @param array<string, mixed> $body
	 * @return list<array{code: string, label: string, required: bool, sortOrder: int,
	 *               showIfItemCode: ?string, showIfResult: ?string}>
	 */
	private function validatedItems(array $body): array
	{
		$items = $body['items'] ?? null;
		if (!is_array($items) || $items === [] || !array_is_list($items)) {
			throw new ValidationException('validation_failed', 'items must be a non-empty list.', [
				['field' => 'items', 'code' => 'required'],
			]);
		}
		if (count($items) > PackSchema::MAX_ITEMS) {
			throw new ValidationException('pack_too_large', 'A procedure may contain at most ' . PackSchema::MAX_ITEMS . ' items.');
		}

		$normalized = [];
		$seen = [];
		foreach ($items as $index => $item) {
			if (!is_array($item)) {
				throw new ValidationException('validation_failed', 'items[' . $index . '] must be an object.', [
					['field' => 'items[' . $index . ']', 'code' => 'invalid_type'],
				]);
			}
			$code = $this->validator->catalogCode($item);
			if (isset($seen[$code])) {
				throw new ValidationException('validation_failed', 'Duplicate item code "' . $code . '".', [
					['field' => 'items[' . $index . '].code', 'code' => 'duplicate'],
				]);
			}
			$seen[$code] = true;

			$label = $this->validator->requiredString($item, 'label', 'label_required', 255, 'label_too_long');
			$required = $this->validator->boolOrDefault($item, 'required', true);
			$sortOrder = $item['sortOrder'] ?? count($normalized);
			if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 100000) {
				throw new ValidationException('validation_failed', 'sortOrder must be a non-negative integer.', [
					['field' => 'items[' . $index . '].sortOrder', 'code' => 'invalid_type'],
				]);
			}
			$showIfCode = $this->validator->boundedOptionalString($item, 'showIfItemCode', 64, 'code_too_long');
			$showIfResult = $this->validator->boundedOptionalString($item, 'showIfResult', 16, 'result_too_long');

			$normalized[] = [
				'code' => $code,
				'label' => $label,
				'required' => $required,
				'sortOrder' => $sortOrder,
				'showIfItemCode' => $showIfCode,
				'showIfResult' => $showIfResult,
			];
		}

		usort($normalized, static fn (array $a, array $b): int => [$a['sortOrder'], $a['code']] <=> [$b['sortOrder'], $b['code']]);
		$this->showIf->validateRules($normalized);
		return $normalized;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{title: string, vertical: ?string, locale: string, active: bool}
	 */
	private function validatedHeader(array $body): array
	{
		$locale = $this->validator->boundedOptionalString($body, 'locale', 8, 'locale_too_long') ?? 'en';
		if (!preg_match('/^[a-z]{2}(?:_[A-Za-z]{2})?$/', $locale)) {
			throw new ValidationException('validation_failed', 'locale must look like "de" or "de_DE".', [
				['field' => 'locale', 'code' => 'invalid_value'],
			]);
		}
		return [
			'title' => $this->validator->requiredString($body, 'title', 'title_required', 255, 'title_too_long'),
			'vertical' => $this->validatedVertical($body),
			'locale' => $locale,
			'active' => $this->validator->boolOrDefault($body, 'active', true),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function validatedVertical(array $body): ?string
	{
		$vertical = $this->validator->boundedOptionalString($body, 'vertical', 32, 'vertical_too_long');
		if ($vertical !== null && !preg_match('/^[a-z0-9_-]{1,32}$/', $vertical)) {
			throw new ValidationException('validation_failed', 'vertical must be a short lowercase identifier.', [
				['field' => 'vertical', 'code' => 'invalid_value'],
			]);
		}
		return $vertical;
	}

	/**
	 * @return list<array{code: string, label: string, required: bool, sortOrder: int,
	 *               showIfItemCode: ?string, showIfResult: ?string}>
	 */
	private function itemsAsArrays(int $procedureId): array
	{
		$out = [];
		foreach ($this->items->findByProcedure($procedureId) as $item) {
			$out[] = [
				'code' => $item->getCode(),
				'label' => $item->getLabel(),
				'required' => $item->getRequired(),
				'sortOrder' => $item->getSortOrder(),
				'showIfItemCode' => $item->getShowIfItemCode(),
				'showIfResult' => $item->getShowIfResult(),
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toDetail(Procedure $procedure): array
	{
		$row = $procedure->toApi();
		$row['inUse'] = $this->workOrders->countForProcedure((int)$procedure->getId()) > 0;
		$row['items'] = array_map(
			static fn (ProcItem $item) => $item->toApi(),
			$this->items->findByProcedure((int)$procedure->getId()),
		);
		return $row;
	}
}
