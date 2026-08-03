<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * Pure W7 Done validation for inspection work orders (CORE §21 AC-W7-3/4).
 */
final class InspectionClosePolicy
{
	/**
	 * @param array<string, mixed> $body
	 * @return array{
	 *   result: string,
	 *   inspectorName: string,
	 *   inspectorNote: ?string,
	 *   defects: list<array{code: string, body: string, photoFileId: ?int}>
	 * }
	 */
	public function validateAndNormalize(WorkOrder $wo, array $body, bool $resultRequired): array
	{
		if ($wo->getKind() !== WorkOrder::KIND_INSPECTION) {
			throw new \InvalidArgumentException('not_inspection');
		}

		$resultRaw = $body['result'] ?? null;
		$result = is_string($resultRaw) ? trim($resultRaw) : '';
		if ($resultRequired && ($result === '' || !in_array($result, WorkOrder::RESULTS, true))) {
			throw new ValidationException('inspection_result_required', 'An inspection result (pass, fail, or conditional) is required.', [
				['field' => 'result', 'code' => 'inspection_result_required'],
			]);
		}
		if ($result !== '' && !in_array($result, WorkOrder::RESULTS, true)) {
			throw new ValidationException('validation_failed', 'result must be pass, fail, or conditional.', [
				['field' => 'result', 'code' => 'invalid_value'],
			]);
		}

		$inspector = '';
		if (array_key_exists('inspectorName', $body)) {
			$inspector = is_string($body['inspectorName']) ? trim($body['inspectorName']) : '';
		} elseif (array_key_exists('inspector_name', $body)) {
			$inspector = is_string($body['inspector_name']) ? trim($body['inspector_name']) : '';
		}
		if ($resultRequired && $inspector === '') {
			throw new ValidationException('validation_failed', 'inspectorName is required to close an inspection.', [
				['field' => 'inspectorName', 'code' => 'required'],
			]);
		}
		if (mb_strlen($inspector) > 128) {
			throw new ValidationException('validation_failed', 'inspectorName is too long.', [
				['field' => 'inspectorName', 'code' => 'too_long'],
			]);
		}

		$note = null;
		if (array_key_exists('inspectorNote', $body) && is_string($body['inspectorNote'])) {
			$note = trim($body['inspectorNote']);
		} elseif (array_key_exists('inspector_note', $body) && is_string($body['inspector_note'])) {
			$note = trim($body['inspector_note']);
		}
		if ($note === '') {
			$note = null;
		}
		if ($note !== null && mb_strlen($note) > 512) {
			throw new ValidationException('validation_failed', 'inspectorNote is too long.', [
				['field' => 'inspectorNote', 'code' => 'too_long'],
			]);
		}

		$defects = $this->normalizeDefects($body['defects'] ?? []);
		if ($result !== '' && $result !== WorkOrder::RESULT_PASS && $defects === []) {
			throw new ValidationException('inspection_defects_required', 'At least one defect is required when the result is not pass.', [
				['field' => 'defects', 'code' => 'inspection_defects_required'],
			]);
		}

		return [
			'result' => $result,
			'inspectorName' => $inspector,
			'inspectorNote' => $note,
			'defects' => $defects,
		];
	}

	/**
	 * @param mixed $raw
	 * @return list<array{code: string, body: string, photoFileId: ?int}>
	 */
	private function normalizeDefects(mixed $raw): array
	{
		if ($raw === null || $raw === []) {
			return [];
		}
		if (!is_array($raw)) {
			throw new ValidationException('validation_failed', 'defects must be an array.', [
				['field' => 'defects', 'code' => 'invalid_type'],
			]);
		}
		$out = [];
		foreach ($raw as $i => $row) {
			if (!is_array($row)) {
				throw new ValidationException('validation_failed', 'Each defect must be an object.', [
					['field' => 'defects[' . $i . ']', 'code' => 'invalid_type'],
				]);
			}
			$code = isset($row['code']) && is_string($row['code']) ? trim($row['code']) : '';
			$body = isset($row['body']) && is_string($row['body']) ? trim($row['body']) : '';
			if ($code === '' || $body === '') {
				throw new ValidationException('validation_failed', 'Each defect needs a code and body.', [
					['field' => 'defects[' . $i . ']', 'code' => 'required'],
				]);
			}
			if (mb_strlen($code) > 64) {
				throw new ValidationException('validation_failed', 'Defect code is too long.', [
					['field' => 'defects[' . $i . '].code', 'code' => 'too_long'],
				]);
			}
			if (mb_strlen($body) > 2000) {
				throw new ValidationException('validation_failed', 'Defect body is too long.', [
					['field' => 'defects[' . $i . '].body', 'code' => 'too_long'],
				]);
			}
			$photo = null;
			if (isset($row['photoFileId']) && $row['photoFileId'] !== null && $row['photoFileId'] !== '') {
				$photo = (int)$row['photoFileId'];
				if ($photo <= 0) {
					$photo = null;
				}
			}
			$out[] = ['code' => $code, 'body' => $body, 'photoFileId' => $photo];
		}
		return $out;
	}
}
