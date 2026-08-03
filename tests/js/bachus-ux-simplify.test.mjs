import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('Bachus: visit overflow uses Complete with details + Skip with reason', () => {
	const app = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(app, /Complete with details/);
	assert.match(app, /Skip with reason/);
	assert.doesNotMatch(app, /label:\s*tr\('Edit details'\)/);
	assert.match(app, /function toast\(message, type, action\)/);
	assert.match(app, /mn-toast__action/);
	assert.match(app, /function beginCreateWorkOrderFromVisit/);
	assert.match(app, /function visitSubjectTitle/);
	assert.match(app, /function displayMaintTypeName/);
});

test('Bachus: checklist incomplete focuses first open item; corrective Open toast CTA', () => {
	const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(pages, /focusFirstIncompleteChecklist/);
	assert.match(pages, /mn-checklist__item--attention/);
	assert.match(pages, /label:\s*tr\('Open'\)/);
	assert.match(pages, /inspectorInput\.value\s*=/);
});

test('Bachus: work-order detail is one job sheet with hero + checklist + More', () => {
	const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(pages, /mn-wo-sheet/);
	assert.match(pages, /mn-wo-hero/);
	assert.match(pages, /mn-wo-hero__primary/);
	assert.match(pages, /mn-wo-hero__title/);
	assert.match(pages, /mn-wo-intake/);
	assert.match(pages, /mn-wo-evidence/);
	assert.match(pages, /mn-wo-more/);
	assert.match(pages, /tr\('Evidence'\)/);
	assert.match(pages, /tr\('Needs setup'\)/);
	assert.match(pages, /tr\('Details'\)/);
	assert.doesNotMatch(pages, /Big buttons — tap one to move the job forward/);
	assert.doesNotMatch(pages, /tr\('Next step'\)/);
	assert.doesNotMatch(pages, /More — procedure, skills, kit, comments/);
	const tpl = readFileSync(join(root, 'templates/work-order-detail.php'), 'utf8');
	assert.match(tpl, /work_order_detail_quickstart_v3/);
	assert.match(tpl, /Big button moves the job/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-wo-sheet/);
	assert.match(css, /\.mn-wo-hero__title/);
	assert.match(css, /\.mn-wo-intake/);
	assert.match(css, /#mn-wo-detail-quickstart/);
	assert.match(css, /min-height:\s*52px/);
});

test('Bachus: CSS attention ring + toast action touch target', () => {
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-checklist__item--attention/);
	assert.match(css, /\.mn-toast__action/);
	assert.match(css, /min-height:\s*44px/);
	assert.match(css, /\.mn-badge:not\(:has\(\.mn-badge__dot\)\):not\(:has\(\.mn-badge__icon\)\)::before/);
	assert.match(css, /\.mn-table-toolbar/);
	assert.match(css, /\.mn-card--table-solo/);
});

