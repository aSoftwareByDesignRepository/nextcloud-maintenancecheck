<?php

declare(strict_types=1);

/**
 * MaintenanceCheck wiring for the shared transfer engine. The access door
 * and office-role grants are app-local appconfig (no platform restriction
 * mirror → no mirrored keys). Validators mirror the exact rules
 * ConfigController / PolicyService / InventoryFlangeService enforce on the
 * settings API — import grants no bypass (NN-08).
 */

namespace OCA\MaintenanceCheck\ConfigTransfer;

use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\ChecklistPolicy;
use OCA\MaintenanceCheck\Service\InventoryFlangeService;
use OCA\MaintenanceCheck\Service\PolicyService;
use OCA\MaintenanceCheck\Service\UpgradeBackupService;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	public function __construct(
		private readonly AccessControlService $access,
		private readonly UpgradeBackupService $backups,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$this->access->requireAppAdmin($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value;
		}
	}

	private function normalize(string $key, string $value): string
	{
		return match ($key) {
			AccessControlService::KEY_ACCESS_RESTRICTION,
			InventoryFlangeService::KEY_F6_ENABLED,
			PolicyService::KEY_REQUIRE_EQUIPMENT_ON_WO,
			PolicyService::KEY_INSPECTION_RESULT_REQUIRED,
			PolicyService::KEY_FAIL_BLOCKS_ROLL => $this->normalizeBool($value),

			AccessControlService::KEY_APP_ADMINS,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_OFFICE_GROUP_IDS => $this->normalizeIdList($value),

			PolicyService::KEY_CHECKLIST_DONE_POLICY => in_array($value, ChecklistPolicy::POLICIES, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_CHECKLIST_POLICY'),

			PolicyService::KEY_CHECKLIST_MIN_PERCENT => $this->normalizePercent($value),

			PolicyService::KEY_SKILLS_ENFORCEMENT,
			PolicyService::KEY_CAPACITY_ENFORCEMENT => in_array($value, PolicyService::ENFORCEMENTS, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_ENFORCEMENT'),

			PolicyService::KEY_CAPACITY_WARN_RATIO => $this->normalizeWarnRatio($value),

			PolicyService::KEY_FAILURE_CODE_ON_CORRECTIVE => in_array($value, PolicyService::FAILURE_CODE_POLICIES, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_FAILURE_CODE_POLICY'),

			PolicyService::KEY_DEFECT_FOLLOW_UP => in_array($value, PolicyService::DEFECT_FOLLOW_POLICIES, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_DEFECT_FOLLOW_UP'),

			InventoryFlangeService::KEY_LOCATION_POLICY => in_array($value, [
				InventoryFlangeService::POLICY_EXPLICIT,
				InventoryFlangeService::POLICY_EQUIPMENT_DEFAULT,
				InventoryFlangeService::POLICY_FAIL_AMBIGUOUS,
			], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_LOCATION_POLICY'),

			InventoryFlangeService::KEY_EXPLICIT_LOCATION_ID => $value === '' || ctype_digit($value)
				? $value
				: throw new \InvalidArgumentException('INVALID_LOCATION_ID'),

			'mn_soft_link_ui' => $this->normalizeBool($value),

			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	/**
	 * Checkbox-style flags: the app stores '1'/'0'; accept the literal
	 * truthy/falsy spellings form encoders produce and canonicalise.
	 */
	private function normalizeBool(string $value): string
	{
		return match ($value) {
			'1', 'true' => '1',
			'0', 'false' => '0',
			default => throw new \InvalidArgumentException('INVALID_BOOL'),
		};
	}

	private function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (is_string($id)) {
				$id = trim($id);
				if ($id !== '') {
					$clean[] = $id;
				}
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	private function normalizePercent(string $value): string
	{
		if (!preg_match('/^\d{1,3}$/', $value)) {
			throw new \InvalidArgumentException('INVALID_PERCENT');
		}
		$percent = (int)$value;
		if ($percent < 0 || $percent > 100) {
			throw new \InvalidArgumentException('INVALID_PERCENT');
		}
		return (string)$percent;
	}

	private function normalizeWarnRatio(string $value): string
	{
		if (!is_numeric($value)) {
			throw new \InvalidArgumentException('INVALID_WARN_RATIO');
		}
		$ratio = (float)$value;
		if ($ratio <= 0.0 || $ratio > 9.99) {
			throw new \InvalidArgumentException('INVALID_WARN_RATIO');
		}
		return number_format($ratio, 2, '.', '');
	}

	public function validateMirrored(array $keyValues): ?string
	{
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		return [
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
			AccessControlService::KEY_APP_ADMINS,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_OFFICE_GROUP_IDS,
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match ($key) {
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
			AccessControlService::KEY_APP_ADMINS => 'access',
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_OFFICE_GROUP_IDS => 'roles',
			InventoryFlangeService::KEY_F6_ENABLED,
			InventoryFlangeService::KEY_LOCATION_POLICY,
			InventoryFlangeService::KEY_EXPLICIT_LOCATION_ID => 'inventory',
			PolicyService::KEY_CHECKLIST_DONE_POLICY,
			PolicyService::KEY_CHECKLIST_MIN_PERCENT,
			PolicyService::KEY_SKILLS_ENFORCEMENT,
			PolicyService::KEY_CAPACITY_ENFORCEMENT,
			PolicyService::KEY_CAPACITY_WARN_RATIO,
			PolicyService::KEY_REQUIRE_EQUIPMENT_ON_WO,
			PolicyService::KEY_FAILURE_CODE_ON_CORRECTIVE,
			PolicyService::KEY_DEFECT_FOLLOW_UP,
			PolicyService::KEY_INSPECTION_RESULT_REQUIRED,
			PolicyService::KEY_FAIL_BLOCKS_ROLL => 'policies',
			default => null,
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		$this->logger->info('maintenancecheck: config transfer ' . $action, [
			'app' => 'maintenancecheck',
			'actor' => $actor === '' ? 'cli' : $actor,
			'details' => $details,
		]);
	}
}
