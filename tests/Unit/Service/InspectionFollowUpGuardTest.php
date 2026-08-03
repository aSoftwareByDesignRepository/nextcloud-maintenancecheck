<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\InspectionFollowUpGuard;
use PHPUnit\Framework\TestCase;

final class InspectionFollowUpGuardTest extends TestCase
{
	public function testReuseWhenExistingIdPresent(): void
	{
		$this->assertSame('reuse', InspectionFollowUpGuard::decide(12));
		$this->assertSame('reuse', InspectionFollowUpGuard::decide(1));
	}

	public function testCreateWhenMissingOrInvalid(): void
	{
		$this->assertSame('create', InspectionFollowUpGuard::decide(null));
		$this->assertSame('create', InspectionFollowUpGuard::decide(0));
		$this->assertSame('create', InspectionFollowUpGuard::decide(-3));
	}
}
