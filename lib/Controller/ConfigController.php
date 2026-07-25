<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Support\UserDirectorySearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * P7 (app admin): access restriction, allow-lists, app-admin list, office
 * lists. Unknown uids/gids → 422 so typos never silently lock people out.
 */
class ConfigController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse([
			'accessRestrictionEnabled' => $this->access->isAccessRestrictionEnabled(),
			'appAdminUserIds' => $this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS),
			'accessAllowedUserIds' => $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS),
			'accessAllowedGroupIds' => $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS),
			'officeUserIds' => $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS),
			'officeGroupIds' => $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_GROUP_IDS),
		]);
	}

	#[NoAdminRequired]
	public function saveAccess(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$body = $this->request->getParams();

		if (array_key_exists('accessRestrictionEnabled', $body)) {
			if (!is_bool($body['accessRestrictionEnabled'])) {
				throw new ValidationException('validation_failed', 'accessRestrictionEnabled must be a boolean.', [
					['field' => 'accessRestrictionEnabled', 'code' => 'invalid_type'],
				]);
			}
			$this->access->setAccessRestrictionEnabled($body['accessRestrictionEnabled']);
		}
		if (array_key_exists('appAdminUserIds', $body)) {
			// SPEC §4.4 — L0 only (NC admin). L1 must not rewrite app admins.
			$this->access->requireSystemAdmin($this->access->currentUserId());
			$this->access->setJsonIdList(
				AccessControlService::KEY_APP_ADMINS,
				$this->validatedUserIds($body['appAdminUserIds'], 'appAdminUserIds'),
			);
		}
		if (array_key_exists('accessAllowedUserIds', $body)) {
			$this->access->setJsonIdList(
				AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
				$this->validatedUserIds($body['accessAllowedUserIds'], 'accessAllowedUserIds'),
			);
		}
		if (array_key_exists('accessAllowedGroupIds', $body)) {
			$this->access->setJsonIdList(
				AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
				$this->validatedGroupIds($body['accessAllowedGroupIds'], 'accessAllowedGroupIds'),
			);
		}

		return $this->index();
	}

	#[NoAdminRequired]
	public function saveOffice(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$body = $this->request->getParams();

		if (array_key_exists('officeUserIds', $body)) {
			$this->access->setJsonIdList(
				AccessControlService::KEY_OFFICE_USER_IDS,
				$this->validatedUserIds($body['officeUserIds'], 'officeUserIds'),
			);
		}
		if (array_key_exists('officeGroupIds', $body)) {
			$this->access->setJsonIdList(
				AccessControlService::KEY_OFFICE_GROUP_IDS,
				$this->validatedGroupIds($body['officeGroupIds'], 'officeGroupIds'),
			);
		}

		return $this->index();
	}

	/**
	 * S12: office-only preview whether a Nextcloud user currently passes
	 * `canUseApp`. Used for the non-blocking assign-dialog warning.
	 * Does not gate the assign itself (allow-lists change independently).
	 */
	#[NoAdminRequired]
	public function userAccess(?string $userId = null): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$userId = trim((string)$userId);
		if ($userId === '') {
			throw new ValidationException('validation_failed', 'userId is required.', [
				['field' => 'userId', 'code' => 'required'],
			]);
		}
		$exists = $this->userManager->userExists($userId);
		return new JSONResponse([
			'uid' => $userId,
			'exists' => $exists,
			'canUseApp' => $exists && $this->access->canUseApp($userId),
		]);
	}

	/**
	 * SPEC §8.3 — NC user picker for seats / access / office lists (P7/P8).
	 */
	#[NoAdminRequired]
	public function searchUsers(?string $q = null, ?string $limit = null): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$q = trim((string)$q);
		if (mb_strlen($q) > 128) {
			throw new ValidationException('invalid_query', 'Search query is too long.');
		}
		$limitInt = 25;
		if ($limit !== null && $limit !== '') {
			if (!preg_match('/^\d+$/', $limit)) {
				throw new ValidationException('invalid_query', 'limit must be a positive integer.');
			}
			$limitInt = max(1, min(50, (int)$limit));
		}
		return new JSONResponse([
			'data' => UserDirectorySearch::search($this->userManager, $q, $limitInt),
		]);
	}

	/**
	 * @return list<string>
	 */
	private function validatedUserIds(mixed $value, string $field): array
	{
		$ids = $this->stringList($value, $field);
		foreach ($ids as $uid) {
			if (!$this->userManager->userExists($uid)) {
				throw new ValidationException('unknown_user', 'This Nextcloud user does not exist: ' . $uid, [
					['field' => $field, 'code' => 'unknown_user'],
				]);
			}
		}
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private function validatedGroupIds(mixed $value, string $field): array
	{
		$ids = $this->stringList($value, $field);
		foreach ($ids as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw new ValidationException('validation_failed', 'This Nextcloud group does not exist: ' . $gid, [
					['field' => $field, 'code' => 'unknown_group'],
				]);
			}
		}
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value, string $field): array
	{
		if (!is_array($value)) {
			throw new ValidationException('validation_failed', $field . ' must be an array of ids.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		$out = [];
		foreach ($value as $id) {
			if (!is_string($id)) {
				throw new ValidationException('validation_failed', $field . ' must contain strings only.', [
					['field' => $field, 'code' => 'invalid_type'],
				]);
			}
			$id = trim($id);
			if ($id !== '') {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}
}
