import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../js/calendar-page.js', import.meta.url), 'utf8');
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
const C = sandbox.MnCalendar;

test('monthMatrix returns 42 Monday-first days covering the month', () => {
	const days = C.monthMatrix('2026-11-15');
	assert.equal(days.length, 42);
	assert.equal(days[0], '2026-10-26'); // Monday before Nov 1 (Sunday)
	assert.equal(days[41], '2026-12-06');
	assert.ok(days.includes('2026-11-30'));
	// Every consecutive day differs by exactly 1.
	for (let i = 1; i < days.length; i += 1) {
		assert.equal(C.dayDiff(days[i - 1], days[i]), 1);
	}
});

test('weekStart anchors on ISO Monday', () => {
	assert.equal(C.weekStart('2026-11-15'), '2026-11-09'); // Sunday → prior Monday
	assert.equal(C.weekStart('2026-11-09'), '2026-11-09'); // already Monday
});

test('clipSpan clips to the window with continuation flags', () => {
	const clip = (a, b, c, d) => {
		const r = C.clipSpan(a, b, c, d);
		return r === null ? null : JSON.parse(JSON.stringify(r));
	};
	assert.deepEqual(clip('2026-11-10', '2026-11-12', '2026-11-01', '2026-11-30'), {
		start: '2026-11-10', end: '2026-11-12', continuesBefore: false, continuesAfter: false,
	});
	assert.deepEqual(clip('2026-10-30', '2026-12-02', '2026-11-01', '2026-11-30'), {
		start: '2026-11-01', end: '2026-11-30', continuesBefore: true, continuesAfter: true,
	});
	assert.equal(clip('2026-10-01', '2026-10-05', '2026-11-01', '2026-11-30'), null);
});

test('groupByDay expands multi-day events once per covered day', () => {
	const map = C.groupByDay([
		{ entity: 'work_order', id: 1, startDay: '2026-11-16', endDay: '2026-11-18' },
		{ entity: 'visit', id: 9, startDay: '2026-11-17', endDay: '2026-11-17' },
	]);
	assert.equal(map['2026-11-16'].length, 1);
	assert.equal(map['2026-11-17'].length, 2);
	assert.equal(map['2026-11-18'].length, 1);
	assert.equal(map['2026-11-19'], undefined);
});

test('cellSplit caps at N + overflow bucket', () => {
	const ev = (id) => ({ id });
	const list = [ev(1), ev(2), ev(3), ev(4), ev(5)];
	const split = C.cellSplit(list, 3);
	assert.equal(split.visible.length, 3);
	assert.equal(split.overflow.length, 2);
	assert.deepEqual(C.cellSplit(list, 3).overflow.map((e) => e.id), [4, 5]);
});

test('spanDays inclusive boundaries', () => {
	assert.equal(C.spanDays('2026-11-16', '2026-11-18'), 3);
	assert.equal(C.spanDays('2026-11-16', '2026-11-16'), 1);
	assert.equal(C.spanDays('2026-11-18', '2026-11-16'), 0);
	assert.equal(C.spanDays('not-a-date', '2026-11-16'), 0);
});

test('addMonths clamps month-end (S2 parity)', () => {
	assert.equal(C.addMonths('2026-01-31', 1), '2026-02-28');
	assert.equal(C.addMonths('2026-11-15', -1), '2026-10-15');
});

test('dayInWindow classifies selected/focus days vs the fetched window', () => {
	const from = '2026-10-26';
	const to = '2026-12-06';
	assert.equal(C.dayInWindow('2026-11-15', from, to), true);
	assert.equal(C.dayInWindow('2026-10-26', from, to), true); // inclusive edges
	assert.equal(C.dayInWindow('2026-12-06', from, to), true);
	assert.equal(C.dayInWindow('2026-12-07', from, to), false); // one past the 42-cell edge
	assert.equal(C.dayInWindow('2026-10-25', from, to), false);
	assert.equal(C.dayInWindow('bogus', from, to), false);
	assert.equal(C.dayInWindow('2026-11-15', '2026-12-06', '2026-10-26'), false); // inverted window
});

test('selection_window_desync guards stay wired in calendar-page.js', () => {
	// Regression contract for mn-crit-r22-calendar-selection-window-desync:
	// selectedDay/focusDay ⊆ windowFor(), boundary arrows shift the window,
	// and the agenda never asserts job-data for a day that was never fetched.
	assert.match(src, /var loadedWindow = null;/);
	assert.match(src, /function clampSelection\(/);
	assert.match(src, /loadedWindow = w;/);
	assert.match(src, /dayInWindow\(target, w\.from, w\.to\)/);
	assert.match(src, /anchor = target;\s*\n\s*board\.focus\(\);\s*\n\s*load\(\);/);
	assert.match(src, /tabindex: day === selectedDay \? '0' : '-1'/);
	assert.match(src, /dayInWindow\(selectedDay, loadedWindow\.from, loadedWindow\.to\)/);
	assert.match(src, /agenda\.appendChild\(skeleton\(2\)\)/);
});
