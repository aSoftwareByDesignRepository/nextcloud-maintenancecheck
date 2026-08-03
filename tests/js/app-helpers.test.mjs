/**
 * Contract tests for the pure client helpers in js/app.js.
 *
 * The JS interval math MUST match lib/Service/IntervalCalculator.php
 * (SPEC §6.1, clamp semantics S2) — the vectors below mirror
 * tests/Unit/Service/IntervalCalculatorTest.php exactly.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = await readFile(join(root, 'js', 'app.js'), 'utf8');

// Execute the IIFE in this module's global scope; app.js bails out before any
// DOM access when `document` is undefined and exports MnApp on globalThis.
(0, eval)(source);
const MnApp = globalThis.MnApp;

test('MnApp is exported with all pure helpers', () => {
	assert.ok(MnApp, 'globalThis.MnApp missing');
	for (const fn of ['isValidYmd', 'addInterval', 'formatDate', 'statusMeta', 'bucketMeta', 'intervalLabel']) {
		assert.equal(typeof MnApp[fn], 'function', `MnApp.${fn} missing`);
	}
});

test('addInterval matches the PHP spec vectors (parity with IntervalCalculator)', () => {
	const vectors = [
		['2026-03-15', 'day', 10, '2026-03-25'],
		['2026-01-31', 'day', 1, '2026-02-01'],
		['2026-12-31', 'day', 1, '2027-01-01'],
		['2028-02-28', 'day', 1, '2028-02-29'],
		['2027-02-28', 'day', 1, '2027-03-01'],
		['2026-03-02', 'week', 1, '2026-03-09'],
		['2026-01-05', 'week', 4, '2026-02-02'],
		['2026-12-28', 'week', 1, '2027-01-04'],
		['2026-03-15', 'month', 1, '2026-04-15'],
		['2026-01-31', 'month', 1, '2026-02-28'],
		['2028-01-31', 'month', 1, '2028-02-29'],
		['2026-01-30', 'month', 1, '2026-02-28'],
		['2026-03-31', 'month', 1, '2026-04-30'],
		['2026-08-31', 'month', 1, '2026-09-30'],
		['2026-01-31', 'month', 2, '2026-03-31'],
		['2026-11-15', 'month', 2, '2027-01-15'],
		['2026-12-31', 'month', 1, '2027-01-31'],
		['2026-05-14', 'month', 12, '2027-05-14'],
		['2026-01-15', 'month', 25, '2028-02-15'],
		['2026-06-10', 'year', 1, '2027-06-10'],
		['2028-02-29', 'year', 1, '2029-02-28'],
		['2028-02-29', 'year', 4, '2032-02-29'],
	];
	for (const [date, unit, count, expected] of vectors) {
		assert.equal(
			MnApp.addInterval(date, unit, count),
			expected,
			`${date} + ${count} ${unit}`,
		);
	}
});

test('addInterval rejects invalid input with null', () => {
	assert.equal(MnApp.addInterval('banana', 'day', 1), null);
	assert.equal(MnApp.addInterval('2026-02-30', 'day', 1), null);
	assert.equal(MnApp.addInterval('2026-01-15', 'fortnight', 1), null);
	assert.equal(MnApp.addInterval(null, 'day', 1), null);
});

test('isValidYmd strict shape and real-date checks', () => {
	assert.equal(MnApp.isValidYmd('2026-01-05'), true);
	assert.equal(MnApp.isValidYmd('2028-02-29'), true);
	assert.equal(MnApp.isValidYmd('2027-02-29'), false);
	assert.equal(MnApp.isValidYmd('2026-13-01'), false);
	assert.equal(MnApp.isValidYmd('2026-01-00'), false);
	assert.equal(MnApp.isValidYmd('2026-1-05'), false);
	assert.equal(MnApp.isValidYmd('2026/01/05'), false);
	assert.equal(MnApp.isValidYmd(''), false);
	assert.equal(MnApp.isValidYmd(20260105), false);
});

test('statusMeta maps every visit status to label, badge class, and A4 icon', () => {
	assert.deepEqual(MnApp.statusMeta('scheduled'), { label: 'Scheduled', badge: 'mn-badge--scheduled', icon: 'calendar' });
	assert.deepEqual(MnApp.statusMeta('done'), { label: 'Done', badge: 'mn-badge--done', icon: 'check' });
	assert.deepEqual(MnApp.statusMeta('skipped'), { label: 'Skipped', badge: 'mn-badge--skipped', icon: 'skip' });
	assert.deepEqual(MnApp.statusMeta('cancelled'), { label: 'Cancelled', badge: 'mn-badge--cancelled', icon: 'x' });
	assert.deepEqual(MnApp.statusMeta('draft'), { label: 'Draft', badge: 'mn-badge--neutral', icon: 'calendar' });
	assert.deepEqual(MnApp.statusMeta('in_progress'), { label: 'In progress', badge: 'mn-badge--scheduled', icon: 'clock' });
	// Unknown statuses degrade to neutral — never colour-only empty class.
	assert.deepEqual(MnApp.statusMeta('weird'), { label: 'weird', badge: 'mn-badge--neutral', icon: null });
});

test('bucketMeta covers all four S8 buckets with A4 icons', () => {
	assert.equal(MnApp.bucketMeta('overdue').title, 'Overdue');
	assert.equal(MnApp.bucketMeta('today').title, 'Due today');
	assert.equal(MnApp.bucketMeta('next7').title, 'Next 7 days');
	assert.equal(MnApp.bucketMeta('later').title, 'Later (up to 30 days)');
	assert.equal(MnApp.bucketMeta('overdue').badge, 'mn-badge--overdue');
	assert.equal(MnApp.bucketMeta('overdue').icon, 'alert-triangle');
	assert.equal(MnApp.bucketMeta('today').icon, 'clock');
	assert.equal(MnApp.bucketMeta('next7').icon, 'calendar');
	assert.equal(MnApp.bucketMeta('later').icon, 'calendar');
	assert.equal(MnApp.bucketMeta('later').badge, 'mn-badge--neutral');
	assert.equal(MnApp.bucketMeta('nope').title, 'nope');
	assert.equal(MnApp.bucketMeta('nope').badge, 'mn-badge--neutral');
});

test('statusBadge returns A4 descriptor without DOM (icon + text, never colour alone)', () => {
	assert.deepEqual(
		MnApp.statusBadge('Overdue', 'mn-badge--overdue', 'alert-triangle'),
		{ label: 'Overdue', badge: 'mn-badge--overdue', icon: 'alert-triangle' },
	);
});

test('visitSubjectTitle never repeats identical customer/equipment labels', () => {
	assert.equal(MnApp.visitSubjectTitle({ customerName: 'ov-1', equipmentLabel: 'ov-1' }), 'ov-1');
	assert.equal(MnApp.visitSubjectTitle({ customerName: 'Acme', equipmentLabel: 'Pump' }), 'Pump');
	assert.equal(MnApp.visitSubjectTitle({ customerName: 'Acme', equipmentLabel: '' }), 'Acme');
	assert.equal(MnApp.visitSubjectTitle({}), 'Visit');
});

test('visitSubjectSub drops duplicate customer and bilingual Inspection leftovers', () => {
	assert.equal(
		MnApp.visitSubjectSub({ customerName: 'ov-1', equipmentLabel: 'ov-1', maintTypeName: 'Inspection / Prüfung' }),
		'Inspection',
	);
	assert.equal(
		MnApp.visitSubjectSub({ customerName: 'Acme', equipmentLabel: 'Pump', maintTypeName: 'Filter change' }),
		'Acme · Filter change',
	);
	assert.equal(
		MnApp.displayMaintTypeName({ isInspection: true, maintTypeName: 'Inspection / Prüfung' }),
		'Inspection',
	);
});

test('todayYmd prefers server calendar date (S1)', () => {
	MnApp.setServerToday('2026-07-24');
	assert.equal(MnApp.todayYmd(), '2026-07-24');
	MnApp.setServerToday('not-a-date');
	assert.equal(MnApp.todayYmd(), '2026-07-24', 'invalid setServerToday must keep prior value');
	MnApp.setServerToday('2026-12-31');
	assert.equal(MnApp.todayYmd(), '2026-12-31');
});

test('intervalLabel pluralises correctly', () => {
	assert.equal(MnApp.intervalLabel('day', 1), 'Every day');
	assert.equal(MnApp.intervalLabel('day', 3), 'Every 3 days');
	assert.equal(MnApp.intervalLabel('week', 1), 'Every week');
	assert.equal(MnApp.intervalLabel('week', 2), 'Every 2 weeks');
	assert.equal(MnApp.intervalLabel('month', 1), 'Every month');
	assert.equal(MnApp.intervalLabel('month', 6), 'Every 6 months');
	assert.equal(MnApp.intervalLabel('year', 1), 'Every year');
	assert.equal(MnApp.intervalLabel('year', 2), 'Every 2 years');
	assert.equal(MnApp.intervalLabel('eon', 1), '');
});

test('formatDate falls back safely for invalid values', () => {
	assert.equal(MnApp.formatDate(null), '');
	assert.equal(MnApp.formatDate('not-a-date'), 'not-a-date');
	// Valid date renders with the year and day present regardless of locale.
	const rendered = MnApp.formatDate('2026-07-24', 'en');
	assert.match(rendered, /2026/);
	assert.match(rendered, /24/);
});

test('normalizeTableColumns keeps only id+label columns (key aliases id)', () => {
	assert.deepEqual(MnApp.normalizeTableColumns(null), []);
	assert.deepEqual(MnApp.normalizeTableColumns('nope'), []);
	assert.deepEqual(
		MnApp.normalizeTableColumns([
			null,
			{ id: '', label: 'X' },
			{ id: 'name', label: '  Name  ', className: 'wide', actions: true },
			{ key: 'status', label: 'Status' },
			{ id: 'skip-me' },
		]),
		[
			{ id: 'name', label: 'Name', className: 'wide', actions: true },
			{ id: 'status', label: 'Status', className: '', actions: false },
		],
	);
});

test('tableCellText never returns a blank cell', () => {
	assert.equal(MnApp.tableCellText(null), '—');
	assert.equal(MnApp.tableCellText(undefined), '—');
	assert.equal(MnApp.tableCellText(''), '—');
	assert.equal(MnApp.tableCellText('   '), '—');
	assert.equal(MnApp.tableCellText('Berlin'), 'Berlin');
	assert.equal(MnApp.tableCellText(0), '0');
});

test('buildTableModel is a pure §3.7 table contract', () => {
	const model = MnApp.buildTableModel(
		[
			{ id: 'name', label: 'Customer' },
			{ key: 'actions', label: 'Actions', actions: true },
			{ id: '', label: 'bad' },
		],
		[{ id: 1 }, { id: 2 }],
	);
	assert.equal(model.rowCount, 2);
	assert.deepEqual(model.columns, [
		{ id: 'name', label: 'Customer', className: '', actions: false },
		{ id: 'actions', label: 'Actions', className: '', actions: true },
	]);
	assert.deepEqual(MnApp.buildTableModel([], null).rowCount, 0);
});

test('list pages use design-system tableOrCards (not orphaned mn-row cards)', () => {
	assert.match(source, /function tableOrCards\(/);
	assert.match(source, /mn-table table table--hover mn-table--responsive/);
	assert.match(source, /data-label/);
	assert.match(source, /mn-table-actions/);
	assert.match(source, /tabindex:\s*'0'/);
	assert.match(source, /list\.appendChild\(tableOrCards\(/);
	assert.match(source, /equipmentTable\(/);
	// Dense office lists must not keep the old card-row renderer.
	assert.doesNotMatch(source, /function equipmentRow\(/);
	// Due board + ops boards: design-system tables (no visit-card / mn-list stacks).
	assert.match(source, /function dueBucketTable\(/);
	assert.doesNotMatch(source, /function visitCard\(/);
	assert.doesNotMatch(source, /function workOrderDueCard\(/);
	assert.doesNotMatch(source, /mn-visit-card/);
});

test('ops boards (exceptions/kpi/tours/kit) use tableOrCards not ul.mn-list stacks', async () => {
	const wo = await readFile(join(root, 'js', 'work-order-pages.js'), 'utf8');
	assert.match(wo, /function pageExceptions\(/);
	assert.match(wo, /function pageKpi\(/);
	assert.match(wo, /tableOrCards\(\[/);
	assert.match(wo, /caption:\s*tr\('Exceptions'\)/);
	assert.match(wo, /caption:\s*tr\('Open work orders by status'\)/);
	assert.match(wo, /caption:\s*tr\('Stops'\)/);
	assert.match(wo, /caption:\s*tr\('Kit \/ parts'\)/);
	assert.doesNotMatch(wo, /class:\s*'mn-list'/);
	assert.doesNotMatch(wo, /mn-tour-stops/);
	assert.match(wo, /MnApp\.__dom/);
});

test('list loads guard against stale responses (loadSeq)', () => {
	assert.match(source, /var loadSeq = 0/);
	assert.match(source, /var seq = \+\+loadSeq/);
	assert.match(source, /if \(seq !== loadSeq\)/);
	assert.match(source, /function announceResults\(/);
	assert.match(source, /announceResults\(/);
	assert.match(source, /function pageDue\(\)[\s\S]*?var loadSeq = 0/);
});

test('required fields set aria-required and required attribute', () => {
	assert.match(source, /input\.setAttribute\('required'/);
	assert.match(source, /input\.setAttribute\('aria-required',\s*'true'\)/);
	assert.match(source, /\{label\} \(required\)/);
	assert.doesNotMatch(source, /form-label--required mn-field__label--required/);
});

test('S9 force-delete dialog re-applies checkbox gate after setBusy(false)', () => {
	// Source contract: setBusy restores prior disabled state and onIdle/syncDeleteGate
	// keeps #mn-confirm-delete disabled unless the confirm checkbox is checked.
	assert.match(source, /dataset\.mnWasDisabled/);
	assert.match(source, /onIdle:\s*syncDeleteGate/);
	assert.match(source, /button\.disabled = !confirmBox\.checked/);
	assert.match(source, /if \(hasChildren && !confirmBox\.checked\) \{\s*return;/);
});

test('SPEC §8.3 seat assignment uses NC user picker combobox', () => {
	assert.match(source, /function attachUserPicker\(/);
	assert.match(source, /apiUrl\('usersSearch'\)/);
	assert.match(source, /role:\s*'combobox'/);
	assert.match(source, /Choose a Nextcloud user from the list\./);
});

test('A9 touch targets: chip remove and toast close are ≥44px in CSS', async () => {
	const css = await readFile(join(root, 'css', 'app.css'), 'utf8');
	assert.match(css, /\.mn-chip__remove\s*\{[^}]*min-width:\s*44px/s);
	assert.match(css, /\.mn-chip__remove\s*\{[^}]*min-height:\s*44px/s);
	assert.match(css, /\.mn-toast__close\s*\{[^}]*min-width:\s*44px/s);
	assert.match(css, /\.mn-toast__close\s*\{[^}]*min-height:\s*44px/s);
	assert.match(css, /\.mn-btn--compact\s*\{[^}]*min-height:\s*44px/s);
});

test('catalogs page: chip panels, no dead Status column, click-name edit', async () => {
	const tpl = await readFile(join(root, 'templates', 'catalogs.php'), 'utf8');
	const css = await readFile(join(root, 'css', 'app.css'), 'utf8');
	assert.match(tpl, /class="mn-catalogs"/);
	assert.match(tpl, /mn-catalogs-toolbar/);
	assert.match(tpl, /data-mn-catalog=/);
	assert.match(tpl, /mn-catalog-panel/);
	assert.match(tpl, /catalogs_quickstart_v2/);
	assert.doesNotMatch(tpl, /mn-catalogs__pair/);
	assert.doesNotMatch(tpl, /class="mn-columns"/);
	assert.match(css, /\.mn-catalogs-toolbar/);
	assert.match(css, /\.mn-catalog-panel\[hidden\]/);
	assert.match(source, /function catalogNameCell\(/);
	assert.match(source, /tr\('Procedure saved\.'\)/);
	assert.match(source, /tr\('No procedures yet'\)/);
	assert.match(source, /tr\('No skills yet'\)/);
	assert.match(source, /tr\('No kit templates yet'\)/);
	assert.match(source, /showCatalogPanel/);
	assert.match(source, /Skills load when you pick a person\./);
	assert.doesNotMatch(source, /tr\('Load skills'\)/);

	const catalogsStart = source.indexOf('function pageCatalogs(');
	const catalogsEnd = source.indexOf('// ── Page: settings', catalogsStart);
	assert.ok(catalogsStart > 0 && catalogsEnd > catalogsStart, 'pageCatalogs slice');
	const catalogsSlice = source.slice(catalogsStart, catalogsEnd);
	assert.match(catalogsSlice, /catalogNameCell\(/);
	assert.match(catalogsSlice, /inactive:\s*!type\.active/);
	assert.match(catalogsSlice, /visitOverflowMenu\(\[/);
	// No dedicated Status column inside catalogs (badge is inline on the name).
	assert.doesNotMatch(catalogsSlice, /id:\s*'status'/);
	assert.doesNotMatch(catalogsSlice, /label:\s*tr\('Status'\)/);
});
