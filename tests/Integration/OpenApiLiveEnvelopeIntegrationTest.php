<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Controller\CustomerController;
use OCA\MaintenanceCheck\Controller\OpsController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Tests\Support\JsonEnvelope;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Live JSON bodies must match OpenAPI ErrorEnvelope / ListEnvelope.
 *
 * @group integration
 */
final class OpenApiLiveEnvelopeIntegrationTest extends IntegrationTestCase
{
	public function testCustomerIndexIsListEnvelope(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$controller = \OC::$server->get(CustomerController::class);
		$response = $controller->index(null, '50', '0');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue(JsonEnvelope::isList($response->getData()), json_encode($response->getData()));
	}

	public function testPermissionDeniedMapsToErrorEnvelope(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$ops = \OC::$server->get(OpsController::class);
		$mw = \OC::$server->get(AppAccessMiddleware::class);
		$response = $mw->afterException($ops, 'kpi', new PermissionDeniedException());
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue(JsonEnvelope::isError($response->getData()), json_encode($response->getData()));
		$this->assertSame('permission_denied', $response->getData()['error']['code']);
	}
}
