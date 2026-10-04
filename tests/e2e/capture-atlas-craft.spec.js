// @ts-check
import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
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
		name: 'settings-access',
		path: '/apps/maintenancecheck/settings/access',
		ready: '#mn-settings-access, #mn-main-content',
	},
]

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
})

async function shot(page, name) {
	mkdirSync(OUT, { recursive: true })
	// let reflow/drawer transitions settle
	await page.waitForTimeout(350)
	await page.screenshot({ path: resolve(OUT, name), fullPage: false })
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
})
