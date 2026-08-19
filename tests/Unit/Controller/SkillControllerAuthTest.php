<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\SkillController;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Skill catalog reads stay P2 (badges on a job). Per-user grants are HR-adjacent.
 * The only UI that loads GET /api/users/{uid}/skills is the office "Grant skills"
 * dialog. Technicians must not harvest a colleague's qualifications by UID.
 */
final class SkillControllerAuthTest extends TestCase
{
	private function controller(AccessControlService $access, ?SkillService $skills = null): SkillController
	{
		return new SkillController(
			$this->createMock(IRequest::class),
			$access,
			$skills ?? $this->createMock(SkillService::class),
		);
	}

	public function testTechnicianCannotReadAnotherUsersSkills(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->with('tech1')->willReturn(false);
		$skills = $this->createMock(SkillService::class);
		$skills->expects($this->never())->method('userSkills');

		$this->expectException(PermissionDeniedException::class);
		$this->controller($access, $skills)->userSkills('alice');
	}

	public function testTechnicianCanReadOwnSkills(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$access->method('isOffice')->willReturn(false);
		$skills = $this->createMock(SkillService::class);
		$skills->expects($this->once())->method('userSkills')->with('tech1')
			->willReturn(['uid' => 'tech1', 'skillIds' => [3]]);

		$res = $this->controller($access, $skills)->userSkills('tech1');
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([3], $res->getData()['skillIds']);
	}

	public function testOfficeCanReadAnyUsersSkills(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$access->method('isOffice')->with('office1')->willReturn(true);
		$skills = $this->createMock(SkillService::class);
		$skills->expects($this->once())->method('userSkills')->with('alice')
			->willReturn(['uid' => 'alice', 'skillIds' => [9]]);

		$res = $this->controller($access, $skills)->userSkills('alice');
		$this->assertSame([9], $res->getData()['skillIds']);
	}
}
