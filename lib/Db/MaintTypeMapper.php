<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\IDBConnection;

class MaintTypeMapper extends CatalogTypeMapper
{
	public const TABLE = 'mn_maint_types';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE);
	}
}
