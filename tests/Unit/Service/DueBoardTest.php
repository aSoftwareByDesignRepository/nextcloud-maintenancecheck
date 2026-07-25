<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\DueBoard;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use PHPUnit\Framework\TestCase;

/**
 * S8 bucket boundaries — every fence tested on both sides (mutation target).
 */
final class DueBoardTest extends TestCase
{
	private const TODAY = '2026-07-24';

	private DueBoard $board;

	protected function setUp(): void
	{
		$this->board = new DueBoard(new IntervalCalculator());
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function bucketVectors(): array
	{
		return [
			'far past' => ['2020-01-01', DueBoard::BUCKET_OVERDUE],
			'yesterday' => ['2026-07-23', DueBoard::BUCKET_OVERDUE],
			'today' => ['2026-07-24', DueBoard::BUCKET_TODAY],
			'tomorrow' => ['2026-07-25', DueBoard::BUCKET_NEXT7],
			'today+7 upper next7 fence' => ['2026-07-31', DueBoard::BUCKET_NEXT7],
			'today+8 lower later fence' => ['2026-08-01', DueBoard::BUCKET_LATER],
			'today+30 upper later fence' => ['2026-08-23', DueBoard::BUCKET_LATER],
			'today+31 beyond horizon' => ['2026-08-24', null],
			'far future' => ['2030-01-01', null],
		];
	}

	/** @dataProvider bucketVectors */
	public function testBucketFor(string $dueOn, ?string $expected): void
	{
		$this->assertSame($expected, $this->board->bucketFor($dueOn, self::TODAY));
	}

	public function testMaxDueOnIsTodayPlusThirty(): void
	{
		$this->assertSame('2026-08-23', $this->board->maxDueOn(self::TODAY));
	}

	public function testBucketsAcrossMonthBoundary(): void
	{
		// today = Jan 28 → next7 spans into February.
		$this->assertSame(DueBoard::BUCKET_NEXT7, $this->board->bucketFor('2026-02-04', '2026-01-28'));
		$this->assertSame(DueBoard::BUCKET_LATER, $this->board->bucketFor('2026-02-05', '2026-01-28'));
		$this->assertSame(DueBoard::BUCKET_LATER, $this->board->bucketFor('2026-02-27', '2026-01-28'));
		$this->assertNull($this->board->bucketFor('2026-02-28', '2026-01-28'));
	}

	public function testBucketsAcrossYearBoundary(): void
	{
		$this->assertSame(DueBoard::BUCKET_NEXT7, $this->board->bucketFor('2027-01-03', '2026-12-30'));
		$this->assertSame(DueBoard::BUCKET_LATER, $this->board->bucketFor('2027-01-29', '2026-12-30'));
		$this->assertNull($this->board->bucketFor('2027-01-30', '2026-12-30'));
	}
}
