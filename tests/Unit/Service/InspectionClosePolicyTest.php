<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\InspectionClosePolicy;
use PHPUnit\Framework\TestCase;

final class InspectionClosePolicyTest extends TestCase
{
	private InspectionClosePolicy $policy;

	protected function setUp(): void
	{
		$this->policy = new InspectionClosePolicy();
	}

	private function inspectionWo(): WorkOrder
	{
		$wo = new WorkOrder();
		$wo->setKind(WorkOrder::KIND_INSPECTION);
		$wo->setTitle('DGUV V3');
		$wo->setNumber('WO-2026-00001');
		$wo->setCustomerId(1);
		return $wo;
	}

	public function testPassRequiresResultAndInspector(): void
	{
		$out = $this->policy->validateAndNormalize($this->inspectionWo(), [
			'result' => 'pass',
			'inspectorName' => 'Ada Lovelace',
		], true);
		$this->assertSame('pass', $out['result']);
		$this->assertSame('Ada Lovelace', $out['inspectorName']);
		$this->assertSame([], $out['defects']);
	}

	public function testInvalidResultRejected(): void
	{
		try {
			$this->policy->validateAndNormalize($this->inspectionWo(), [
				'result' => 'almost',
				'inspectorName' => 'Ada',
				'defects' => [['code' => 'x', 'body' => 'y']],
			], false);
			$this->fail('expected ValidationException for invalid result');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertStringContainsString('pass, fail, or conditional', $e->getMessage());
		}
	}

	public function testMissingInspectorFailsWhenRequired(): void
	{
		try {
			$this->policy->validateAndNormalize($this->inspectionWo(), [
				'result' => 'pass',
				'inspectorName' => '   ',
			], true);
			$this->fail('expected ValidationException for missing inspector');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertStringContainsString('inspectorName', $e->getMessage());
		}
	}

	public function testDefectRowsNeedCodeAndBody(): void
	{
		try {
			$this->policy->validateAndNormalize($this->inspectionWo(), [
				'result' => 'fail',
				'inspectorName' => 'Ada',
				'defects' => [['code' => '', 'body' => 'x']],
			], true);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
		}
	}

	public function testDefectsMustBeArray(): void
	{
		try {
			$this->policy->validateAndNormalize($this->inspectionWo(), [
				'result' => 'fail',
				'inspectorName' => 'Ada',
				'defects' => 'nope',
			], true);
			$this->fail('expected ValidationException for non-array defects');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertStringContainsString('defects must be an array', $e->getMessage());
		}
	}

	public function testMissingResultFailsWhenRequired(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessageMatches('/inspection result/i');
		$this->policy->validateAndNormalize($this->inspectionWo(), [
			'inspectorName' => 'Ada',
		], true);
	}

	public function testFailWithoutDefectsFails(): void
	{
		try {
			$this->policy->validateAndNormalize($this->inspectionWo(), [
				'result' => 'fail',
				'inspectorName' => 'Ada',
				'defects' => [],
			], true);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('inspection_defects_required', $e->getErrorCode());
		}
	}

	public function testFailWithDefectOk(): void
	{
		$out = $this->policy->validateAndNormalize($this->inspectionWo(), [
			'result' => 'fail',
			'inspectorName' => 'Ada',
			'defects' => [['code' => 'CRACK', 'body' => 'Rung cracked']],
		], true);
		$this->assertCount(1, $out['defects']);
		$this->assertSame('CRACK', $out['defects'][0]['code']);
	}

	public function testResultNotRequiredAllowsEmpty(): void
	{
		$out = $this->policy->validateAndNormalize($this->inspectionWo(), [
			'inspectorName' => '',
		], false);
		$this->assertSame('', $out['result']);
	}
}
