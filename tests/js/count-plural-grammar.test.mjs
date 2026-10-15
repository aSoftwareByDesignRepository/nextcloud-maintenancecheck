import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const app = readFileSync(join(root, 'js/app.js'), 'utf8');
const pages = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8');

const catalogs = {};
for (const file of readdirSync(join(root, 'l10n'))) {
	if (file.endsWith('.json')) {
		catalogs[file.replace(/\.json$/, '')] = JSON.parse(
			readFileSync(join(root, 'l10n', file), 'utf8'),
		).translations;
	}
}

test('tn() plural helper delegates to window.n with two-form fallback', () => {
	for (const [name, src] of [['app.js', app], ['work-order-pages.js', pages]]) {
		assert.match(src, /function tn\(singular, plural, count\)/, `${name} missing tn() helper`);
		assert.match(src, /window\.n\(APP, singular, plural, n\)/, `${name} tn() must delegate to window.n`);
	}
});

test('every tn() source pair has a _::_ catalog entry with per-locale forms', () => {
	const pairs = new Set();
	for (const src of [app, pages]) {
		for (const m of src.matchAll(/\btn\('((?:\\'|[^'])*)',\s*'((?:\\'|[^'])*)',/g)) {
			pairs.add([m[1], m[2]]);
		}
	}
	assert.ok(pairs.size >= 6, `expected >=6 tn() pairs, got ${pairs.size}`);
	for (const [singular, plural] of pairs) {
		const key = `_${singular}_::_${plural}_`;
		for (const [loc, tr] of Object.entries(catalogs)) {
			const entry = tr[key];
			assert.ok(Array.isArray(entry), `${loc}: missing array entry for ${key}`);
			assert.ok(entry.length >= 2, `${loc}: ${key} needs >=2 forms`);
			for (const form of entry) {
				assert.match(form, /%n/, `${loc}: ${key} form must keep the %n placeholder`);
			}
		}
	}
});

test('delete-customer dialog composes a plural-aware {children} summary', () => {
	assert.match(app, /tn\('%n equipment item', '%n equipment items', counts\.equipment\)/);
	assert.match(app, /tn\('%n plan', '%n plans', counts\.plans\)/);
	assert.match(app, /tn\('%n visit', '%n visits', counts\.visits\)/);
	assert.match(app, /\{children\}/);
	assert.doesNotMatch(app, /\{equipment\} equipment, \{plans\} plans and \{visits\} visits/);
});

test('no fixed-plural count-noun enumeration keys remain in catalogs', () => {
	const banned = [
		'“{name}” still has {equipment} equipment, {plans} plans and {visits} visits. Deleting the customer removes all of it permanently.',
		'Yes, permanently delete this customer including {equipment} equipment, {plans} plans and {visits} visits.',
		'{count} exceptions',
		'{count} stops',
		'{n} minutes per day',
		'{n} results',
	];
	for (const [loc, tr] of Object.entries(catalogs)) {
		for (const key of banned) {
			assert.ok(!(key in tr), `${loc}: fixed-plural key still present: ${key}`);
		}
	}
});

test('every l10n js mirror registers the locale pluralForm (multi-form locales need it)', () => {
	for (const file of readdirSync(join(root, 'l10n'))) {
		if (!file.endsWith('.js')) {
			continue;
		}
		const src = readFileSync(join(root, 'l10n', file), 'utf8');
		assert.match(src, /"nplurals=\d+; plural=/, `${file} missing nplurals register arg`);
	}
	const plJs = readFileSync(join(root, 'l10n/pl.js'), 'utf8');
	assert.match(plJs, /nplurals=3/, 'pl.js must declare 3 plural forms');
});
