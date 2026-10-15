<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller {
	/**
	 * Fixture: AppAccessMiddleware only inspects the class-name prefix.
	 * Lives in this file so lib/Controller scans never see it.
	 */
	final class JsonFallthroughProbeController
	{
	}
}

namespace OCA\MaintenanceCheck\Tests\Unit\Middleware {

use OCA\MaintenanceCheck\Controller\JsonFallthroughProbeController;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * api_500_html_envelope contract: an unexpected Throwable on a JSON route
 * must collapse into the §7.1 envelope — never the core HTML 500 page.
 * Page GET routes keep core handling (re-throw).
 */
final class AppAccessMiddlewareJsonFallthroughTest extends TestCase
{
	private function middleware(string $path, string $method): AppAccessMiddleware
	{
		$session = $this->createMock(IUserSession::class);
		$access = $this->createMock(AccessControlService::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn($method);
		$url = $this->createMock(IURLGenerator::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return new AppAccessMiddleware($session, $access, $request, $url, $factory, $this->createMock(LoggerInterface::class));
	}

	public function testApiRouteUnexpectedThrowableYieldsJsonEnvelope(): void
	{
		$mw = $this->middleware('/apps/maintenancecheck/api/customers', 'GET');
		$response = $mw->afterException(
			new JsonFallthroughProbeController(),
			'index',
			new \RuntimeException('DB connection lost'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$body = $response->getData();
		$this->assertSame('internal_error', $body['error']['code']);
		$this->assertIsString($body['error']['message']);
		$this->assertNotSame('', $body['error']['message']);
		// The raw exception detail must not leak into the client payload.
		$this->assertStringNotContainsString('DB connection lost', (string)$body['error']['message']);
	}

	public function testMobileRouteUnexpectedThrowableYieldsJsonEnvelope(): void
	{
		$mw = $this->middleware('/apps/maintenancecheck/mobile/workorders', 'GET');
		$response = $mw->afterException(
			new JsonFallthroughProbeController(),
			'list',
			new \RuntimeException('boom'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal_error', $response->getData()['error']['code']);
	}

	public function testNonGetRouteUnexpectedThrowableYieldsJsonEnvelope(): void
	{
		$mw = $this->middleware('/apps/maintenancecheck/settings', 'POST');
		$response = $mw->afterException(
			new JsonFallthroughProbeController(),
			'save',
			new \RuntimeException('boom'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal_error', $response->getData()['error']['code']);
	}

	public function testPageGetRouteRethrowsForCoreErrorPage(): void
	{
		$mw = $this->middleware('/apps/maintenancecheck/', 'GET');
		$exception = new \RuntimeException('boom');
		try {
			$mw->afterException(new JsonFallthroughProbeController(), 'index', $exception);
			$this->fail('expected the exception to be re-thrown on page GET routes');
		} catch (\RuntimeException $thrown) {
			$this->assertSame($exception, $thrown);
		}
	}

	public function testDomainExceptionsStillMapToTheirEnvelope(): void
	{
		$mw = $this->middleware('/apps/maintenancecheck/api/customers/999', 'GET');
		$response = $mw->afterException(
			new JsonFallthroughProbeController(),
			'show',
			new NotFoundException('gone'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('not_found', $response->getData()['error']['code']);
	}
}

}
