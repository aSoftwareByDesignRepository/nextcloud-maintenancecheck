<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\DueQueryKind;
use PHPUnit\Framework\TestCase;

/**
 * COMP §9.2: `filter=inspection` is an alias of `kind=inspection`.
 */
final class DueQueryKindTest extends TestCase
{
	public function testKindWinsWhenBothPresent(): void
	{
		$this->assertSame('all', DueQueryKind::resolve('all', 'inspection'));
		$this->assertSame('inspection', DueQueryKind::resolve('inspection', 'all'));
	}

	public function testFilterAliasWhenKindAbsent(): void
	{
		$this->assertSame('inspection', DueQueryKind::resolve(null, 'inspection'));
		$this->assertSame('inspection', DueQueryKind::resolve('', 'inspection'));
		$this->assertSame('inspection', DueQueryKind::resolve('   ', ' inspection '));
		$this->assertSame('all', DueQueryKind::resolve(null, 'all'));
	}

	public function testBothAbsentYieldsNull(): void
	{
		$this->assertNull(DueQueryKind::resolve(null, null));
		$this->assertNull(DueQueryKind::resolve('', ''));
		$this->assertNull(DueQueryKind::resolve('  ', "\t"));
	}

	public function testKindAlone(): void
	{
		$this->assertSame('inspection', DueQueryKind::resolve('inspection', null));
		$this->assertSame('all', DueQueryKind::resolve('all'));
	}
}
