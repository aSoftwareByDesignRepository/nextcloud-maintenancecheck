<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

/**
 * CORE §11.2 kit ready gate: a kit is ready when every non-optional line is
 * packed to at least its required quantity. Optional lines never block, and
 * over-packing is not an error.
 *
 * Pure service — no I/O, mutation-test target.
 */
class KitReadiness
{
	/**
	 * @param list<array{label: string, qtyRequired: int, qtyPacked: int, optional: bool}> $lines
	 * @return array{ready: bool, missing: list<array{label: string, qtyRequired: int, qtyPacked: int}>}
	 */
	public function assess(array $lines): array
	{
		$missing = [];
		foreach ($lines as $line) {
			if ($line['optional']) {
				continue;
			}
			if ($line['qtyPacked'] < $line['qtyRequired']) {
				$missing[] = [
					'label' => $line['label'],
					'qtyRequired' => $line['qtyRequired'],
					'qtyPacked' => $line['qtyPacked'],
				];
			}
		}
		return ['ready' => $missing === [], 'missing' => $missing];
	}
}
