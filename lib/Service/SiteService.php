<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\Site;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W1 sites — optional address hubs under a customer (CORE §8, §14.1).
 * Deleting a site that equipment still points at is refused (409
 * `site_in_use`) so tour geodata never silently degrades.
 */
class SiteService
{
	public function __construct(
		private readonly SiteMapper $sites,
		private readonly CustomerMapper $customers,
		private readonly EquipmentMapper $equipment,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function listForCustomer(int $customerId): array
	{
		$this->customers->findById($customerId);
		return [
			'data' => array_map(static fn (Site $s) => $s->toApi(), $this->sites->findByCustomer($customerId)),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $customerId, array $body): array
	{
		$this->customers->findById($customerId);
		$now = $this->clock->now();

		$site = new Site();
		$site->setCustomerId($customerId);
		$this->applyFields($site, $body, true);
		$site->setCreatedAt($now);
		$site->setUpdatedAt($now);
		$site->setCreatedBy($uid);
		return $this->sites->insert($site)->toApi();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$site = $this->sites->findById($id);
		$this->applyFields($site, $body, false);
		$site->setUpdatedAt($this->clock->now());
		return $this->sites->update($site)->toApi();
	}

	public function delete(int $id): void
	{
		$site = $this->sites->findById($id);
		if ($this->equipment->countForSite($id) > 0) {
			throw new ConflictException('site_in_use', 'Equipment is linked to this site. Move it first.');
		}
		$this->sites->delete($site);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function applyFields(Site $site, array $body, bool $isCreate): void
	{
		if ($isCreate || array_key_exists('name', $body)) {
			$site->setName($this->validator->requiredString($body, 'name', 'name_required', 255, 'name_too_long'));
		}
		if (array_key_exists('street', $body)) {
			$site->setStreet($this->validator->boundedOptionalString($body, 'street', 255, 'street_too_long'));
		}
		if (array_key_exists('postalCode', $body)) {
			$site->setPostalCode($this->validator->boundedOptionalString($body, 'postalCode', 32, 'postal_code_too_long'));
		}
		if (array_key_exists('city', $body)) {
			$site->setCity($this->validator->boundedOptionalString($body, 'city', 128, 'city_too_long'));
		}
		if (array_key_exists('country', $body)) {
			$country = $this->validator->optionalString($body, 'country');
			if ($country !== null && $country !== '') {
				$country = strtoupper($country);
				if (!preg_match('/^[A-Z]{2}$/', $country)) {
					throw new ValidationException('validation_failed', 'Country must be a two-letter ISO code.', [
						['field' => 'country', 'code' => 'invalid_country'],
					]);
				}
				$site->setCountry($country);
			} else {
				$site->setCountry(null);
			}
		}
		if (array_key_exists('lat', $body)) {
			$site->setLat($this->validator->coordinate($body, 'lat', -90.0, 90.0));
		}
		if (array_key_exists('lng', $body)) {
			$site->setLng($this->validator->coordinate($body, 'lng', -180.0, 180.0));
		}
		if (array_key_exists('notes', $body)) {
			$site->setNotes($this->validator->boundedOptionalString($body, 'notes', 10000, 'notes_too_long'));
		}
		if (array_key_exists('active', $body)) {
			$site->setActive($this->validator->boolOrDefault($body, 'active', $site->getActive()));
		}
	}
}
