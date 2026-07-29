<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AC-F3 MN←DutyCheck on-duty soft read (CORE §11.1 / W4).
 *
 * Soft-fail only: missing app, disabled hook, or any exception →
 * `{available:false, effective:false, onDutyUids:[]}` so capacity UI still works.
 * Never writes into DutyCheck; never hard-depends on OCA\DutyCheck classes.
 */
class DutyCheckOnDutyService
{
	private const READER = 'OCA\\DutyCheck\\Integration\\MaintenanceCheckOnDutyReader';

	/**
	 * @param null|callable(?string, ?string): list<array{linkedUserId:?string}> $readerInvoker
	 *        Test seam — production resolves the DutyCheck reader via the server container.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
		private readonly mixed $readerInvoker = null,
	) {
	}

	/**
	 * @return array{available: bool, effective: bool, onDutyUids: list<string>}
	 */
	public function forDay(?string $day = null): array
	{
		$empty = ['available' => false, 'effective' => false, 'onDutyUids' => []];
		try {
			if (!$this->appManager->isEnabledForUser('dutycheck')) {
				return $empty;
			}
		} catch (\Throwable) {
			return $empty;
		}

		$actor = null;
		if ($this->userSession !== null) {
			$user = $this->userSession->getUser();
			$actor = $user !== null ? $user->getUID() : null;
		}

		try {
			$assignments = $this->readAssignments($day, $actor);
		} catch (\Throwable $e) {
			$this->logger->warning('DutyCheck on-duty soft read failed closed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return $empty;
		}

		$uids = [];
		foreach ($assignments as $row) {
			$uid = trim((string)($row['linkedUserId'] ?? ''));
			if ($uid !== '') {
				$uids[$uid] = true;
			}
		}
		$uidList = array_keys($uids);
		sort($uidList);

		return [
			'available' => true,
			'effective' => $assignments !== [] || $this->readerReportsEffective(),
			'onDutyUids' => $uidList,
		];
	}

	public function isOnDuty(string $uid, ?string $day = null): ?bool
	{
		$snapshot = $this->forDay($day);
		if (!$snapshot['available'] || !$snapshot['effective']) {
			return null;
		}
		return in_array($uid, $snapshot['onDutyUids'], true);
	}

	/**
	 * @return list<array{linkedUserId:?string}>
	 */
	private function readAssignments(?string $day, ?string $actor): array
	{
		if ($this->readerInvoker !== null) {
			/** @var list<array{linkedUserId:?string}> $rows */
			$rows = ($this->readerInvoker)($day, $actor);
			return $rows;
		}
		if (!class_exists(self::READER)) {
			return [];
		}
		$reader = \OC::$server->get(self::READER);
		if (!is_object($reader) || !method_exists($reader, 'onDutyToday')) {
			return [];
		}
		/** @var list<array{linkedUserId:?string}> $rows */
		$rows = $reader->onDutyToday($day, $actor);
		return is_array($rows) ? $rows : [];
	}

	private function readerReportsEffective(): bool
	{
		if ($this->readerInvoker !== null) {
			return true;
		}
		if (!class_exists(self::READER)) {
			return false;
		}
		try {
			$reader = \OC::$server->get(self::READER);
			if (is_object($reader) && method_exists($reader, 'isEffective')) {
				return (bool)$reader->isEffective();
			}
		} catch (\Throwable) {
			return false;
		}
		return false;
	}
}
