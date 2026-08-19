<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * N5: mobile mutations skip CSRF only when Basic credentials actually
 * authenticate as the current session user (official companion sends
 * Basic loginName:appPassword). A cookie session plus a forged
 * `Authorization: Bearer anything` must not skip CSRF.
 */
final class MobileCsrfChannelContractTest extends TestCase
{
	public function testMutationChannelDoesNotTrustAuthorizationShape(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$start = strpos($src, 'function assertSafeMutationChannel');
		$this->assertNotFalse($start, 'assertSafeMutationChannel must exist');
		$end = strpos($src, "\n\tprivate function ", $start + 10);
		if ($end === false) {
			$end = strpos($src, "\n\tprivate function isRequestTokenValid", $start);
		}
		$channel = $end === false ? substr($src, $start) : substr($src, $start, $end - $start);

		$this->assertStringContainsString('passesCSRFCheck', $channel);
		$this->assertStringContainsString('authorizationBasicAuthenticatesCurrentUser', $channel);
		$this->assertDoesNotMatchRegularExpression(
			'/preg_match\s*\(\s*[\'"]\/\^\(Basic\|Bearer\)/',
			$channel,
			'Must not accept Authorization presence alone as a CSRF bypass',
		);
		$this->assertStringContainsString('checkPassword', $src);
		$this->assertStringContainsString('hash_equals', $src);
	}
}
