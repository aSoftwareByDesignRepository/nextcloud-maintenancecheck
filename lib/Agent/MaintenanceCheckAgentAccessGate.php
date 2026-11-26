<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Agent;

use OCA\MaintenanceCheck\Service\AccessControlService;
use OCP\IGroupManager;

/**
 * App-door adapter: the agent surface honours the exact same access policy
 * as the web UI. canUseApp = the app access door; canAdminister = app admins
 * or server admins — matching who may reach the settings page that hosts the
 * "AI access (MCP)" section.
 */
final class MaintenanceCheckAgentAccessGate implements AgentAccessGate
{
	public function __construct(
		private readonly AccessControlService $access,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function canUseApp(string $uid): bool
	{
		return $this->access->canUseApp($uid);
	}

	public function canAdminister(string $uid): bool
	{
		return $this->access->isAppAdmin($uid)
			|| $this->groupManager->isAdmin($uid);
	}
}
