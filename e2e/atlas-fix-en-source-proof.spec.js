// @ts-check
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from '../tests/e2e/helpers/auth.js'

/**
 * Surgical re-capture for fix-mn-vis-en-source-german-vocabulary.
 *
 * Proves the two renamed en msgids render English on the live instance and
 * refreshes the stale craft PNGs that still showed '…Prüfung.':
 *   craft/web/mn-equipment-detail-populated-{mobile,tablet,desktop}.png
 *   proofs/en-source-equipment-hint-dialog.png
 *
 * Run (from the app dir):
 *   npx playwright test e2e/atlas-fix-en-source-proof.spec.js --project=chromium-1280
 */

const __dirname = dirname(fileURLToPath(import.meta.url))
const FARM = resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/maintenancecheck')
const OUT = join(FARM, 'craft/web')
const PROOFS = join(FARM, 'proofs')
const APP = '/index.php/apps/maintenancecheck'
const API = `${APP}/api`
const MARKER = `mx-en-${Date.now().toString(36)}-${process.pid.toString(36)}`

const VPS = [
	{ name: 'mobile', width: 320, height: 800 },
	{ name: 'tablet', width: 768, height: 800 },
	{ name: 'desktop', width: 1280, height: 800 },
]

let customerId = 0
let equipmentId = 0

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

test.describe('en-source vocabulary fix proof', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(10 * 60_000)

	test.beforeEach(async ({}, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'capture runs once (chromium-1280)')
	})

	test.afterAll(async ({ browser }, testInfo) => {
		if (testInfo.project.name !== 'chromium-1280') {
			return
		}
		if (!customerId) {
			return
		}
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		const creds = primaryCreds()
		if (creds) {
			await login(page, creds)
			await page.goto(APP, { waitUntil: 'domcontentloaded' }).catch(() => {})
			await api(page, 'DELETE', `${API}/customers/${customerId}?force=1`).catch(() => {})
		}
		await ctx.close()
	})

	test('seed equipment without obligations', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds (tests/e2e/.env)')
		await login(page, creds)
		await page.goto(APP, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const types = await api(page, 'GET', `${API}/equip-types?limit=1&offset=0`)
		expect(types.status, JSON.stringify(types.data)).toBe(200)
		const equipTypeId = types.data.data[0].id

		const customer = await api(page, 'POST', `${API}/customers`, { name: `MX EN ${MARKER}` })
		expect([200, 201].includes(customer.status), JSON.stringify(customer.data)).toBeTruthy()
		customerId = customer.data.id

		const equipment = await api(page, 'POST', `${API}/equipment`, {
			label: `MX EN Pump ${MARKER}`,
			customerId,
			equipTypeId,
		})
		expect([200, 201].includes(equipment.status), JSON.stringify(equipment.data)).toBeTruthy()
		equipmentId = equipment.data.id
	})

	test('equipment-detail renders en inspection copy + recapture craft', async ({ page }) => {
		test.skip(!equipmentId, 'fixture seed failed')
		const creds = primaryCreds()
		await login(page, creds)
		mkdirSync(OUT, { recursive: true })

		await page.goto(`${APP}/equipment/${equipmentId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#mn-equipment-detail')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#app-content [aria-busy="true"]')).toHaveCount(0, { timeout: 30_000 })

		// The renamed msgid renders as plain English — and the German source
		// vocabulary is gone from the surface.
		await expect(
			page.locator('text=Add an obligation from an equipment class to schedule the next inspection.'),
		).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('text=/Prüfung|Prüfpflicht/')).toHaveCount(0)

		for (const vp of VPS) {
			await page.setViewportSize({ width: vp.width, height: vp.height })
			await page.waitForTimeout(350)
			const contentH = await page.evaluate(() =>
				(document.querySelector('#app-content') || document.documentElement).scrollHeight)
			const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192)
			if (shotH !== vp.height) {
				await page.setViewportSize({ width: vp.width, height: shotH })
				await page.waitForTimeout(150)
			}
			await page.screenshot({ path: join(OUT, `mn-equipment-detail-populated-${vp.name}.png`) })
		}
	})

	test('equipment form hint renders en inspection-obligations copy', async ({ page }) => {
		test.skip(!equipmentId, 'fixture seed failed')
		const creds = primaryCreds()
		await login(page, creds)
		mkdirSync(PROOFS, { recursive: true })

		await page.goto(`${APP}/equipment`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#mn-equipment-list, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
		await page.locator('button[data-mn-action="new-equipment"]').first().click()
		const dialog = page.locator('.mn-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		await expect(
			dialog.locator('text=Optional class code for inspection obligations (e.g. ladder, fire_extinguisher).'),
		).toBeVisible({ timeout: 15_000 })
		await expect(dialog.locator('text=/Prüfung|Prüfpflicht/')).toHaveCount(0)

		await page.setViewportSize({ width: 1280, height: 900 })
		await page.waitForTimeout(250)
		await page.screenshot({ path: join(PROOFS, 'en-source-equipment-hint-dialog.png') })
		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()

		writeFileSync(
			join(PROOFS, 'en-source-vocabulary-render-proof.json'),
			JSON.stringify(
				{
					fix: 'mn-vis-en-source-german-vocabulary',
					seeded_marker: MARKER,
					customerId,
					equipmentId,
					rendered: [
						'Add an obligation from an equipment class to schedule the next inspection.',
						'Optional class code for inspection obligations (e.g. ladder, fire_extinguisher).',
					],
					absent: ['Prüfung', 'Prüfpflichten'],
					recaptured: VPS.map((v) => `craft/web/mn-equipment-detail-populated-${v.name}.png`),
					extra_shot: 'proofs/en-source-equipment-hint-dialog.png',
				},
				null,
				2,
			),
		)
	})
})
