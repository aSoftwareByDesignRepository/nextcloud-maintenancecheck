<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Integration;

use OCA\MaintenanceCheck\Tests\Support\JsonEnvelope;
use OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory;

/**
 * OWASP API2 / mandate item 7: every JSON route without a session must not
 * return a success body. Nextcloud answers 401 or a login redirect.
 *
 * Hits Apache inside the Nextcloud container (port 80).
 *
 * @group integration
 */
final class UnauthenticatedJsonHttpIntegrationTest extends IntegrationTestCase
{
	private string $base = '';

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['http://127.0.0.1', 'http://localhost'] as $base) {
			$probe = $this->request('GET', $base . '/status.php');
			if ($probe['status'] !== 0) {
				$this->base = $base;
				return;
			}
		}
	}

	public function testJsonGetRoutesRejectAnonymous(): void
	{
		if ($this->base === '') {
			$this->fail('Apache is not reachable at 127.0.0.1 inside the Nextcloud container');
		}
		$routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$checked = 0;
		foreach ($routes['routes'] as $route) {
			$url = (string)$route['url'];
			$verb = strtoupper((string)$route['verb']);
			$name = (string)$route['name'];
			if ($verb !== 'GET' || !RouteAuthInventory::isJsonApi($url)) {
				continue;
			}
			$path = $this->instantiatePath($url);
			$result = $this->request('GET', $this->base . '/index.php/apps/maintenancecheck' . $path);
			$checked++;
			$this->assertNotSame(
				0,
				$result['status'],
				$name . ' connection failed',
			);
			$decoded = json_decode($result['body'], true);
		$looksLikeSuccess = is_array($decoded)
			&& (JsonEnvelope::isList($decoded) || (isset($decoded['id']) && !JsonEnvelope::isError($decoded)));
		$this->assertFalse(
			$looksLikeSuccess,
			$name . ' leaked JSON to anonymous (HTTP ' . $result['status'] . ' body=' . substr($result['body'], 0, 180) . ')',
		);
		}
		$this->assertGreaterThan(40, $checked);
	}

	public function testAnonymousPostCustomerIsNotCreated(): void
	{
		if ($this->base === '') {
			$this->fail('Apache is not reachable at 127.0.0.1 inside the Nextcloud container');
		}
		$result = $this->request(
			'POST',
			$this->base . '/index.php/apps/maintenancecheck/api/customers',
			'{"name":"anon-must-fail"}',
		);
		$this->assertNotSame(201, $result['status']);
		$this->assertNotSame(200, $result['status']);
		$decoded = json_decode($result['body'], true);
		$this->assertFalse(is_array($decoded) && isset($decoded['id']));
	}

	private function instantiatePath(string $url): string
	{
		$replaced = preg_replace('/\{token\}/', 'AaAaAaAaAaAaAaAa', $url) ?? $url;
		return preg_replace('/\{[^}]+\}/', '1', $replaced) ?? $replaced;
	}

	/**
	 * @return array{status: int, body: string}
	 */
	private function request(string $method, string $url, ?string $body = null): array
	{
		$headers = "Accept: application/json\r\n";
		if ($body !== null) {
			$headers .= "Content-Type: application/json\r\n";
		}
		$ctx = stream_context_create([
			'http' => [
				'method' => $method,
				'timeout' => 8,
				'ignore_errors' => true,
				'follow_location' => 0,
				'header' => $headers,
				'content' => $body ?? '',
			],
		]);
		$raw = @file_get_contents($url, false, $ctx);
		$status = 0;
		if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
			$status = (int)$m[1];
		}
		return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
	}
}
