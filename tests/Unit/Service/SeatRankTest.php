<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §8.4 deterministic seat ranking — (assigned_at ASC, id ASC).
 */
final class SeatRankTest extends TestCase
{
	public function testRanksOrderByAssignedAtThenId(): void
	{
		$seats = [
			['id' => 30, 'assignedAt' => 300],
			['id' => 10, 'assignedAt' => 100],
			['id' => 20, 'assignedAt' => 200],
		];
		$this->assertSame([10 => 1, 20 => 2, 30 => 3], SeatRank::ranks($seats));
	}

	public function testTieOnAssignedAtBreaksById(): void
	{
		$seats = [
			['id' => 7, 'assignedAt' => 100],
			['id' => 3, 'assignedAt' => 100],
			['id' => 5, 'assignedAt' => 100],
		];
		$this->assertSame([3 => 1, 5 => 2, 7 => 3], SeatRank::ranks($seats));
	}

	public function testEmptyInput(): void
	{
		$this->assertSame([], SeatRank::ranks([]));
	}

	public function testWithinLimitExactBoundary(): void
	{
		$seats = [
			['id' => 1, 'assignedAt' => 10],
			['id' => 2, 'assignedAt' => 20],
			['id' => 3, 'assignedAt' => 30],
		];
		// limit 2: seats ranked 1 and 2 pass, rank 3 fails.
		$this->assertTrue(SeatRank::isWithinLimit($seats, 1, 2));
		$this->assertTrue(SeatRank::isWithinLimit($seats, 2, 2));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 3, 2));
		// limit 3: everyone passes.
		$this->assertTrue(SeatRank::isWithinLimit($seats, 3, 3));
	}

	public function testDowngradeKeepsOldestSeats(): void
	{
		// Downgrade from 3 → 1 seat: only the earliest assignment survives.
		$seats = [
			['id' => 9, 'assignedAt' => 500],
			['id' => 4, 'assignedAt' => 100],
			['id' => 6, 'assignedAt' => 300],
		];
		$this->assertTrue(SeatRank::isWithinLimit($seats, 4, 1));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 6, 1));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 9, 1));
	}

	public function testZeroAndNegativeLimitRejectEveryone(): void
	{
		$seats = [['id' => 1, 'assignedAt' => 10]];
		$this->assertFalse(SeatRank::isWithinLimit($seats, 1, 0));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 1, -5));
	}

	public function testUnknownSeatIdIsNeverWithinLimit(): void
	{
		$seats = [['id' => 1, 'assignedAt' => 10]];
		$this->assertFalse(SeatRank::isWithinLimit($seats, 99, 10));
	}
}
