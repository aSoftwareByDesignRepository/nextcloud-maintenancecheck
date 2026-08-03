import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('MN navigation shell uses AZ collapsible register + settings underpages', () => {
	const nav = readFileSync(join(root, 'templates/common/navigation.php'), 'utf8');
	assert.match(nav, /mn-register-subnav/);
	assert.match(nav, /nav-parent-toggle/);
	assert.match(nav, /Open settings/);
	assert.match(nav, /mn-settings-subnav/);
	assert.doesNotMatch(nav, /mn-admin-subnav/);
	const js = readFileSync(join(root, 'js/common/navigation.js'), 'utf8');
	assert.match(js, /aria-expanded/);
});

test('MN dialogs use modal-backdrop and inert unlock', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /modal-backdrop/);
	assert.match(src, /removeAttribute\('inert'\)/);
});

test('MN list pages use AZ filter-panel cards (visits + due use flat Bachus toolbars)', () => {
	for (const page of ['customers.php', 'equipment.php', 'work-orders.php']) {
		const tpl = readFileSync(join(root, 'templates', page), 'utf8');
		assert.match(tpl, /mn-filter-panel/, `${page} missing mn-filter-panel`);
		assert.doesNotMatch(tpl, /class="mn-toolbar"/, `${page} still uses bare mn-toolbar`);
	}
	const visits = readFileSync(join(root, 'templates/visits.php'), 'utf8');
	assert.match(visits, /mn-visits-toolbar/);
	assert.doesNotMatch(visits, /mn-filter-panel/);
	const due = readFileSync(join(root, 'templates/due.php'), 'utf8');
	assert.match(due, /mn-due-toolbar/);
	assert.match(due, /mn-due-kind-all/);
	assert.doesNotMatch(due, /mn-filter-panel/);
});

test('MN visits filter uses live status + when chips (no Apply click)', () => {
	const tpl = readFileSync(join(root, 'templates/visits.php'), 'utf8');
	assert.match(tpl, /mn-filter-status-chips/);
	assert.match(tpl, /mn-filter-when-chips/);
	assert.match(tpl, /mn-visits-toolbar/);
	assert.doesNotMatch(tpl, /Apply filters/);
	const app = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(app, /Date range was swapped so From is before To\./);
	assert.match(app, /function syncReset\(/);
	assert.match(app, /function setWhen\(/);
	assert.match(app, /statusChips\.addEventListener\('click'/);
	assert.match(app, /whenChips\.addEventListener\('click'/);
	const wo = readFileSync(join(root, 'templates/work-orders.php'), 'utf8');
	assert.doesNotMatch(wo, /Apply filters/);
	const woJs = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(woJs, /statusSelect\.addEventListener\('change',\s*applyFilters\)/);
	assert.match(woJs, /qInput\.addEventListener\('input',\s*runSearch\)/);
});


test('MN work-order pages script registers phone execute modules', () => {
	const src = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(src, /registerPage\('work-orders'/);
	assert.match(src, /registerPage\('work-order-detail'/);
	assert.match(src, /registerPage\('dispatch'/);
	assert.match(src, /registerPage\('tours'/);
	assert.match(src, /checklist/);
	assert.match(src, /showBootFailure/);
	assert.match(src, /Work orders could not start/);
	assert.match(src, /apiUrl\('customers'\)/);
	assert.match(src, /Select a customer/);
	assert.match(src, /data\.days/);
	assert.match(src, /date: date/);
	assert.match(src, /Download service report/);
	assert.match(src, /Suggest order/);
	assert.match(src, /enableDispatchKeyboard/);
	assert.match(src, /ArrowDown/);
	assert.match(src, /afterTransitionOk/);
	assert.match(src, /announce\(msg,\s*false\)/);
	assert.match(src, /Status: \{status\}/);
	assert.match(src, /mn-signature-canvas/);
	assert.doesNotMatch(src, /background\s*=\s*'#fff'/);
	assert.doesNotMatch(src, /Numeric customer id from the register/);
	const app = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(app, /MnApp\.__dom/);
	assert.match(app, /case 'in_progress'/);
	assert.match(app, /Create work order/);
	assert.match(app, /recordTimeUrl/);
	assert.match(app, /quickCompleteVisit/);
	assert.match(app, /Complete with details/);
	assert.match(app, /visitOverflowMenu/);
	assert.match(app, /options\.overflow === false/);
	assert.match(app, /MutationObserver/);
	assert.doesNotMatch(app, /addEventListener\(\s*['"]DOMNodeRemoved['"]/);
});

test('MN capacity assign gate locks tech row before assess (W4 TOCTOU)', () => {
	const src = readFileSync(join(root, 'lib/Service/WorkOrderService.php'), 'utf8');
	assert.match(src, /lockAssignGate\(\$primary\)/);
	assert.match(src, /capacityEnforcement\(\)/);
	const capacity = readFileSync(join(root, 'lib/Service/CapacityService.php'), 'utf8');
	assert.match(capacity, /function lockAssignGate/);
	const mapper = readFileSync(join(root, 'lib/Db/UserCapacityMapper.php'), 'utf8');
	assert.match(mapper, /function ensureAndLock/);
	assert.match(mapper, /forUpdate\(\)/);
});

test('MN builtin seeder ships facility/hvac/industrial packs', () => {
	const src = readFileSync(join(root, 'lib/Service/BuiltinProcedurePackSeeder.php'), 'utf8');
	assert.match(src, /builtin-facility-v1\.json/);
	assert.match(src, /builtin-hvac-v1\.json/);
	assert.match(src, /builtin-industrial-v1\.json/);
	for (const file of [
		'builtin-facility-v1.json',
		'builtin-hvac-v1.json',
		'builtin-industrial-v1.json',
	]) {
		assert.ok(existsSync(join(root, 'data/procedure-packs', file)), file);
	}
});
