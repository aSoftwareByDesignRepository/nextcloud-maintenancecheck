<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Public;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\MaintenanceCheck\Db\Customer;
use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Field soft-link surface for CustomerCheck / suite identity (SHARED-IDENTITY W3).
 *
 * Never writes CRM or ProjectCheck tables. Never opens cross-app TX.
 */
class CrmFieldCustomerFacade
{
	public const FACADE_VERSION = 1;
	public const CONFIG_SOFT_LINK_UI = 'mn_soft_link_ui';

	public function __construct(
		private readonly CustomerMapper $customers,
		private readonly AccessControlService $access,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly IConfig $config,
	) {
	}

	public function softLinkUiEnabled(): bool
	{
		$raw = $this->config->getAppValue('maintenancecheck', self::CONFIG_SOFT_LINK_UI, '1');

		return $raw === '1' || $raw === 'true';
	}

	/**
	 * @return array{mnLinkedPc:int,mnLinkedCrm:int,mnUnlinked:int}
	 */
	public function identityHealthCounts(): array
	{
		return $this->customers->identityLinkCounts();
	}

	/**
	 * @param array{
	 *   actorUid:string,
	 *   displayName:string,
	 *   crmCompanyId?:int|null,
	 *   pcCustomerId?:int|null,
	 *   street?:?string,
	 *   postalCode?:?string,
	 *   city?:?string,
	 *   country?:?string,
	 *   email?:?string,
	 *   phone?:?string
	 * } $payload
	 * @return array{mnCustomerId:int,created:bool}
	 */
	public function createFromHub(array $payload): array
	{
		$actor = trim((string)($payload['actorUid'] ?? ''));
		$this->access->requireOffice($actor);

		$name = trim((string)($payload['displayName'] ?? ''));
		if ($name === '') {
			throw new ConflictException('validation_failed', 'displayName is required.');
		}

		$pcId = isset($payload['pcCustomerId']) ? (int)$payload['pcCustomerId'] : 0;
		$crmId = isset($payload['crmCompanyId']) ? (int)$payload['crmCompanyId'] : 0;
		if ($pcId > 0) {
			$existing = $this->customers->findByPcCustomerId($pcId);
			if ($existing !== null) {
				return [
					'mnCustomerId' => (int)$existing->getId(),
					'created' => false,
				];
			}
		}
		if ($crmId > 0) {
			$existing = $this->customers->findByCrmCompanyId($crmId);
			if ($existing !== null) {
				return [
					'mnCustomerId' => (int)$existing->getId(),
					'created' => false,
				];
			}
		}

		$now = $this->clock->now();
		$customer = new Customer();
		$customer->setName(mb_substr($name, 0, 255));
		$customer->setStreet($this->nullableString($payload['street'] ?? null));
		$customer->setPostalCode($this->nullableString($payload['postalCode'] ?? null));
		$customer->setCity($this->nullableString($payload['city'] ?? null));
		$customer->setCountry($this->nullableString($payload['country'] ?? null));
		$customer->setEmail($this->nullableString($payload['email'] ?? null));
		$customer->setPhone($this->nullableString($payload['phone'] ?? null));
		$customer->setActive(true);
		$customer->setCreatedAt($now);
		$customer->setUpdatedAt($now);
		$customer->setCreatedBy($actor);
		if ($pcId > 0) {
			$customer->setPcCustomerId($pcId);
		}
		if ($crmId > 0) {
			$customer->setCrmCompanyId($crmId);
		}

		try {
			$stored = $this->customers->insert($customer);
		} catch (\Throwable $e) {
			if ($this->isUniqueViolation($e)) {
				throw new ConflictException('link_conflict', 'Soft-link unique constraint violated.');
			}
			throw $e;
		}

		return [
			'mnCustomerId' => (int)$stored->getId(),
			'created' => true,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function ensureLink(int $mnCustomerId, string $actorUid, ?int $pcCustomerId, ?int $crmCompanyId, ?int $updatedAt = null): array
	{
		$this->access->requireOffice($actorUid);
		try {
			$customer = $this->customers->findById($mnCustomerId);
		} catch (DoesNotExistException|NotFoundException) {
			throw new NotFoundException();
		}

		if ($updatedAt !== null && (int)$customer->getUpdatedAt() !== $updatedAt) {
			throw new ConflictException('stale_precondition', 'Customer was updated elsewhere. Reload and retry.');
		}

		if ($pcCustomerId !== null) {
			if ($pcCustomerId <= 0) {
				$customer->setPcCustomerId(null);
			} else {
				$other = $this->customers->findByPcCustomerId($pcCustomerId);
				if ($other !== null && (int)$other->getId() !== $mnCustomerId) {
					throw new ConflictException('link_conflict', 'This ProjectCheck customer is already linked to another field customer.');
				}
				$customer->setPcCustomerId($pcCustomerId);
			}
		}
		if ($crmCompanyId !== null) {
			if ($crmCompanyId <= 0) {
				$customer->setCrmCompanyId(null);
			} else {
				$other = $this->customers->findByCrmCompanyId($crmCompanyId);
				if ($other !== null && (int)$other->getId() !== $mnCustomerId) {
					throw new ConflictException('link_conflict', 'This CRM company is already linked to another field customer.');
				}
				$customer->setCrmCompanyId($crmCompanyId);
			}
		}

		// AS-05: when both soft links set, CRM's pc_customer_id must match (or be null).
		$finalPc = (int)($customer->getPcCustomerId() ?? 0);
		$finalCrm = (int)($customer->getCrmCompanyId() ?? 0);
		if ($finalPc > 0 && $finalCrm > 0) {
			$this->assertCrmPcConsistent($actorUid, $finalCrm, $finalPc);
		}

		$customer->setUpdatedAt($this->clock->now());
		try {
			return $this->customers->update($customer)->toApi();
		} catch (\Throwable $e) {
			if ($this->isUniqueViolation($e)) {
				throw new ConflictException('link_conflict', 'Soft-link unique constraint violated.');
			}
			throw $e;
		}
	}

	/**
	 * Soft-check via CRM facade only — never SQL into crm_* tables.
	 */
	private function assertCrmPcConsistent(string $actorUid, int $crmCompanyId, int $pcCustomerId): void
	{
		try {
			$row = $this->probeCrmCompany($actorUid, $crmCompanyId);
			if ($row === null) {
				// CRM unavailable or company missing — do not block field CRUD when probe soft-fails.
				// Explicit not_found from probe throws ValidationException below path.
				return;
			}
			$crmPc = isset($row['pcCustomerId']) ? (int)$row['pcCustomerId'] : 0;
			if ($crmPc > 0 && $crmPc !== $pcCustomerId) {
				throw new ValidationException(
					'validation_failed',
					'Field soft links disagree: CRM company is linked to a different ProjectCheck customer.',
				);
			}
		} catch (ValidationException $e) {
			throw $e;
		} catch (\Throwable) {
			// Sibling probe failed — do not block field CRUD.
		}
	}

	/**
	 * @return array{pcCustomerId:?int,crmCompanyId:int}|null
	 */
	protected function probeCrmCompany(string $actorUid, int $crmCompanyId): ?array
	{
		$facadeClass = 'OCA\\CustomerCheck\\Public\\CrmCompanyLinkReadFacade';
		if (!class_exists($facadeClass)) {
			return null;
		}
		$facade = \OC::$server->get($facadeClass);
		if (!is_object($facade) || !method_exists($facade, 'findByCompanyId')) {
			return null;
		}
		$row = $facade->findByCompanyId($actorUid, $crmCompanyId);
		if (!is_array($row)) {
			throw new ValidationException('not_found', 'CRM company not found for soft-link.');
		}

		return [
			'crmCompanyId' => $crmCompanyId,
			'pcCustomerId' => isset($row['pcCustomerId']) ? (int)$row['pcCustomerId'] : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function unlink(int $mnCustomerId, string $actorUid, bool $clearPc, bool $clearCrm, ?int $updatedAt = null): array
	{
		return $this->ensureLink(
			$mnCustomerId,
			$actorUid,
			$clearPc ? 0 : null,
			$clearCrm ? 0 : null,
			$updatedAt,
		);
	}

	private function nullableString(mixed $v): ?string
	{
		if ($v === null) {
			return null;
		}
		$s = trim((string)$v);

		return $s === '' ? null : mb_substr($s, 0, 255);
	}

	private function isUniqueViolation(\Throwable $e): bool
	{
		if ($e instanceof UniqueConstraintViolationException) {
			return true;
		}
		$prev = $e->getPrevious();

		return $prev instanceof \Throwable && $this->isUniqueViolation($prev);
	}
}
