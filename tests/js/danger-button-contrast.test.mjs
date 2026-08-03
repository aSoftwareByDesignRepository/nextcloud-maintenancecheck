/**
 * Contract: danger buttons never paint white on NC --color-error tint.
 * Regression of the Cancel-visit illegible pink button (WCAG 1.4.3).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '../..')

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

test('tokens define mn-danger-fill / on-fill / ink from element-error', () => {
	const raw = readFileSync(join(root, 'css/common/tokens.css'), 'utf8')
	const css = stripComments(raw)
	assert.match(css, /--mn-danger-fill:\s*var\(\s*--color-element-error/)
	assert.match(css, /--mn-danger-on-fill:\s*var\(\s*--color-primary-text/)
	assert.match(css, /--mn-danger-ink:\s*var\(\s*--color-error-text/)
	assert.match(raw, /Never put white/)
})

test('shell + app danger buttons use mn-danger-fill, not color-error', () => {
	for (const rel of ['css/common/shell-chrome.css', 'css/app.css']) {
		const css = stripComments(readFileSync(join(root, rel), 'utf8'))
		const blocks = [...css.matchAll(/\.[\w-]*btn--danger[^{]*\{([^}]*)\}/g)]
		assert.ok(blocks.length > 0, `${rel}: missing .mn-btn--danger`)
		for (const [, body] of blocks) {
			assert.match(body, /background:\s*var\(\s*--mn-danger-fill/, `${rel}: fill`)
			assert.match(body, /color:\s*var\(\s*--mn-danger-on-fill/, `${rel}: on-fill text`)
			assert.doesNotMatch(body, /background(?:-color)?:\s*[^;]*var\(\s*--color-error\b(?!-)/, `${rel}: forbids --color-error fill`)
		}
	}
})

test('dialog overlay danger override beats NC button.button pale cascade', () => {
	const css = stripComments(readFileSync(join(root, 'css/common/shell-chrome.css'), 'utf8'))
	assert.match(css, /\.mn-dialog-overlay\s+button\.mn-btn--danger[\s\S]{0,200}--mn-danger-fill/)
	assert.match(css, /\.mn-dialog-overlay\s+button\.mn-btn--danger[\s\S]{0,200}--mn-danger-on-fill/)
})

test('dark themes darken mn-danger-fill for white on-fill AA', () => {
	const raw = readFileSync(join(root, 'css/common/tokens.css'), 'utf8')
	assert.match(raw, /body\[data-theme-dark\][\s\S]{0,80}body\[data-theme-dark-highcontrast\]\s*\{[^}]*--mn-danger-fill:\s*color-mix\([^;]*--color-main-background\)/s)
})

test('cancel-visit confirm still uses mn-btn--danger variant', () => {
	const js = readFileSync(join(root, 'js/app.js'), 'utf8')
	assert.match(js, /tr\('Cancel visit'\)[\s\S]{0,200}variant:\s*'mn-btn--danger'/)
})
