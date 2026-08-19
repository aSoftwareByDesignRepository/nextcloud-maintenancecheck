<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Support;

use OCA\MaintenanceCheck\Controller\CapacityController;
use OCA\MaintenanceCheck\Controller\CatalogController;
use OCA\MaintenanceCheck\Controller\ConfigController;
use OCA\MaintenanceCheck\Controller\CustomerController;
use OCA\MaintenanceCheck\Controller\DispatchController;
use OCA\MaintenanceCheck\Controller\EquipDocController;
use OCA\MaintenanceCheck\Controller\EquipmentController;
use OCA\MaintenanceCheck\Controller\InspectionObligationController;
use OCA\MaintenanceCheck\Controller\KitController;
use OCA\MaintenanceCheck\Controller\LicenseController;
use OCA\MaintenanceCheck\Controller\MeterController;
use OCA\MaintenanceCheck\Controller\MobileController;
use OCA\MaintenanceCheck\Controller\OpsController;
use OCA\MaintenanceCheck\Controller\PageController;
use OCA\MaintenanceCheck\Controller\PlanController;
use OCA\MaintenanceCheck\Controller\ProcedureController;
use OCA\MaintenanceCheck\Controller\SiteController;
use OCA\MaintenanceCheck\Controller\SkillController;
use OCA\MaintenanceCheck\Controller\TourController;
use OCA\MaintenanceCheck\Controller\VisitController;
use OCA\MaintenanceCheck\Controller\WorkOrderController;

/**
 * Single source for route → auth-gate classification. Used by the unit
 * contract test, OpenAPI fixture, and the technician denial matrix.
 *
 * @phpstan-type Gate string
 */
final class RouteAuthInventory
{
	/** @var list<string> */
	public const ALLOWED_GATES = [
		'page_l2', 'page_office', 'page_l2_tours_scoped',
		'l2_read', 'l2_p3', 'l2_mine_or_office', 'l2_corrective_or_office',
		'l2_own_or_office', 'office', 'office_or_self', 'execute', 'app_admin',
		'mobile_bootstrap', 'mobile_read', 'mobile_n5', 'mobile_read_self',
	];

