<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use OCA\MaintenanceCheck\Tests\Support\WorkerStdoutToken;
use PHPUnit\Framework\TestCase;

final class WorkerStdoutTokenTest extends TestCase
{
	public function testSkipsPcovJitWarningAndBlankLines(): void
	{
		$stdout = "Warning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.\n"
			. "\n"
			. "OK\n";
		$this->assertSame('OK', WorkerStdoutToken::first($stdout));
	}

	public function testKeepsConflictToken(): void
	{
		$stdout = "PHP Warning: something\nCONFLICT:visit_not_open\n";
		$this->assertSame('CONFLICT:visit_not_open', WorkerStdoutToken::first($stdout));
	}

	public function testEmptyWhenOnlyWarnings(): void
	{
		$this->assertSame('', WorkerStdoutToken::first("Warning: JIT disabled.\n"));
	}
}
