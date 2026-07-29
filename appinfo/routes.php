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
		['name' => 'page#equipmentByQr', 'url' => '/equipment/by-qr/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9]{16,128}']],
		['name' => 'page#equipmentShow', 'url' => '/equipment/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#visits', 'url' => '/visits', 'verb' => 'GET'],
		['name' => 'page#catalogs', 'url' => '/catalogs', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#workOrders', 'url' => '/work-orders', 'verb' => 'GET'],
		['name' => 'page#workOrderShow', 'url' => '/work-orders/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#dispatch', 'url' => '/dispatch', 'verb' => 'GET'],
		['name' => 'page#tours', 'url' => '/tours', 'verb' => 'GET'],

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
		['name' => 'equipment#rotateQr', 'url' => '/api/equipment/{id}/qr/rotate', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],

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
		['name' => 'config#saveInventoryFlange', 'url' => '/api/config/inventory-flange', 'verb' => 'POST'],
		['name' => 'config#savePolicies', 'url' => '/api/config/policies', 'verb' => 'POST'],
		['name' => 'config#userAccess', 'url' => '/api/config/user-access', 'verb' => 'GET'],
		['name' => 'config#searchUsers', 'url' => '/api/users/search', 'verb' => 'GET'],

		// ── License (Track L) ──────────────────────────────────────────
		['name' => 'license#show', 'url' => '/api/license', 'verb' => 'GET'],
		['name' => 'license#apply', 'url' => '/api/license', 'verb' => 'POST'],
		['name' => 'license#remove', 'url' => '/api/license', 'verb' => 'DELETE'],
		['name' => 'license#seats', 'url' => '/api/license/seats', 'verb' => 'GET'],
		['name' => 'license#assignSeat', 'url' => '/api/license/seats', 'verb' => 'POST'],
		['name' => 'license#removeSeat', 'url' => '/api/license/seats/{uid}', 'verb' => 'DELETE'],

		// ── Work orders (W1–W3 HTTP) ────────────────────────────────────
		['name' => 'work_order#index', 'url' => '/api/work-orders', 'verb' => 'GET'],
		['name' => 'work_order#create', 'url' => '/api/work-orders', 'verb' => 'POST'],
		['name' => 'work_order#show', 'url' => '/api/work-orders/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#update', 'url' => '/api/work-orders/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#createFromVisit', 'url' => '/api/visits/{visitId}/work-orders', 'verb' => 'POST', 'requirements' => ['visitId' => '\\d+']],
		['name' => 'work_order#assign', 'url' => '/api/work-orders/{id}/assign', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#transition', 'url' => '/api/work-orders/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#setChecklistResult', 'url' => '/api/work-orders/{id}/checklist/{itemCode}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+', 'itemCode' => '[A-Za-z0-9._-]{1,64}']],
		['name' => 'work_order#setSkills', 'url' => '/api/work-orders/{id}/skills', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#listPhotos', 'url' => '/api/work-orders/{id}/photos', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#addPhoto', 'url' => '/api/work-orders/{id}/photos', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#downloadPhoto', 'url' => '/api/work-orders/{id}/photos/{photoId}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+', 'photoId' => '\\d+']],
		['name' => 'work_order#deletePhoto', 'url' => '/api/work-orders/{id}/photos/{photoId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+', 'photoId' => '\\d+']],
		['name' => 'work_order#setSignature', 'url' => '/api/work-orders/{id}/signature', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#downloadSignature', 'url' => '/api/work-orders/{id}/signature', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#jobPackPdf', 'url' => '/api/work-orders/{id}/pdf/job-pack', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'work_order#serviceberichtPdf', 'url' => '/api/work-orders/{id}/pdf/servicebericht', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],

		// ── Kits (W2) ──────────────────────────────────────────────────
		['name' => 'kit#indexTemplates', 'url' => '/api/kit-templates', 'verb' => 'GET'],
		['name' => 'kit#createTemplate', 'url' => '/api/kit-templates', 'verb' => 'POST'],
		['name' => 'kit#showTemplate', 'url' => '/api/kit-templates/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'kit#updateTemplate', 'url' => '/api/kit-templates/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'kit#attach', 'url' => '/api/work-orders/{id}/kit', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'kit#addLine', 'url' => '/api/work-orders/{id}/kit/lines', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'kit#packLine', 'url' => '/api/work-orders/{id}/kit/lines/{lineId}/pack', 'verb' => 'POST', 'requirements' => ['id' => '\\d+', 'lineId' => '\\d+']],
		['name' => 'kit#removeLine', 'url' => '/api/work-orders/{id}/kit/lines/{lineId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+', 'lineId' => '\\d+']],

		// ── Sites (W1) ─────────────────────────────────────────────────
		['name' => 'site#indexForCustomer', 'url' => '/api/customers/{customerId}/sites', 'verb' => 'GET', 'requirements' => ['customerId' => '\\d+']],
		['name' => 'site#create', 'url' => '/api/customers/{customerId}/sites', 'verb' => 'POST', 'requirements' => ['customerId' => '\\d+']],
		['name' => 'site#update', 'url' => '/api/sites/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'site#destroy', 'url' => '/api/sites/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		// ── Procedures + packs (W1/W3) ─────────────────────────────────
		['name' => 'procedure#index', 'url' => '/api/procedures', 'verb' => 'GET'],
		['name' => 'procedure#create', 'url' => '/api/procedures', 'verb' => 'POST'],
		['name' => 'procedure#exportPack', 'url' => '/api/procedures/pack', 'verb' => 'GET'],
		['name' => 'procedure#importPack', 'url' => '/api/procedures/pack', 'verb' => 'POST'],
		['name' => 'procedure#show', 'url' => '/api/procedures/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'procedure#update', 'url' => '/api/procedures/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'procedure#destroy', 'url' => '/api/procedures/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'procedure#fork', 'url' => '/api/procedures/{id}/fork', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],

		// ── Skills (W2) ────────────────────────────────────────────────
		['name' => 'skill#index', 'url' => '/api/skills', 'verb' => 'GET'],
		['name' => 'skill#create', 'url' => '/api/skills', 'verb' => 'POST'],
		['name' => 'skill#update', 'url' => '/api/skills/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'skill#userSkills', 'url' => '/api/users/{uid}/skills', 'verb' => 'GET'],
		['name' => 'skill#setUserSkills', 'url' => '/api/users/{uid}/skills', 'verb' => 'PUT'],

		// ── Capacity (W4) ──────────────────────────────────────────────
		['name' => 'capacity#index', 'url' => '/api/capacity', 'verb' => 'GET'],
		['name' => 'capacity#set', 'url' => '/api/capacity/{uid}', 'verb' => 'PUT'],

		// ── Dispatch + tours (W3) ──────────────────────────────────────
		['name' => 'dispatch#board', 'url' => '/api/dispatch', 'verb' => 'GET'],
		['name' => 'tour#index', 'url' => '/api/tours', 'verb' => 'GET'],
		['name' => 'tour#create', 'url' => '/api/tours', 'verb' => 'POST'],
		['name' => 'tour#show', 'url' => '/api/tours/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'tour#update', 'url' => '/api/tours/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'tour#destroy', 'url' => '/api/tours/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'tour#addStop', 'url' => '/api/tours/{id}/stops', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'tour#removeStop', 'url' => '/api/tours/{id}/stops/{stopId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+', 'stopId' => '\\d+']],
		['name' => 'tour#reorder', 'url' => '/api/tours/{id}/reorder', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'tour#suggestOrder', 'url' => '/api/tours/{id}/suggest-order', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],

		// ── Meters (W5) ────────────────────────────────────────────────
		['name' => 'meter#indexForEquipment', 'url' => '/api/equipment/{equipmentId}/meters', 'verb' => 'GET', 'requirements' => ['equipmentId' => '\\d+']],
		['name' => 'meter#create', 'url' => '/api/equipment/{equipmentId}/meters', 'verb' => 'POST', 'requirements' => ['equipmentId' => '\\d+']],
		['name' => 'meter#update', 'url' => '/api/meters/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'meter#destroy', 'url' => '/api/meters/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'meter#readings', 'url' => '/api/meters/{id}/readings', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'meter#addReading', 'url' => '/api/meters/{id}/readings', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'meter#importCsv', 'url' => '/api/equipment/{equipmentId}/meters/import', 'verb' => 'POST', 'requirements' => ['equipmentId' => '\\d+']],

		// ── Mobile v1 (SPEC §9 + COMPANION §9.2 capabilities) ───────────
		['name' => 'mobile#bootstrap', 'url' => '/mobile/v1/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile#due', 'url' => '/mobile/v1/due', 'verb' => 'GET'],
		['name' => 'mobile#equipmentByQr', 'url' => '/mobile/v1/equipment/by-qr/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9.:_\\-]{8,200}']],
		['name' => 'mobile#equipment', 'url' => '/mobile/v1/equipment/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#visits', 'url' => '/mobile/v1/visits', 'verb' => 'GET'],
		['name' => 'mobile#complete', 'url' => '/mobile/v1/visits/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#skip', 'url' => '/mobile/v1/visits/{id}/skip', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#customers', 'url' => '/mobile/v1/customers', 'verb' => 'GET'],
		['name' => 'mobile#workOrders', 'url' => '/mobile/v1/work-orders', 'verb' => 'GET'],
		['name' => 'mobile#workOrder', 'url' => '/mobile/v1/work-orders/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#workOrderTransition', 'url' => '/mobile/v1/work-orders/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#workOrderChecklist', 'url' => '/mobile/v1/work-orders/{id}/checklist/{itemCode}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+', 'itemCode' => '[A-Za-z0-9._-]{1,64}']],
		['name' => 'mobile#workOrderPhotos', 'url' => '/mobile/v1/work-orders/{id}/photos', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#workOrderAddPhoto', 'url' => '/mobile/v1/work-orders/{id}/photos', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#workOrderKit', 'url' => '/mobile/v1/work-orders/{id}/kit', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#workOrderPackLine', 'url' => '/mobile/v1/work-orders/{id}/kit/lines/{lineId}/pack', 'verb' => 'POST', 'requirements' => ['id' => '\\d+', 'lineId' => '\\d+']],
		['name' => 'mobile#workOrderSignature', 'url' => '/mobile/v1/work-orders/{id}/signature', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#servicebericht', 'url' => '/mobile/v1/work-orders/{id}/pdf/servicebericht', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'mobile#tourToday', 'url' => '/mobile/v1/tours/today', 'verb' => 'GET'],
		['name' => 'mobile#equipmentMeters', 'url' => '/mobile/v1/equipment/{equipmentId}/meters', 'verb' => 'GET', 'requirements' => ['equipmentId' => '\\d+']],
		['name' => 'mobile#addMeterReading', 'url' => '/mobile/v1/meters/{id}/readings', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
	],
];
