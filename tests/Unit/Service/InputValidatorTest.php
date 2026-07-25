<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Exception\ValidationException;
use OCA\MaintenanceCheck\Service\InputValidator;
use OCA\MaintenanceCheck\Service\IntervalCalculator;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §4.1 field contracts: trim-first, reject-overlong (never truncate),
 * strict types, S5/S7/S13/S14 date and query bounds.
 */
final class InputValidatorTest extends TestCase
{
	private const TODAY = '2026-07-24';

	private InputValidator $v;

	protected function setUp(): void
	{
		$this->v = new InputValidator(new IntervalCalculator());
	}

	/**
	 * @param callable(): mixed $fn
	 */
	private function assertThrowsCode(string $errorCode, callable $fn, ?string $field = null, ?string $fieldCode = null): void
	{
		try {
			$fn();
			$this->fail('Expected ValidationException ' . $errorCode);
		} catch (ValidationException $e) {
			$this->assertSame($errorCode, $e->getErrorCode());
			if ($field !== null) {
				$details = $e->getDetails();
				$this->assertNotEmpty($details);
				$this->assertSame($field, $details[0]['field']);
				$this->assertSame($fieldCode, $details[0]['code']);
			}
		}
	}

	// ── Generic helpers ─────────────────────────────────────────────────

	public function testOptionalStringTrimsAndHandlesAbsence(): void
	{
		$this->assertNull($this->v->optionalString([], 'x'));
		$this->assertNull($this->v->optionalString(['x' => null], 'x'));
		$this->assertSame('a b', $this->v->optionalString(['x' => "  a b\t"], 'x'));
		$this->assertSame('', $this->v->optionalString(['x' => '   '], 'x'));
	}

