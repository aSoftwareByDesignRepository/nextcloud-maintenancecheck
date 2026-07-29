<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Db\Equipment;
use OCA\MaintenanceCheck\Db\EquipmentMapper;
use OCA\MaintenanceCheck\Db\EquipTypeMapper;
use OCA\MaintenanceCheck\Db\PlanMapper;
use OCA\MaintenanceCheck\Db\SiteMapper;
use OCA\MaintenanceCheck\Db\VisitMapper;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\EquipmentService;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * QR sticker issue / resolve (CORE steal #7, COMPANION by-qr).
 */
final class EquipmentQrServiceTest extends TestCase
{
	private function service(EquipmentMapper $mapper, ISecureRandom $random, IURLGenerator $url): EquipmentService
	{
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_700_000_000);
		$clock->method('today')->willReturn('2026-07-26');

		return new EquipmentService(
			$mapper,
			$this->createMock(CustomerMapper::class),
			$this->createMock(EquipTypeMapper::class),
			$this->createMock(PlanMapper::class),
			$this->createMock(VisitMapper::class),
			$this->createMock(SiteMapper::class),
			new InputValidator(new IntervalCalculator()),
			$clock,
			$random,
			$url,
		);
	}

	public function testRotateStoresHashAndReturnsSvgPayload(): void
	{
		$equipment = new Equipment();
		$equipment->setId(42);
		$equipment->setCustomerId(1);
		$equipment->setEquipTypeId(1);
		$equipment->setLabel('Boiler');
		$equipment->setActive(true);
		$equipment->setCreatedAt(1);
		$equipment->setUpdatedAt(1);
		$equipment->setCreatedBy('office');

		$mapper = $this->createMock(EquipmentMapper::class);
		$mapper->method('findById')->with(42)->willReturn($equipment);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Equipment $e): Equipment {
				return $e;
			},
		);

		$plaintext = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn($plaintext);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn(
			'https://nc.test/apps/maintenancecheck/equipment/by-qr/' . $plaintext,
		);

		$result = $this->service($mapper, $random, $url)->rotateQrToken(42);
		$this->assertSame($plaintext, $result['qrToken']);
		$this->assertSame('mn-eq:' . $plaintext, $result['qrPayload']);
		$this->assertStringContainsString('<svg', $result['qrSvg']);
		$this->assertSame(hash('sha256', $plaintext), $equipment->getQrTokenHash());
		$this->assertSame(1_700_000_000, $equipment->getQrTokenRotatedAt());
		$this->assertTrue($result['equipment']['hasQrToken']);
	}

	public function testNormalizeRejectsShortToken(): void
	{
		$this->expectException(ValidationException::class);
		$this->service(
			$this->createMock(EquipmentMapper::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(IURLGenerator::class),
		)->resolveByQr('short');
	}
}
