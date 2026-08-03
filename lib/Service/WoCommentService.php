<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use OCA\MaintenanceCheck\Db\WoComment;
use OCA\MaintenanceCheck\Db\WoCommentMapper;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;

/**
 * W6 append-only WO comments (CORE §20 W6-R5, AC-W6-7).
 * No edit/delete in W6 — handoff notes only, not a messenger.
 */
class WoCommentService
{
	public function __construct(
		private readonly WoCommentMapper $comments,
		private readonly WorkOrderMapper $workOrders,
		private readonly WorkOrderAccessPolicy $woAccess,
		private readonly InputValidator $validator,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function list(int $woId): array
	{
		$this->workOrders->findById($woId);
		return [
			'data' => array_map(static fn (WoComment $c) => $c->toApi(), $this->comments->findByWorkOrder($woId)),
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function create(string $uid, int $woId, array $body, bool $isOffice): array
	{
		$wo = $this->workOrders->findById($woId);
		$this->woAccess->assertCanExecute($uid, $wo, $isOffice);

		$bodyText = trim($this->validator->requiredString($body, 'body', 'body_required', WoComment::MAX_BODY, 'body_too_long'));
		if ($bodyText === '') {
			throw new ValidationException('validation_failed', 'Comment body is required.', [
				['field' => 'body', 'code' => 'required'],
			]);
		}

		$comment = new WoComment();
		$comment->setWoId($woId);
		$comment->setBody($bodyText);
		$comment->setCreatedBy($uid);
		$comment->setCreatedAt($this->clock->now());
		return $this->comments->insert($comment)->toApi();
	}
}