	public function testOptionalStringRejectsNonString(): void
	{
		$this->assertThrowsCode('validation_failed', fn () => $this->v->optionalString(['x' => 5], 'x'), 'x', 'invalid_type');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->optionalString(['x' => ['a']], 'x'), 'x', 'invalid_type');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->optionalString(['x' => true], 'x'), 'x', 'invalid_type');
	}

	public function testRequiredStringBounds(): void
	{
		$this->assertSame('ok', $this->v->requiredString(['n' => ' ok '], 'n', 'name_required', 255, 'name_too_long'));
		$this->assertSame(str_repeat('ä', 255), $this->v->requiredString(['n' => str_repeat('ä', 255)], 'n', 'name_required', 255, 'name_too_long'));

		$this->assertThrowsCode('validation_failed', fn () => $this->v->requiredString([], 'n', 'name_required', 255, 'name_too_long'), 'n', 'name_required');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->requiredString(['n' => '   '], 'n', 'name_required', 255, 'name_too_long'), 'n', 'name_required');
		// 256 multibyte chars: overlong must be rejected, never truncated.
		$this->assertThrowsCode('validation_failed', fn () => $this->v->requiredString(['n' => str_repeat('ä', 256)], 'n', 'name_required', 255, 'name_too_long'), 'n', 'name_too_long');
	}

	public function testBoundedOptionalStringNormalizesEmptyToNull(): void
	{
		$this->assertNull($this->v->boundedOptionalString([], 'x', 10, 'too_long'));
		$this->assertNull($this->v->boundedOptionalString(['x' => '  '], 'x', 10, 'too_long'));
		$this->assertSame('abc', $this->v->boundedOptionalString(['x' => ' abc '], 'x', 10, 'too_long'));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->boundedOptionalString(['x' => str_repeat('y', 11)], 'x', 10, 'too_long'), 'x', 'too_long');
	}

	public function testIntOrThrow(): void
	{
		$this->assertSame(7, $this->v->intOrThrow(['x' => 7], 'x'));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->intOrThrow(['x' => '7'], 'x'), 'x', 'invalid_type');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->intOrThrow([], 'x'), 'x', 'invalid_type');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->intOrThrow(['x' => 7.5], 'x'), 'x', 'invalid_type');
	}

	public function testBoolOrDefault(): void
	{
		$this->assertTrue($this->v->boolOrDefault([], 'x', true));
		$this->assertFalse($this->v->boolOrDefault(['x' => null], 'x', false));
		$this->assertTrue($this->v->boolOrDefault(['x' => true], 'x', false));
		$this->assertFalse($this->v->boolOrDefault(['x' => false], 'x', true));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->boolOrDefault(['x' => 1], 'x', true), 'x', 'invalid_type');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->boolOrDefault(['x' => 'true'], 'x', true), 'x', 'invalid_type');
	}

	// ── Pagination (S7) ─────────────────────────────────────────────────

	public function testPaginationDefaults(): void
	{
		$this->assertSame(['limit' => 50, 'offset' => 0], $this->v->pagination(null, null));
		$this->assertSame(['limit' => 50, 'offset' => 0], $this->v->pagination('', ''));
	}

	public function testPaginationBounds(): void
	{
		$this->assertSame(['limit' => 1, 'offset' => 0], $this->v->pagination('1', null));
		$this->assertSame(['limit' => 200, 'offset' => 400], $this->v->pagination('200', '400'));

		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination('0', null));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination('201', null));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination('-5', null));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination('abc', null));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination('1.5', null));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination(null, '-1'));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->pagination(null, 'x'));
	}

	// ── Search (S13) ────────────────────────────────────────────────────

	public function testSearchTerm(): void
	{
		$this->assertSame('', $this->v->searchTerm(null));
		$this->assertSame('', $this->v->searchTerm('   '));
		$this->assertSame('pump', $this->v->searchTerm(' pump '));
		$this->assertSame(str_repeat('ä', 128), $this->v->searchTerm(str_repeat('ä', 128)));
		$this->assertThrowsCode('invalid_query', fn () => $this->v->searchTerm(str_repeat('ä', 129)));
	}

	// ── Dates (S5, S14/S15) ─────────────────────────────────────────────

	public function testDoneOnDefaultsToToday(): void
	{
		$this->assertSame(self::TODAY, $this->v->doneOn(null, self::TODAY));
		$this->assertSame(self::TODAY, $this->v->doneOn('  ', self::TODAY));
	}

	public function testDoneOnAcceptsPastUpToToday(): void
	{
		$this->assertSame('2000-01-01', $this->v->doneOn('2000-01-01', self::TODAY));
		$this->assertSame('2026-07-20', $this->v->doneOn(' 2026-07-20 ', self::TODAY));
		$this->assertSame(self::TODAY, $this->v->doneOn(self::TODAY, self::TODAY));
	}

	public function testDoneOnRejectsFutureAndOutOfRange(): void
	{
		$this->assertThrowsCode('invalid_done_on', fn () => $this->v->doneOn('2026-07-25', self::TODAY));
		$this->assertThrowsCode('invalid_done_on', fn () => $this->v->doneOn('1999-12-31', self::TODAY));
		$this->assertThrowsCode('invalid_done_on', fn () => $this->v->doneOn('2026-02-30', self::TODAY));
		$this->assertThrowsCode('invalid_done_on', fn () => $this->v->doneOn('yesterday', self::TODAY));
	}

	public function testDueOnAcceptsWindow(): void
	{
		$this->assertSame('2000-01-01', $this->v->dueOn('2000-01-01', self::TODAY));
		$this->assertSame(self::TODAY, $this->v->dueOn(self::TODAY, self::TODAY));
		// today + 10 years is the inclusive maximum.
		$this->assertSame('2036-07-24', $this->v->dueOn('2036-07-24', self::TODAY));
	}

	public function testDueOnRejectsOutOfWindow(): void
	{
		$this->assertThrowsCode('invalid_due_date', fn () => $this->v->dueOn('2036-07-25', self::TODAY));
		$this->assertThrowsCode('invalid_due_date', fn () => $this->v->dueOn('1999-12-31', self::TODAY));
		$this->assertThrowsCode('invalid_due_date', fn () => $this->v->dueOn(null, self::TODAY));
		$this->assertThrowsCode('invalid_due_date', fn () => $this->v->dueOn('', self::TODAY));
		$this->assertThrowsCode('invalid_due_date', fn () => $this->v->dueOn('soon', self::TODAY));
	}

	// ── Customer contract ───────────────────────────────────────────────

	public function testCustomerMinimal(): void
	{
		$out = $this->v->customer(['name' => ' ACME GmbH ']);
		$this->assertSame('ACME GmbH', $out['name']);
		$this->assertNull($out['customerNo']);
		$this->assertNull($out['country']);
		$this->assertNull($out['email']);
		$this->assertTrue($out['active']);
	}

	public function testCustomerFull(): void
	{
		$out = $this->v->customer([
			'name' => 'ACME',
			'customerNo' => 'C-1001',
			'street' => 'Hauptstraße 1',
			'postalCode' => '70173',
			'city' => 'Stuttgart',
			'country' => 'de',
			'email' => 'x@example.org',
			'phone' => '+49 711 1234',
			'notes' => 'Gate code 4711',
			'active' => false,
		]);
		$this->assertSame('DE', $out['country'], 'country is uppercased');
		$this->assertSame('x@example.org', $out['email']);
		$this->assertFalse($out['active']);
	}

	public function testCustomerRejectsBadCountry(): void
	{
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer(['name' => 'A', 'country' => 'DEU']), 'country', 'invalid_country');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer(['name' => 'A', 'country' => 'D1']), 'country', 'invalid_country');
	}

	public function testCustomerEmptyCountryAndEmailBecomeNull(): void
	{
		$out = $this->v->customer(['name' => 'A', 'country' => ' ', 'email' => '']);
		$this->assertNull($out['country']);
		$this->assertNull($out['email']);
	}

	public function testCustomerRejectsBadEmail(): void
	{
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer(['name' => 'A', 'email' => 'nope']), 'email', 'invalid_email');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer(['name' => 'A', 'email' => 'two@@x']), 'email', 'invalid_email');
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer(['name' => 'A', 'email' => 'a b@x.de']), 'email', 'invalid_email');
		$this->assertThrowsCode(
			'validation_failed',
			fn () => $this->v->customer(['name' => 'A', 'email' => str_repeat('a', 250) . '@x.com']),
			'email',
			'invalid_email',
		);
	}

	public function testCustomerRejectsMissingName(): void
	{
		$this->assertThrowsCode('validation_failed', fn () => $this->v->customer([]), 'name', 'name_required');
	}

	// ── Equipment contract ──────────────────────────────────────────────

	public function testEquipment(): void
	{
		$out = $this->v->equipment(['label' => ' Heat pump ', 'serialNo' => 'SN-1']);
		$this->assertSame('Heat pump', $out['label']);
		$this->assertSame('SN-1', $out['serialNo']);
		$this->assertNull($out['manufacturer']);
		$this->assertTrue($out['active']);

		$this->assertThrowsCode('validation_failed', fn () => $this->v->equipment([]), 'label', 'label_required');
		$this->assertThrowsCode(
			'validation_failed',
			fn () => $this->v->equipment(['label' => 'x', 'locationText' => str_repeat('y', 513)]),
			'locationText',
			'location_too_long',
		);
	}

	// ── Catalog contracts ───────────────────────────────────────────────

	public function testCatalogCode(): void
	{
		$this->assertSame('heat_pump_2', $this->v->catalogCode(['code' => ' heat_pump_2 ']));

		$this->assertThrowsCode('invalid_code', fn () => $this->v->catalogCode([]));
		$this->assertThrowsCode('invalid_code', fn () => $this->v->catalogCode(['code' => 'Heat']));
		$this->assertThrowsCode('invalid_code', fn () => $this->v->catalogCode(['code' => 'has space']));
		$this->assertThrowsCode('invalid_code', fn () => $this->v->catalogCode(['code' => 'ümlaut']));
		$this->assertThrowsCode('invalid_code', fn () => $this->v->catalogCode(['code' => str_repeat('a', 65)]));
	}

	public function testCatalogName(): void
	{
		$this->assertSame('Inspection', $this->v->catalogName(['name' => ' Inspection ']));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->catalogName([]), 'name', 'name_required');
	}

	// ── Notes bounds ────────────────────────────────────────────────────

	public function testNotesBounds(): void
	{
		$this->assertSame(str_repeat('n', 10000), $this->v->visitNotes(['notes' => str_repeat('n', 10000)]));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->visitNotes(['notes' => str_repeat('n', 10001)]), 'notes', 'notes_too_long');

		$this->assertSame(str_repeat('c', 512), $this->v->contractNotes(['contractNotes' => str_repeat('c', 512)]));
		$this->assertThrowsCode('validation_failed', fn () => $this->v->contractNotes(['contractNotes' => str_repeat('c', 513)]), 'contractNotes', 'notes_too_long');
	}
}
