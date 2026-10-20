// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/maintenancecheck/craft/web/. The companion
 * gate .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — real fixture chain seeded via the app API in beforeAll
 *               (customer → equipment → maintenance plan → open visit →
 *               work order), so list and detail cells carry honest rows.
 *   empty     — honest zero-row render driven through each surface's own
 *               filter UI: the search inputs for customers/equipment/
 *               work-orders and a far-past custom date range for visits.
 *               The app's real empty state — never a mocked response.
 *   current   — state-invariant chrome (settings underpages).
 *
 * Run (from the app dir):
 *   npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
import { test, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, primaryCreds } from '../tests/e2e/helpers/auth.js'
import {
	acquireThemeLock,
	releaseThemeLock,
	resetUserTheme,
	setUserTheme,
} from '../tests/e2e/helpers/theming.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const MANIFEST = JSON.parse(readFileSync(join(__dirname, 'screenshot-matrix.json'), 'utf8'))
const OUT = resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/maintenancecheck', MANIFEST.outDir)
const APP = '/apps/maintenancecheck'
const API = '/index.php/apps/maintenancecheck/api'
// Unique per worker AND per run — parallel lanes share this instance.
const MARKER = `mx-${Date.now().toString(36)}-${process.pid.toString(36)}`

/** Container that must render before a cell is captured. */
const READY = {
	'due': '#mn-due-board',
	'visits': '#mn-visit-list',
	'work-orders': '#mn-wo-list',
	'work-order-detail': '#mn-wo-detail',
	'customers': '#mn-customer-list',
	'customer-detail': '#mn-customer-detail',
	'equipment': '#mn-equipment-list',
	'equipment-detail': '#mn-equipment-detail',
	'catalogs': '#mn-catalogs-toolbar',
	'dispatch': '#mn-dispatch-board',
	'tours': '#mn-tours-board',
	'kpi': '#mn-kpi-snapshot',
	'exceptions': '#mn-exceptions-board',
	'settings-access': '#mn-settings-subnav, .mn-empty',
	'settings-policies': '#mn-settings-subnav, .mn-empty',
	'settings-license': '#mn-settings-subnav, .mn-empty',
	'settings-support': '#mn-settings-subnav, .mn-empty',
}

let fixtures = { customerId: 0, equipmentId: 0, woId: 0, visitId: 0 }
const capturedFiles = []

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| ''
			const res = await fetch(path, {
				method,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let data = null
			try {
				data = text ? JSON.parse(text) : null
			} catch {
				data = { raw: text }
			}
			return { status: res.status, data }
		},
		{ method, path, body },
	)
}

function expectOk(result, label) {
	expect([200, 201, 204].includes(result.status), `${label}: ${JSON.stringify(result.data)}`).toBeTruthy()
}

/** Seed customer → equipment → plan → visit → work order via the real API. */
async function seedFixtures(page) {
	const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
	expect(serverToday, 'no data-mn-server-today on #app-content').toBeTruthy()

	const types = await api(page, 'GET', `${API}/equip-types?limit=1&offset=0`)
	expectOk(types, 'equip-types')
	const equipTypeId = types.data.data[0].id
	const maint = await api(page, 'GET', `${API}/maint-types?limit=50&offset=0`)
	expectOk(maint, 'maint-types')
	const maintTypeId = maint.data.data[0].id

	const customer = await api(page, 'POST', `${API}/customers`, { name: `MX Matrix ${MARKER}` })
	expectOk(customer, 'customer')
	fixtures.customerId = customer.data.id

	const equipment = await api(page, 'POST', `${API}/equipment`, {
		label: `MX Matrix Pump ${MARKER}`,
		customerId: fixtures.customerId,
		equipTypeId,
	})
	expectOk(equipment, 'equipment')
	fixtures.equipmentId = equipment.data.id

	const plan = await api(page, 'POST', `${API}/equipment/${fixtures.equipmentId}/plans`, {
		maintTypeId,
		intervalUnit: 'year',
		intervalCount: 1,
		firstDueOn: serverToday,
	})
	expectOk(plan, 'plan')
	fixtures.visitId = plan.data.openVisit.id

	const wo = await api(page, 'POST', `${API}/visits/${fixtures.visitId}/work-orders`, {
		procedureSkipped: true,
		procedureSkipReason: 'Atlas screenshot matrix',
	})
	expectOk(wo, 'work-order')
	fixtures.woId = wo.data.id
	await api(page, 'POST', `${API}/work-orders/${fixtures.woId}/transition`, { to: 'ready' })
	await api(page, 'POST', `${API}/work-orders/${fixtures.woId}/transition`, { to: 'in_progress' })
}

async function cleanupFixtures(page) {
	if (!fixtures.customerId) {
		return
	}
	await page.goto(APP, { waitUntil: 'domcontentloaded' }).catch(() => {})
	await api(page, 'DELETE', `${API}/customers/${fixtures.customerId}?force=1`).catch(() => {})
}

/**
 * Drive a surface into its honest empty state through the real filter UI.
 * @param {import('@playwright/test').Page} page
 */
