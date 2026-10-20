/**
 * Contract: exception-board 'Why' column renders translated badges, never
 * raw snake_case enum codes (mn-vis-exceptions-reasons-raw-enum).
 * The label map is pinned against the PHP enum in ExceptionBoardService so a
 * new server-side reason code cannot ship unmapped.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '../..')

test('exceptionReasonMeta maps every PHP enum code to a translated label', async () => {
	globalThis.document = undefined
	await import(join(root, 'js/app.js'))
	const meta = globalThis.MnApp?.exceptionReasonMeta
	assert.equal(typeof meta, 'function', 'MnApp.exceptionReasonMeta exported')

	const php = readFileSync(join(root, 'lib/Service/ExceptionBoardService.php'), 'utf8')
	const codes = [...new Set([...php.matchAll(/\$reasons\[\]\s*=\s*'([a-z_]+)'/g)].map((m) => m[1]))]
	assert.ok(codes.length >= 4, `expected the exception enum, got ${JSON.stringify(codes)}`)
	for (const code of codes) {
		const m = meta(code)
		assert.ok(m.label && m.label !== code, `${code}: raw enum leaked as label`)
		assert.ok(!/[a-z]_[a-z]/.test(m.label), `${code}: label still snake_case (${m.label})`)
		assert.match(m.badge, /^mn-badge--/, `${code}: missing badge class`)
	}
})

test('exceptions Why column renders badges via exceptionReasonMeta, not a raw join', () => {
	const js = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8')
	const at = js.indexOf("id: 'reasons'")
	assert.ok(at >= 0, 'reasons column not found')
	const block = [js.slice(at, at + 1500)]
	assert.ok(block, 'reasons column render not found')
	assert.match(block[0], /exceptionReasonMeta/, 'reasons render must map codes through exceptionReasonMeta')
	assert.doesNotMatch(block[0], /\.join\(\s*'/, 'reasons render must not join raw codes')
})

test('exceptionReasonMeta is exposed on the runtime dom registry', () => {
	const js = readFileSync(join(root, 'js/app.js'), 'utf8')
	assert.match(js, /__dom\s*=\s*\{[\s\S]*?exceptionReasonMeta:\s*exceptionReasonMeta/)
})
