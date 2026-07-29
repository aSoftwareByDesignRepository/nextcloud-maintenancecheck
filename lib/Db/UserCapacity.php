<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W4 per-user daily capacity in minutes (default 480 = 8h).
 *
 * @method string getUid()
 * @method void setUid(string $v)
 * @method int getDailyMinutes()
 * @method void setDailyMinutes(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $v)
 */
class UserCapacity extends Entity
{
	public const DEFAULT_DAILY_MINUTES = 480;

	protected string $uid = '';
	protected int $dailyMinutes = self::DEFAULT_DAILY_MINUTES;
	protected int $updatedAt = 0;
	protected string $updatedBy = '';

	public function __construct()
	{
		$this->addType('uid', 'string');
		$this->addType('dailyMinutes', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('updatedBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'uid' => $this->uid,
			'dailyMinutes' => $this->dailyMinutes,
			'updatedAt' => $this->updatedAt,
			'updatedBy' => $this->updatedBy,
		];
	}
}
