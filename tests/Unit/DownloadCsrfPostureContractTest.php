<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Controller\EquipDocController;
use OCA\MaintenanceCheck\Controller\OpsController;
use OCA\MaintenanceCheck\Controller\ProcedureController;
use OCA\MaintenanceCheck\Controller\WorkOrderController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Web downloads are opened via plain <a href> / window.open — browsers never
 * send requesttoken on top-level navigation. Nextcloud App Framework still
 * enforces CSRF unless #[NoCSRFRequired] is present. Missing that attribute is
 * exactly the "CSRF check failed" failure users hit on job-pack PDF links.
 *
 * Mutations (POST/PUT/DELETE) must keep CSRF: cookie-only cross-site writes
 * must remain blocked.
 */
final class DownloadCsrfPostureContractTest extends TestCase
{
	/**
	 * @return list<class-string>
	 */
	private function downloadControllers(): array
	{
		return [
			WorkOrderController::class,
			EquipDocController::class,
			OpsController::class,
			ProcedureController::class,
		];
	}

	public function testEveryDataDownloadMethodIsCsrfExempt(): void
	{
		$found = 0;
		foreach ($this->downloadControllers() as $class) {
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				$return = $method->getReturnType();
				if (!$return instanceof ReflectionNamedType || $return->getName() !== DataDownloadResponse::class) {
					continue;
				}
				$found++;
				$attrs = $method->getAttributes(NoCSRFRequired::class);
				$this->assertNotEmpty(
					$attrs,
					$class . '::' . $method->getName() . ' returns DataDownloadResponse but lacks #[NoCSRFRequired] — plain <a href> downloads will 412 CSRF failed',
				);
			}
		}
		$this->assertGreaterThanOrEqual(
			7,
			$found,
			'Expected job-pack/servicebericht/inspection PDFs, photos, signature, equip-doc, KPI CSV, procedure pack',
		);
	}

	/**
	 * @return list<array{0: class-string, 1: string}>
	 */
	public function requiredDownloadExemptions(): array
	{
		return [
			[WorkOrderController::class, 'jobPackPdf'],
			[WorkOrderController::class, 'serviceberichtPdf'],
			[WorkOrderController::class, 'inspectionEvidencePdf'],
			[WorkOrderController::class, 'downloadPhoto'],
			[WorkOrderController::class, 'downloadSignature'],
			[EquipDocController::class, 'download'],
			[OpsController::class, 'kpiCsv'],
			[ProcedureController::class, 'exportPack'],
		];
	}

	/**
	 * @dataProvider requiredDownloadExemptions
	 * @param class-string $class
	 */
	public function testNamedDownloadEndpointsAreCsrfExempt(string $class, string $method): void
	{
		$ref = new ReflectionMethod($class, $method);
		$this->assertNotEmpty(
			$ref->getAttributes(NoCSRFRequired::class),
			$class . '::' . $method . ' must be #[NoCSRFRequired] for href-based downloads',
		);
	}

	/**
	 * @return list<array{0: class-string, 1: string}>
	 */
	public function statefulMutationsMustKeepCsrf(): array
	{
		return [
			[WorkOrderController::class, 'create'],
			[WorkOrderController::class, 'createFromVisit'],
			[WorkOrderController::class, 'update'],
			[WorkOrderController::class, 'assign'],
			[WorkOrderController::class, 'transition'],
			[WorkOrderController::class, 'setChecklistResult'],
			[WorkOrderController::class, 'addPhoto'],
			[WorkOrderController::class, 'deletePhoto'],
			[WorkOrderController::class, 'setSignature'],
			[WorkOrderController::class, 'addComment'],
			[EquipDocController::class, 'create'],
			[EquipDocController::class, 'destroy'],
			[OpsController::class, 'createFailureCode'],
			[OpsController::class, 'updateFailureCode'],
			[ProcedureController::class, 'importPack'],
			[ProcedureController::class, 'destroy'],
		];
	}

	/**
	 * @dataProvider statefulMutationsMustKeepCsrf
	 * @param class-string $class
	 */
	public function testMutationsAreNotCsrfExempt(string $class, string $method): void
	{
		$ref = new ReflectionMethod($class, $method);
		$this->assertEmpty(
			$ref->getAttributes(NoCSRFRequired::class),
			$class . '::' . $method . ' must NOT be #[NoCSRFRequired] — state changes need requesttoken',
		);
	}

	public function testUiOpensPdfsViaPlainHrefNotFetchApi(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/work-order-pages.js');
		$this->assertStringContainsString("/pdf/job-pack'", $js);
		$this->assertStringContainsString("/pdf/servicebericht'", $js);
		$this->assertStringContainsString("/pdf/inspection-evidence'", $js);
		// Overflow items use href: — that path is why NoCSRFRequired is load-bearing.
		$this->assertMatchesRegularExpression(
			"/href:\\s*apiUrl\\('workOrders'\\)\\s*\\+\\s*'\\/'\\s*\\+\\s*wo\\.id\\s*\\+\\s*'\\/pdf\\/job-pack'/",
			$js,
		);
		$this->assertDoesNotMatchRegularExpression(
			"/api\\(\\s*['\"]GET['\"]\\s*,\\s*apiUrl\\('workOrders'\\).*\\/pdf\\/job-pack/",
			$js,
			'Job-pack must stay an <a href> download; do not switch to api() without also keeping NoCSRFRequired',
		);
	}
}
