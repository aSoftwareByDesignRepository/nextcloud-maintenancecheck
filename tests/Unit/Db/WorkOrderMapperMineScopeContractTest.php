<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * CORE §7 execute scope includes helpers. The list/due-board SQL must match
 * {@see \OCA\MaintenanceCheck\Db\WorkOrder::isAssigneeOrPool} — otherwise a
 * helper can open a job by id but never finds it in "my work orders".
 */
final class WorkOrderMapperMineScopeContractTest extends TestCase
{
	public function testMineFilterIncludesHelperUidsInListAndDueBoard(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/WorkOrderMapper.php');
		$this->assertMatchesRegularExpression(
			'/private function constrainToMine\([^)]+\): void\s*\{[^}]*like\(\'helper_uids\'/',
			$src,
			'constrainToMine() must keep helper_uids in the WHERE, not only in ORDER BY',
		);
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($src, 'constrainToMine('),
			'search() and findOpenPreventiveDue() must both apply the helper-aware mine filter',
		);
	}

	public function testMineListSortsAssignedAndHelpersBeforePool(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/WorkOrderMapper.php');
		$this->assertStringContainsString('applyMineAwareSort', $src);
		$this->assertStringContainsString('THEN 0 ELSE 1 END', $src);
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($src, 'applyMineAwareSort('),
			'search() and findOpenPreventiveDue() must both rank assigned/helper ahead of pool',
		);
	}
}
