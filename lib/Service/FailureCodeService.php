<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CatalogType;
use OCA\MaintenanceCheck\Db\FailureCodeMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W6 failure-code catalog + seed (CORE §20 W6-R3, AC-W6-5).
 */
class FailureCodeService
{
	/** @var list<array{code: string, name: string, sort: int}> */
	public const SEED = [
		['code' => 'no_power', 'name' => 'No power / dead', 'sort' => 10],
		['code' => 'sensor_fault', 'name' => 'Sensor fault', 'sort' => 20],
		['code' => 'leak', 'name' => 'Leak / fluid loss', 'sort' => 30],
		['code' => 'noise', 'name' => 'Abnormal noise', 'sort' => 40],
		['code' => 'overheat', 'name' => 'Overheating', 'sort' => 50],
		['code' => 'wear', 'name' => 'Wear / end of life', 'sort' => 60],
		['code' => 'user_error', 'name' => 'User / operator error', 'sort' => 70],
		['code' => 'external', 'name' => 'External cause', 'sort' => 80],
		['code' => 'unknown', 'name' => 'Unknown / other', 'sort' => 999],
	];

	public function __construct(
		private readonly FailureCodeMapper $codes,
		private readonly InputValidator $validator,
	) {
	}

	/**
	 * Idempotent seed — inserts missing codes only.
	 */
	public function seedIfEmpty(): int
	{
		$inserted = 0;
		foreach (self::SEED as $row) {
			if ($this->codes->findByCode($row['code']) !== null) {
				continue;
			}
			$type = new CatalogType();
			$type->setCode($row['code']);
			$type->setName($row['name']);
			$type->setSortOrder($row['sort']);
			$type->setActive(true);
			try {
				$this->codes->insert($type);
				$inserted++;
			} catch (\OCP\DB\Exception) {
				if ($this->codes->findByCode($row['code']) === null) {
					throw new \RuntimeException('Failed to seed failure code ' . $row['code']);
				}
			}
		}
		return $inserted;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $limit, ?string $offset, bool $activeOnly = false): array
	{
		if ($activeOnly) {
			$data = array_map(static fn (CatalogType $t) => $t->toApi(), $this->codes->listActive());
			return ['data' => $data, 'total' => count($data), 'limit' => count($data), 'offset' => 0];
		}
		$page = $this->validator->pagination($limit, $offset);
		$result = $this->codes->listAll($page['limit'], $page['offset']);
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
	public function create(array $body): array
	{
		$code = $this->validator->catalogCode($body);
		$name = $this->validator->catalogName($body);
		$sortOrder = 0;
		if (array_key_exists('sortOrder', $body) && is_int($body['sortOrder'])) {
			$sortOrder = $body['sortOrder'];
		}
		if ($this->codes->findByCode($code) !== null) {
			throw new ConflictException('code_exists', 'A failure code with this code already exists.');
		}
		$type = new CatalogType();
		$type->setCode($code);
		$type->setName($name);
		$type->setSortOrder($sortOrder);
		$type->setActive(true);
		try {
			return $this->codes->insert($type)->toApi();
		} catch (\OCP\DB\Exception $e) {
			if ($this->codes->findByCode($code) !== null) {
				throw new ConflictException('code_exists', 'A failure code with this code already exists.');
			}
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function update(int $id, array $body): array
	{
		$type = $this->codes->findById($id);
		if (array_key_exists('name', $body)) {
			$type->setName($this->validator->catalogName($body));
		}
		if (array_key_exists('sortOrder', $body) && is_int($body['sortOrder'])) {
			$type->setSortOrder($body['sortOrder']);
		}
		if (array_key_exists('active', $body)) {
			$type->setActive($this->validator->boolOrDefault($body, 'active', $type->getActive()));
		}
		return $this->codes->update($type)->toApi();
	}

	public function assertActiveCode(string $code): void
	{
		$row = $this->codes->findByCode($code);
		if ($row === null || !$row->getActive()) {
			throw new ValidationException('failure_code_invalid', 'Unknown or inactive failure code.', [
				['field' => 'failureCode', 'code' => 'invalid_value'],
			]);
		}
	}
}
