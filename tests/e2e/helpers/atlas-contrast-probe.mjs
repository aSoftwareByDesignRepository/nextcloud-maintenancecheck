// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (maintenancecheck).
 *
 * Logs in, walks representative pages, and measures the COMPUTED WCAG 2.1
 * contrast of semantic chrome: error inks, status badges, danger buttons,
 * invalid-field borders, and control borders — across light / dark /
 * light-highcontrast user themes.
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   node tests/e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Requires tests/e2e/.env creds (NC_E2E_USER/PASS) and http://localhost:8081.
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'
import { login, primaryCreds } from './auth.js'
import { resetUserTheme, setUserTheme } from './theming.js'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../../..')
const ENV_PATH = resolve(APP_ROOT, 'tests/e2e/.env')
if (existsSync(ENV_PATH)) {
	for (const line of readFileSync(ENV_PATH, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  // Chrome serialises color-mix() as color(srgb r g b / a) — floats 0..1.
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  // Walk ancestors for the first non-transparent background.
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  // color(srgb r g b / a) — fourth channel is the alpha.
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__mnProbe = { effBg, ratio, alphaOf, borderRatio }
`

/** Elements to measure per page: [page_url, [probe rows]] */
const PROBES = [
	{
		page: '/apps/maintenancecheck/',
		label: 'due-board',
		rows: [
			{ sel: '.mn-badge--overdue, .mn-badge--error, .mn-badge', kind: 'badge', what: 'text' },
			{ sel: 'button.mn-btn--primary, a.mn-btn--primary, .mn-btn--primary', kind: 'primary-cta', what: 'text' },
			{ sel: '.mn-filter-panel select, .mn-filter-panel .mn-select, .mn-filter-panel input', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: '/apps/maintenancecheck/visits',
		label: 'visits',
		rows: [
			{ sel: '.mn-badge, .mn-pill, .mn-status', kind: 'badge', what: 'text' },
			{ sel: '.mn-filter-panel select, .mn-filter-panel .mn-select, .mn-filter-panel input, #mn-visit-search', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: '/apps/maintenancecheck/customers',
		label: 'customers',
		rows: [
			{ sel: '#mn-customer-search, #mn-customer-filters select, .mn-filter-panel input, .mn-pagination button', kind: 'control-border', what: 'border' },
		],
	},
]

/** Resolve a customer with equipment (delete dialog renders its gate). */
async function resolveCustomerDetailPage(page) {
	return page.evaluate(async () => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, Accept: 'application/json' }
		const list = await fetch('/apps/maintenancecheck/api/customers', { headers, credentials: 'same-origin' })
		const env = list.ok ? await list.json() : {}
		for (const c of env.data || env.customers || env.items || []) {
			const res = await fetch(`/apps/maintenancecheck/api/customers/${c.id}`, { headers, credentials: 'same-origin' })
			const d = res.ok ? await res.json() : {}
			if ((d.counts || {}).equipment > 0) return `/apps/maintenancecheck/customers/${c.id}`
		}
		return null
	})
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		await page.goto(p.page, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#mn-main-content', { timeout: 30_000 })
		if (p.wait) await page.waitForSelector(p.wait, { timeout: 30_000 })
		await page.waitForTimeout(400)
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__mnProbe.effBg(el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
						}
						if (what !== 'border') {
							item.textRatio = window.__mnProbe.ratio(cs.color, bg)
						}
						if (what !== 'text' && parseFloat(cs.borderWidth) > 0) {
							item.borderRatio = window.__mnProbe.borderRatio(cs.borderColor, bg)
							item.borderAlpha = window.__mnProbe.alphaOf(cs.borderColor)
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			results.push({ page: p.label, kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
	}
	return results
}

async function measureFieldError(page) {
	// Live server-fields error: 422 invalid_email → aria-invalid + inline error.
	await page.goto('/apps/maintenancecheck/customers', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#mn-customer-list, .mn-empty', { timeout: 30_000 })
	await page.locator('button[data-mn-action="new-customer"]').click()
	const dialog = page.locator('.mn-dialog')
	await dialog.waitFor({ state: 'visible' })
	await dialog.locator('input[required]').fill('Atlas Contrast Probe')
	await dialog.locator('input[type="email"]').fill('not-an-email')
	await dialog.locator('button.mn-btn--primary').click()
	const emailInput = dialog.locator('input[type="email"]')
	await emailInput.waitFor({ state: 'visible' })
	await page.waitForFunction(
		(el) => el && el.getAttribute('aria-invalid') === 'true',
		await emailInput.elementHandle(),
		{ timeout: 15_000 },
	)
	return page.evaluate(() => {
		const input = document.querySelector('.mn-dialog input[type="email"]')
		const err = document.querySelector('.mn-dialog .mn-field__error:not([hidden])')
		const borderEl = input
		const out = { ariaInvalid: input && input.getAttribute('aria-invalid') }
		if (err) {
			const cs = getComputedStyle(err)
			out.fieldErrorText = {
				fg: cs.color,
				bg: window.__mnProbe.effBg(err),
				textRatio: window.__mnProbe.ratio(cs.color, window.__mnProbe.effBg(err)),
				text: err.textContent,
			}
		}
		const cs = getComputedStyle(borderEl)
		out.invalidBorder = {
			borderColor: cs.borderColor,
			borderWidth: cs.borderWidth,
			bg: window.__mnProbe.effBg(borderEl),
			borderRatio: window.__mnProbe.borderRatio(cs.borderColor, window.__mnProbe.effBg(borderEl)),
		}
		return out
	})
}

async function main() {
	const creds = primaryCreds()
	if (!creds) throw new Error('NC_E2E_* creds missing (tests/e2e/.env)')
	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'maintenancecheck', probe: 'live-computed-contrast', base: BASE, generated_at: new Date().toISOString(), themes: {} }
	let failures = 0

	try {
		await login(page, creds)
		await context.addInitScript(EVAL_FN)

		for (const theme of ['light', 'dark', 'light-highcontrast']) {
			await setUserTheme(page, theme)
			// Resolve per theme — the shared instance mutates under lanes.
			const detailPage = await resolveCustomerDetailPage(page)
			const probes = detailPage
				? [...PROBES, {
					page: detailPage,
					label: 'customer-detail',
					wait: 'button[data-mn-action="delete-customer"]',
					rows: [
						{ sel: 'button[data-mn-action="delete-customer"], .mn-btn--danger', kind: 'danger-btn', what: 'text+border' },
					],
				}]
				: PROBES
			const themeRes = { pages: await measure(page, probes) }
			if (theme === 'light') {
				themeRes.field_error_state = await measureFieldError(page)
			}
			report.themes[theme] = themeRes
		}
		await resetUserTheme(page)
	} finally {
		await context.close()
		await browser.close()
	}

	// Verdict
	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	for (const [theme, t] of Object.entries(report.themes)) {
		for (const row of t.pages) {
			for (const s of row.samples || []) {
				if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
			}
		}
		const fe = t.field_error_state
		if (fe) {
			if (fe.fieldErrorText && fe.fieldErrorText.textRatio !== null && fe.fieldErrorText.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'dialog', kind: 'field-error-text', ratio: fe.fieldErrorText.textRatio, min: TEXT_MIN })
			}
			if (fe.invalidBorder && fe.invalidBorder.borderRatio !== null && fe.invalidBorder.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'dialog', kind: 'invalid-border', ratio: fe.invalidBorder.borderRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'
	failures = findings.length

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(failures > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
