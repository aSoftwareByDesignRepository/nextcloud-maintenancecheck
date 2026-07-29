<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W1 optional site under a customer (address hub, CORE §8).
 *
 * @method int getCustomerId()
 * @method void setCustomerId(int $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getStreet()
 * @method void setStreet(?string $v)
 * @method string|null getPostalCode()
 * @method void setPostalCode(?string $v)
 * @method string|null getCity()
 * @method void setCity(?string $v)
 * @method string|null getCountry()
 * @method void setCountry(?string $v)
 * @method string|null getLat()
 * @method void setLat(?string $v)
 * @method string|null getLng()
 * @method void setLng(?string $v)
 * @method string|null getNotes()
 * @method void setNotes(?string $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Site extends Entity
{
	protected int $customerId = 0;
	protected string $name = '';
	protected ?string $street = null;
	protected ?string $postalCode = null;
	protected ?string $city = null;
	protected ?string $country = null;
	protected ?string $lat = null;
	protected ?string $lng = null;
	protected ?string $notes = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('customerId', 'integer');
		$this->addType('name', 'string');
		$this->addType('street', 'string');
		$this->addType('postalCode', 'string');
		$this->addType('city', 'string');
		$this->addType('country', 'string');
		// Decimal columns travel as strings — no float drift.
		$this->addType('lat', 'string');
		$this->addType('lng', 'string');
		$this->addType('notes', 'string');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'customerId' => $this->customerId,
			'name' => $this->name,
			'street' => $this->street,
			'postalCode' => $this->postalCode,
			'city' => $this->city,
			'country' => $this->country,
			'lat' => $this->lat,
			'lng' => $this->lng,
			'notes' => $this->notes,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
