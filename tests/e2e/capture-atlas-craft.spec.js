// @ts-check
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { credsFromEnv, login, primaryCreds } from './helpers/auth.js'
import {
	acquireThemeLock,
	releaseThemeLock,
	resetUserTheme,
	setUserTheme,
} from './helpers/theming.js'

/**
 * Atlas farm ds_chrome craft capture for MaintenanceCheck web.
 *
 * Writes theme × width screenshots into
 * .cursor/atlas-farm-v3/artifacts/maintenancecheck/craft/web/
 *   themes: light | dark | highcontrast (= NC light-highcontrast)
 *   widths: 320 | 768 | 1440
 *
 * Run (from the app dir):
 *   npx playwright test tests/e2e/capture-atlas-craft.spec.js --project=chromium-1280
 */

const OUT = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/maintenancecheck/craft/web',
)

const THEMES = [
	{ id: 'light', label: 'light' },
	{ id: 'dark', label: 'dark' },
	{ id: 'light-highcontrast', label: 'highcontrast' },
]

const WIDTHS = [
	{ width: 320, height: 640, label: '320' },
	{ width: 768, height: 1024, label: '768' },
	{ width: 1440, height: 900, label: '1440' },
]

const VIEWS = [
	{
		name: 'due',
		path: '/apps/maintenancecheck/',
		ready: '#mn-due-board, .mn-empty, #mn-main-content',
	},
	{
		name: 'visits',
		path: '/apps/maintenancecheck/visits',
		ready: '#mn-visit-list, .mn-empty, #mn-main-content',
	},
	{
		name: 'work-orders',
		path: '/apps/maintenancecheck/work-orders',
		ready: '#mn-wo-list, .mn-empty, #mn-main-content',
	},
	{
		name: 'customers',
		path: '/apps/maintenancecheck/customers',
		ready: '#mn-customer-list, .mn-empty, #mn-main-content',
	},
	{
		name: 'settings-access',
		path: '/apps/maintenancecheck/settings/access',
		ready: '#mn-settings-access, #mn-main-content',
	},
]

/**
 * Resolve live fixture ids through the app API — the dev instance is shared
 * across lanes, so hardcoded customer/WO ids can be mutated mid-run.
 */
async function apiJson(page, url) {
	return page.evaluate(async (u) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const res = await fetch(u, {
			headers: { requesttoken: token, Accept: 'application/json' },
			credentials: 'same-origin',
		})
		return res.ok ? res.json() : null
	}, url)
}

async function resolveFixtures(page) {
	const base = '/apps/maintenancecheck/api'
	const woEnv = (await apiJson(page, `${base}/work-orders`)) || {}
	const wos = woEnv.data || woEnv.work_orders || woEnv.items || []
	const done = wos.find((w) => w.status === 'done')
	const active = wos.find((w) => w.status === 'in_progress')
		|| wos.find((w) => w.status !== 'done' && w.status !== 'cancelled')
	const custEnv = (await apiJson(page, `${base}/customers`)) || {}
	const customers = custEnv.data || custEnv.customers || custEnv.items || []
	let customerId = null
	for (const c of customers) {
		const detail = (await apiJson(page, `${base}/customers/${c.id}`)) || {}
		const counts = detail.counts || {}
		if ((counts.equipment || 0) > 0) {
			customerId = c.id
			break
		}
	}
	return { doneWoId: done && done.id, woId: active && active.id, customerId }
}

/** Files captured this run — written to _manifest.json by afterAll. */
const capturedFiles = []

// Theme state is per-user server state on the shared dev instance — hold the
// cross-worker mutex for the whole capture so no parallel spec flips themes
// mid-shot (see theme-visual.spec.js).
test.setTimeout(30 * 60_000)
test.beforeAll(async ({}, testInfo) => {
	testInfo.setTimeout(30 * 60_000)
	await acquireThemeLock()
})
test.afterAll(async () => {
	releaseThemeLock()
	if (capturedFiles.length > 0) {
		mkdirSync(OUT, { recursive: true })
		writeFileSync(
			resolve(OUT, '_manifest.json'),
			JSON.stringify(
				{
					app: 'maintenancecheck',
					lane: 'ds_chrome',
					policy: '3.5.14',
					generated_by: 'tests/e2e/capture-atlas-craft.spec.js',
					themes: THEMES.map((t) => t.label),
					widths: WIDTHS.map((w) => w.label),
					files: capturedFiles,
				},
				null,
				2,
			),
		)
	}
})

async function shot(page, name) {
	mkdirSync(OUT, { recursive: true })
	// let reflow/drawer transitions settle
	await page.waitForTimeout(350)
	await page.screenshot({ path: resolve(OUT, name), fullPage: false })
	capturedFiles.push(name)
}

