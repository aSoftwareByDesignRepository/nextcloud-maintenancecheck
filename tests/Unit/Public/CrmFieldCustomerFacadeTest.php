<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Public;

use OCA\MaintenanceCheck\Db\Customer;
use OCA\MaintenanceCheck\Db\CustomerMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCA\MaintenanceCheck\Public\CrmFieldCustomerFacade;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\Clock;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class CrmFieldCustomerFacadeTest extends TestCase
{
	public function testCreateFromHubSetsLinks(): void
	{
		$mapper = $this->createMock(CustomerMapper::class);
		$mapper->method('findByPcCustomerId')->willReturn(null);
		$mapper->method('findByCrmCompanyId')->willReturn(null);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (Customer $c) {
			$c->setId(55);
			$this->assertSame('Acme Field', $c->getName());
			$this->assertSame(42, $c->getPcCustomerId());
			$this->assertSame(7, $c->getCrmCompanyId());

			return $c;
		});

		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('requireOffice')->with('alice');

		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_700_000_000);

		$facade = new CrmFieldCustomerFacade(
			$mapper,
			$access,
			$this->createMock(InputValidator::class),
			$clock,
			$this->createMock(IConfig::class),
		);

		$result = $facade->createFromHub([
			'actorUid' => 'alice',
			'displayName' => 'Acme Field',
			'pcCustomerId' => 42,
			'crmCompanyId' => 7,
		]);
		$this->assertSame(55, $result['mnCustomerId']);
		$this->assertTrue($result['created']);
	}

	public function testCreateFromHubReturnsExistingWhenPcAlreadyLinked(): void
	{
		$existing = new Customer();
		$existing->setId(88);

		$mapper = $this->createMock(CustomerMapper::class);
		$mapper->method('findByPcCustomerId')->with(42)->willReturn($existing);
		$mapper->expects($this->never())->method('insert');

		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('requireOffice')->with('alice');

		$facade = new CrmFieldCustomerFacade(
			$mapper,
			$access,
			$this->createMock(InputValidator::class),
			$this->createMock(Clock::class),
			$this->createMock(IConfig::class),
		);

		$result = $facade->createFromHub([
			'actorUid' => 'alice',
			'displayName' => 'Acme Field',
			'pcCustomerId' => 42,
		]);
		$this->assertSame(88, $result['mnCustomerId']);
		$this->assertFalse($result['created']);
	}

	public function testEnsureLinkConflictWhenPcTaken(): void
	{
		$existing = new Customer();
		$existing->setId(99);
		$target = new Customer();
		$target->setId(1);
		$target->setUpdatedAt(10);

		$mapper = $this->createMock(CustomerMapper::class);
		$mapper->method('findById')->willReturn($target);
		$mapper->method('findByPcCustomerId')->with(42)->willReturn($existing);

		$access = $this->createMock(AccessControlService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(11);

		$facade = new CrmFieldCustomerFacade(
			$mapper,
			$access,
			$this->createMock(InputValidator::class),
			$clock,
			$this->createMock(IConfig::class),
		);

		$this->expectException(ConflictException::class);
		$facade->ensureLink(1, 'alice', 42, null, 10);
	}

	public function testSoftLinkUiFlagOff(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$facade = new CrmFieldCustomerFacade(
			$this->createMock(CustomerMapper::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(InputValidator::class),
			$this->createMock(Clock::class),
			$config,
		);
		$this->assertFalse($facade->softLinkUiEnabled());
	}

	public function testEnsureLinkRejectsInconsistentDualLinksWhenCrmFacadeReturnsMismatch(): void
	{
		$target = new Customer();
		$target->setId(1);
		$target->setUpdatedAt(10);

		$mapper = $this->createMock(CustomerMapper::class);
		$mapper->method('findById')->willReturn($target);
		$mapper->method('findByPcCustomerId')->willReturn(null);
		$mapper->method('findByCrmCompanyId')->willReturn(null);
		$mapper->expects($this->never())->method('update');

		$access = $this->createMock(AccessControlService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(11);

		$facade = new class($mapper, $access, $this->createMock(InputValidator::class), $clock, $this->createMock(IConfig::class)) extends CrmFieldCustomerFacade {
			protected function probeCrmCompany(string $actorUid, int $crmCompanyId): ?array
			{
				return ['crmCompanyId' => $crmCompanyId, 'pcCustomerId' => 999];
			}
		};

		$this->expectException(\OCA\MaintenanceCheck\Exception\ValidationException::class);
		$facade->ensureLink(1, 'alice', 42, 7, 10);
	}

	public function testEnsureLinkAllowsMatchingDualLinks(): void
	{
		$target = new Customer();
		$target->setId(1);
		$target->setUpdatedAt(10);

		$mapper = $this->createMock(CustomerMapper::class);
		$mapper->method('findById')->willReturn($target);
		$mapper->method('findByPcCustomerId')->willReturn(null);
		$mapper->method('findByCrmCompanyId')->willReturn(null);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static function (Customer $c) {
			return $c;
		});

		$access = $this->createMock(AccessControlService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(11);

		$facade = new class($mapper, $access, $this->createMock(InputValidator::class), $clock, $this->createMock(IConfig::class)) extends CrmFieldCustomerFacade {
			protected function probeCrmCompany(string $actorUid, int $crmCompanyId): ?array
			{
				return ['crmCompanyId' => $crmCompanyId, 'pcCustomerId' => 42];
			}
		};

		$result = $facade->ensureLink(1, 'alice', 42, 7, 10);
		$this->assertIsArray($result);
		$this->assertSame(42, $result['pcCustomerId'] ?? null);
		$this->assertSame(7, $result['crmCompanyId'] ?? null);
	}
}