async function driveEmpty(page, surface) {
	const listSelector = READY[surface.id]
	if (surface.emptyDriver === 'searchNoMatch') {
		const input = page.locator(surface.searchSelector)
		await expect(input).toBeVisible({ timeout: 15_000 })
		await input.fill(`zz-nomatch-${MARKER}`)
	} else if (surface.emptyDriver === 'visitsCustomRange') {
		await page.locator('#mn-filter-when-chips [data-mn-when="custom"]').click()
		await page.locator('#mn-filter-from').fill('1999-01-01')
		await page.locator('#mn-filter-to').fill('1999-01-02')
	} else {
		throw new Error(`surface ${surface.id}: unknown emptyDriver ${surface.emptyDriver}`)
	}
	// The zero-row render must be the app's own empty state, not a stale list.
	await expect(page.locator(`${listSelector.split(',')[0]} .mn-empty`).first())
		.toBeVisible({ timeout: 15_000 })
}

function surfacePath(surface) {
	return surface.path
		.replace('{woId}', String(fixtures.woId))
		.replace('{customerId}', String(fixtures.customerId))
		.replace('{equipmentId}', String(fixtures.equipmentId))
}

function* matrixCells(theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) {
					continue
				}
				yield { surface, state, vp }
			}
		}
	}
}

function shotName(surface, state, vp, theme) {
	return `${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(30 * 60_000)

	test.beforeEach(async ({}, testInfo) => {
		// Cells drive their own viewports — one project run captures all widths.
		test.skip(testInfo.project.name !== 'chromium-1280', 'matrix capture runs once (chromium-1280)')
	})

	test.beforeAll(async ({}, testInfo) => {
		if (testInfo.project.name !== 'chromium-1280') {
			return
		}
		// Theme state is per-user server state on the shared dev instance —
		// hold the cross-worker mutex for the whole capture.
		await acquireThemeLock()
	})

	test.afterAll(async ({ browser }, testInfo) => {
		if (testInfo.project.name !== 'chromium-1280') {
			return
		}
		try {
			const ctx = await browser.newContext()
			const page = await ctx.newPage()
			const creds = primaryCreds()
			if (creds) {
				await login(page, creds)
				await resetUserTheme(page).catch(() => {})
				await cleanupFixtures(page)
			}
			await ctx.close()
		} finally {
			releaseThemeLock()
			if (capturedFiles.length > 0) {
				mkdirSync(OUT, { recursive: true })
				writeFileSync(
					resolve(OUT, '_manifest-screenshot-matrix.json'),
					JSON.stringify(
						{
							app: 'maintenancecheck',
							lane: 'ds_chrome',
							generated_by: 'e2e/capture-screenshot-matrix.spec.js',
							files: capturedFiles,
						},
						null,
						2,
					),
				)
			}
		}
	})

	test('seeds fixtures', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_ADMIN_* or NC_E2E_* creds (tests/e2e/.env)')
		await login(page, creds)
		await page.goto(APP, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
		await seedFixtures(page)
		expect(fixtures.woId).toBeGreaterThan(0)
	})

	test('captures every manifest cell', async ({ page }) => {
		test.skip(!fixtures.woId, 'fixture seed failed')
		const creds = primaryCreds()
		await login(page, creds)
		mkdirSync(OUT, { recursive: true })
		const missing = []

		for (const theme of ['light', 'dark']) {
			if (theme === 'dark') {
				await page.goto(APP, { waitUntil: 'domcontentloaded' })
				await setUserTheme(page, 'dark')
			}
			for (const { surface, state, vp } of matrixCells(theme)) {
				await page.setViewportSize({ width: vp.width, height: vp.height })
				await page.goto(`${APP}${surfacePath(surface)}`, { waitUntil: 'domcontentloaded' })
				const ready = page.locator(READY[surface.id]).first()
				await expect(ready).toBeVisible({ timeout: 30_000 })
				// Async listings signal completion via aria-busy — never capture mid-fetch.
				await expect(page.locator('#app-content [aria-busy="true"]'))
					.toHaveCount(0, { timeout: 30_000 })
				if (state === 'empty') {
					await driveEmpty(page, surface)
				}
				await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
				await page.waitForTimeout(350)
				// #app-content scrolls internally — grow the viewport to the full
				// content height so the shot is not fold-clipped.
				const contentH = await page.evaluate(() =>
					(document.querySelector('#app-content') || document.documentElement).scrollHeight)
				const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192)
				if (shotH !== vp.height) {
					await page.setViewportSize({ width: vp.width, height: shotH })
					await page.waitForTimeout(150)
				}
				const file = join(OUT, shotName(surface, state, vp, theme))
				await page.screenshot({ path: file })
				capturedFiles.push(shotName(surface, state, vp, theme))
				if (!existsSync(file)) {
					missing.push(file)
				}
			}
			if (theme === 'dark') {
				await page.goto(APP, { waitUntil: 'domcontentloaded' })
				await resetUserTheme(page)
			}
		}

		expect(missing, `matrix cells missing: ${missing.join(', ')}`).toEqual([])
	})
})
