<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IConfig;

/**
 * Org-level enforcement policies (CORE §6 R5–R7, §10.3, §10.5).
 *
 * Stored as app config values; invalid stored values silently fall back to
 * the documented default so a broken config row can never brick the app.
 * Writes are validated (422) — bad values never reach storage.
 */
class PolicyService
{
	public const KEY_CHECKLIST_DONE_POLICY = 'checklist_done_policy';
	public const KEY_CHECKLIST_MIN_PERCENT = 'checklist_min_percent';
	public const KEY_SKILLS_ENFORCEMENT = 'skills_enforcement';
	public const KEY_CAPACITY_ENFORCEMENT = 'capacity_enforcement';
	public const KEY_CAPACITY_WARN_RATIO = 'capacity_warn_ratio';
	public const KEY_REQUIRE_EQUIPMENT_ON_WO = 'require_equipment_on_wo';
	public const KEY_FAILURE_CODE_ON_CORRECTIVE = 'failure_code_on_corrective';

	public const ENFORCEMENT_OFF = 'off';
	public const ENFORCEMENT_WARN = 'warn';
	public const ENFORCEMENT_BLOCK = 'block';
	public const ENFORCEMENTS = [self::ENFORCEMENT_OFF, self::ENFORCEMENT_WARN, self::ENFORCEMENT_BLOCK];

	/** W6-R3: off | warn | required (default warn). */
	public const FAILURE_CODE_OFF = 'off';
	public const FAILURE_CODE_WARN = 'warn';
	public const FAILURE_CODE_REQUIRED = 'required';
	public const FAILURE_CODE_POLICIES = [
		self::FAILURE_CODE_OFF,
		self::FAILURE_CODE_WARN,
		self::FAILURE_CODE_REQUIRED,
	];

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function checklistDonePolicy(): string
	{
		$value = $this->config->getAppValue(Application::APP_ID, self::KEY_CHECKLIST_DONE_POLICY, ChecklistPolicy::POLICY_ALL_REQUIRED);
		return in_array($value, ChecklistPolicy::POLICIES, true) ? $value : ChecklistPolicy::POLICY_ALL_REQUIRED;
	}

