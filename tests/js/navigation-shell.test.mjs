import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('MN navigation shell uses AZ collapsible register/admin', () => {
	const nav = readFileSync(join(root, 'templates/common/navigation.php'), 'utf8');
	assert.match(nav, /mn-register-subnav/);
	assert.match(nav, /mn-admin-subnav/);
	assert.match(nav, /nav-parent-toggle/);
	const js = readFileSync(join(root, 'js/common/navigation.js'), 'utf8');
	assert.match(js, /aria-expanded/);
});

test('MN dialogs use modal-backdrop and inert unlock', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /modal-backdrop/);
	assert.match(src, /removeAttribute\('inert'\)/);
});
