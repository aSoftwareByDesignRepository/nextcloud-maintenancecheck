<?php

declare(strict_types=1);

return [
	'routes' => [
		// ── Pages ──────────────────────────────────────────────────────
		['name' => 'page#due', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dueAlias', 'url' => '/due', 'verb' => 'GET'],
		['name' => 'page#customers', 'url' => '/customers', 'verb' => 'GET'],
		['name' => 'page#customer', 'url' => '/customers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#equipment', 'url' => '/equipment', 'verb' => 'GET'],
		['name' => 'page#equipmentShow', 'url' => '/equipment/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#visits', 'url' => '/visits', 'verb' => 'GET'],
		['name' => 'page#catalogs', 'url' => '/catalogs', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],

		// ── Customers ──────────────────────────────────────────────────
		['name' => 'customer#index', 'url' => '/api/customers', 'verb' => 'GET'],
		['name' => 'customer#show', 'url' => '/api/customers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'customer#create', 'url' => '/api/customers', 'verb' => 'POST'],
		['name' => 'customer#update', 'url' => '/api/customers/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'customer#destroy', 'url' => '/api/customers/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		// ── Equipment ──────────────────────────────────────────────────
		['name' => 'equipment#index', 'url' => '/api/equipment', 'verb' => 'GET'],
		['name' => 'equipment#show', 'url' => '/api/equipment/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'equipment#create', 'url' => '/api/equipment', 'verb' => 'POST'],
		['name' => 'equipment#update', 'url' => '/api/equipment/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'equipment#destroy', 'url' => '/api/equipment/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		// ── Plans ──────────────────────────────────────────────────────
		['name' => 'plan#indexForEquipment', 'url' => '/api/equipment/{id}/plans', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'plan#create', 'url' => '/api/equipment/{id}/plans', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'plan#update', 'url' => '/api/plans/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'plan#deactivate', 'url' => '/api/plans/{id}/deactivate', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'plan#schedule', 'url' => '/api/plans/{id}/schedule', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],

		// ── Visits ─────────────────────────────────────────────────────
		['name' => 'visit#index', 'url' => '/api/visits', 'verb' => 'GET'],
		['name' => 'visit#due', 'url' => '/api/visits/due', 'verb' => 'GET'],
		['name' => 'visit#update', 'url' => '/api/visits/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'visit#complete', 'url' => '/api/visits/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'visit#skip', 'url' => '/api/visits/{id}/skip', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'visit#cancel', 'url' => '/api/visits/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'visit#assign', 'url' => '/api/visits/{id}/assign', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],

		// ── Catalogs ───────────────────────────────────────────────────
		['name' => 'catalog#equipTypes', 'url' => '/api/equip-types', 'verb' => 'GET'],
		['name' => 'catalog#createEquipType', 'url' => '/api/equip-types', 'verb' => 'POST'],
		['name' => 'catalog#updateEquipType', 'url' => '/api/equip-types/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'catalog#maintTypes', 'url' => '/api/maint-types', 'verb' => 'GET'],
		['name' => 'catalog#createMaintType', 'url' => '/api/maint-types', 'verb' => 'POST'],
		['name' => 'catalog#updateMaintType', 'url' => '/api/maint-types/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],

		// ── Config ─────────────────────────────────────────────────────
		['name' => 'config#index', 'url' => '/api/config', 'verb' => 'GET'],
		['name' => 'config#saveAccess', 'url' => '/api/config/access', 'verb' => 'POST'],
		['name' => 'config#saveOffice', 'url' => '/api/config/office', 'verb' => 'POST'],
		['name' => 'config#userAccess', 'url' => '/api/config/user-access', 'verb' => 'GET'],
		['name' => 'config#searchUsers', 'url' => '/api/users/search', 'verb' => 'GET'],

		// ── License (Track L) ──────────────────────────────────────────
		['name' => 'license#show', 'url' => '/api/license', 'verb' => 'GET'],
		['name' => 'license#apply', 'url' => '/api/license', 'verb' => 'POST'],
		['name' => 'license#remove', 'url' => '/api/license', 'verb' => 'DELETE'],
		['name' => 'license#seats', 'url' => '/api/license/seats', 'verb' => 'GET'],
		['name' => 'license#assignSeat', 'url' => '/api/license/seats', 'verb' => 'POST'],
		['name' => 'license#removeSeat', 'url' => '/api/license/seats/{uid}', 'verb' => 'DELETE'],

		// ── Mobile v1 (SPEC §9 — full domain surface behind gate) ───────
		['name' => 'mobile#bootstrap', 'url' => '/mobile/v1/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile#due', 'url' => '/mobile/v1/due', 'verb' => 'GET'],
		['name' => 'mobile#equipment', 'url' => '/mobile/v1/equipment/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#visits', 'url' => '/mobile/v1/visits', 'verb' => 'GET'],
		['name' => 'mobile#complete', 'url' => '/mobile/v1/visits/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#skip', 'url' => '/mobile/v1/visits/{id}/skip', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#customers', 'url' => '/mobile/v1/customers', 'verb' => 'GET'],
	],
];
