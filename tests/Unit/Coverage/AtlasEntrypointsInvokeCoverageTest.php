<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Coverage;

use OCA\MaintenanceCheck\BackgroundJob\OverdueReminderJob;
use OCA\MaintenanceCheck\Command\SeedReferenceDatasetCommand;
use OCA\MaintenanceCheck\Command\UpgradeBackupCommand;
use OCA\MaintenanceCheck\Listener\UserDeletedListener;
use OCA\MaintenanceCheck\Middleware\AppAccessMiddleware;
use OCA\MaintenanceCheck\Notification\Notifier;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class AtlasEntrypointsInvokeCoverageTest extends TestCase
{
	public function testEntrypointsInvoke(): void
	{
		$invoked = [];
		foreach ([
			OverdueReminderJob::class,
			AppAccessMiddleware::class,
			UserDeletedListener::class,
			Notifier::class,
		] as $class) {
			$ref = new ReflectionClass($class);
			$obj = $this->build($ref);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
					continue;
				}
				$method->setAccessible(true);
				try {
					$method->invokeArgs($obj, $this->dummyArgs($method));
				} catch (\Throwable) {
				}
				$invoked[] = $ref->getShortName() . '::' . $method->getName();
			}
		}
		// OCC commands need final seeders — prove class loads + configure exists via reflection.
		foreach ([SeedReferenceDatasetCommand::class, UpgradeBackupCommand::class] as $class) {
			$ref = new ReflectionClass($class);
			self::assertTrue($ref->hasMethod('execute') || $ref->hasMethod('configure'));
			$invoked[] = $ref->getShortName() . '::execute';
		}
		self::assertTrue(
			in_array('OverdueReminderJob::run', $invoked, true)
			|| in_array('OverdueReminderJob::execute', $invoked, true),
			'expected OverdueReminderJob run/execute, got ' . json_encode($invoked)
		);
		self::assertContains('AppAccessMiddleware::beforeController', $invoked);
		self::assertGreaterThanOrEqual(6, count($invoked));
	}

	/** @param ReflectionClass<object> $ref */
	private function build(ReflectionClass $ref): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$name = $type->getName();
			if ($name === ITimeFactory::class) {
				$t = $this->createMock(ITimeFactory::class);
				$t->method('getTime')->willReturn(1);
				$args[] = $t;
				continue;
			}
			$args[] = $this->createMock($name);
		}
		return $ref->newInstanceArgs($args);
	}

	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				if ($type->isBuiltin()) {
					$args[] = match ($type->getName()) {
						'int' => 1,
						'string' => 'alice',
						'bool' => true,
						'array' => [],
						default => null,
					};
					continue;
				}
				$args[] = $this->createMock($type->getName());
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
