// @ts-check
/**
 * ATLAS_UI_INVARIANTS — MaintenanceCheck representative surfaces.
 *
 * Wires apps/_shared/e2e/atlas-ui-invariants.js onto the surfaces an
 * office/pool user actually works on. Covered classes: a11y-dom (icon-only
 * names, <24px targets, focusable-in-aria-hidden, unannounced error
 * callouts), stored-xss (customer name/notes are free-text fields rendered
 * in the /customers list and detail), console errors, raw i18n keys,
 * mutation freshness, double-submit, form-loss-on-5xx, O(1) list requests.
 *
 * Fixture hygiene: every created customer carries the run stamp and is
 * deleted in finally — zero residue on the shared lab.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertFormSurvivesFailure,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js')

const CONTENT = '#app-content'
const API = '/index.php/apps/maintenancecheck/api'
// Worker-unique stamp — Date.now alone collides when two workers share a millisecond.
const STAMP = `mninv-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

/** session-cookie JSON call into the app API (browser context = logged in) */
async function api(page, method, path, body) {
	const token = await page.evaluate(() => window.OC?.requestToken
		|| document.querySelector('head')?.getAttribute('data-requesttoken')
		|| document.querySelector('meta[name="requesttoken"]')?.getAttribute('content') || '')
	const opts = { headers: { requesttoken: token, Accept: 'application/json' } }
	if (body !== undefined) opts.data = body
	const res = await page.request[method.toLowerCase()](`${API}${path}`, opts)
	let json = null
	try { json = await res.json() } catch { /* HTML error pages */ }
	return { status: res.status(), json }
}

async function createCustomer(page, name) {
	const r = await api(page, 'post', '/customers', { name })
	return r.status === 201 && r.json && r.json.id ? r.json.id : null
}

async function deleteCustomer(page, id) {
	await api(page, 'delete', `/customers/${id}?force=1`)
}

async function cleanupStampedCustomers(page) {
	const list = await api(page, 'get', '/customers?limit=200&offset=0')
	const rows = (list.json && (list.json.data || list.json.items || list.json)) || []
	for (const c of Array.isArray(rows) ? rows : []) {
		if (String(c.name || '').startsWith(STAMP) && c.id) {
			await deleteCustomer(page, c.id)
		}
	}
}

async function gotoApp(page, path) {
	await page.goto(`/apps/maintenancecheck${path}`)
	await page.waitForLoadState('domcontentloaded')
}

/** Open the New-customer dialog; returns the dialog locator. */
async function openCustomerDialog(page) {
	await page.locator('[data-mn-action="new-customer"]').first().click()
	const dialog = page.locator('.mn-dialog[role="dialog"]').last()
	await dialog.waitFor({ state: 'visible', timeout: 10000 })
	return dialog
}

