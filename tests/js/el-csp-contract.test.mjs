/**
 * CSP contract for el(): any `onX` attr with a function value MUST go through
 * addEventListener — never setAttribute. An inline `on…="…"` attribute is an
 * inline event handler and is blocked by Nextcloud's script-src CSP (the
 * user-picker assign flow broke exactly this way via onMouseDown).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = await readFile(join(root, 'js', 'app.js'), 'utf8');

function makeNode(tag) {
	const node = {
		tagName: String(tag).toUpperCase(),
		children: [],
		listeners: {},
		attrs: {},
		dataset: {},
		hidden: false,
		className: '',
		textContent: '',
		innerHTML: '',
		firstChild: null,
		setAttribute(name, value) { this.attrs[name] = String(value); },
		getAttribute(name) { return Object.hasOwn(this.attrs, name) ? this.attrs[name] : null; },
		removeAttribute(name) { delete this.attrs[name]; },
		addEventListener(name, fn) { (this.listeners[name] ??= []).push(fn); },
		appendChild(child) { this.children.push(child); this.firstChild = this.children[0] ?? null; return child; },
		removeChild(child) {
			this.children = this.children.filter((c) => c !== child);
			this.firstChild = this.children[0] ?? null;
			return child;
		},
		querySelectorAll() { return []; },
	};
	return node;
}

// Minimal DOM stub: readyState 'loading' defers boot() until we fire the
// captured DOMContentLoaded listener, so eval'ing app.js is side-effect free.
const appRoot = makeNode('div');
appRoot.dataset = { mnPage: '__test__', mnUrls: '{}' };

let domReady = null;
globalThis.document = {
	readyState: 'loading',
	documentElement: makeNode('html'),
	getElementById: (id) => (id === 'app-content' ? appRoot : null),
	createElement: makeNode,
	createTextNode: (t) => ({ nodeType: 3, textContent: String(t) }),
	querySelector: () => null,
	querySelectorAll: () => [],
	addEventListener: (name, fn) => { if (name === 'DOMContentLoaded') domReady = fn; },
};
globalThis.window = {
	localStorage: { getItem: () => null, setItem: () => {} },
	location: { assign: () => {} },
	setTimeout,
	clearTimeout,
};

(0, eval)(source);

assert.ok(domReady, 'app.js did not register a DOMContentLoaded boot listener');
domReady();

const el = globalThis.MnApp?.__dom?.el;
assert.equal(typeof el, 'function', 'MnApp.__dom.el missing — boot() did not run');

test('el() routes every onX function attr through addEventListener', () => {
	const node = el('li', {
		onClick: () => {},
		onMouseDown: () => {},
		onKeyDown: () => {},
		onFocus: () => {},
		onPointerUp: () => {},
	});
	assert.deepEqual(
		Object.keys(node.listeners).sort(),
		['click', 'focus', 'keydown', 'mousedown', 'pointerup'],
	);
	for (const name of Object.keys(node.attrs)) {
		assert.ok(!/^on/i.test(name), `inline handler attribute leaked to DOM: ${name}`);
	}
});

test('el() still maps class/text/html, booleans, and plain attributes', () => {
	const node = el('button', {
		type: 'button',
		class: 'mn-btn',
		text: 'Go',
		html: '<b>i</b>',
		disabled: true,
		hidden: false,
		title: null,
		'aria-label': 'run',
	});
	assert.equal(node.className, 'mn-btn');
	assert.equal(node.textContent, 'Go');
	assert.equal(node.innerHTML, '<b>i</b>');
	assert.equal(node.attrs.type, 'button');
	assert.equal(node.attrs.disabled, '');
	assert.equal(node.attrs['aria-label'], 'run');
	assert.ok(!('hidden' in node.attrs), 'false attr must be skipped');
	assert.ok(!('title' in node.attrs), 'null attr must be skipped');
});

test('el() appends string children as text nodes, skips falsy children', () => {
	const child = el('span', { text: 'x' });
	const node = el('div', {}, ['txt', child, null, false]);
	assert.equal(node.children.length, 2);
	assert.equal(node.children[0].textContent, 'txt');
	assert.equal(node.children[1], child);
});