	/**
	 * @return array<string, string>
	 */
	public static function gates(): array
	{
		return [
			'page#due' => 'page_l2',
			'page#dueAlias' => 'page_l2',
			'page#customers' => 'page_l2',
			'page#customer' => 'page_l2',
			'page#equipment' => 'page_l2',
			'page#equipmentByQr' => 'page_l2',
			'page#equipmentShow' => 'page_l2',
			'page#visits' => 'page_l2',
			'page#catalogs' => 'page_l2',
			'page#settings' => 'page_l2',
			'page#settingsSection' => 'page_l2',
			'page#workOrders' => 'page_l2',
			'page#workOrderShow' => 'page_l2',
			'page#dispatch' => 'page_office',
			'page#tours' => 'page_l2_tours_scoped',
			'page#kpi' => 'page_office',
			'page#exceptions' => 'page_office',
			'customer#index' => 'l2_read',
			'customer#show' => 'l2_read',
			'customer#create' => 'office',
			'customer#update' => 'office',
			'customer#destroy' => 'office',
			'customer#ensureLink' => 'office',
			'customer#unlinkIdentity' => 'office',
			'equipment#index' => 'l2_read',
			'equipment#show' => 'l2_read',
			'equipment#create' => 'office',
			'equipment#update' => 'office',
			'equipment#destroy' => 'office',
			'equipment#rotateQr' => 'office',
			'plan#indexForEquipment' => 'l2_read',
			'plan#create' => 'office',
			'plan#update' => 'office',
			'plan#deactivate' => 'office',
			'plan#schedule' => 'office',
			'visit#index' => 'l2_read',
			'visit#due' => 'l2_read',
			'visit#show' => 'l2_read',
			'visit#update' => 'office',
			'visit#complete' => 'l2_p3',
			'visit#skip' => 'l2_p3',
			'visit#cancel' => 'office',
			'visit#assign' => 'office',
			'catalog#equipTypes' => 'l2_read',
			'catalog#createEquipType' => 'office',
			'catalog#updateEquipType' => 'office',
			'catalog#maintTypes' => 'l2_read',
			'catalog#createMaintType' => 'office',
			'catalog#updateMaintType' => 'office',
			'config#index' => 'app_admin',
			'config#saveAccess' => 'app_admin',
			'config#saveOffice' => 'app_admin',
			'config#saveInventoryFlange' => 'app_admin',
			'config#savePolicies' => 'app_admin',
			'config#userAccess' => 'office',
			'config#searchUsers' => 'app_admin',
			'config#searchGroups' => 'app_admin',
			'license#show' => 'app_admin',
			'license#apply' => 'app_admin',
			'license#remove' => 'app_admin',
			'license#seats' => 'app_admin',
			'license#assignSeat' => 'app_admin',
			'license#removeSeat' => 'app_admin',
			'work_order#index' => 'l2_mine_or_office',
			'work_order#create' => 'l2_corrective_or_office',
			'work_order#show' => 'execute',
			'work_order#update' => 'office',
			'work_order#createFromVisit' => 'office',
			'work_order#assign' => 'office',
			'work_order#transition' => 'execute',
			'work_order#setChecklistResult' => 'execute',
			'work_order#setSkills' => 'office',
			'work_order#listPhotos' => 'execute',
			'work_order#addPhoto' => 'execute',
			'work_order#downloadPhoto' => 'execute',
			'work_order#deletePhoto' => 'execute',
			'work_order#setSignature' => 'execute',
			'work_order#downloadSignature' => 'execute',
			'work_order#jobPackPdf' => 'execute',
			'work_order#serviceberichtPdf' => 'execute',
			'work_order#inspectionEvidencePdf' => 'execute',
			'work_order#comments' => 'execute',
			'work_order#addComment' => 'execute',
			'kit#indexTemplates' => 'l2_read',
			'kit#createTemplate' => 'office',
			'kit#showTemplate' => 'l2_read',
			'kit#updateTemplate' => 'office',
			'kit#attach' => 'office',
			'kit#addLine' => 'office',
			'kit#packLine' => 'execute',
			'kit#removeLine' => 'office',
			'site#indexForCustomer' => 'l2_read',
			'site#create' => 'office',
			'site#update' => 'office',
			'site#destroy' => 'office',
			'procedure#index' => 'l2_read',
			'procedure#create' => 'office',
			'procedure#exportPack' => 'office',
			'procedure#importPack' => 'office',
			'procedure#show' => 'l2_read',
			'procedure#update' => 'office',
			'procedure#destroy' => 'office',
			'procedure#fork' => 'office',
			'skill#index' => 'l2_read',
			'skill#create' => 'office',
			'skill#update' => 'office',
			'skill#userSkills' => 'office_or_self',
			'skill#setUserSkills' => 'office',
			'capacity#index' => 'office',
			'capacity#set' => 'office',
			'dispatch#board' => 'office',
			'tour#index' => 'l2_own_or_office',
			'tour#create' => 'office',
			'tour#show' => 'l2_own_or_office',
			'tour#update' => 'office',
			'tour#destroy' => 'office',
			'tour#addStop' => 'office',
			'tour#removeStop' => 'office',
			'tour#reorder' => 'office',
			'tour#suggestOrder' => 'office',
			'meter#indexForEquipment' => 'l2_read',
			'meter#create' => 'office',
			'meter#update' => 'office',
			'meter#destroy' => 'office',
			'meter#readings' => 'l2_read',
			'meter#addReading' => 'l2_p3',
			'meter#importCsv' => 'office',
			'mobile#bootstrap' => 'mobile_bootstrap',
			'mobile#due' => 'mobile_read',
			'mobile#equipmentByQr' => 'mobile_read',
			'mobile#equipment' => 'mobile_read',
			'mobile#visits' => 'mobile_read',
			'mobile#visit' => 'mobile_read',
			'mobile#complete' => 'mobile_n5',
			'mobile#skip' => 'mobile_n5',
			'mobile#customers' => 'mobile_read',
			'mobile#workOrders' => 'mobile_read',
			'mobile#createWorkOrderFromVisit' => 'mobile_n5',
			'mobile#workOrder' => 'mobile_read',
			'mobile#workOrderTransition' => 'mobile_n5',
			'mobile#workOrderChecklist' => 'mobile_n5',
			'mobile#workOrderPhotos' => 'mobile_read',
			'mobile#workOrderAddPhoto' => 'mobile_n5',
			'mobile#workOrderKit' => 'mobile_read',
			'mobile#workOrderPackLine' => 'mobile_n5',
			'mobile#workOrderSignature' => 'mobile_n5',
			'mobile#servicebericht' => 'mobile_read',
			'mobile#inspectionEvidence' => 'mobile_read',
			'mobile#inspectionEvidenceAlias' => 'mobile_read',
			'mobile#equipmentObligations' => 'mobile_read',
			'mobile#tourToday' => 'mobile_read',
			'mobile#equipmentMeters' => 'mobile_read',
			'mobile#addMeterReading' => 'mobile_n5',
			'mobile#workOrderComments' => 'mobile_read',
			'mobile#workOrderAddComment' => 'mobile_n5',
			'mobile#equipmentDocs' => 'mobile_read',
			'mobile#downloadEquipDoc' => 'mobile_read',
			'mobile#failureCodes' => 'mobile_read',
			'mobile#exceptions' => 'mobile_read_self',
			'ops#kpi' => 'office',
			'ops#kpiCsv' => 'office',
			'ops#exceptions' => 'office',
			'ops#failureCodes' => 'l2_read',
			'ops#createFailureCode' => 'office',
			'ops#updateFailureCode' => 'office',
			'ops#reminderDryRun' => 'app_admin',
			'equip_doc#index' => 'l2_read',
			'equip_doc#create' => 'office',
			'equip_doc#destroy' => 'office',
			'equip_doc#download' => 'l2_read',
			'inspection_obligation#classes' => 'l2_read',
			'inspection_obligation#index' => 'l2_read',
			'inspection_obligation#create' => 'office',
		];
	}

