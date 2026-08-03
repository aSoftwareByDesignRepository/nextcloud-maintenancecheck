<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\EquipmentClass;
use OCA\MaintenanceCheck\Db\EquipmentClassMapper;

/**
 * W7 equipment class catalog (CORE §21 AC-W7-1) — ≥6 seeded codes.
 */
class EquipmentClassService
{
	/** @var list<array{code: string, nameDe: string, nameEn: string, unit: string, count: int, sort: int}> */
	public const SEED = [
		['code' => 'portable_electrical', 'nameDe' => 'Ortsveränderliche elektrische Geräte', 'nameEn' => 'Portable electrical equipment', 'unit' => 'year', 'count' => 1, 'sort' => 10],
		['code' => 'ladder', 'nameDe' => 'Leitern und Tritte', 'nameEn' => 'Ladders and steps', 'unit' => 'year', 'count' => 1, 'sort' => 20],
		['code' => 'fire_extinguisher', 'nameDe' => 'Feuerlöscher', 'nameEn' => 'Fire extinguishers', 'unit' => 'year', 'count' => 2, 'sort' => 30],
		['code' => 'pressure_vessel', 'nameDe' => 'Druckbehälter', 'nameEn' => 'Pressure vessels', 'unit' => 'year', 'count' => 1, 'sort' => 40],
		['code' => 'lifting', 'nameDe' => 'Hebezeuge', 'nameEn' => 'Lifting equipment', 'unit' => 'year', 'count' => 1, 'sort' => 50],
		['code' => 'scaffold', 'nameDe' => 'Gerüste', 'nameEn' => 'Scaffolding', 'unit' => 'year', 'count' => 1, 'sort' => 60],
	];

	public function __construct(
		private readonly EquipmentClassMapper $classes,
	) {
	}

	public function seedIfEmpty(): int
	{
		$inserted = 0;
		foreach (self::SEED as $row) {
			if ($this->classes->findByCode($row['code']) !== null) {
				continue;
			}
			$entity = new EquipmentClass();
			$entity->setCode($row['code']);
			$entity->setNameDe($row['nameDe']);
			$entity->setNameEn($row['nameEn']);
			$entity->setDefaultIntervalUnit($row['unit']);
			$entity->setDefaultIntervalCount($row['count']);
			$entity->setSortOrder($row['sort']);
			$entity->setActive(true);
			$this->classes->insert($entity);
			$inserted++;
		}
		return $inserted;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int}
	 */
	public function list(): array
	{
		$this->seedIfEmpty();
		$rows = $this->classes->listActive();
		return [
			'data' => array_map(static fn (EquipmentClass $c) => $c->toApi(), $rows),
			'total' => count($rows),
		];
	}

	public function requireActive(string $code): EquipmentClass
	{
		$this->seedIfEmpty();
		$class = $this->classes->findByCode($code);
		if ($class === null || !$class->getActive()) {
			throw new \OCA\MaintenanceCheck\Exception\ValidationException(
				'validation_failed',
				'Unknown or inactive equipment class.',
				[['field' => 'classCode', 'code' => 'unknown_class']],
			);
		}
		return $class;
	}
}
