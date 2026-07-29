<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\KitTemplate;
use OCA\MaintenanceCheck\Db\KitTemplateMapper;
use OCA\MaintenanceCheck\Db\KitTplLine;
use OCA\MaintenanceCheck\Db\KitTplLineMapper;
use OCA\MaintenanceCheck\Db\WoKit;
use OCA\MaintenanceCheck\Db\WoKitLine;
use OCA\MaintenanceCheck\Db\WoKitLineMapper;
use OCA\MaintenanceCheck\Db\WoKitMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * W2 job kits (CORE §10.2, §14.1): reusable templates plus per-WO instances
 * with packing progress. The instance is a *copy* of the template so later
 * template edits never change a packed van.
 *
 * Structure (attach / add / remove lines): office. Packing qty: assigned tech
 * / pool / office under a row lock (pack races).
 */
class KitService
{
	public const MAX_LINES = 200;
	public const MAX_QTY = 100000;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly KitTemplateMapper $templates,
		private readonly KitTplLineMapper $templateLines,
		private readonly WoKitMapper $woKits,
		private readonly WoKitLineMapper $woKitLines,
		private readonly WorkOrderMapper $workOrders,
		private readonly KitReadiness $readiness,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly WorkOrderAccessPolicy $woAccess,
	) {
	}

	// ── Templates ───────────────────────────────────────────────────────

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listTemplates(?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$result = $this->templates->listAll($page['limit'], $page['offset']);
		return [
			'data' => array_map(fn (KitTemplate $t) => $this->templateDetail($t), $result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getTemplate(int $id): array
	{
		return $this->templateDetail($this->templates->findById($id));
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function createTemplate(string $uid, array $body): array
	{
		$code = $this->validator->catalogCode($body);
		$name = $this->validator->catalogName($body);
		$description = $this->validator->boundedOptionalString($body, 'description', 512, 'description_too_long');
		$lines = $this->validatedLines($body);

		if ($this->templates->findByCode($code) !== null) {
			throw new ConflictException('code_exists', 'A kit template with this code already exists.');
		}

		$now = $this->clock->now();
		$template = new KitTemplate();
		$template->setCode($code);
		$template->setName($name);
		$template->setDescription($description);
		$template->setActive($this->validator->boolOrDefault($body, 'active', true));
		$template->setCreatedAt($now);
		$template->setUpdatedAt($now);
		$template->setCreatedBy($uid);

		$this->db->beginTransaction();
		try {
			$template = $this->templates->insert($template);
			$this->insertTemplateLines((int)$template->getId(), $lines);
			$this->db->commit();
		} catch (\OCP\DB\Exception $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($this->templates->findByCode($code) !== null) {
				throw new ConflictException('code_exists', 'A kit template with this code already exists.');
			}
			throw $e;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->templateDetail($template);
	}

	/**
	 * Template edits never touch existing WO kit instances (copies).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function updateTemplate(string $uid, int $id, array $body): array
	{
		$template = $this->templates->findById($id);
		if (array_key_exists('name', $body)) {
			$template->setName($this->validator->catalogName($body));
		}
		if (array_key_exists('description', $body)) {
			$template->setDescription($this->validator->boundedOptionalString($body, 'description', 512, 'description_too_long'));
		}
		if (array_key_exists('active', $body)) {
			$template->setActive($this->validator->boolOrDefault($body, 'active', $template->getActive()));
		}
		$newLines = array_key_exists('lines', $body) ? $this->validatedLines($body) : null;
		$template->setUpdatedAt($this->clock->now());

		$this->db->beginTransaction();
		try {
			$this->templates->update($template);
			if ($newLines !== null) {
				$this->templateLines->deleteForTemplate($id);
				$this->insertTemplateLines($id, $newLines);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->templateDetail($template);
	}

	// ── WO kit instances ────────────────────────────────────────────────

	/**
	 * Attach a kit to a WO — from a template or empty (ad-hoc). One kit per
	 * WO; re-attaching replaces an *unpacked* kit and 409s once packing
	 * started.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function attachKit(string $uid, int $workOrderId, array $body): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}

		$templateId = null;
		$templateLineRows = [];
		if (array_key_exists('templateId', $body) && $body['templateId'] !== null) {
			$templateId = $this->validator->intOrThrow($body, 'templateId');
			$template = $this->templates->findById($templateId);
			if (!$template->getActive()) {
				throw new ValidationException('validation_failed', 'This kit template is deactivated.', [
					['field' => 'templateId', 'code' => 'inactive'],
				]);
			}
			$templateLineRows = $this->templateLines->findByTemplate($templateId);
		}

		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			// Serialise on the WO row: two concurrent attaches must not
			// produce two kits (unique index is the final arbiter anyway).
			$this->workOrders->lockRow($workOrderId);
			$existing = $this->woKits->findByWorkOrder($workOrderId);
			if ($existing !== null) {
				foreach ($this->woKitLines->findByKit((int)$existing->getId()) as $line) {
					if ($line->getQtyPacked() > 0) {
						$this->db->rollBack();
						throw new ConflictException('kit_packing_started', 'Packing already started; the kit cannot be replaced.');
					}
				}
				$this->woKitLines->deleteForKit((int)$existing->getId());
				$this->woKits->delete($existing);
			}

			$kit = new WoKit();
			$kit->setWorkOrderId($workOrderId);
			$kit->setTemplateId($templateId);
			$kit->setCreatedAt($now);
			$kit->setUpdatedAt($now);
			$kit->setCreatedBy($uid);
			$kit = $this->woKits->insert($kit);

			foreach ($templateLineRows as $index => $templateLine) {
				$line = new WoKitLine();
				$line->setWoKitId((int)$kit->getId());
				$line->setLineType($templateLine->getLineType());
				$line->setSku($templateLine->getSku());
				$line->setLabel($templateLine->getLabel());
				$line->setQtyRequired($templateLine->getQtyRequired());
				$line->setQtyPacked(0);
				$line->setOptional($templateLine->getOptional());
				$line->setSortOrder($templateLine->getSortOrder() !== 0 ? $templateLine->getSortOrder() : $index);
				$this->woKitLines->insert($line);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->kitDetail($workOrderId);
	}

	/**
	 * Add / update / remove a single instance line (office curates, tech
	 * packs).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function addLine(string $uid, int $workOrderId, array $body): array
	{
		$this->assertOpenWo($workOrderId);
		$kit = $this->woKits->findByWorkOrder($workOrderId);
		if ($kit === null) {
			throw new NotFoundException();
		}
		$validated = $this->validatedLine($body, 0);
		if (count($this->woKitLines->findByKit((int)$kit->getId())) >= self::MAX_LINES) {
			throw new ValidationException('validation_failed', 'A kit may contain at most ' . self::MAX_LINES . ' lines.', [
				['field' => 'lines', 'code' => 'too_many'],
			]);
		}

		$line = new WoKitLine();
		$line->setWoKitId((int)$kit->getId());
		$line->setLineType($validated['lineType']);
		$line->setSku($validated['sku']);
		$line->setLabel($validated['label']);
		$line->setQtyRequired($validated['qtyRequired']);
		$line->setQtyPacked(0);
		$line->setOptional($validated['optional']);
		$line->setSortOrder($validated['sortOrder']);
		$this->woKitLines->insert($line);
		return $this->kitDetail($workOrderId);
	}

	/**
	 * Pack progress on one line (tech). Serialised under FOR UPDATE on the
	 * kit line so concurrent pack posts cannot lose qty updates.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function packLine(string $uid, int $workOrderId, int $lineId, array $body): array
	{
		$qtyPacked = $this->validator->intOrThrow($body, 'qtyPacked');
		if ($qtyPacked < 0 || $qtyPacked > self::MAX_QTY) {
			throw new ValidationException('validation_failed', 'qtyPacked must be between 0 and ' . self::MAX_QTY . '.', [
				['field' => 'qtyPacked', 'code' => 'out_of_range'],
			]);
		}

		$this->db->beginTransaction();
		try {
			$this->assertOpenWo($workOrderId);
			$wo = $this->workOrders->findById($workOrderId);
			$this->woAccess->assertCanExecute($uid, $wo);
			$kit = $this->woKits->findByWorkOrder($workOrderId);
			if ($kit === null) {
				throw new NotFoundException();
			}
			if (!$this->woKitLines->lockRow($lineId)) {
				throw new NotFoundException();
			}
			$line = $this->woKitLines->findById($lineId);
			if ($line->getWoKitId() !== (int)$kit->getId()) {
				throw new NotFoundException();
			}
			$line->setQtyPacked($qtyPacked);
			$this->woKitLines->update($line);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->kitDetail($workOrderId);
	}

	public function removeLine(string $uid, int $workOrderId, int $lineId): array
	{
		$this->assertOpenWo($workOrderId);
		$kit = $this->woKits->findByWorkOrder($workOrderId);
		if ($kit === null) {
			throw new NotFoundException();
		}
		$line = $this->woKitLines->findById($lineId);
		if ($line->getWoKitId() !== (int)$kit->getId()) {
			throw new NotFoundException();
		}
		$this->woKitLines->delete($line);
		return $this->kitDetail($workOrderId);
	}

	// ── Gate helpers ────────────────────────────────────────────────────

	/**
	 * CORE §10.2 kit readiness for the planned→ready gate. A WO without a
	 * kit counts as ready (nothing to pack).
	 *
	 * @return array{hasKit: bool, ready: bool,
	 *               missing: list<array{label: string, qtyRequired: int, qtyPacked: int}>}
	 */
	public function readinessFor(int $workOrderId): array
	{
		$kit = $this->woKits->findByWorkOrder($workOrderId);
		if ($kit === null) {
			return ['hasKit' => false, 'ready' => true, 'missing' => []];
		}
		$lines = [];
		foreach ($this->woKitLines->findByKit((int)$kit->getId()) as $line) {
			$lines[] = [
				'label' => $line->getLabel(),
				'qtyRequired' => $line->getQtyRequired(),
				'qtyPacked' => $line->getQtyPacked(),
				'optional' => $line->getOptional(),
			];
		}
		$assessed = $this->readiness->assess($lines);
		return ['hasKit' => true, 'ready' => $assessed['ready'], 'missing' => $assessed['missing']];
	}

	/**
	 * Kit payload for the WO detail (null when none attached).
	 *
	 * @return array<string, mixed>|null
	 */
	public function kitFor(int $workOrderId): ?array
	{
		if ($this->woKits->findByWorkOrder($workOrderId) === null) {
			return null;
		}
		return $this->kitDetail($workOrderId);
	}

	/**
	 * SKU lines for the InventoryCheck flange (issue-on-close).
	 *
	 * @return list<array{sku: string, label: string, qty: int}>
	 */
	public function skuLinesFor(int $workOrderId): array
	{
		$kit = $this->woKits->findByWorkOrder($workOrderId);
		if ($kit === null) {
			return [];
		}
		$out = [];
		foreach ($this->woKitLines->findByKit((int)$kit->getId()) as $line) {
			$sku = $line->getSku();
			if ($sku !== null && $sku !== '' && $line->getLineType() === KitTplLine::TYPE_PART && $line->getQtyPacked() > 0) {
				$out[] = ['sku' => $sku, 'label' => $line->getLabel(), 'qty' => $line->getQtyPacked()];
			}
		}
		return $out;
	}

	// ── Internals ───────────────────────────────────────────────────────

	private function assertOpenWo(int $workOrderId): WorkOrder
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}
		return $wo;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function templateDetail(KitTemplate $template): array
	{
		$row = $template->toApi();
		$row['lines'] = array_map(
			static fn (KitTplLine $line) => $line->toApi(),
			$this->templateLines->findByTemplate((int)$template->getId()),
		);
		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function kitDetail(int $workOrderId): array
	{
		$kit = $this->woKits->findByWorkOrder($workOrderId);
		if ($kit === null) {
			throw new NotFoundException();
		}
		$row = $kit->toApi();
		$row['lines'] = array_map(
			static fn (WoKitLine $line) => $line->toApi(),
			$this->woKitLines->findByKit((int)$kit->getId()),
		);
		$readiness = $this->readinessFor($workOrderId);
		$row['ready'] = $readiness['ready'];
		$row['missing'] = $readiness['missing'];
		return $row;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return list<array{lineType: string, sku: ?string, label: string,
	 *               qtyRequired: int, optional: bool, sortOrder: int}>
	 */
	private function validatedLines(array $body): array
	{
		$lines = $body['lines'] ?? [];
		if (!is_array($lines) || !array_is_list($lines)) {
			throw new ValidationException('validation_failed', 'lines must be a list.', [
				['field' => 'lines', 'code' => 'invalid_type'],
			]);
		}
		if (count($lines) > self::MAX_LINES) {
			throw new ValidationException('validation_failed', 'A kit may contain at most ' . self::MAX_LINES . ' lines.', [
				['field' => 'lines', 'code' => 'too_many'],
			]);
		}
		$out = [];
		foreach ($lines as $index => $line) {
			if (!is_array($line)) {
				throw new ValidationException('validation_failed', 'lines[' . $index . '] must be an object.', [
					['field' => 'lines[' . $index . ']', 'code' => 'invalid_type'],
				]);
			}
			$out[] = $this->validatedLine($line, count($out));
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $line
	 * @return array{lineType: string, sku: ?string, label: string,
	 *               qtyRequired: int, optional: bool, sortOrder: int}
	 */
	private function validatedLine(array $line, int $defaultSort): array
	{
		$lineType = $this->validator->boundedOptionalString($line, 'lineType', 8, 'line_type_too_long') ?? KitTplLine::TYPE_PART;
		if (!in_array($lineType, KitTplLine::TYPES, true)) {
			throw new ValidationException('validation_failed', 'lineType must be part or tool.', [
				['field' => 'lineType', 'code' => 'invalid_value'],
			]);
		}
		$qtyRequired = $line['qtyRequired'] ?? 1;
		if (!is_int($qtyRequired) || $qtyRequired < 1 || $qtyRequired > self::MAX_QTY) {
			throw new ValidationException('validation_failed', 'qtyRequired must be between 1 and ' . self::MAX_QTY . '.', [
				['field' => 'qtyRequired', 'code' => 'out_of_range'],
			]);
		}
		$sortOrder = $line['sortOrder'] ?? $defaultSort;
		if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 100000) {
			throw new ValidationException('validation_failed', 'sortOrder must be a non-negative integer.', [
				['field' => 'sortOrder', 'code' => 'invalid_type'],
			]);
		}
		return [
			'lineType' => $lineType,
			'sku' => $this->validator->boundedOptionalString($line, 'sku', 64, 'sku_too_long'),
			'label' => $this->validator->requiredString($line, 'label', 'label_required', 255, 'label_too_long'),
			'qtyRequired' => $qtyRequired,
			'optional' => $this->validator->boolOrDefault($line, 'optional', false),
			'sortOrder' => $sortOrder,
		];
	}

	/**
	 * @param list<array{lineType: string, sku: ?string, label: string,
	 *               qtyRequired: int, optional: bool, sortOrder: int}> $lines
	 */
	private function insertTemplateLines(int $templateId, array $lines): void
	{
		foreach ($lines as $line) {
			$row = new KitTplLine();
			$row->setTemplateId($templateId);
			$row->setLineType($line['lineType']);
			$row->setSku($line['sku']);
			$row->setLabel($line['label']);
			$row->setQtyRequired($line['qtyRequired']);
			$row->setOptional($line['optional']);
			$row->setSortOrder($line['sortOrder']);
			$this->templateLines->insert($row);
		}
	}
}
