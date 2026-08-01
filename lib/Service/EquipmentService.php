<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use splitbrain\phpQRCode\QRCode;

class EquipmentService
{
	/** Sticker payload prefix — companion scanners strip this before hashing. */
	public const QR_PAYLOAD_PREFIX = 'mn-eq:';

	public function __construct(
		private readonly EquipmentMapper $equipment,
		private readonly CustomerMapper $customers,
		private readonly EquipTypeMapper $equipTypes,
		private readonly PlanMapper $plans,
		private readonly VisitMapper $visits,
		private readonly SiteMapper $sites,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $customerId, ?string $q, ?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$term = $this->validator->searchTerm($q);
		$customerFilter = null;
		if ($customerId !== null && $customerId !== '') {
			if (!preg_match('/^\d+$/', $customerId)) {
				throw new ValidationException('invalid_query', 'customerId must be a positive integer.');
			}
			$customerFilter = (int)$customerId;
		}
		$result = $this->equipment->search($customerFilter, $term, $page['limit'], $page['offset']);
		$customerIds = array_map(
			static fn (Equipment $e) => $e->getCustomerId(),
			$result['data'],
		);
		$names = $this->customers->mapNamesByIds($customerIds);
		$data = [];
		foreach ($result['data'] as $equipment) {
			$row = $equipment->toApi();
			$row['customerName'] = $names[(int)$equipment->getCustomerId()] ?? '';
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
	 * @return array<string, mixed>
	 */
	public function get(int $id): array
	{
		$equipment = $this->equipment->findById($id);
		$data = $equipment->toApi();
		$data['counts'] = [
			'plans' => $this->plans->countForEquipment($id),
			'visits' => $this->visits->countForEquipment($id),
		];
		return $data;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, array $body): array
	{
		$fields = $this->validator->equipment($body);
		$customerId = $this->requireCustomer($body);
		$equipTypeId = $this->requireActiveEquipType($body, null);
		$now = $this->clock->now();

		$equipment = new Equipment();
		$equipment->setCustomerId($customerId);
		$equipment->setEquipTypeId($equipTypeId);
		$this->applyFields($equipment, $fields);
		$this->applyGeoAndSite($equipment, $body);
		$equipment->setCreatedAt($now);
		$equipment->setUpdatedAt($now);
		$equipment->setCreatedBy($uid);
		$plaintext = $this->issueQrToken($equipment, $now);
		$api = $this->equipment->insert($equipment)->toApi();
		// Plaintext is returned exactly once so the sticker can be printed.
		$api['qrToken'] = $plaintext;
		$api['qrPayload'] = self::QR_PAYLOAD_PREFIX . $plaintext;
		$api['qrSvg'] = $this->qrSvg(self::QR_PAYLOAD_PREFIX . $plaintext);
		$api['qrDeepLink'] = $this->qrDeepLink($plaintext);
		return $api;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$equipment = $this->equipment->findById($id);
		$fields = $this->validator->equipment($body);

		if (array_key_exists('customerId', $body)) {
			$equipment->setCustomerId($this->requireCustomer($body));
		}
		if (array_key_exists('equipTypeId', $body)) {
			$equipment->setEquipTypeId($this->requireActiveEquipType($body, $equipment->getEquipTypeId()));
		}

		$this->applyFields($equipment, $fields);
		$this->applyGeoAndSite($equipment, $body);
		$equipment->setUpdatedAt($this->clock->now());
		return $this->equipment->update($equipment)->toApi();
	}

	/**
	 * S10: block — equipment with ≥ 1 plan or ≥ 1 visit → 409 `equipment_in_use`.
	 */
	public function delete(int $id): void
	{
		$equipment = $this->equipment->findById($id);
		if ($this->plans->countForEquipment($id) > 0 || $this->visits->countForEquipment($id) > 0) {
			throw new ConflictException('equipment_in_use', 'This equipment has plans or visits. Deactivate it instead.');
		}
		$this->equipment->delete($equipment);
	}

	/**
	 * Issue or rotate the equipment sticker token. Returns plaintext once
	 * (CORE steal #7 / COMPANION by-qr). Prior stickers stop resolving.
	 *
	 * @return array{equipment: array<string, mixed>, qrToken: string, qrPayload: string, qrSvg: string, qrDeepLink: string}
	 */
	public function rotateQrToken(int $id): array
	{
		$equipment = $this->equipment->findById($id);
		$now = $this->clock->now();
		$plaintext = $this->issueQrToken($equipment, $now);
		$api = $this->equipment->update($equipment)->toApi();
		$payload = self::QR_PAYLOAD_PREFIX . $plaintext;
		return [
			'equipment' => $api,
			'qrToken' => $plaintext,
			'qrPayload' => $payload,
			'qrSvg' => $this->qrSvg($payload),
			'qrDeepLink' => $this->qrDeepLink($plaintext),
		];
	}

	/**
	 * Resolve a sticker payload or raw token to the mobile equipment summary.
	 *
	 * @return array<string, mixed>
	 */
	public function resolveByQr(string $presented): array
	{
		$token = $this->normalizePresentedQr($presented);
		$hash = hash('sha256', $token);
		$equipment = $this->equipment->findByQrTokenHash($hash);
		// Defence in depth: unique index already matched; still constant-time
		// compare against the stored hash so empty/null hashes never pass.
		$stored = (string)$equipment->getQrTokenHash();
		if ($stored === '' || !hash_equals($stored, $hash)) {
			throw new NotFoundException();
		}
		return $this->mobileSummary((int)$equipment->getId());
	}

	/**
	 * SPEC §9.2: mobile equipment detail — summary + active plans + last 5 visits.
	 *
	 * @return array<string, mixed>
	 */
	public function mobileSummary(int $id): array
	{
		$equipment = $this->equipment->findById($id);
		$customer = $this->customers->findById($equipment->getCustomerId());
		$equipType = null;
		try {
			$equipType = $this->equipTypes->findById($equipment->getEquipTypeId());
		} catch (NotFoundException) {
			// Catalog row may have been removed after historical inserts — omit name.
		}

		$activePlans = [];
		foreach ($this->plans->findByEquipment($id) as $plan) {
			if (!$plan->getActive()) {
				continue;
			}
			$row = $plan->toApi();
			$open = $this->visits->findOpenByPlan((int)$plan->getId());
			$row['openVisit'] = $open?->toApi();
			$activePlans[] = $row;
		}

		$recent = array_map(
			static fn ($visit) => $visit->toApi(),
			$this->visits->findRecentForEquipment($id, 5),
		);

		$data = $equipment->toApi();
		$data['customerName'] = $customer->getName();
		$data['equipTypeName'] = $equipType?->getName() ?? '';
		$data['activePlans'] = $activePlans;
		$data['recentVisits'] = $recent;
		return $data;
	}

	private function requireCustomer(array $body): int
	{
		$customerId = $body['customerId'] ?? null;
		if (!is_int($customerId) || $customerId < 1 || !$this->customers->exists($customerId)) {
			throw new ValidationException('validation_failed', 'Unknown customer.', [
				['field' => 'customerId', 'code' => 'unknown_customer'],
			]);
		}
		return $customerId;
	}

	/**
	 * S11: creating/updating equipment with an inactive type → 422; keeping
	 * the current (possibly deactivated) type on update stays allowed.
	 */
	private function requireActiveEquipType(array $body, ?int $currentTypeId): int
	{
		$typeId = $body['equipTypeId'] ?? null;
		if (!is_int($typeId) || $typeId < 1) {
			throw new ValidationException('validation_failed', 'Unknown equipment type.', [
				['field' => 'equipTypeId', 'code' => 'unknown_equip_type'],
			]);
		}
		try {
			$type = $this->equipTypes->findById($typeId);
		} catch (NotFoundException) {
			throw new ValidationException('validation_failed', 'Unknown equipment type.', [
				['field' => 'equipTypeId', 'code' => 'unknown_equip_type'],
			]);
		}
		if (!$type->getActive() && $typeId !== $currentTypeId) {
			throw new ValidationException('inactive_equip_type', 'This equipment type is deactivated.');
		}
		return $typeId;
	}

	/**
	 * W1: optional site link (must belong to the same customer) + optional
	 * coordinates for W3 tour sorting.
	 *
	 * @param array<string, mixed> $body
	 */
	private function applyGeoAndSite(Equipment $equipment, array $body): void
	{
		if (array_key_exists('siteId', $body)) {
			$siteId = $body['siteId'];
			if ($siteId === null) {
				$equipment->setSiteId(null);
			} elseif (is_int($siteId) && $siteId >= 1) {
				$site = $this->sites->findById($siteId);
				if ($site->getCustomerId() !== $equipment->getCustomerId()) {
					throw new ValidationException('validation_failed', 'The site belongs to a different customer.', [
						['field' => 'siteId', 'code' => 'invalid_value'],
					]);
				}
				$equipment->setSiteId($siteId);
			} else {
				throw new ValidationException('validation_failed', 'Unknown site.', [
					['field' => 'siteId', 'code' => 'invalid_type'],
				]);
			}
		} elseif ($equipment->getSiteId() !== null) {
			// Customer may have changed in this request — never keep a site
			// link across customers.
			try {
				$site = $this->sites->findById($equipment->getSiteId());
				if ($site->getCustomerId() !== $equipment->getCustomerId()) {
					$equipment->setSiteId(null);
				}
			} catch (NotFoundException) {
				$equipment->setSiteId(null);
			}
		}
		if (array_key_exists('lat', $body)) {
			$equipment->setLat($this->validator->coordinate($body, 'lat', -90.0, 90.0));
		}
		if (array_key_exists('lng', $body)) {
			$equipment->setLng($this->validator->coordinate($body, 'lng', -180.0, 180.0));
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(Equipment $equipment, array $fields): void
	{
		$equipment->setLabel($fields['label']);
		$equipment->setManufacturer($fields['manufacturer']);
		$equipment->setModel($fields['model']);
		$equipment->setSerialNo($fields['serialNo']);
		$equipment->setLocationText($fields['locationText']);
		$equipment->setNotes($fields['notes']);
		$equipment->setActive($fields['active']);
		if (array_key_exists('warrantyEnd', $fields)) {
			$equipment->setWarrantyEnd($fields['warrantyEnd']);
		}
	}

	private function issueQrToken(Equipment $equipment, int $now): string
	{
		$plaintext = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$equipment->setQrTokenHash(hash('sha256', $plaintext));
		$equipment->setQrTokenRotatedAt($now);
		$equipment->setUpdatedAt($now);
		return $plaintext;
	}

	private function normalizePresentedQr(string $presented): string
	{
		$token = trim($presented);
		if (str_starts_with($token, self::QR_PAYLOAD_PREFIX)) {
			$token = substr($token, strlen(self::QR_PAYLOAD_PREFIX));
		}
		// Deep-link form: …/equipment/by-qr/{token}
		if (str_contains($token, '/by-qr/')) {
			$parts = explode('/by-qr/', $token);
			$token = (string)end($parts);
		}
		$token = trim($token);
		if ($token === '' || !preg_match('/^[A-Za-z0-9]{16,128}$/', $token)) {
			throw new ValidationException('validation_failed', 'The QR token is not valid.', [
				['field' => 'token', 'code' => 'invalid_value'],
			]);
		}
		return $token;
	}

	private function qrDeepLink(string $plaintext): string
	{
		return $this->urlGenerator->linkToRouteAbsolute(
			'maintenancecheck.page.equipmentByQr',
			['token' => $plaintext],
		);
	}

	private function qrSvg(string $payload): string
	{
		require_once __DIR__ . '/../Vendor/splitbrain/phpQRCode/QRCode.php';
		return QRCode::svg($payload, ['s' => 'qrm']);
	}
}
