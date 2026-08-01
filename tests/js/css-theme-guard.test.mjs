import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Theme-compliance guard (design system §0.1, §7, §12).
 *
 * 1. No raw colours in feature CSS — every colour must come from a Nextcloud
 *    --color-* variable or an --mn-* token. Raw values are only allowed as
 *    var() fallbacks, inside url() data URIs, or on the explicit allowlist
 *    (print/QR surfaces that must stay scanner-white in every theme).
 * 2. Every var(--x) consumed in CSS must be defined in this app or be a
 *    Nextcloud-core-provided variable. Undefined custom properties silently
 *    resolve to `unset` — that is how this app once lost modal z-indexes and
 *    focus outlines (fixed via css/common/legacy-bridge.css).
 */

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const cssRoot = join(appRoot, 'css')

/** Custom-property definitions that may carry raw colour values. */
const RAW_COLOR_PROPERTY_ALLOWLIST = new Set([
	'--mn-print-surface', // QR/print stickers stay light for scanners in all themes
	'--mn-signature-ink', // Signature pad stroke must stay opaque on canvas (not theme text)
])

/** Variables Nextcloud server/theming provides at runtime. */
const NC_PROVIDED_PREFIXES = [
	'--color-',
	'--border-radius',
	'--header-height',
	'--icon-',
	'--default-',
	'--body-container-',
]

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

function stripUrls(css) {
	return css.replace(/url\((?:[^()]|\([^()]*\))*\)/g, 'url(#stripped)')
}

/** Remove var(...) groups (incl. nested fallbacks) so only non-fallback colours remain. */
function stripVarGroups(css) {
	let previous
	do {
		previous = css
		css = css.replace(/var\(\s*--[\w-]+\s*(?:,[^()]*(?:\([^()]*\)[^()]*)*)?\)/g, '')
	} while (css !== previous)
	return css
}

const files = cssFiles(cssRoot)

test('CSS uses no raw colours outside var() fallbacks and the allowlist', () => {
	const offenders = []
	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const [index, rawLine] of source.split('\n').entries()) {
			const property = rawLine.match(/^\s*(--[\w-]+)\s*:/)?.[1]
			if (property && RAW_COLOR_PROPERTY_ALLOWLIST.has(property)) {
				continue
			}
			const line = stripVarGroups(stripUrls(rawLine))
			if (/#[0-9a-fA-F]{3,8}\b|(?<![\w-])(?:rgb|rgba|hsl|hsla)\(/.test(line)) {
				offenders.push(`${relative(appRoot, file)}:${index + 1}: ${rawLine.trim()}`)
			}
		}
	}
	assert.deepEqual(offenders, [], `Raw colours found (map to NC --color-* / --mn-* tokens):\n${offenders.join('\n')}`)
})

test('every consumed CSS variable is defined in-app or provided by Nextcloud', () => {
	const defined = new Set()
	const consumed = new Map()

	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const match of source.matchAll(/(--[\w-]+)\s*:/g)) {
			defined.add(match[1])
		}
		for (const match of source.matchAll(/var\(\s*(--[\w-]+)/g)) {
			if (!consumed.has(match[1])) {
				consumed.set(match[1], relative(appRoot, file))
			}
		}
	}

	const undefinedVars = [...consumed.entries()]
		.filter(([name]) => !defined.has(name)
			&& !NC_PROVIDED_PREFIXES.some((prefix) => name.startsWith(prefix)))
		.map(([name, firstUse]) => `${name} (first used in ${firstUse})`)

	assert.deepEqual(undefinedVars, [],
		`Undefined CSS variables (add to tokens.css or legacy-bridge.css):\n${undefinedVars.join('\n')}`)
})

test('JS writes no hardcoded colours except the signature-pad ink', () => {
	const jsRoot = join(appRoot, 'js')
	const offenders = []
	for (const entry of readdirSync(jsRoot, { recursive: true })) {
		if (!String(entry).endsWith('.js')) {
			continue
		}
		const file = join(jsRoot, String(entry))
		const source = readFileSync(file, 'utf8')
		for (const [index, line] of source.split('\n').entries()) {
			if (!/style\.(color|background|backgroundColor|borderColor|fill|stroke)\s*=|strokeStyle|fillStyle/.test(line)) {
				continue
			}
			if (!/#[0-9a-fA-F]{3,8}\b|(?:rgb|rgba|hsl|hsla)\(/.test(line)) {
				continue
			}
			// Signature pad draws dark ink on a forced-light pad (--mn-signature-surface)
			if (/strokeStyle\s*=\s*'#111'/.test(line)) {
				continue
			}
			offenders.push(`${relative(appRoot, file)}:${index + 1}: ${line.trim()}`)
		}
	}
	assert.deepEqual(offenders, [], `Hardcoded colours in JS:\n${offenders.join('\n')}`)
})
