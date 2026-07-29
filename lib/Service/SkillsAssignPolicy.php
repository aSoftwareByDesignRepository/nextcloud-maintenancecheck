<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * Pure UC-SKILL assign gate (CORE R6 / AC-W2-2).
 *
 * WorkOrderService supplies missing skills + org enforcement; this class decides
 * whether to block, warn (409 until force), or allow.
 */
final class SkillsAssignPolicy
{
	/**
	 * @param list<array{id: int, code: string, name: string}> $missing
	 * @return list<array<string, mixed>> warn-level findings when force acknowledged
	 *
	 * @throws ValidationException `skills_missing` when enforcement is block
	 * @throws ConflictException `skills_warning` when enforcement is warn and !force
	 */
	public function evaluate(string $enforcement, array $missing, bool $force): array
	{
		if ($enforcement === PolicyService::ENFORCEMENT_OFF || $missing === []) {
			return [];
		}

		if ($enforcement === PolicyService::ENFORCEMENT_BLOCK) {
			throw new ValidationException('skills_missing', 'This technician is missing required skills.', array_map(
				static fn (array $skill) => ['field' => 'primaryUserId', 'code' => 'missing_skill:' . $skill['code']],
				$missing,
			));
		}

		if ($enforcement === PolicyService::ENFORCEMENT_WARN) {
			if (!$force) {
				throw new ConflictException('skills_warning', 'This technician is missing required skills. Confirm to assign anyway.', [
					'missing' => $missing,
				]);
			}
			return [['code' => 'skills_missing', 'missing' => $missing]];
		}

		return [];
	}
}
