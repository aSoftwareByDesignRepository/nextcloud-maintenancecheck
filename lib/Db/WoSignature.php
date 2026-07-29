<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * W3 Servicebericht signature — at most one per work order (unique index).
 * PNG binary lives in appdata; this row is the index and audit anchor.
 *
 * @method int getWorkOrderId()
 * @method void setWorkOrderId(int $v)
 * @method string getFileName()
 * @method void setFileName(string $v)
 * @method int getSizeBytes()
 * @method void setSizeBytes(int $v)
 * @method string|null getSignerName()
 * @method void setSignerName(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class WoSignature extends Entity
{
	protected int $workOrderId = 0;
	protected string $fileName = '';
	protected int $sizeBytes = 0;
	protected ?string $signerName = null;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('workOrderId', 'integer');
		$this->addType('fileName', 'string');
		$this->addType('sizeBytes', 'integer');
		$this->addType('signerName', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'workOrderId' => $this->workOrderId,
			// Opaque server-generated name — required for Servicebericht embed.
			'fileName' => $this->fileName,
			'signerName' => $this->signerName,
			'sizeBytes' => $this->sizeBytes,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
