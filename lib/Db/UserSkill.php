<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W2 user↔skill grant (unique per pair).
 *
 * @method string getUid()
 * @method void setUid(string $v)
 * @method int getSkillId()
 * @method void setSkillId(int $v)
 * @method int getGrantedAt()
 * @method void setGrantedAt(int $v)
 * @method string getGrantedBy()
 * @method void setGrantedBy(string $v)
 */
class UserSkill extends Entity
{
	protected string $uid = '';
	protected int $skillId = 0;
	protected int $grantedAt = 0;
	protected string $grantedBy = '';

	public function __construct()
	{
		$this->addType('uid', 'string');
		$this->addType('skillId', 'integer');
		$this->addType('grantedAt', 'integer');
		$this->addType('grantedBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'uid' => $this->uid,
			'skillId' => $this->skillId,
			'grantedAt' => $this->grantedAt,
			'grantedBy' => $this->grantedBy,
		];
	}
}
