// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import { acquireThemeLock, releaseThemeLock, resetUserTheme, setUserTheme } from './helpers/theming.js'

/**
 * Rendered regression proofs for the r3 visual-critic defects:
 *
 * 1. mn-vis-select-chevron-broken — the .mn-input enforcer painted the
 *    `background:` shorthand and reset the chevron image/position/repeat/size.
 *    Proof: computed background-image is a real url() and repeat stays
 *    no-repeat in light AND dark (dark once tiled a caret grid).
 * 2. mn-vis-due-actions-column-clipped-desktop — table min-width:max-content
 *    pushed the Actions column behind the wrap at 1440. Proof: the due table
 *    does not overflow its wrap (scrollWidth ≈ clientWidth) and the Actions
 *    header is fully inside the card.
 * 3. mn-vis-exceptions-reasons-raw-enum — the Why column joined raw
 *    snake_case codes. Proof: cells render .mn-badge nodes and contain no
 *    snake_case tokens.
 */

const APP = '/apps/maintenancecheck'

test.describe('visual fix regressions (r3)', () => {
	test.describe.configure({ mode: 'serial' })
	test.beforeEach(async ({}, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'rendered proofs run once')
	})
	test.beforeAll(async () => {
		await acquireThemeLock()
	})
	test.afterAll(async ({ browser }) => {
		try {
			const ctx = await browser.newContext()
			const page = await ctx.newPage()
			const creds = primaryCreds()
			if (creds) {
				await login(page, creds)
				await resetUserTheme(page).catch(() => {})
			}
			await ctx.close()
		} finally {
			releaseThemeLock()
		}
	})

	test('select chevron survives the .mn-input enforcer in light and dark', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds')
		await login(page, creds)
		await page.setViewportSize({ width: 1440, height: 900 })

		for (const theme of ['light', 'dark']) {
			await page.goto(APP, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			await page.goto(`${APP}/work-orders`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#app-content select').first()
			await expect(select).toBeVisible({ timeout: 30_000 })
			const probe = await select.evaluate((node) => {
				const cs = getComputedStyle(node)
				return {
					image: cs.backgroundImage,
					repeat: cs.backgroundRepeat,
					position: cs.backgroundPosition,
					size: cs.backgroundSize,
					appearance: cs.appearance,
				}
			})
			expect(probe.appearance, `${theme}: select lost appearance:none`).toBe('none')
			expect(probe.image, `${theme}: chevron background-image missing`).toContain('url(')
			expect(probe.repeat, `${theme}: chevron tiles (dark-grid regression)`).toBe('no-repeat')
			expect(probe.position, `${theme}: chevron unpositioned`).not.toBe('0% 0%')
		}
		await resetUserTheme(page)
	})

	test('due board actions column is fully visible at 1440', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds')
		await login(page, creds)
		await page.setViewportSize({ width: 1440, height: 900 })
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		const wrap = page.locator('#mn-due-board .mn-table-wrap').first()
		test.skip(!(await wrap.count()), 'no due-board table on this fixture')
		await expect(wrap.locator('table')).toBeVisible({ timeout: 30_000 })
		const fit = await wrap.evaluate((w) => ({
			scrollW: w.scrollWidth,
			clientW: w.clientWidth,
		}))
		expect(
			fit.scrollW <= fit.clientW + 1,
			`due table overflows wrap (scrollWidth ${fit.scrollW} > clientWidth ${fit.clientW})`,
		).toBeTruthy()
		// Header cell must sit entirely inside the wrap's visible box.
		const actionsTh = wrap.locator('thead th.mn-table-actions-col').first()
		const thBox = await actionsTh.boundingBox()
		const wrapBox = await wrap.boundingBox()
		expect(thBox && wrapBox).toBeTruthy()
		expect(thBox.x + thBox.width <= wrapBox.x + wrapBox.width + 1).toBeTruthy()
	})

	test('exceptions Why column renders translated badges, never raw codes', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds')
		await login(page, creds)
		await page.goto(`${APP}/exceptions`, { waitUntil: 'domcontentloaded' })
		const board = page.locator('#mn-exceptions-board')
		await expect(board).toBeVisible({ timeout: 30_000 })
		const rows = board.locator('table tbody tr')
		test.skip(!(await rows.count()), 'no exception rows on this fixture')
		const whyCells = board.locator('table tbody td[data-label="Why"]')
		const n = await whyCells.count()
		expect(n).toBeGreaterThan(0)
		for (let i = 0; i < n; i++) {
			const cell = whyCells.nth(i)
			await expect(cell.locator('.mn-badge').first()).toBeVisible()
			const text = (await cell.innerText()).trim()
			expect(text.length).toBeGreaterThan(0)
			expect(
				/\b(?:blocked|overdue|kit_incomplete|skills_missing)\b/.test(text),
				`raw exception code leaked to UI: ${text}`,
			).toBeFalsy()
		}
	})
})