test.describe('Atlas ds_chrome craft sweep (web)', () => {
	test.describe.configure({ mode: 'serial' })

	test('capture theme × width matrix on primary views', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds (tests/e2e/.env)')

		await login(page, creds)
		mkdirSync(OUT, { recursive: true })
		const captured = []

		for (const theme of THEMES) {
			// First page load, then pin the user theme via OCS theming API.
			await page.goto('/apps/maintenancecheck/')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
			await setUserTheme(page, theme.id)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

			for (const view of VIEWS) {
				await page.goto(view.path)
				await expect(page.locator(view.ready).first()).toBeVisible({ timeout: 30_000 })
				for (const w of WIDTHS) {
					await page.setViewportSize({ width: w.width, height: w.height })
					const name = `mn-web-${view.name}-${theme.label}-${w.label}.png`
					await shot(page, name)
					captured.push(name)
				}
			}
		}

		// Leave the shared fixture user on system default theme.
		await page.goto('/apps/maintenancecheck/')
		await resetUserTheme(page)

		expect(captured.length).toBe(THEMES.length * VIEWS.length * WIDTHS.length)
	})

	test('capture server field-error + dialog + WO-detail states', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds (tests/e2e/.env)')
		await login(page, creds)
		await page.setViewportSize({ width: 1440, height: 900 })
		const fx = await resolveFixtures(page)
		expect(fx.woId && fx.doneWoId && fx.customerId).toBeTruthy()

		// ── Form dialog (light) — New customer on the customers list. ────
		await page.goto('/apps/maintenancecheck/customers')
		await expect(page.locator('#mn-customer-list, .mn-empty').first()).toBeVisible()
		await page.locator('button[data-mn-action="new-customer"]').click()
		const dialog = page.locator('.mn-dialog')
		await expect(dialog).toBeVisible()
		await shot(page, 'mn-web-dialog-new-customer-light-1440.png')

		// ── Server `fields` → aria-invalid + inline error (light). ───────
		// POST /api/customers with an unparseable email is rejected 422 with
		// details[{field:"email",code:"invalid_email"}]; the client pins the
		// named control (aria-invalid + .mn-field__error, WCAG 3.3.1/3.3.3).
		// (country is unusable here: maxlength=2 truncates to a valid code.)
		await dialog.locator('input[required]').fill('Atlas FieldError Probe')
		await dialog.locator('input[type="email"]').fill('not-an-email')
		await dialog.locator('button.mn-btn--primary').click()
		const emailInput = dialog.locator('input[type="email"]')
		await expect(emailInput).toHaveAttribute('aria-invalid', 'true')
		await expect(dialog.locator('.mn-field__error:not([hidden])')).toHaveCount(1)
		await shot(page, 'mn-web-field-error-new-customer-light-1440.png')
		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()

		// ── Destructive-gate dialog (light) — delete a customer with equipment. ──
		await page.goto(`/apps/maintenancecheck/customers/${fx.customerId}`)
		// The destructive control renders only after the customer fetch resolves.
		const delButton = page.locator('button[data-mn-action="delete-customer"]')
		await expect(delButton).toBeVisible({ timeout: 30_000 })
		await delButton.click()
		const delDialog = page.locator('.mn-dialog')
		await expect(delDialog).toBeVisible()
		// Checkbox-gated confirm: destructive action stays disabled until ticked.
		const confirm = delDialog.locator('#mn-confirm-delete')
		await expect(confirm).toBeDisabled()
		await delDialog.locator('input[type="checkbox"]').check()
		await expect(confirm).toBeEnabled()
		await shot(page, 'mn-web-dialog-delete-customer-light-1440.png')
		await page.keyboard.press('Escape')
		await expect(delDialog).toBeHidden()

		// Done WO (light/default theme): terminal status must show the
		// locked-checklist copy — never "start work" after done
		// (seed wo-done-checklist-copy non-regression).
		await page.goto(`/apps/maintenancecheck/work-orders/${fx.doneWoId}`)
		await expect(page.locator('#mn-main-content')).toBeVisible()
		await expect(page.locator('.mn-wo-checklist__idle')).toBeVisible()
		await shot(page, 'mn-web-work-order-detail-done-light-1440.png')

		// ── Work-order detail across themes (seeded in-progress WO). ─────
		for (const theme of THEMES) {
			await page.goto('/apps/maintenancecheck/')
			await setUserTheme(page, theme.id)
			await page.goto(`/apps/maintenancecheck/work-orders/${fx.woId}`)
			await expect(page.locator('#mn-main-content')).toBeVisible()
			await shot(page, `mn-web-work-order-detail-${theme.label}-1440.png`)
		}
		await page.goto('/apps/maintenancecheck/')
		await resetUserTheme(page)
	})

	test('capture technician-role crafts (office chrome absent)', async ({ page, browser }) => {
		const tech = credsFromEnv('TECH')
		test.skip(!tech, 'Requires NC_TECH_* creds (tests/e2e/.env)')
		// Fresh context — the admin session cookies on `page` must not leak.
		const context = await browser.newContext({
			baseURL: process.env.NC_BASE_URL || 'http://localhost:8081',
		})
		try {
			const techPage = await context.newPage()
			await login(techPage, tech)
			await techPage.setViewportSize({ width: 1440, height: 900 })

			for (const view of VIEWS.slice(0, 3)) {
				await techPage.goto(view.path)
				await expect(techPage.locator(view.ready).first()).toBeVisible({ timeout: 30_000 })
				await shot(techPage, `mn-web-${view.name}-tech-light-1440.png`)
			}

			// Office-only planning page must redirect technicians back to the
			// due board (PageController.officePage guard), never a dead shell.
			await techPage.goto('/apps/maintenancecheck/dispatch')
			expect(new URL(techPage.url()).pathname).not.toContain('dispatch')
			await expect(techPage.locator('#mn-due-board, .mn-empty, #mn-main-content').first()).toBeVisible()
			await shot(techPage, 'mn-web-dispatch-redirect-tech-light-1440.png')
		} finally {
			await context.close()
		}
	})
})
