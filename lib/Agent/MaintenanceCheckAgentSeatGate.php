<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Agent;

use OCA\MaintenanceCheck\Service\MobileGateService;

/**
 * Per-request seat entitlement for agent requests. Delegates to the app's
 * mobile-seat authority — a revoked seat kills the token on its next call.
 * Any entitlement failure collapses to one uniform 403.
 */
final class MaintenanceCheckAgentSeatGate implements AgentSeatGate
{
	public function __construct(
		private readonly MobileGateService $gate,
	) {
	}

	public function assertEntitled(string $uid): void
	{
		try {
			$this->gate->assertGatePassed($uid);
		} catch (AgentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new AgentException(
				'agent_seat_revoked',
				'This agent token has no active entitlement.',
				403,
			);
		}
	}
}
