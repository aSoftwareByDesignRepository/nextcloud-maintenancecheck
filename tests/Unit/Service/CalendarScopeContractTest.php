<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * NN-03 enforcement (spec §10): every `due_on` day-window predicate in lib/
 * must use the FA-1 span semantics — a raw `due_on =/lt/gt :x` equality join
 * in a window consumer is an invisible-WO / wrong-capacity regression.
 * This source-scan contract fails the build if a consumer silently
 * re-adds single-day semantics.
 */
final class CalendarScopeContractTest extends TestCase
{
	private const SRC = __DIR__ . '/../../../lib';

	/** Files allowed to compare due_on directly (validation, migration, display, visit-only queries). */
	private const ALLOWED_FILES = [
		'Migration/',
		'Db/WorkOrder.php',
		'Service/WorkOrderSpan.php',
		'Service/InputValidator.php',
		'Service/VisitService.php', // visit rows are single-day; WO rows go through dueUntil
		'Service/KpiService.php',   // visit KPI uses mn_visits.due_on; WO paths use due_until (asserted below)
		'Service/OverdueReminderService.php', // visit reminders use v.due_on; WO reminder uses due_until
	];

	public function testWorkOrderMapperUsesSpanPredicates(): void
	{
		$src = (string)file_get_contents(self::SRC . '/Db/WorkOrderMapper.php');
		// Window + day membership must go through the shared span predicates.
		$this->assertStringContainsString("lte('due_on'", $src);
		$this->assertStringContainsString("gte('due_until'", $src);
		$this->assertStringContainsString('constrainToWindow(', $src);
		$this->assertStringContainsString('constrainToDay(', $src);
		// The dispatch window must not keep single-day equality.
		$this->assertDoesNotMatchRegularExpression(
			"/findForDispatch[^}]*eq\('due_on'/s",
			$src,
			'findForDispatch must query the span overlap, not due_on equality',
		);
	}

	public function testNoRawDueOnWindowPredicateOutsideAllowlist(): void
	{
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC)) as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			if (!str_ends_with($path, '.php')) {
				continue;
			}
			$relative = substr($path, strlen(dirname(self::SRC)) + 1);
			foreach (self::ALLOWED_FILES as $allowed) {
				if (str_contains($relative, $allowed)) {
					continue 2;
				}
			}
			$src = (string)file_get_contents($path);
			if (!str_contains($src, 'mn_work_orders') && !str_contains($src, "->from('mn_work_orders')")) {
				continue;
			}
			// Raw single-day predicates on work-order spans. lte('due_on') is
			// the legitimate *start* bound of an overlap pair, so only the
			// span-end comparators (lt/gt/gte/eq) are violations — those must
			// compare due_until.
			if (preg_match('/->(lt|gt|gte|eq)\(\s*[\'"](?:v\.)?due_on[\'"]/', $src, $m)) {
				$this->fail(sprintf(
					'%s uses a raw `%s(due_on)` predicate — FA-1 requires span semantics (due_until) for window queries.',
					$relative,
					$m[1],
				));
			}
		}
		$this->addToAssertionCount(1);
	}

	public function testKpiWorkOrderOverdueUsesDueUntil(): void
	{
		$src = (string)file_get_contents(self::SRC . '/Service/KpiService.php');
		// The WO overdue/compliance paths compare due_until; only the visit
		// metric (mn_visits) may keep a single-day due_on predicate.
		$this->assertStringContainsString("lt('due_until'", $src);
	}

	public function testCalendarServiceScopeCannotWidenViaQuery(): void
	{
		$src = (string)file_get_contents(self::SRC . '/Service/CalendarService.php');
		// Non-office path must set mineUid and null the office filters.
		$this->assertStringContainsString('$mineUid = $isOffice ? null : $uid;', $src);
		$this->assertStringContainsString('$techUids = $isOffice', $src);
	}

	public function testOfficeTechFilterIncludesHelpers(): void
	{
		$src = (string)file_get_contents(self::SRC . '/Db/WorkOrderMapper.php');
		// The office tech filter must match the mine scope minus pool —
		// primary assignee OR JSON helper membership — or filtering to a
		// tech would silently drop the jobs they are booked on as helper.
		preg_match('/function findForCalendar.*?\n\t}/s', $src, $m);
		$this->assertNotEmpty($m, 'findForCalendar source not found');
		$body = $m[0];
		$this->assertStringContainsString('helper_uids', $body, 'office tech filter must include helper assignments');
		$this->assertStringContainsString("in('primary_user_id'", $body);
	}
}
