<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Db;

use OCA\MaintenanceCheck\Db\WoPhoto;
use OCA\MaintenanceCheck\Db\WoSignature;
use PHPUnit\Framework\TestCase;

/** UC-SB: PDF path needs opaque fileName on evidence API payloads. */
final class EvidenceApiContractTest extends TestCase
{
	public function testWoPhotoToApiExposesFileNameAndDisplayName(): void
	{
		$photo = new WoPhoto();
		$photo->setId(7);
		$photo->setWorkOrderId(3);
		$photo->setFileName('p-3-abcdef0123456789.jpg');
		$photo->setOriginalName('leak.jpg');
		$photo->setMime('image/jpeg');
		$photo->setSizeBytes(1200);
		$photo->setCreatedAt(1_700_000_000);
		$photo->setCreatedBy('tech');

		$api = $photo->toApi();
		$this->assertSame('p-3-abcdef0123456789.jpg', $api['fileName']);
		$this->assertSame('leak.jpg', $api['originalName']);
		$this->assertSame('leak.jpg', $api['name']);
	}

	public function testWoSignatureToApiExposesFileName(): void
	{
		$sig = new WoSignature();
		$sig->setId(2);
		$sig->setWorkOrderId(3);
		$sig->setFileName('sig-3.png');
		$sig->setSignerName('Alex');
		$sig->setSizeBytes(800);
		$sig->setCreatedAt(1_700_000_000);
		$sig->setCreatedBy('tech');

		$api = $sig->toApi();
		$this->assertSame('sig-3.png', $api['fileName']);
		$this->assertSame('Alex', $api['signerName']);
	}
}
