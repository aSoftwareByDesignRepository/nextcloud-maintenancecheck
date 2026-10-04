/**
 * Contract: toasts dedup on kind+text with timer reset — identical toasts must
 * not stack (design-system rule: toast hygiene; canonical implementation:
 * customercheck/js/common/components.js showToast, data-crm-toast-key).
 *
 * Regression guard for the ds_chrome fix: toast() previously appended a new
 * node on every call, so rapid retries/repeated API failures stacked.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '../..')
const app = readFileSync(join(root, 'js/app.js'), 'utf8')

// Slice out the toast() body for scoped assertions.
const toastBody = (() => {
	const start = app.indexOf('function toast(message, type, action)')
	assert.ok(start > -1, 'toast() must exist')
	// toast() ends at the dialog section comment that follows it.
	const end = app.indexOf('Dialogs (A6', start)
	assert.ok(end > start, 'toast() must precede the dialog section')
	return app.slice(start, end)
})()

test('toast() dedups on kind+text via data-mn-toast-key', () => {
	assert.match(toastBody, /var dedupKey\s*=\s*azcKind/)
	assert.match(toastBody, /String\(message\)/)
	assert.match(toastBody, /querySelectorAll\('\.mn-toast\[data-mn-toast-key\]'\)/)
	assert.match(toastBody, /'data-mn-toast-key':\s*dedupKey/)
})

test('dedup hit resets the dismiss timer instead of stacking', () => {
	assert.match(toastBody, /clearTimeout\(dupe\._mnDismissTimer\)/)
	assert.match(toastBody, /dupe\._mnDismissTimer\s*=\s*window\.setTimeout/)
	// early return — no second node is appended for an identical toast
	const hit = toastBody.indexOf("getAttribute('data-mn-toast-key') === dedupKey")
	assert.ok(hit > -1, 'dedup key comparison must exist')
	const retIdx = toastBody.indexOf('return;', hit)
	const appendIdx = toastBody.indexOf('region.appendChild(node)')
	assert.ok(retIdx > hit, 'dedup hit must return early')
	assert.ok(appendIdx === -1 || retIdx < appendIdx, 'dedup return must precede any append')
})

test('created toasts carry their dismiss timer for later resets', () => {
	assert.match(toastBody, /node\._mnDismissTimer\s*=\s*timer/)
})
