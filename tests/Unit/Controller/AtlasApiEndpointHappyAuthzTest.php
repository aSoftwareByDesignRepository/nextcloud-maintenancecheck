<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

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
use OCA\MaintenanceCheck\Exception\AppAccessDeniedException;
use OCA\MaintenanceCheck\Exception\MobileGateException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\MobileGateService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Atlas v3 — per-endpoint happy (2xx/designed) + AuthZ deny proofs.
 */
final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	private const CONTROLLERS = [
		CapacityController::class,
		CatalogController::class,
		ConfigController::class,
		CustomerController::class,
		DispatchController::class,
		EquipDocController::class,
		EquipmentController::class,
		InspectionObligationController::class,
		KitController::class,
		LicenseController::class,
		MeterController::class,
		MobileController::class,
		OpsController::class,
		PageController::class,
		PlanController::class,
		ProcedureController::class,
		SiteController::class,
		SkillController::class,
		TourController::class,
		VisitController::class,
		WorkOrderController::class,
	];

	/** @var array<string, list<string>> */
	private const AUTHZ_ACTIONS = [
		CapacityController::class => [
			'set',
		],
		CatalogController::class => [
			'createEquipType', 'createMaintType', 'updateEquipType', 'updateMaintType',
		],
		ConfigController::class => [
			'saveAccess', 'saveInventoryFlange', 'saveOffice', 'savePolicies',
		],
		CustomerController::class => [
			'create', 'destroy', 'ensureLink', 'show', 'unlinkIdentity', 'update',
		],
		EquipDocController::class => [
			'create', 'destroy', 'download', 'index',
		],
		EquipmentController::class => [
			'create', 'destroy', 'rotateQr', 'show', 'update',
		],
		InspectionObligationController::class => [
			'create', 'index',
		],
		KitController::class => [
			'addLine', 'attach', 'createTemplate', 'packLine', 'removeLine', 'showTemplate', 'updateTemplate',
		],
		LicenseController::class => [
			'apply', 'assignSeat', 'remove', 'removeSeat',
		],
		MeterController::class => [
			'addReading', 'create', 'destroy', 'importCsv', 'indexForEquipment', 'readings', 'update',
		],
		MobileController::class => [
			'addMeterReading', 'complete', 'createWorkOrderFromVisit', 'downloadEquipDoc', 'equipment', 'equipmentByQr', 'equipmentDocs', 'equipmentMeters', 'equipmentObligations', 'inspectionEvidence', 'inspectionEvidenceAlias', 'servicebericht', 'skip', 'visit', 'workOrder', 'workOrderAddComment', 'workOrderAddPhoto', 'workOrderChecklist', 'workOrderComments', 'workOrderKit', 'workOrderPackLine', 'workOrderPhotos', 'workOrderSignature', 'workOrderTransition',
		],
		OpsController::class => [
			'createFailureCode', 'reminderDryRun', 'updateFailureCode',
		],
		PlanController::class => [
			'create', 'deactivate', 'indexForEquipment', 'schedule', 'update',
		],
		ProcedureController::class => [
			'create', 'destroy', 'fork', 'importPack', 'show', 'update',
		],
		SiteController::class => [
			'create', 'destroy', 'indexForCustomer', 'update',
		],
		SkillController::class => [
			'create', 'setUserSkills', 'update', 'userSkills',
		],
		TourController::class => [
			'addStop', 'create', 'destroy', 'removeStop', 'reorder', 'show', 'suggestOrder', 'update',
		],
		VisitController::class => [
			'assign', 'cancel', 'complete', 'show', 'skip', 'update',
		],
		WorkOrderController::class => [
			'addComment', 'addPhoto', 'assign', 'comments', 'create', 'createFromVisit', 'deletePhoto', 'downloadPhoto', 'downloadSignature', 'inspectionEvidencePdf', 'jobPackPdf', 'listPhotos', 'serviceberichtPdf', 'setChecklistResult', 'setSignature', 'setSkills', 'show', 'transition', 'update',
		],
	];

	/** @var array<string, list<string>> */
	private const MIDDLEWARE_AUTHZ = [
		CustomerController::class => [
			'show',
		],
		EquipDocController::class => [
			'download', 'index',
		],
		EquipmentController::class => [
			'show',
		],
		InspectionObligationController::class => [
			'create', 'index',
		],
		KitController::class => [
			'packLine', 'showTemplate',
		],
		MeterController::class => [
			'addReading', 'indexForEquipment', 'readings',
		],
		MobileController::class => [
			'inspectionEvidenceAlias',
		],
		PlanController::class => [
			'indexForEquipment',
		],
		ProcedureController::class => [
			'show',
		],
		SiteController::class => [
			'indexForCustomer',
		],
		VisitController::class => [
			'complete', 'show', 'skip',
		],
		WorkOrderController::class => [
			'addComment', 'addPhoto', 'comments', 'create', 'deletePhoto', 'downloadPhoto', 'downloadSignature', 'inspectionEvidencePdf', 'jobPackPdf', 'listPhotos', 'serviceberichtPdf', 'setChecklistResult', 'setSignature', 'show', 'transition',
		],
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOrDesignedStatus(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				if (!(($status >= 200 && $status < 300) || ($status >= 300 && $status < 400))) {
					$body = '';
					if ($result instanceof JSONResponse) {
						$body = (string)json_encode($result->getData());
					}
					$failures[] = $symbol . ' status=' . $status . ' body=' . $body;
					continue;
				}
				// Strict Atlas envelope: 2xx JSON arrays must carry ok:true (not merely absent).
				if ($status < 300 && $result instanceof JSONResponse) {
					$data = $result->getData();
					if (is_array($data) && ($data['ok'] ?? null) !== true) {
						$failures[] = $symbol . ' ok!=true body=' . json_encode($data);
						continue;
					}
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(150, count($proved), 'expected ≥150 controller actions, got ' . count($proved));
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$ref = new ReflectionClass($class);
				self::assertTrue($ref->hasMethod($action), $class . '::' . $action);
				$symbol = $ref->getShortName() . '::' . $action;

				if ($this->isMiddlewareAuthz($class, $action)) {
					try {
						$this->invokeMiddlewareDeny($class, $action);
						$failures[] = $symbol . ' middleware did not deny';
					} catch (AppAccessDeniedException $e) {
						self::assertSame(403, $e->getHttpStatus(), $symbol);
						self::assertSame('app_access_denied', $e->getMessage(), $symbol);
						$proved[] = $symbol . '@middleware';
					} catch (\Throwable $e) {
						$failures[] = $symbol . ' middleware threw ' . $e::class . ': ' . $e->getMessage();
					}
					continue;
				}

				$ctrl = $this->buildController($class, allow: false, mode: 'authz');
				$method = $ref->getMethod($action);
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method, authz: true));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					$status = $result->getStatus();
					// Exact deny band only: 403 (permission/app) or 402 (mobile gate). Never 404 / soft >=400.
					if (!in_array($status, [403, 402], true)) {
						$body = $result instanceof JSONResponse ? (string)json_encode($result->getData()) : '';
						$failures[] = $symbol . ' deny status=' . $status . ' body=' . $body;
						continue;
					}
					if ($result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== false) {
							$failures[] = $symbol . ' deny envelope ok!=false';
							continue;
						}
					}
				} catch (PermissionDeniedException|AppAccessDeniedException $e) {
					self::assertSame(403, $e->getHttpStatus(), $symbol);
					self::assertNotSame('', $e->getMessage(), $symbol);
				} catch (MobileGateException $e) {
					self::assertSame(402, $e->getHttpStatus(), $symbol);
					self::assertContains(
						$e->getErrorCode(),
						['license_missing', 'license_expired', 'seat_required', 'seat_limit_exceeded'],
						$symbol
					);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(100, count($proved), 'expected ≥100 authz negatives, got ' . count($proved));
		self::assertTrue(
			in_array('MobileController::bootstrap', $proved, true)
			|| in_array('MobileController::visit', $proved, true),
			'expected at least one MobileController AuthZ proof'
		);
	}

	/** @param class-string $class */
	private function isMiddlewareAuthz(string $class, string $action): bool
	{
		return in_array($action, self::MIDDLEWARE_AUTHZ[$class] ?? [], true);
	}

	/** @param class-string $class */
	private function invokeMiddlewareDeny(string $class, string $action): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('bob')->willReturn(false);
		$access->method('denialReasonWhenCannotUseApp')->with('bob')
			->willReturn(AccessControlService::DENIAL_RESTRICTION);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/maintenancecheck/api/x');
		$request->method('getMethod')->willReturn('GET');

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/apps/files');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$mw = new AppAccessMiddleware($session, $access, $request, $url, $factory);
		$ctrl = $this->buildController($class, allow: true, mode: 'happy');
		$mw->beforeController($ctrl, $action);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'maintenancecheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request();
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode, $class);
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	/** @param class-string $controllerClass */
	private function mockFor(string $typeName, bool $allow, string $mode, string $controllerClass): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode . ':' . $controllerClass;
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === AccessControlService::class) {
			$mock = $this->createMock(AccessControlService::class);
			$mock->method('currentUserId')->willReturn('alice');
			$mock->method('isAppAdmin')->willReturn($allow);
			$mock->method('isOffice')->willReturn($allow);
			$mock->method('isSystemAdmin')->willReturn($allow);
			$mock->method('canUseApp')->willReturn($allow);
			$mock->method('denialReasonWhenCannotUseApp')->willReturn(AccessControlService::DENIAL_RESTRICTION);
			$mock->method('requireAppAdmin')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new PermissionDeniedException('admin');
					}
				}
			);
			$mock->method('requireOffice')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new PermissionDeniedException('office');
					}
				}
			);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === MobileGateService::class) {
			$mock = $this->createMock(MobileGateService::class);
			$mock->method('assertGatePassed')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new MobileGateException('seat_required');
					}
				}
			);
			$mock->method('bootstrapPayload')->willReturn([
				'ok' => true,
				'user' => ['id' => 'alice', 'displayName' => 'Alice'],
				'licensed' => true,
				'seatAssigned' => true,
				'capabilities' => [],
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserSession::class) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$this->byType[$key] = $session;
			return $session;
		}

		if ($typeName === IFactory::class) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
			$factory = $this->createMock(IFactory::class);
			$factory->method('get')->willReturn($l10n);
			$this->byType[$key] = $factory;
			return $factory;
		}

		if ($typeName === IL10N::class) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
			$this->byType[$key] = $l10n;
			return $l10n;
		}

		if ($typeName === IURLGenerator::class) {
			$url = $this->createMock(IURLGenerator::class);
			$url->method('linkToRoute')->willReturn('/apps/maintenancecheck/');
			$url->method('linkToRouteAbsolute')->willReturn('http://localhost/apps/maintenancecheck/');
			$url->method('linkToDefaultPageUrl')->willReturn('/apps/files');
			$this->byType[$key] = $url;
			return $url;
		}

		if ($typeName === IConfig::class) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturn('0');
			$config->method('getUserValue')->willReturn('');
			$this->byType[$key] = $config;
			return $config;
		}

		if ($typeName === IUserManager::class) {
			$um = $this->createMock(IUserManager::class);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('bob');
			$user->method('getDisplayName')->willReturn('Bob');
			$um->method('get')->willReturn($user);
			$um->method('search')->willReturn([]);
			$um->method('userExists')->willReturn(true);
			$um->method('checkPassword')->willReturn($user);
			$this->byType[$key] = $um;
			return $um;
		}

		if ($typeName === IGroupManager::class) {
			$gm = $this->createMock(IGroupManager::class);
			$gm->method('search')->willReturn([]);
			$gm->method('isAdmin')->willReturn(false);
			$this->byType[$key] = $gm;
			return $gm;
		}

		// Binary / PDF download payloads must include string content.
		if (str_ends_with($typeName, '\\EquipDocService')
			|| str_ends_with($typeName, '\\WoEvidenceService')
			|| str_ends_with($typeName, '\\WoPdfService')
		) {
			$bin = [
				'content' => '%PDF-1.4 mock',
				'name' => 'doc.pdf',
				'filename' => 'doc.pdf',
				'mime' => 'application/pdf',
				'contentType' => 'application/pdf',
				'id' => 1,
				'ok' => true,
			];
			$mock = $this->createMock($typeName);
			$sref = new ReflectionClass($typeName);
			foreach ($sref->getMethods(ReflectionMethod::IS_PUBLIC) as $sm) {
				if ($sm->getDeclaringClass()->getName() !== $typeName || $sm->isConstructor()) {
					continue;
				}
				try {
					$mock->method($sm->getName())->willReturn($bin);
				} catch (\Throwable) {
				}
			}
			$this->byType[$key] = $mock;
			return $mock;
		}

		// Generic service / facade mock — return array-ish happy payloads for any method.
		$mock = $this->createMock($typeName);
		if (class_exists($typeName) || interface_exists($typeName)) {
			try {
				$sref = new ReflectionClass($typeName);
				foreach ($sref->getMethods(ReflectionMethod::IS_PUBLIC) as $sm) {
					if ($sm->getDeclaringClass()->getName() !== $typeName) {
						continue;
					}
					if ($sm->isConstructor() || $sm->isDestructor() || $sm->isStatic()) {
						continue;
					}
					$name = $sm->getName();
					$ret = $sm->getReturnType();
					$payload = $this->defaultReturn($ret, $name);
					try {
						$mock->method($name)->willReturn($payload);
					} catch (\Throwable) {
						// void / unmockable
					}
				}
			} catch (\Throwable) {
			}
		}
		$this->byType[$key] = $mock;
		return $mock;
	}

	private function defaultReturn(?\ReflectionType $ret, string $name): mixed
	{
		if ($ret instanceof ReflectionNamedType) {
			if ($ret->getName() === 'void') {
				return null;
			}
			if ($ret->getName() === 'array') {
				if (str_contains(strtolower($name), 'list') || $name === 'index' || str_starts_with($name, 'list')) {
					return ['ok' => true, 'items' => [], 'total' => 0];
				}
				return ['id' => 1, 'ok' => true, 'status' => 'open'];
			}
			if ($ret->getName() === 'string') {
				return 'x';
			}
			if ($ret->getName() === 'int') {
				return 1;
			}
			if ($ret->getName() === 'bool') {
				return true;
			}
			if ($ret->getName() === 'float') {
				return 1.0;
			}
			if (!$ret->isBuiltin()) {
				// entity-ish — leave null; callers may need overrides
				return null;
			}
		}
		return ['id' => 1, 'ok' => true];
	}

	private function request(): IRequest
	{
		$params = [
			'name' => 'Test',
			'title' => 'Test',
			'code' => 'T1',
			'key' => 'LICENSE-KEY-TEST-0001',
			'csv' => "meter,value\nM1,1\n",
			'contentBase64' => base64_encode('fakepngbytes'),
			'fileName' => 'photo.png',
			'customerId' => 1,
			'equipmentId' => 1,
			'visitId' => 1,
			'workOrderId' => 1,
			'userId' => 'bob',
			'imageBase64' => base64_encode('iVBORw0KGgo='),
			'status' => 'open',
			'qty' => 1,
			'value' => 1,
			'note' => 'n',
			'result' => 'ok',
			'pass' => true,
			'skills' => [],
			'stops' => [],
			'items' => [],
			'payload' => [],
			'updatedAt' => 1,
			'force' => '0',
		];
		$req = $this->createMock(IRequest::class);
		$req->method('passesCSRFCheck')->willReturn(true);
		$req->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return $params[$key] ?? $default;
			}
		);
		$req->method('getParams')->willReturn($params);
		$req->method('getHeader')->willReturn('');
		$req->method('getUploadedFile')->willReturn([
			'tmp_name' => '/tmp/x',
			'name' => 'x.png',
			'type' => 'image/png',
			'size' => 4,
			'error' => 0,
		]);
		return $req;
	}

	private function dummyArgs(ReflectionMethod $method, bool $authz = false): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			// AuthZ: force cross-user uid so office_or_self denies when !isOffice.
			if ($authz && $param->getName() === 'uid') {
				$args[] = 'other-user';
				continue;
			}
			if ($param->getName() === 'userId' && $param->getType() instanceof ReflectionNamedType
				&& $param->getType()->getName() === 'string') {
				$args[] = 'bob';
				continue;
			}
			if ($param->isDefaultValueAvailable()) {
				// ConfigController::userAccess(?string $userId = null) needs a value.
				if ($param->getName() === 'userId') {
					$args[] = 'bob';
					continue;
				}
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull()) {
					if ($param->getName() === 'userId') {
						$args[] = 'bob';
						continue;
					}
					$args[] = null;
					continue;
				}
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => 'alice',
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => null,
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
