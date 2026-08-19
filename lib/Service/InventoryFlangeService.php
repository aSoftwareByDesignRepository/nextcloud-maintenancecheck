<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * F6 MN→IV stock issue flange (CHECK-SUITE §4.4.1 / AC-S2.2 / AC-L5).
 *
 * Runs *after* WO `done` is committed. Soft-fail only — never rolls back WO.
 * Admin toggle defaults **off**.
 */
class InventoryFlangeService
{
	public const KEY_F6_ENABLED = 'f6_inventory_flange_enabled';
	public const KEY_LOCATION_POLICY = 'issue_location_policy';
	public const KEY_EXPLICIT_LOCATION_ID = 'issue_location_id';

	public const POLICY_EXPLICIT = 'explicit_location_id';
	public const POLICY_EQUIPMENT_DEFAULT = 'equipment_default_location';
	public const POLICY_FAIL_AMBIGUOUS = 'fail_if_ambiguous';

	private const FACADE = 'OCA\\InventoryCheck\\Public\\StockIssueFacade';
	private const REQUEST = 'OCA\\InventoryCheck\\Public\\StockIssueRequest';

	/**
	 * @param null|callable(string,int,list<array{sku:string,qty:int}>,string,?int):object $issueInvoker
	 *        Test seam — production DI leaves this null and uses StockIssueFacade.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly mixed $issueInvoker = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_F6_ENABLED, '0') === '1';
	}

	public function setEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_F6_ENABLED, $enabled ? '1' : '0');
	}

	public function locationPolicy(): string
	{
		$raw = $this->config->getAppValue(
			Application::APP_ID,
			self::KEY_LOCATION_POLICY,
			self::POLICY_FAIL_AMBIGUOUS,
		);
		$allowed = [self::POLICY_EXPLICIT, self::POLICY_EQUIPMENT_DEFAULT, self::POLICY_FAIL_AMBIGUOUS];
		return in_array($raw, $allowed, true) ? $raw : self::POLICY_FAIL_AMBIGUOUS;
	}

	public function setLocationPolicy(string $policy): void
	{
		$allowed = [self::POLICY_EXPLICIT, self::POLICY_EQUIPMENT_DEFAULT, self::POLICY_FAIL_AMBIGUOUS];
		if (!in_array($policy, $allowed, true)) {
			throw new \InvalidArgumentException('invalid_location_policy');
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_LOCATION_POLICY, $policy);
	}

	public function explicitLocationId(): ?int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_EXPLICIT_LOCATION_ID, '');
		if ($raw !== '' && ctype_digit($raw)) {
			return (int)$raw;
		}

		return null;
	}

	public function setExplicitLocationId(?int $locationId): void
	{
		if ($locationId === null || $locationId < 1) {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_EXPLICIT_LOCATION_ID);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_EXPLICIT_LOCATION_ID, (string)$locationId);
	}

	/**
	 * @return array{enabled: bool, locationPolicy: string, explicitLocationId: ?int}
	 */
	public function adminSnapshot(): array
	{
		return [
			'enabled' => $this->isEnabled(),
			'locationPolicy' => $this->locationPolicy(),
			'explicitLocationId' => $this->explicitLocationId(),
		];
	}

	/**
	 * @param list<array{sku: string, label?: string, qty: int}> $skuLines
	 * @return array{sync: string, code: ?string}
	 */
	public function issueForWorkOrder(string $actorUid, int $woId, array $skuLines): array
	{
		if (!$this->isEnabled()) {
			return ['sync' => 'disabled', 'code' => 'flange_disabled'];
		}

		$lines = [];
		foreach ($skuLines as $row) {
			$sku = trim((string)($row['sku'] ?? ''));
			$qty = (int)($row['qty'] ?? 0);
			if ($sku === '' || $qty < 1) {
				continue;
			}
			$lines[] = ['sku' => $sku, 'qty' => $qty];
		}
		// Nothing to issue — succeed without requiring InventoryCheck.
		if ($lines === []) {
			return ['sync' => 'ok', 'code' => null];
		}

		if ($this->issueInvoker === null && !$this->siblingFacadeAvailable()) {
			return ['sync' => 'unavailable', 'code' => 'sibling_unavailable'];
		}

		$policy = $this->locationPolicy();
		// Only the explicit policy sends MN's configured location id.
		// equipment_default_location lets IV resolve FlangeService default (or fail soft).
		$locationId = null;
		if ($policy === self::POLICY_EXPLICIT || $policy === self::POLICY_FAIL_AMBIGUOUS) {
			$rawLoc = $this->config->getAppValue(Application::APP_ID, self::KEY_EXPLICIT_LOCATION_ID, '');
			if ($rawLoc !== '' && ctype_digit($rawLoc)) {
				$locationId = (int)$rawLoc;
			}
		}

		try {
			$result = $this->invokeIssue($actorUid, $woId, $lines, $policy, $locationId);
			if ($result->ok) {
				return ['sync' => 'ok', 'code' => $result->code];
			}
			return ['sync' => 'failed', 'code' => $result->code ?? 'inventory_sync_failed'];
		} catch (\Throwable $e) {
			$this->logger->warning('F6 StockIssueFacade call failed for WO ' . $woId, ['exception' => $e]);
			return ['sync' => 'failed', 'code' => 'inventory_sync_failed'];
		}
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 */
	private function invokeIssue(string $actorUid, int $woId, array $lines, string $policy, ?int $locationId): object
	{
		if (is_callable($this->issueInvoker)) {
			return ($this->issueInvoker)($actorUid, $woId, $lines, $policy, $locationId);
		}
		$facade = \OCP\Server::get(self::FACADE);
		$requestClass = self::REQUEST;
		$request = new $requestClass(
			actorUid: $actorUid,
			lines: $lines,
			locationPolicy: $policy,
			refType: $requestClass::REF_MAINT_WO,
			refId: $woId,
			locationId: $locationId,
		);
		return $facade->issueBySkuBundle($request);
	}

	/**
	 * Test seam: a subclass can pretend InventoryCheck is not installed even
	 * when this workspace has the sibling app (otherwise the unavailable
	 * branch is permanently skipped).
	 */
	protected function siblingFacadeAvailable(): bool
	{
		return class_exists(self::FACADE) && class_exists(self::REQUEST);
	}
}
