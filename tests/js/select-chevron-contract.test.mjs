/**
 * Contract: rules that match <select> must not use the bare `background:`
 * shorthand — it resets the chevron's image/position/repeat/size to initial.
 * Regression of mn-vis-select-chevron-broken (light: chevron gone; dark:
 * tiled caret grid after the theme rule re-added image only).
 *
 * Widened for mn-input-residual-background-shorthand: class rules like
 * `.mn-input` match `select.mn-input` without naming `select`, so leg 2
 * discovers every class provably applied to a <select> in js/ + templates/
 * and rejects the bare shorthand in ANY rule using it (dialog selects sit
 * outside #app-content, where no restoring rule exists).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const cssRoot = join(appRoot, 'css')

function cssFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return cssFiles(path)
		}
		return entry.name.endsWith('.css') ? [path] : []
	})
}

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

/** Brace-aware rule extractor: yields {selector, body} for every ruleset. */
function* rules(css) {
	let i = 0
	while (i < css.length) {
		const open = css.indexOf('{', i)
		if (open === -1) {
			return
		}
		const selector = css.slice(css.lastIndexOf('}', i - 1) + 1, open).trim()
		let depth = 1
		let j = open + 1
		while (j < css.length && depth > 0) {
			if (css[j] === '{') depth++
			else if (css[j] === '}') depth--
			j++
		}
		yield { selector, body: css.slice(open + 1, j - 1) }
		i = j
	}
}

test('no select-matching rule uses the bare background: shorthand', () => {
	const offenders = []
	for (const file of cssFiles(cssRoot)) {
		const css = stripComments(readFileSync(file, 'utf8'))
		for (const { selector, body } of rules(css)) {
			if (!/\bselect\b/.test(selector)) {
				continue
			}
			if (/(^|;)\s*background\s*:/.test(body)) {
				offenders.push(`${relative(appRoot, file)}: ${selector}`)
			}
		}
	}
	assert.deepEqual(offenders, [],
		`bare background: shorthand resets the chevron (use background-color:):\n${offenders.join('\n')}`)
})

test('select chevron rule keeps image + position + repeat + size', () => {
	const css = stripComments(readFileSync(join(cssRoot, 'common/form-controls.css'), 'utf8'))
	const chevron = [...rules(css)].find((r) => /\bselect\b/.test(r.selector) && /background-image/.test(r.body))
	assert.ok(chevron, 'select chevron rule missing in form-controls.css')
	for (const prop of ['background-image', 'background-position', 'background-repeat', 'background-size']) {
		assert.match(chevron.body, new RegExp(`${prop}\\s*:`), `chevron rule lost ${prop}`)
	}
})

function sourceFiles(dir, exts) {
	try {
		return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
			const path = join(dir, entry.name)
			if (entry.isDirectory()) {
				return sourceFiles(path, exts)
			}
			return exts.some((ext) => entry.name.endsWith(ext)) ? [path] : []
		})
	} catch {
		return []
	}
}

/** Classes provably applied to <select> elements in js/ + templates/. */
function selectAppliedClasses() {
	const classes = new Set()
	const addClassValue = (raw) => {
		for (const tok of raw.split(/\s+/)) {
			if (/^[A-Za-z_-][\w-]*$/.test(tok)) {
				classes.add(tok)
			}
		}
	}
	const dirs = [join(appRoot, 'js'), join(appRoot, 'templates')]
	for (const dir of dirs) {
		for (const file of sourceFiles(dir, ['.js', '.php'])) {
			const src = stripComments(readFileSync(file, 'utf8'))
			for (const m of src.matchAll(/<select\b[^>]*?\bclass\s*=\s*["']([^"']+)["']/gi)) {
				addClassValue(m[1])
			}
			for (const m of src.matchAll(/(?:\bcreateElement|\bel|\bh|\$)\(\s*["']select["']/gi)) {
				let segment = src.slice(m.index + m[0].length, m.index + m[0].length + 600)
				const nxt = segment.search(/(?:\bcreateElement|\bel|\bh|\$)\(/i)
				if (nxt !== -1) {
					segment = segment.slice(0, nxt)
				}
				for (const cm of segment.matchAll(/class(?:Name)?\s*[:=]\s*["']([^"']+)["']/g)) {
					addClassValue(cm[1])
				}
				for (const cl of segment.matchAll(/classList\.add\(([^)]*)\)/g)) {
					for (const qm of cl[1].matchAll(/["']([^"']+)["']/g)) {
						addClassValue(qm[1])
					}
				}
			}
		}
	}
	return classes
}

test('no rule on a select-applied class uses the bare background: shorthand', () => {
	const selectClasses = selectAppliedClasses()
	const offenders = []
	for (const file of cssFiles(cssRoot)) {
		const css = stripComments(readFileSync(file, 'utf8'))
		for (const { selector, body } of rules(css)) {
			if (/\bselect\b/.test(selector)) {
				continue // covered by the literal-select leg
			}
			const hit = [...selectClasses].find((cls) =>
				new RegExp(`(?<![\\w-])\\.${cls.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`).test(selector))
			if (hit && /(^|;)\s*background\s*:/.test(body)) {
				offenders.push(`${relative(appRoot, file)}: ${selector}  [class .${hit} applied to <select>]`)
			}
		}
	}
	assert.deepEqual(offenders, [],
		`class rules matching <select> reset the chevron where no #app-content rule restores it (use background-color:):\n${offenders.join('\n')}`)
})

test('mn-input enforcer paints only background-color', () => {
	const css = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	const enforcer = [...rules(css)].find((r) => r.selector.includes('select.mn-input') && /min-height/.test(r.body))
	assert.ok(enforcer, 'mn-input enforcer rule missing')
	assert.match(enforcer.body, /background-color\s*:/)
})
