<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getCustomerNo()
 * @method void setCustomerNo(?string $v)
 * @method string|null getStreet()
 * @method void setStreet(?string $v)
 * @method string|null getPostalCode()
 * @method void setPostalCode(?string $v)
 * @method string|null getCity()
 * @method void setCity(?string $v)
 * @method string|null getCountry()
 * @method void setCountry(?string $v)
 * @method string|null getEmail()
 * @method void setEmail(?string $v)
 * @method string|null getPhone()
 * @method void setPhone(?string $v)
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
class Customer extends Entity
{
	protected string $name = '';
	protected ?string $customerNo = null;
	protected ?string $street = null;
	protected ?string $postalCode = null;
	protected ?string $city = null;
	protected ?string $country = null;
	protected ?string $email = null;
	protected ?string $phone = null;
	protected ?string $notes = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('customerNo', 'string');
		$this->addType('street', 'string');
		$this->addType('postalCode', 'string');
		$this->addType('city', 'string');
		$this->addType('country', 'string');
		$this->addType('email', 'string');
		$this->addType('phone', 'string');
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
			'name' => $this->name,
			'customerNo' => $this->customerNo,
			'street' => $this->street,
			'postalCode' => $this->postalCode,
			'city' => $this->city,
			'country' => $this->country,
			'email' => $this->email,
			'phone' => $this->phone,
			'notes' => $this->notes,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
