<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\IDBConnection;

/**
 * W2 skills catalog — column-compatible with the W0 catalog tables, so the
 * shared CatalogType entity/mapper machinery is reused verbatim.
 */
class SkillMapper extends CatalogTypeMapper
{
	public const TABLE = 'mn_skills';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE);
	}
}
