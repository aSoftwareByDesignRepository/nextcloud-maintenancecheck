/**
 * Contract: work-order kind renders a translated label, never the raw server
 * enum, and unknown kinds never fall through to a wrong-sense label
 * (maintenancecheck-avd-20261120T150304Z-web-wo-kind-raw-render).
 * The map is pinned against WorkOrder::KIND_* so a new server-side kind
 * cannot ship unmapped.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '../..')

globalThis.document = undefined
await import(join(root, 'js/app.js'))
const kindLabel = globalThis.MnApp?.workOrderKindLabel
assert.equal(typeof kindLabel, 'function', 'MnApp.workOrderKindLabel exported')

test('workOrderKindLabel maps every PHP enum kind to a translated label', () => {
	const php = readFileSync(join(root, 'lib/Db/WorkOrder.php'), 'utf8')
	const kinds = [...php.matchAll(/const KIND_\w+ = '([a-z_]+)'/g)].map((m) => m[1])
	assert.ok(kinds.length >= 4, `expected the work-order kind enum, got ${JSON.stringify(kinds)}`)
	for (const kind of kinds) {
		const label = kindLabel(kind)
		assert.ok(label && label !== kind, `${kind}: raw enum leaked as label`)
		assert.ok(!/[a-z]_[a-z]/.test(label), `${kind}: label still snake_case (${label})`)
		assert.match(label, /work order$/i, `${kind}: label should name the entity (${label})`)
	}
})

test('unknown, empty and null kinds never mislabel as preventive', () => {
	for (const bogus of ['bogus', '', null, undefined, 'PREVENTIVE', 'preventative']) {
		const label = kindLabel(bogus)
		assert.ok(label && !/preventive/i.test(label), `${JSON.stringify(bogus)} mislabelled (${label})`)
		assert.equal(label, 'Work order', `${JSON.stringify(bogus)} should fall back to the generic title`)
	}
})

test('work-orders list subtitle renders the localized kind label, not the raw enum', () => {
	const js = readFileSync(join(root, 'js/work-order-pages.js'), 'utf8')
	assert.match(js, /workOrderKindLabel\(wo\.kind\)/, 'subtitle must map kind through workOrderKindLabel')
	assert.doesNotMatch(js, /wo\.kind \|\| ''/, 'subtitle must not pass the raw enum through')
})

test('due-bucket rows route kind through workOrderKindLabel (no preventive fallthrough)', () => {
	const js = readFileSync(join(root, 'js/app.js'), 'utf8')
	assert.match(js, /workOrderKindLabel\(row\.kind\)/, 'due-bucket kind must map through workOrderKindLabel')
	const at = js.indexOf('workOrderKindLabel(row.kind)')
	const block = js.slice(at, at + 400)
	assert.doesNotMatch(block, /Preventive work order'\)/, 'no wrong-sense preventive fallthrough after the map')
})

test('workOrderKindLabel is exposed on the runtime dom registry', () => {
	const js = readFileSync(join(root, 'js/app.js'), 'utf8')
	assert.match(js, /__dom\s*=\s*\{[\s\S]*?workOrderKindLabel:\s*workOrderKindLabel/)
})