	public static function technicianMustBeDenied(string $gate): bool
	{
		return in_array($gate, ['office', 'app_admin', 'page_office'], true);
	}

	/**
	 * @return class-string
	 */
	public static function controllerClass(string $routeName): string
	{
		$prefix = explode('#', $routeName, 2)[0];
		return match ($prefix) {
			'page' => PageController::class,
			'customer' => CustomerController::class,
			'equipment' => EquipmentController::class,
			'plan' => PlanController::class,
			'visit' => VisitController::class,
			'catalog' => CatalogController::class,
			'config' => ConfigController::class,
			'license' => LicenseController::class,
			'work_order' => WorkOrderController::class,
			'kit' => KitController::class,
			'site' => SiteController::class,
			'procedure' => ProcedureController::class,
			'skill' => SkillController::class,
			'capacity' => CapacityController::class,
			'dispatch' => DispatchController::class,
			'tour' => TourController::class,
			'meter' => MeterController::class,
			'mobile' => MobileController::class,
			'ops' => OpsController::class,
			'equip_doc' => EquipDocController::class,
			'inspection_obligation' => InspectionObligationController::class,
			default => throw new \InvalidArgumentException('Unknown controller prefix: ' . $prefix),
		};
	}

	/**
	 * OpenAPI 3.0 document derived from appinfo/routes.php + gates().
	 *
	 * @param array{routes: list<array{name: string, url: string, verb: string}>} $routesFile
	 * @return array<string, mixed>
	 */
	public static function openapiDocument(array $routesFile): array
	{
		$gates = self::gates();
		$paths = [];
		foreach ($routesFile['routes'] as $route) {
			$name = (string)$route['name'];
			$url = (string)$route['url'];
			$verb = strtolower((string)$route['verb']);
			$gate = $gates[$name] ?? 'unknown';
			$template = preg_replace('/\{([^}]+)\}/', '{$1}', $url) ?? $url;
			if (!isset($paths[$template])) {
				$paths[$template] = [];
			}
			$paths[$template][$verb] = [
				'operationId' => $name,
				'summary' => $name,
				'x-mn-gate' => $gate,
				'security' => [
					['nextcloud_session' => []],
					['basic_auth' => []],
				],
				'responses' => self::responsesFor($name, $url),
			];
		}
		ksort($paths);
		return [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'MaintenanceCheck',
				'version' => '1.2.8',
				'description' => 'Web `/api/*` and companion `/mobile/v1/*`. Auth: Nextcloud session or HTTP Basic app password, then L2 app access, then the per-route x-mn-gate. JSON errors use ErrorEnvelope (§7.1). List GETs use ListEnvelope `{data, total?}`.',
			],
			'servers' => [
				['url' => '/index.php/apps/maintenancecheck'],
			],
			'paths' => $paths,
			'components' => [
				'schemas' => self::schemas(),
				'securitySchemes' => [
					'nextcloud_session' => [
						'type' => 'apiKey',
						'in' => 'cookie',
						'name' => 'nc_session_id',
					],
					'basic_auth' => [
						'type' => 'http',
						'scheme' => 'basic',
					],
				],
			],
		];
	}

	/** @var list<string> */
	public const BINARY_DOWNLOADS = [
		'work_order#downloadPhoto',
		'work_order#downloadSignature',
		'work_order#jobPackPdf',
		'work_order#serviceberichtPdf',
		'work_order#inspectionEvidencePdf',
		'ops#kpiCsv',
		'equip_doc#download',
		'mobile#servicebericht',
		'mobile#inspectionEvidence',
		'mobile#inspectionEvidenceAlias',
		'mobile#downloadEquipDoc',
	];

	public static function isJsonApi(string $url): bool
	{
		return str_starts_with($url, '/api/') || str_starts_with($url, '/mobile/');
	}

	public static function isBinaryDownload(string $name): bool
	{
		return in_array($name, self::BINARY_DOWNLOADS, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function responsesFor(string $name, string $url): array
	{
		$error = [
			'description' => 'SPEC §7.1 error envelope',
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
				],
			],
		];
		if (!self::isJsonApi($url)) {
			return [
				'200' => [
					'description' => 'HTML page',
					'content' => ['text/html' => ['schema' => ['type' => 'string']]],
				],
				'401' => ['description' => 'No Nextcloud session (login redirect or 401)'],
				'403' => ['description' => 'Authenticated but not allowed for this gate'],
			];
		}
		if (self::isBinaryDownload($name)) {
			return [
				'200' => ['description' => 'Binary download (PDF, photo, CSV, signature)'],
				'401' => $error,
				'403' => $error,
			];
		}
		return [
			'200' => [
				'description' => 'JSON success (object; list endpoints include data[])',
				'content' => [
					'application/json' => [
						'schema' => [
							'oneOf' => [
								['$ref' => '#/components/schemas/ListEnvelope'],
								['$ref' => '#/components/schemas/JsonObject'],
							],
						],
					],
				],
			],
			'401' => $error,
			'403' => $error,
			'404' => $error,
			'409' => $error,
			'422' => $error,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function schemas(): array
	{
		return [
			'ErrorEnvelope' => [
				'type' => 'object',
				'required' => ['error'],
				'additionalProperties' => false,
				'properties' => [
					'error' => [
						'type' => 'object',
						'required' => ['code', 'message'],
						'properties' => [
							'code' => ['type' => 'string', 'minLength' => 1],
							'message' => ['type' => 'string'],
							'details' => ['type' => 'object'],
						],
					],
				],
			],
			'ListEnvelope' => [
				'type' => 'object',
				'required' => ['data'],
				'properties' => [
					'data' => ['type' => 'array'],
					'total' => ['type' => 'integer'],
					'limit' => ['type' => 'integer'],
					'offset' => ['type' => 'integer'],
				],
			],
			'JsonObject' => [
				'type' => 'object',
				'additionalProperties' => true,
			],
		];
	}
}
