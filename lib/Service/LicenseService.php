<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\LicenseState;
use OCA\MaintenanceCheck\Db\LicenseStateMapper;
use OCA\MaintenanceCheck\Db\MobileSeat;
use OCA\MaintenanceCheck\Db\MobileSeatMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\License\Mn2Codec;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Track L (SPEC §8): MN2 singleton state + named mobile seats.
 *
 * Web features are never gated; this surface only prepares the mobile gate.
 * Seat downgrade is deterministic (§8.4) — over-limit seats stay listed and
 * are never auto-deleted.
 */
class LicenseService
{
	/**
	 * S17: compile-time constant. Flips to 'available' in the release that
	 * ships after the Play app is live — never admin-editable.
	 */
	public const MOBILE_APP_STATUS = 'coming_soon';

	/** Cross-request mutex for seat capacity (AC-15 / §8.2 TOCTOU). */
	private const SEAT_LOCK = 'maintenancecheck/seat_assign';

	/** Cross-request mutex for singleton license paste (SPEC §4.2 at-most-one). */
	private const LICENSE_LOCK = 'maintenancecheck/license_apply';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LicenseStateMapper $licenseState,
		private readonly MobileSeatMapper $seats,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
		private readonly InputValidator $validator,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return array<string, mixed> GET /api/license payload (SPEC §8.2)
	 */
	public function status(): array
	{
		$state = $this->licenseState->findSingleton();
		return [
			'state' => $state === null ? null : [
				'customerId' => $state->getCustomerId(),
				'issuedAt' => $state->getIssuedAt(),
				'validUntil' => $state->getValidUntil(),
				'mobileSeats' => $state->getMobileSeats(),
				'valid' => Mn2Codec::isValidOn($state->getValidUntil(), $this->clock->today()),
				'appliedAt' => $state->getAppliedAt(),
				'appliedBy' => $state->getAppliedBy(),
			],
			'seats' => [
				'assigned' => $this->seats->countAll(),
				'limit' => $state?->getMobileSeats() ?? 0,
			],
			'mobileAppStatus' => self::MOBILE_APP_STATUS,
		];
	}

	/**
	 * §8.1 verify + replace singleton (delete-then-insert, one transaction).
	 * Seats are retained on key replacement (S16).
	 *
	 * @return array<string, mixed>
	 */
	public function apply(string $uid, string $wireKey): array
	{
		$error = Mn2Codec::classifyError($wireKey);
		if ($error !== '') {
			$message = match ($error) {
				Mn2Codec::ERROR_INVALID_FORMAT => 'The key does not have the expected MN2.<payload>.<signature> shape.',
				Mn2Codec::ERROR_INVALID_SIGNATURE => 'The signature does not match — the key was altered or not issued for this product.',
				default => 'The key payload failed validation for MaintenanceCheck.',
			};
			throw new ValidationException('license_invalid', $message);
		}

		/** @var array{payload: array<string, mixed>, payloadB64: string, signatureB64: string} $verified */
		$verified = Mn2Codec::parseAndVerify($wireKey);
		$payload = $verified['payload'];

		return $this->withExclusiveLock(
			self::LICENSE_LOCK,
			'license_busy',
			'Another license update is in progress. Try again in a moment.',
			function () use ($uid, $payload, $verified): array {
				$state = new LicenseState();
				$state->setCustomerId((string)$payload['customerId']);
				$state->setIssuedAt((string)$payload['issuedAt']);
				$state->setValidUntil((string)$payload['validUntil']);
				$state->setMobileSeats((int)$payload['mobileSeats']);
				$state->setPayloadB64($verified['payloadB64']);
				$state->setSignatureB64($verified['signatureB64']);
				$state->setAppliedAt($this->clock->now());
				$state->setAppliedBy($uid);

				$this->db->beginTransaction();
				try {
					$this->licenseState->deleteAll();
					$this->licenseState->insert($state);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}

				return $this->status();
			},
		);
	}