test('Bachus: list/board tables are table-solo (no soft-band header glued onto thead)', () => {
	const chrome = readFileSync(join(root, 'css/common/shell-chrome.css'), 'utf8');
	assert.match(chrome, /#content\.app-maintenancecheck\s+#app-content\s+\.mn-card:has\(\.mn-card__header\)/);
	assert.match(chrome, /Bachus \/ AZ parity: structured cards/);

	for (const page of [
		'visits.php',
		'customers.php',
		'equipment.php',
		'work-orders.php',
		'dispatch.php',
		'tours.php',
		'exceptions.php',
	]) {
		const tpl = readFileSync(join(root, 'templates', page), 'utf8');
		assert.match(tpl, /mn-card--table-solo/, `${page} missing mn-card--table-solo`);
		assert.doesNotMatch(tpl, /mn-card__header/, `${page} still has soft-band mn-card__header`);
		assert.doesNotMatch(tpl, /mn-card__lead/, `${page} still has lead glued above table`);
	}

	for (const page of ['catalogs.php', 'customer-detail.php', 'equipment-detail.php', 'kpi.php']) {
		const tpl = readFileSync(join(root, 'templates', page), 'utf8');
		assert.match(tpl, /mn-card--table-solo/, `${page} missing mn-card--table-solo`);
		assert.match(tpl, /mn-table-toolbar/, `${page} missing mn-table-toolbar`);
		assert.doesNotMatch(tpl, /mn-card__header/, `${page} still uses soft-band header`);
		assert.doesNotMatch(tpl, /mn-card__lead/, `${page} still has lead glued above table`);
	}

	// Settings underpages keep real form headers (not list tables).
	const access = readFileSync(join(root, 'templates/settings/access.php'), 'utf8');
	assert.match(access, /mn-card__header-text/);
});

test('Bachus: Catalogs chip tabs — one panel, no five-card wall', () => {
	const tpl = readFileSync(join(root, 'templates/catalogs.php'), 'utf8');
	assert.match(tpl, /mn-catalogs-toolbar/);
	assert.match(tpl, /catalogs_quickstart_v2/);
	assert.match(tpl, /data-mn-catalog="equip"/);
	assert.match(tpl, /mn-catalog-panel/);
	assert.doesNotMatch(tpl, /mn-catalogs__pair/);
	assert.doesNotMatch(tpl, /mn-catalogs__wide/);
	const app = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(app, /showCatalogPanel/);
	assert.match(app, /Skills load when you pick a person\./);
	assert.doesNotMatch(app, /tr\('Load skills'\)/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-catalogs-toolbar/);
	assert.match(css, /\.mn-catalog-panel\[hidden\]/);
	const ctrl = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
	assert.match(ctrl, /Pick a list, then add or edit/);
});

test('Bachus: Day tours empty CTA + date nav outside list + tech buckets', () => {
	const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(pages, /mn-tours-toolbar/);
	assert.match(pages, /Plan who drives today — then add stops\./);
	assert.match(pages, /Ask the office to plan your stops\./);
	assert.match(pages, /No tours on this day/);
	assert.match(pages, /Previous day/);
	assert.match(pages, /Next day/);
	assert.match(pages, /techDisplayName/);
	assert.match(pages, /Tour created\./);
	assert.match(pages, /Stop added\./);
	assert.match(pages, /mn-bucket mn-tour/);
	assert.match(pages, /mn-tour__actions/);
	assert.match(pages, /renderToolbar/);
	assert.doesNotMatch(pages, /Office can plan day tours under Planning\./);
	assert.doesNotMatch(pages, /mn-tour-card/);
	assert.match(pages, /visitOverflowMenu/);
	assert.match(pages, /Suggest order/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-tours-toolbar/);
	assert.match(css, /\.mn-tour__title/);
	assert.match(css, /\.mn-tours-toolbar__nav/);
	assert.match(css, /\.mn-tour__actions/);
	assert.doesNotMatch(css, /\.mn-tour-card\s*\{/);
	const emptyCss = readFileSync(join(root, 'css/common/shell-chrome.css'), 'utf8');
	assert.doesNotMatch(emptyCss, /\.mn-empty[\s\S]{0,120}dashed/);
	const tours = readFileSync(join(root, 'templates/tours.php'), 'utf8');
	assert.match(tours, /tours_quickstart_v3/);
	assert.match(tours, /mn-card--table-solo/);
	assert.match(tours, /id="mn-tours-toolbar"/);
	assert.match(tours, /mn-board mn-listing/);
	const ctrl = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
	assert.match(ctrl, /Pick the day, create a tour, add stops/);
});

test('Bachus: Dispatch flat chips + day buckets + Assign CTA, no nested day cards', () => {
	const tpl = readFileSync(join(root, 'templates/dispatch.php'), 'utf8');
	assert.match(tpl, /mn-dispatch-toolbar/);
	assert.match(tpl, /mn-card--table-solo/);
	assert.match(tpl, /dispatch_quickstart_v2/);
	assert.match(tpl, /data-mn-dispatch-filter="unassigned"/);
	assert.match(tpl, /data-mn-dispatch-filter="all"/);
	assert.match(tpl, /Needs owner/);
	assert.match(tpl, /All open/);
	assert.doesNotMatch(tpl, /mn-card__header/);
	assert.doesNotMatch(tpl, /mn-card__lead/);
	const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(pages, /Nothing needs an owner/);
	assert.match(pages, /Job assigned\./);
	assert.match(pages, /openDispatchAssign/);
	assert.match(pages, /mn-bucket/);
	assert.match(pages, /mn-sr-only mn-dispatch-hint/);
	assert.match(pages, /primaryUserDisplayName/);
	assert.doesNotMatch(pages, /mn-dispatch-lane/);
	assert.doesNotMatch(pages, /No jobs in this lane\./);
	assert.doesNotMatch(pages, /class: 'mn-muted mn-dispatch-hint'/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-dispatch-toolbar/);
	assert.match(css, /\.mn-dispatch-toolbar\s+\.mn-chip\.is-active/);
	assert.doesNotMatch(css, /\.mn-dispatch-lane\s*\{/);
	const ctrl = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
	assert.match(ctrl, /Tap Assign on a job — search and pick, never type an id/);
	const svc = readFileSync(join(root, 'lib/Service/DispatchService.php'), 'utf8');
	assert.match(svc, /primaryUserDisplayName/);
	assert.match(svc, /IUserManager/);
});

test('Bachus: Exceptions flat chips + filter-aware empty CTA, no soft-band lead', () => {
	const tpl = readFileSync(join(root, 'templates/exceptions.php'), 'utf8');
	assert.match(tpl, /mn-exceptions-toolbar/);
	assert.match(tpl, /mn-card--table-solo/);
	assert.match(tpl, /exceptions_quickstart_v2/);
	assert.match(tpl, /aria-pressed/);
	assert.doesNotMatch(tpl, /mn-card__header/);
	assert.doesNotMatch(tpl, /mn-card__lead/);
	assert.doesNotMatch(tpl, /Work that is blocked/);
	const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');
	assert.match(pages, /No blocked jobs/);
	assert.match(pages, /No incomplete kits/);
	assert.match(pages, /No missing skills/);
	assert.match(pages, /Open Work orders/);
	assert.match(pages, /When a job needs attention, it appears here\./);
	assert.doesNotMatch(pages, /When a job is blocked, overdue, or missing kit\/skills/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-exceptions-toolbar\s+\.mn-chip\.is-active/);
	const ctrl = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
	assert.match(ctrl, /Open a job and clear the blocker\./);
	assert.doesNotMatch(ctrl, /Blocked, overdue, kit or skills issues/);
});

test('Bachus: KPI actions chips align with CSV (no filter-bar margin)', () => {
	const tpl = readFileSync(join(root, 'templates/kpi.php'), 'utf8');
	assert.match(tpl, /mn-kpi-actions__chips/);
	assert.match(tpl, /aria-pressed/);
	assert.doesNotMatch(tpl, /mn-filter-bar/);
	const css = readFileSync(join(root, 'css/app.css'), 'utf8');
	assert.match(css, /\.mn-kpi-actions__chips/);
	assert.match(css, /\.mn-kpi-actions\s+\.mn-chip/);
	assert.match(css, /#mn-kpi-csv/);
	assert.doesNotMatch(css, /\.mn-kpi-actions[\s\S]{0,80}margin-bottom:\s*1rem/);
});
