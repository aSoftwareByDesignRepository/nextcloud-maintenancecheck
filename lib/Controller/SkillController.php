<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Controller;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * W2 skills: catalog reads are P2 (job badges). Per-user grants: office or
 * self. Mutations: office. Technicians must not read another uid's grants.
 */
class SkillController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly SkillService $skills,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?string $limit = null, ?string $offset = null): JSONResponse
	{
		return new JSONResponse($this->skills->list($limit, $offset));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->skills->create($this->jsonBody()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse($this->skills->update($id, $this->jsonBody()));
	}

	#[NoAdminRequired]
	public function userSkills(string $uid): JSONResponse
	{
		$actor = $this->access->currentUserId();
		if ($actor !== $uid && !$this->access->isOffice($actor)) {
			throw new PermissionDeniedException();
		}
		return new JSONResponse($this->skills->userSkills($uid));
	}

	#[NoAdminRequired]
	public function setUserSkills(string $uid): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireOffice($actor);
		return new JSONResponse($this->skills->setUserSkills($actor, $uid, $this->jsonBody()));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$params = $this->request->getParams();
		unset($params['id'], $params['uid'], $params['_route']);
		return $params;
	}
}