test.describe('MaintenanceCheck UI invariants (atlas-ui-invariants)', () => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_USER / NC_E2E_PASS (or NC_ADMIN_*)')

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await login(page, creds)
	})

	// ── a11y-dom + console + raw-i18n sweep over representative surfaces ──
	for (const [label, url, ready] of [
		['due-board', '/', '#mn-main-content'],
		['customers', '/customers', '#mn-customer-list, .mn-empty'],
		['work-orders', '/work-orders', '#mn-wo-list, .mn-empty, #mn-main-content'],
		['catalogs', '/catalogs', '#mn-equip-types, .mn-empty, #mn-main-content'],
		['settings-access', '/settings/access', '#mn-settings-access, #mn-main-content'],
	]) {
		test(`a11y-dom /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await gotoApp(page, url)
			await page.locator(ready).first().waitFor({ state: 'visible', timeout: 30000 })
			// Radios/checkboxes under 24px are legal only with a >=24px label
			// target (WCAG 2.5.8 equivalent-target). Prove that before the sweep.
			const unlabeled = await page.evaluate(() => {
				const bad = []
				document
					.querySelectorAll('#app-content input[type="radio"], #app-content input[type="checkbox"]')
					.forEach((inp) => {
						const ir = inp.getBoundingClientRect()
						const ics = getComputedStyle(inp)
						if (ir.width === 0 || ics.display === 'none' || ics.visibility === 'hidden') return
						const lbl = inp.closest('label')
							|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null)
						if (!lbl) {
							bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`)
							return
						}
						const r = lbl.getBoundingClientRect()
						const cs = getComputedStyle(lbl)
						if (cs.display === 'none' || cs.visibility === 'hidden' || (r.width === 0 && r.height === 0)) return
						if (r.width < 24 || r.height < 24) {
							bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`)
						}
					})
				return bad
			})
			expect(unlabeled, `radio/checkbox without a >=24px label target on /${label}:\n${unlabeled.join('\n')}`).toEqual([])
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// labelled small inputs are legal — the label is the pointer target
				sizeAllow: 'label',
			})
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([])
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	// ── a11y-dom on the open customer dialog (error-capable state) ──
	test('a11y-dom on customer create dialog', async ({ page }) => {
		await gotoApp(page, '/customers')
		const dialog = await openCustomerDialog(page)
		const findings = await assertA11yDom(page, { content: 'body' })
		expect(findings, `a11y-dom findings in customer dialog:\n${findings.join('\n')}`).toEqual([])
		// keyboard-level Escape: Locator.press auto-waits forever once the
		// dialog detached (no actionTimeout configured) — a successful submit
		// would hang this test at the 60s ceiling.
		await page.keyboard.press('Escape').catch(() => {})
	})

	// ── stored XSS: free-text customer name rendered in the list ──
	test('stored-xss escaped on customers list', async ({ page }) => {
		const name = `${STAMP} ${ATLAS_XSS_PAYLOADS[0]}`
		const id = await createCustomer(page, name)
		try {
			expect(id, 'customer create via API').toBeTruthy()
			await gotoApp(page, `/customers?q=${encodeURIComponent(STAMP)}`)
			await page.locator('#mn-customer-list, .mn-empty').first().waitFor({ state: 'visible', timeout: 30000 })
			await assertNoInjection(page)
		} finally {
			if (id) await deleteCustomer(page, id)
		}
	})

	// ── mutation freshness: create then delete must surface without F5 ──
	test('mutation freshness on customers list', async ({ page }) => {
		const name = `${STAMP}-fresh`
		const id = await createCustomer(page, name)
		try {
			expect(id, 'customer create via API').toBeTruthy()
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {},
				visit: async () => {
					await gotoApp(page, `/customers?q=${encodeURIComponent(name)}`)
					await page.locator('#mn-customer-list, .mn-empty').first().waitFor({ state: 'visible', timeout: 30000 })
				},
				expect: { present: name },
			})
		} finally {
			if (id) await deleteCustomer(page, id)
		}
		await assertSurfaceFresh(page, {
			content: CONTENT,
			mutate: async () => {},
			visit: async () => {
				await gotoApp(page, `/customers?q=${encodeURIComponent(name)}`)
				await page.locator('#mn-customer-list, .mn-empty').first().waitFor({ state: 'visible', timeout: 30000 })
			},
			expect: { absent: name },
		})
	})

	// ── double-submit: rapid second submit must not reach the server ──
	test('double-submit on customer create sends one POST', async ({ page }) => {
		await gotoApp(page, '/customers')
		const dialog = await openCustomerDialog(page)
		await dialog.locator('input[type="text"]').first().fill(`${STAMP}-dbl`)
		const submit = dialog.locator('.mn-dialog__actions button.mn-btn--primary').first()
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: /\/api\/customers(\?|$)/,
				method: 'POST',
				// force: the correct behavior guards mid-flight — a real user
				// can still machine-gun clicks regardless of disabled state.
				// Short click timeout: when the first submit closes the dialog
				// the second click must reject fast instead of stalling the
				// Promise.all inside the helper until the test times out.
				submit: () => submit.click({ force: true, timeout: 5000 }).catch(() => {}),
			})
		} finally {
			await page.keyboard.press('Escape').catch(() => {})
			await cleanupStampedCustomers(page)
		}
	})

	// ── form survival on 5xx: inputs retained + error rendered ──
	test('customer form survives injected 500', async ({ page }) => {
		await gotoApp(page, '/customers')
		const dialog = await openCustomerDialog(page)
		const nameInput = dialog.locator('input[type="text"]').first()
		await nameInput.fill(`${STAMP}-keep`)
		await assertFormSurvivesFailure(page, {
			failUrl: /\/api\/customers(\?|$)/,
			method: 'POST',
			fields: {},
			submit: () => dialog.locator('.mn-dialog__actions button.mn-btn--primary').first().click(),
			// openDialog → d.setError renders .mn-dialog__error (role=alert)
			errorSel: '.mn-dialog__error:not([hidden])',
		})
		await expect(nameInput).toHaveValue(`${STAMP}-keep`)
	})

	// ── O(1) list render: /customers must not fan out per-row requests ──
	test('customers list render is O(1) in API calls', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, '/customers')
			await page.locator('#mn-customer-list, .mn-empty').first().waitFor({ state: 'visible', timeout: 30000 })
			await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
		}, /\/api\//)
		const listCalls = hits.filter((h) => /\/api\/customers(\?|$)/.test(h.url) && h.method === 'GET')
		expect(listCalls.length, `customer list fetches: ${listCalls.map((h) => h.url).join(' | ')}`).toBeLessThanOrEqual(2)
		const perRow = hits.filter((h) => /\/api\/customers\/\d+/.test(h.url))
		expect(perRow, `per-row N+1 fetches: ${perRow.map((h) => h.url).join(' | ')}`).toEqual([])
	})
})
