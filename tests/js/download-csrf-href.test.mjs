/**
 * Contract: PDF / document downloads stay plain href navigation.
 * That is why WorkOrderController PDF methods must be #[NoCSRFRequired].
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

test('work-order PDF menu items use href (not api fetch)', async () => {
	const js = await readFile(join(root, 'js', 'work-order-pages.js'), 'utf8');
	assert.match(js, /href:\s*apiUrl\('workOrders'\)\s*\+\s*'\/'\s*\+\s*wo\.id\s*\+\s*'\/pdf\/job-pack'/);
	assert.match(js, /href:\s*apiUrl\('workOrders'\)\s*\+\s*'\/'\s*\+\s*wo\.id\s*\+\s*'\/pdf\/servicebericht'/);
	assert.match(js, /href:\s*apiUrl\('workOrders'\)\s*\+\s*'\/'\s*\+\s*wo\.id\s*\+\s*'\/pdf\/inspection-evidence'/);
	assert.doesNotMatch(js, /api\(\s*['"]GET['"]\s*,\s*[^)]*\/pdf\/job-pack/);
});

test('photo chips and equip-doc links open via href with new-tab a11y attrs', async () => {
	const wo = await readFile(join(root, 'js', 'work-order-pages.js'), 'utf8');
	assert.match(wo, /href:\s*apiUrl\('workOrders'\)\s*\+\s*'\/'\s*\+\s*wo\.id\s*\+\s*'\/photos\/'\s*\+\s*photo\.id/);
	assert.match(wo, /target:\s*'_blank'/);

	const app = await readFile(join(root, 'js', 'app.js'), 'utf8');
	assert.match(app, /apiUrl\('equipDocs'\)\s*\+\s*'\/'\s*\+\s*doc\.id\s*\+\s*'\/download'/);
	assert.match(app, /target:\s*'_blank'/);
	assert.match(app, /rel:\s*'noopener noreferrer'/);
});

test('overflow menu marks href items as menuitem links with noopener', async () => {
	const app = await readFile(join(root, 'js', 'app.js'), 'utf8');
	const idx = app.indexOf('function visitOverflowMenu');
	assert.ok(idx > 0, 'visitOverflowMenu missing');
	const slice = app.slice(idx, idx + 2500);
	assert.match(slice, /role:\s*'menuitem'/);
	assert.match(slice, /target:\s*'_blank'/);
	assert.match(slice, /rel:\s*'noopener noreferrer'/);
	assert.match(slice, /'aria-label':\s*tr\('More actions'\)/);
});

test('controllers annotate PDF downloads with NoCSRFRequired', async () => {
	const src = await readFile(join(root, 'lib', 'Controller', 'WorkOrderController.php'), 'utf8');
	for (const method of ['jobPackPdf', 'serviceberichtPdf', 'inspectionEvidencePdf', 'downloadPhoto', 'downloadSignature']) {
		const re = new RegExp(`#\\[NoCSRFRequired\\][\\s\\S]{0,120}function ${method}\\(`);
		assert.match(src, re, `${method} must carry #[NoCSRFRequired]`);
	}
	assert.doesNotMatch(
		src,
		/#\[NoCSRFRequired\][\s\S]{0,80}function create\(\): JSONResponse/,
		'create() must keep CSRF',
	);
});
