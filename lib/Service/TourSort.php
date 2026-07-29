<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * CORE §12.2 / A3 tour suggest-order.
 *
 * Stops with coordinates are chained greedily (nearest neighbour, haversine)
 * starting from the geo stop closest to the current first position. Stops
 * without coordinates degrade to a stable (postal code, city, id) sort and
 * are appended after the geo chain. All ties break by id, so the suggestion
 * is fully deterministic.
 *
 * Pure service — no I/O, mutation-test target.
 */
class TourSort
{
	private const EARTH_RADIUS_KM = 6371.0;

	/**
	 * @param list<array{id: int, lat: ?string, lng: ?string, postalCode: ?string, city: ?string}> $stops
	 *        in current tour order; `id` is the work order id (§10.4 tie-break)
	 * @param array{lat: float, lng: float}|null $start optional tech start point
	 * @return list<int> suggested work order ids, best order first
	 */
	public function suggestOrder(array $stops, ?array $start = null): array
	{
		$geo = [];
		$rest = [];
		foreach ($stops as $stop) {
			if ($stop['lat'] !== null && $stop['lng'] !== null
				&& is_numeric($stop['lat']) && is_numeric($stop['lng'])
			) {
				$geo[] = $stop;
			} else {
				$rest[] = $stop;
			}
		}

		$order = $this->chainNearestNeighbour($geo, $start);

		usort($rest, static function (array $a, array $b): int {
			return [(string)($a['postalCode'] ?? ''), (string)($a['city'] ?? ''), $a['id']]
				<=> [(string)($b['postalCode'] ?? ''), (string)($b['city'] ?? ''), $b['id']];
		});
		foreach ($rest as $stop) {
			$order[] = $stop['id'];
		}
		return $order;
	}

	/**
	 * Great-circle distance in kilometres.
	 */
	public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
	{
		$dLat = deg2rad($lat2 - $lat1);
		$dLng = deg2rad($lng2 - $lng1);
		$a = sin($dLat / 2) ** 2
			+ cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
		return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
	}

	/**
	 * @param list<array{id: int, lat: ?string, lng: ?string}> $geo
	 * @param array{lat: float, lng: float}|null $start
	 * @return list<int>
	 */
	private function chainNearestNeighbour(array $geo, ?array $start): array
	{
		if ($geo === []) {
			return [];
		}
		if ($start !== null) {
			// §10.4: nearest neighbour from the tech start point.
			$remaining = $geo;
			$current = $this->popNearest($remaining, $start['lat'], $start['lng']);
		} else {
			// No configured start: anchor on the stop that is currently
			// first — the dispatcher's starting intent.
			$current = $geo[0];
			$remaining = array_slice($geo, 1);
		}
		$order = [$current['id']];

		while ($remaining !== []) {
			$current = $this->popNearest($remaining, (float)$current['lat'], (float)$current['lng']);
			$order[] = $current['id'];
		}
		return $order;
	}

	/**
	 * Remove and return the stop nearest to (lat, lng); ties break by
	 * work order id ASC (§10.4).
	 *
	 * @param list<array{id: int, lat: ?string, lng: ?string}> $remaining by reference
	 * @return array{id: int, lat: ?string, lng: ?string}
	 */
	private function popNearest(array &$remaining, float $lat, float $lng): array
	{
		$bestIndex = 0;
		$bestDistance = PHP_FLOAT_MAX;
		foreach ($remaining as $index => $candidate) {
			$distance = $this->distanceKm($lat, $lng, (float)$candidate['lat'], (float)$candidate['lng']);
			if ($distance < $bestDistance
				|| ($distance === $bestDistance && $candidate['id'] < $remaining[$bestIndex]['id'])
			) {
				$bestDistance = $distance;
				$bestIndex = $index;
			}
		}
		$stop = $remaining[$bestIndex];
		array_splice($remaining, $bestIndex, 1);
		return $stop;
	}
}
