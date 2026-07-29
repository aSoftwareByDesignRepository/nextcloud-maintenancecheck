<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\CatalogType;
use OCA\MaintenanceCheck\Db\SkillMapper;
use OCA\MaintenanceCheck\Db\UserSkill;
use OCA\MaintenanceCheck\Db\UserSkillMapper;
use OCA\MaintenanceCheck\Db\WoSkill;
use OCA\MaintenanceCheck\Db\WoSkillMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCP\IUserManager;

/**
 * W2 skills (CORE R6, UC-SKILL): catalog, per-user grants, required skills
 * per WO, and the assign-gate data. Enforcement level (off/warn/block) is a
 * policy decision applied by WorkOrderService.
 */
class SkillService
{
	public const MAX_SKILLS_PER_WO = 50;

	public function __construct(
		private readonly SkillMapper $skills,
		private readonly UserSkillMapper $userSkills,
		private readonly WoSkillMapper $woSkills,
		private readonly WorkOrderMapper $workOrders,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
	) {
	}

	// ── Catalog (reuses CatalogType machinery, S11 semantics) ──────────

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$result = $this->skills->listAll($page['limit'], $page['offset']);
		return [
			'data' => array_map(static fn (CatalogType $s) => $s->toApi(), $result['data']),
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
		if ($this->skills->findByCode($code) !== null) {
			throw new ConflictException('code_exists', 'A skill with this code already exists.');
		}
		$skill = new CatalogType();
		$skill->setCode($code);
		$skill->setName($name);
		$skill->setSortOrder(0);
		$skill->setActive(true);
		try {
			return $this->skills->insert($skill)->toApi();
		} catch (\OCP\DB\Exception $e) {
			if ($this->skills->findByCode($code) !== null) {
				throw new ConflictException('code_exists', 'A skill with this code already exists.');
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
		$skill = $this->skills->findById($id);
		if (array_key_exists('name', $body)) {
			$skill->setName($this->validator->catalogName($body));
		}
		if (array_key_exists('active', $body)) {
			$skill->setActive($this->validator->boolOrDefault($body, 'active', $skill->getActive()));
		}
		return $this->skills->update($skill)->toApi();
	}

	// ── User grants ─────────────────────────────────────────────────────

	/**
	 * @return array{uid: string, skillIds: list<int>}
	 */
	public function userSkills(string $uid): array
	{
		return ['uid' => $uid, 'skillIds' => $this->userSkills->skillIdsFor($uid)];
	}

	/**
	 * Replace a user's grant set (idempotent PUT semantics).
	 *
	 * @param array<string, mixed> $body
	 * @return array{uid: string, skillIds: list<int>}
	 */
	public function setUserSkills(string $grantedBy, string $uid, array $body): array
	{
		if (!$this->userManager->userExists($uid)) {
			throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
		}
		$skillIds = $this->validatedSkillIds($body);

		$current = $this->userSkills->skillIdsFor($uid);
		$now = $this->clock->now();
		foreach (array_diff($current, $skillIds) as $removeId) {
			$this->userSkills->remove($uid, $removeId);
		}
		foreach (array_diff($skillIds, $current) as $addId) {
			$grant = new UserSkill();
			$grant->setUid($uid);
			$grant->setSkillId($addId);
			$grant->setGrantedAt($now);
			$grant->setGrantedBy($grantedBy);
			try {
				$this->userSkills->insert($grant);
			} catch (\OCP\DB\Exception) {
				// Concurrent duplicate grant — unique index already holds it.
			}
		}
		return $this->userSkills($uid);
	}

	// ── Required skills on a WO ─────────────────────────────────────────

	/**
	 * Replace the required-skill set of a WO.
	 *
	 * @param array<string, mixed> $body
	 * @return array{workOrderId: int, skillIds: list<int>}
	 */
	public function setWoSkills(int $workOrderId, array $body): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'This work order is closed.');
		}
		$skillIds = $this->validatedSkillIds($body);

		$this->woSkills->deleteForWorkOrder($workOrderId);
		foreach ($skillIds as $skillId) {
			$link = new WoSkill();
			$link->setWorkOrderId($workOrderId);
			$link->setSkillId($skillId);
			try {
				$this->woSkills->insert($link);
			} catch (\OCP\DB\Exception) {
				// Concurrent duplicate — unique index already holds it.
			}
		}
		return ['workOrderId' => $workOrderId, 'skillIds' => $this->woSkillIds($workOrderId)];
	}

	/**
	 * @return list<int>
	 */
	public function woSkillIds(int $workOrderId): array
	{
		$ids = [];
		foreach ($this->woSkills->findByWorkOrder($workOrderId) as $link) {
			$ids[] = $link->getSkillId();
		}
		return $ids;
	}

	/**
	 * Assign-gate data (UC-SKILL): skills required by the WO that the user
	 * does not hold.
	 *
	 * @return list<array{id: int, code: string, name: string}>
	 */
	public function missingSkillsFor(int $workOrderId, string $uid): array
	{
		$requiredIds = $this->woSkillIds($workOrderId);
		if ($requiredIds === []) {
			return [];
		}
		$heldIds = $this->userSkills->skillIdsFor($uid);
		$missing = [];
		foreach (array_diff($requiredIds, $heldIds) as $skillId) {
			$skill = $this->skills->findById($skillId);
			$missing[] = [
				'id' => (int)$skill->getId(),
				'code' => $skill->getCode(),
				'name' => $skill->getName(),
			];
		}
		return $missing;
	}

	/**
	 * Detail payload for the WO page.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function woSkillsDetail(int $workOrderId): array
	{
		$out = [];
		foreach ($this->woSkillIds($workOrderId) as $skillId) {
			$out[] = $this->skills->findById($skillId)->toApi();
		}
		return $out;
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $body
	 * @return list<int> validated, deduplicated skill ids (must exist + be active)
	 */
	private function validatedSkillIds(array $body): array
	{
		$raw = $body['skillIds'] ?? null;
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new ValidationException('validation_failed', 'skillIds must be a list of ids.', [
				['field' => 'skillIds', 'code' => 'invalid_type'],
			]);
		}
		if (count($raw) > self::MAX_SKILLS_PER_WO) {
			throw new ValidationException('validation_failed', 'At most ' . self::MAX_SKILLS_PER_WO . ' skills are allowed.', [
				['field' => 'skillIds', 'code' => 'too_many'],
			]);
		}
		$ids = [];
		foreach ($raw as $id) {
			if (!is_int($id) || $id < 1) {
				throw new ValidationException('validation_failed', 'skillIds must contain positive integers.', [
					['field' => 'skillIds', 'code' => 'invalid_type'],
				]);
			}
			// Throws NotFoundException (404) for unknown ids.
			$this->skills->findById($id);
			$ids[] = $id;
		}
		return array_values(array_unique($ids));
	}
}
