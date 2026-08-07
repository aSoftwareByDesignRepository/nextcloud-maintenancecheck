<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY AC-C-20 — MN erase clears soft links via row delete; no PC/CRM cascade.
 */
final class CustomerDeleteNoSiblingCascadeContractTest extends TestCase
{
	public function testDeleteDoesNotCallSiblingFacadesOrApps(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CustomerService.php');
		$methodStart = strpos($src, 'public function delete(int $id, bool $force): array');
		$this->assertNotFalse($methodStart);
		$methodEnd = strpos($src, 'private function applyFields', $methodStart);
		$this->assertNotFalse($methodEnd);
		$delete = substr($src, $methodStart, $methodEnd - $methodStart);

		$this->assertStringContainsString('$this->customers->delete($customer)', $delete);
		$this->assertStringNotContainsString('ProjectCheck', $delete);
		$this->assertStringNotContainsString('CustomerCheck', $delete);
		$this->assertStringNotContainsString('CrmFieldCustomerFacade', $delete);
		$this->assertStringNotContainsString('ICustomerWriteFacade', $delete);
		$this->assertStringNotContainsString('createOrLink', $delete);
	}

	public function testFieldDocsDocumentEraseClearsSoftLinksWithoutCascade(): void
	{
		$body = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/FIELD-DUAL-CUSTOMER.md');
		$this->assertMatchesRegularExpression('/soft-link columns/i', $body);
		$this->assertMatchesRegularExpression('/does \\*\\*not\\*\\* delete commercial/i', $body);
	}
}
