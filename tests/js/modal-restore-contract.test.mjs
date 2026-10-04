/**
 * Contract: modal restore contract (COMPANION-DESIGN-SYSTEM §8 /
 * modal_restore_contract_violation):
 *  - restore target is resolved lazily at close time; a rebuilt trigger still
 *    counts, else #mn-page-actions → view heading → main content; never body
 *  - page lock (body overflow + inert regions) is paired per dialog — nested
 *    dialogs must not unlock the page while an outer dialog is still open.
 *
 * Regression guard for the ds_chrome fix in js/app.js openDialog/close.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '../..')
const app = readFileSync(join(root, 'js/app.js'), 'utf8')

const dialogBody = (() => {
	const start = app.indexOf('Dialogs (A6')
	assert.ok(start > -1, 'dialog section must exist')
	const end = app.indexOf('Form field helpers', start)
	assert.ok(end > start, 'dialog section must precede field helpers')
	return app.slice(start, end)
})()

test('restore target resolved lazily with isConnected + labelled fallbacks', () => {
	assert.match(dialogBody, /resolveDialogRestoreTarget\(previousFocus\)/)
	assert.match(dialogBody, /previousFocus\.isConnected/)
	assert.match(dialogBody, /getElementById\('mn-page-actions'\)/)
	assert.match(dialogBody, /getElementById\('mn-page-title'\)/)
	assert.match(dialogBody, /getElementById\('mn-main-content'\)/)
})

test('page lock is paired per open dialog (stack), not unconditional', () => {
	assert.match(dialogBody, /var openDialogStack\s*=\s*\[\]/)
	assert.match(dialogBody, /openDialogStack\.push\(ctx\)/)
	assert.match(dialogBody, /openDialogStack\.indexOf\(ctx\)/)
	// unlock only releases when the stack is empty
	assert.match(dialogBody, /function unlockPage\(\)\s*\{\s*if \(openDialogStack\.length !== 0\)\s*\{\s*return;/s)
	// lock applied before registering this ctx (empty-stack gate)
	assert.match(dialogBody, /lockPage\(\);\s*\n\s*openDialogStack\.push\(ctx\)/)
})

test('close() no longer clears the page lock unconditionally', () => {
	const closeBody = (() => {
		const s = dialogBody.indexOf('function close()')
		const e = dialogBody.indexOf('function focusables()', s)
		assert.ok(s > -1 && e > s)
		return dialogBody.slice(s, e)
	})()
	assert.doesNotMatch(closeBody, /document\.body\.style\.overflow\s*=\s*''/)
	assert.doesNotMatch(closeBody, /removeAttribute\('inert'\)/)
	assert.match(closeBody, /unlockPage\(\)/)
	assert.match(closeBody, /resolveDialogRestoreTarget\(previousFocus\)/)
})
