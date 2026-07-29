<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\TourSort;
use PHPUnit\Framework\TestCase;

/** AC-W3-1 — deterministic suggest-order. */
final class TourSortTest extends TestCase
{
	private TourSort $sort;

	protected function setUp(): void
	{
		$this->sort = new TourSort();
	}

	public function testNearestNeighbourFromConfiguredStart(): void
	{
		// Berlin centre → Potsdam → Brandenburg an der Havel (westward chain).
		$stops = [
			['id' => 30, 'lat' => '52.5200', 'lng' => '13.4050', 'postalCode' => '10115', 'city' => 'Berlin'],
			['id' => 10, 'lat' => '52.3906', 'lng' => '13.0645', 'postalCode' => '14467', 'city' => 'Potsdam'],
			['id' => 20, 'lat' => '52.4125', 'lng' => '12.5316', 'postalCode' => '14770', 'city' => 'Brandenburg'],
		];
		$order = $this->sort->suggestOrder($stops, ['lat' => 52.5200, 'lng' => 13.4050]);
		$this->assertSame([30, 10, 20], $order);
	}

	public function testDeterministicAcrossCalls(): void
	{
		$stops = [
			['id' => 2, 'lat' => '50.0', 'lng' => '8.0', 'postalCode' => null, 'city' => null],
			['id' => 1, 'lat' => '50.1', 'lng' => '8.1', 'postalCode' => null, 'city' => null],
			['id' => 3, 'lat' => '50.2', 'lng' => '8.2', 'postalCode' => null, 'city' => null],
		];
		$start = ['lat' => 50.0, 'lng' => 8.0];
		$this->assertSame(
			$this->sort->suggestOrder($stops, $start),
			$this->sort->suggestOrder(array_reverse($stops), $start),
		);
	}

	public function testPostalFallbackWhenNoCoords(): void
	{
		$stops = [
			['id' => 5, 'lat' => null, 'lng' => null, 'postalCode' => '80331', 'city' => 'München'],
			['id' => 2, 'lat' => null, 'lng' => null, 'postalCode' => '10115', 'city' => 'Berlin'],
			['id' => 9, 'lat' => null, 'lng' => null, 'postalCode' => '10115', 'city' => 'Berlin'],
		];
		$this->assertSame([2, 9, 5], $this->sort->suggestOrder($stops));
	}

	public function testMixedGeoThenPostalAppended(): void
	{
		$stops = [
			['id' => 1, 'lat' => '52.5', 'lng' => '13.4', 'postalCode' => '10115', 'city' => 'Berlin'],
			['id' => 7, 'lat' => null, 'lng' => null, 'postalCode' => '20095', 'city' => 'Hamburg'],
			['id' => 3, 'lat' => '52.4', 'lng' => '13.0', 'postalCode' => '14467', 'city' => 'Potsdam'],
		];
		$order = $this->sort->suggestOrder($stops, ['lat' => 52.5, 'lng' => 13.4]);
		$this->assertSame([1, 3, 7], $order);
	}

	public function testTieBreakByWorkOrderIdAsc(): void
	{
		// Identical coordinates — nearer-neighbour distance ties; id ASC wins.
		$stops = [
			['id' => 40, 'lat' => '50.0', 'lng' => '8.0', 'postalCode' => null, 'city' => null],
			['id' => 10, 'lat' => '50.0', 'lng' => '8.0', 'postalCode' => null, 'city' => null],
			['id' => 20, 'lat' => '50.0', 'lng' => '8.0', 'postalCode' => null, 'city' => null],
		];
		$order = $this->sort->suggestOrder($stops, ['lat' => 50.0, 'lng' => 8.0]);
		$this->assertSame([10, 20, 40], $order);
	}

	public function testDistanceKmSymmetricAndPositive(): void
	{
		$d = $this->sort->distanceKm(52.52, 13.405, 52.3906, 13.0645);
		$this->assertGreaterThan(20.0, $d);
		$this->assertLessThan(40.0, $d);
		$this->assertEqualsWithDelta(
			$d,
			$this->sort->distanceKm(52.3906, 13.0645, 52.52, 13.405),
			0.0001,
		);
	}
}
