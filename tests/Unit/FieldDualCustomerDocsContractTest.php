<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** WP-S2-FIELD-DOCS / AC-S2.5 — dual-customer onboarding must be documented + UI path. */
final class FieldDualCustomerDocsContractTest extends TestCase
{
	public function testDualCustomerDocExists(): void
	{
		$path = dirname(__DIR__, 2) . '/docs/FIELD-DUAL-CUSTOMER.md';
		$this->assertFileExists($path);
		$body = (string)file_get_contents($path);
		$this->assertStringContainsString('no silent merge', strtolower($body));
		$this->assertStringContainsString('CustomerCheck', $body);
		$this->assertStringContainsString('MaintenanceCheck', $body);
	}

	public function testReadmeLinksDualCustomerDoc(): void
	{
		$readme = (string)file_get_contents(dirname(__DIR__, 2) . '/README.md');
		$this->assertStringContainsString('FIELD-DUAL-CUSTOMER.md', $readme);
		$this->assertStringContainsString('AC-S2.5', $readme);
	}

	public function testCustomerDetailUiMentionsDualCustomer(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/app.js');
		$this->assertStringContainsString('Field vs CRM customers', $js);
		$this->assertStringContainsString('nothing merges silently', $js);
		$this->assertStringContainsString('/apps/customercheck/companies', $js);
	}
}
