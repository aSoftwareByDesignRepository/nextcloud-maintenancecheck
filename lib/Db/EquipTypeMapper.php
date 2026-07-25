<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\IDBConnection;

class EquipTypeMapper extends CatalogTypeMapper
{
	public const TABLE = 'mn_equip_types';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE);
	}
}