	/**
	 * DELETE /api/license — seats retained (SPEC §5.3).
	 *
	 * @return array<string, mixed>
	 */
	public function remove(): array
	{
		$this->licenseState->deleteAll();
		return $this->status();
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listSeats(?string $limit, ?string $offset): array
	{
		$page = $this->validator->pagination($limit, $offset);
		$ranked = $this->seats->findAllRanked();
		$seatLimit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;

		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$ranked,
		);

		$rows = [];
		foreach ($ranked as $seat) {
			$rows[] = [
				'uid' => $seat->getUid(),
				'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
				'assignedAt' => $seat->getAssignedAt(),
				'assignedBy' => $seat->getAssignedBy(),
				'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $seatLimit),
			];
		}

		return [
			'data' => array_slice($rows, $page['offset'], $page['limit']),
			'total' => count($rows),
			'limit' => $page['limit'],
			'offset' => $page['offset'],
		];
	}

	/**
	 * POST /api/license/seats — idempotent; limit enforced (§8.2).
	 *
	 * Capacity checks run under an exclusive lock so two parallel assigns
	 * cannot both pass `count < limit` and exceed `mobileSeats` (AC-15).
	 *
	 * @return array{created: bool, seat: array<string, mixed>}
	 */
	public function assignSeat(string $adminUid, mixed $userId): array
	{
		if (!is_string($userId) || trim($userId) === '') {
			throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
		}
		$userId = trim($userId);
		if (!$this->userManager->userExists($userId)) {
			throw new ValidationException('unknown_user', 'This Nextcloud user does not exist.');
		}

		$existing = $this->seats->findByUid($userId);
		if ($existing !== null) {
			return ['created' => false, 'seat' => $this->seatRow($existing)];
		}

		return $this->withExclusiveLock(
			self::SEAT_LOCK,
			'seat_limit_reached',
			'All licensed seats are assigned. Remove a seat or upgrade the license.',
			function () use ($adminUid, $userId): array {

			$existing = $this->seats->findByUid($userId);
			if ($existing !== null) {
				return ['created' => false, 'seat' => $this->seatRow($existing)];
			}

			$limit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
			if ($this->seats->countAll() >= $limit) {
				throw new ConflictException('seat_limit_reached', 'All licensed seats are assigned. Remove a seat or upgrade the license.');
			}

			$seat = new MobileSeat();
			$seat->setUid($userId);
			$seat->setAssignedAt($this->clock->now());
			$seat->setAssignedBy($adminUid);
			try {
				$seat = $this->seats->insert($seat);
			} catch (\OCP\DB\Exception $e) {
				// Unique index on uid: concurrent double-assign resolves idempotently.
				$existing = $this->seats->findByUid($userId);
				if ($existing !== null) {
					return ['created' => false, 'seat' => $this->seatRow($existing)];
				}
				throw $e;
			}
			return ['created' => true, 'seat' => $this->seatRow($seat)];
			},
		);
	}

	public function removeSeat(string $uid): void
	{
		$seat = $this->seats->findByUid($uid);
		if ($seat === null) {
			throw new NotFoundException();
		}
		$this->seats->delete($seat);
	}

	/**
	 * Mobile gate inputs (SPEC §9.1 rungs 3–6) for one user.
	 *
	 * @return array{hasLicense: bool, licenseValid: bool, seatAssigned: bool, seatWithinLimit: bool,
	 *               payloadB64: ?string, signatureB64: ?string}
	 */
	public function gateState(string $uid): array
	{
		$state = $this->licenseState->findSingleton();
		$seat = $this->seats->findByUid($uid);
		$withinLimit = false;
		if ($seat !== null && $state !== null) {
			$rankInput = array_map(
				static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
				$this->seats->findAllRanked(),
			);
			$withinLimit = SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $state->getMobileSeats());
		}
		return [
			'hasLicense' => $state !== null,
			'licenseValid' => $state !== null && Mn2Codec::isValidOn($state->getValidUntil(), $this->clock->today()),
			'seatAssigned' => $seat !== null,
			'seatWithinLimit' => $withinLimit,
			'payloadB64' => $state?->getPayloadB64(),
			'signatureB64' => $state?->getSignatureB64(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seatRow(MobileSeat $seat): array
	{
		$limit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$this->seats->findAllRanked(),
		);
		return [
			'uid' => $seat->getUid(),
			'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
			'assignedAt' => $seat->getAssignedAt(),
			'assignedBy' => $seat->getAssignedBy(),
			'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $limit),
		];
	}

	/**
	 * Serialize capacity-sensitive writes across PHP-FPM workers.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withExclusiveLock(string $key, string $busyCode, string $busyMessage, callable $fn): mixed
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new ConflictException($busyCode, $busyMessage);
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