	public function checklistMinPercent(): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_CHECKLIST_MIN_PERCENT, (string)ChecklistPolicy::DEFAULT_MIN_PERCENT);
		if (!preg_match('/^\d{1,3}$/', $raw)) {
			return ChecklistPolicy::DEFAULT_MIN_PERCENT;
		}
		return max(0, min(100, (int)$raw));
	}

	/** R6 — default `warn`. */
	public function skillsEnforcement(): string
	{
		return $this->enforcement(self::KEY_SKILLS_ENFORCEMENT, self::ENFORCEMENT_WARN);
	}

	/** §10.5 — default `warn`. */
	public function capacityEnforcement(): string
	{
		return $this->enforcement(self::KEY_CAPACITY_ENFORCEMENT, self::ENFORCEMENT_WARN);
	}

	public function capacityWarnRatio(): float
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_CAPACITY_WARN_RATIO, '1.0');
		if (!preg_match('/^\d(\.\d{1,2})?$/', $raw)) {
			return CapacityCalculator::DEFAULT_WARN_RATIO;
		}
		$value = (float)$raw;
		return ($value > 0.0 && $value <= 9.99) ? $value : CapacityCalculator::DEFAULT_WARN_RATIO;
	}

	/** UC-BF — default on. */
	public function requireEquipmentOnWo(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_REQUIRE_EQUIPMENT_ON_WO, '1') === '1';
	}

	/** W6-R3 — default `warn`. */
	public function failureCodeOnCorrective(): string
	{
		$value = $this->config->getAppValue(Application::APP_ID, self::KEY_FAILURE_CODE_ON_CORRECTIVE, self::FAILURE_CODE_WARN);
		return in_array($value, self::FAILURE_CODE_POLICIES, true) ? $value : self::FAILURE_CODE_WARN;
	}

	/**
	 * @return array<string, mixed> full policy snapshot for the settings UI
	 */
	public function snapshot(): array
	{
		return [
			'checklistDonePolicy' => $this->checklistDonePolicy(),
			'checklistMinPercent' => $this->checklistMinPercent(),
			'skillsEnforcement' => $this->skillsEnforcement(),
			'capacityEnforcement' => $this->capacityEnforcement(),
			'capacityWarnRatio' => $this->capacityWarnRatio(),
			'requireEquipmentOnWo' => $this->requireEquipmentOnWo(),
			'failureCodeOnCorrective' => $this->failureCodeOnCorrective(),
		];
	}

	/**
	 * Validate and persist a partial policy update.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> fresh snapshot
	 */
	public function save(array $body): array
	{
		if (array_key_exists('checklistDonePolicy', $body)) {
			$value = $body['checklistDonePolicy'];
			if (!is_string($value) || !in_array($value, ChecklistPolicy::POLICIES, true)) {
				throw new ValidationException('validation_failed', 'checklistDonePolicy must be all_required, percent, or off.', [
					['field' => 'checklistDonePolicy', 'code' => 'invalid_value'],
				]);
			}
			$this->config->setAppValue(Application::APP_ID, self::KEY_CHECKLIST_DONE_POLICY, $value);
		}
		if (array_key_exists('checklistMinPercent', $body)) {
			$value = $body['checklistMinPercent'];
			if (!is_int($value) || $value < 0 || $value > 100) {
				throw new ValidationException('validation_failed', 'checklistMinPercent must be an integer between 0 and 100.', [
					['field' => 'checklistMinPercent', 'code' => 'invalid_value'],
				]);
			}
			$this->config->setAppValue(Application::APP_ID, self::KEY_CHECKLIST_MIN_PERCENT, (string)$value);
		}
		foreach ([
			'skillsEnforcement' => self::KEY_SKILLS_ENFORCEMENT,
			'capacityEnforcement' => self::KEY_CAPACITY_ENFORCEMENT,
		] as $field => $key) {
			if (array_key_exists($field, $body)) {
				$value = $body[$field];
				if (!is_string($value) || !in_array($value, self::ENFORCEMENTS, true)) {
					throw new ValidationException('validation_failed', $field . ' must be off, warn, or block.', [
						['field' => $field, 'code' => 'invalid_value'],
					]);
				}
				$this->config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		if (array_key_exists('capacityWarnRatio', $body)) {
			$value = $body['capacityWarnRatio'];
			if ((!is_float($value) && !is_int($value)) || $value <= 0 || $value > 9.99) {
				throw new ValidationException('validation_failed', 'capacityWarnRatio must be a number between 0.01 and 9.99.', [
					['field' => 'capacityWarnRatio', 'code' => 'invalid_value'],
				]);
			}
			$this->config->setAppValue(Application::APP_ID, self::KEY_CAPACITY_WARN_RATIO, number_format((float)$value, 2, '.', ''));
		}
		if (array_key_exists('requireEquipmentOnWo', $body)) {
			$value = $body['requireEquipmentOnWo'];
			if (!is_bool($value)) {
				throw new ValidationException('validation_failed', 'requireEquipmentOnWo must be a boolean.', [
					['field' => 'requireEquipmentOnWo', 'code' => 'invalid_type'],
				]);
			}
			$this->config->setAppValue(Application::APP_ID, self::KEY_REQUIRE_EQUIPMENT_ON_WO, $value ? '1' : '0');
		}
		if (array_key_exists('failureCodeOnCorrective', $body)) {
			$value = $body['failureCodeOnCorrective'];
			if (!is_string($value) || !in_array($value, self::FAILURE_CODE_POLICIES, true)) {
				throw new ValidationException('validation_failed', 'failureCodeOnCorrective must be off, warn, or required.', [
					['field' => 'failureCodeOnCorrective', 'code' => 'invalid_value'],
				]);
			}
			$this->config->setAppValue(Application::APP_ID, self::KEY_FAILURE_CODE_ON_CORRECTIVE, $value);
		}
		return $this->snapshot();
	}

	private function enforcement(string $key, string $default): string
	{
		$value = $this->config->getAppValue(Application::APP_ID, $key, $default);
		return in_array($value, self::ENFORCEMENTS, true) ? $value : $default;
	}
}
