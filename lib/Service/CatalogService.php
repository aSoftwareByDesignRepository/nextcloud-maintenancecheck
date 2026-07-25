<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CatalogType;
use OCA\MaintenanceCheck\Db\CatalogTypeMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\MaintTypeMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * S11: catalog entries are never deleted, only deactivated. Codes are
 * immutable after creation (unknown body fields are ignored on update).
 */
class CatalogService
{
	public function __construct(
		private readonly EquipTypeMapper $equipTypes,
		private readonly MaintTypeMapper $maintTypes,
		private readonly InputValidator $validator,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $kind, ?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$result = $this->mapper($kind)->listAll($page['limit'], $page['offset']);
		return [
			'data' => array_map(static fn (CatalogType $t) => $t->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $kind, array $body): array
	{
		$mapper = $this->mapper($kind);
		$code = $this->validator->catalogCode($body);
		$name = $this->validator->catalogName($body);
		$sortOrder = $this->optionalSortOrder($body);

		if ($mapper->findByCode($code) !== null) {
			throw new ConflictException('code_exists', 'A catalog entry with this code already exists.');
		}

		$type = new CatalogType();
		$type->setCode($code);
		$type->setName($name);
		$type->setSortOrder($sortOrder ?? 0);
		$type->setActive(true);
		try {
			return $mapper->insert($type)->toApi();
		} catch (\OCP\DB\Exception $e) {
			// Unique index on code: concurrent create must surface as 409, not 500.
			if ($mapper->findByCode($code) !== null) {
				throw new ConflictException('code_exists', 'A catalog entry with this code already exists.');
			}
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(string $kind, int $id, array $body): array
	{
		$mapper = $this->mapper($kind);
		$type = $mapper->findById($id);

		if (array_key_exists('name', $body)) {
			$type->setName($this->validator->catalogName($body));
		}
		$sortOrder = $this->optionalSortOrder($body);
		if ($sortOrder !== null) {
			$type->setSortOrder($sortOrder);
		}
		if (array_key_exists('active', $body)) {
			$type->setActive($this->validator->boolOrDefault($body, 'active', $type->getActive()));
		}

		return $mapper->update($type)->toApi();
	}

	private function optionalSortOrder(array $body): ?int
	{
		if (!array_key_exists('sortOrder', $body) || $body['sortOrder'] === null) {
			return null;
		}
		if (!is_int($body['sortOrder']) || $body['sortOrder'] < -100000 || $body['sortOrder'] > 100000) {
			throw new ValidationException('validation_failed', 'Sort order must be an integer.', [
				['field' => 'sortOrder', 'code' => 'invalid_type'],
			]);
		}
		return $body['sortOrder'];
	}

	private function mapper(string $kind): CatalogTypeMapper
	{
		return match ($kind) {
			'equip' => $this->equipTypes,
			'maint' => $this->maintTypes,
			default => throw new \InvalidArgumentException('Unknown catalog kind: ' . $kind),
		};
	}
}
