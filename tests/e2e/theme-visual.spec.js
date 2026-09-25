// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import { setUserTheme, resetUserTheme, acquireThemeLock, releaseThemeLock } from './helpers/theming.js'

// See theme-a11y-matrix.spec.js: NC themes are shared per-user server state;
// hold the cross-worker mutex so no parallel spec can flip the theme between
// setUserTheme() and the pixel assertions.
// File-level timeout covers the lock wait in beforeAll (a peer spec may hold
// the mutex for several minutes); describes keep their own test timeouts.
test.setTimeout(30 * 60_000)
test.beforeAll(async ({}, testInfo) => {
	// Hooks run on the 60s config timeout, NOT test.setTimeout — extend the
	// hook itself before blocking on the cross-worker mutex.
	testInfo.setTimeout(30 * 60_000)
	if (testInfo.project.name === 'chromium-1280') {
		await acquireThemeLock()
	}
})
test.afterAll(async ({}, testInfo) => {
	if (testInfo.project.name === 'chromium-1280') {
		releaseThemeLock()
	}
})

/**
 * Visual regression of the app shell across themes and breakpoints.
 *
 * The main content area is masked: it renders live maintenance data whose
 * dates/counts churn daily and would make pixel baselines flaky. The page
 * chrome — navigation drawer, breadcrumb, page header, scope strip, shell
 * background — is asserted pixel-exact per theme and breakpoint.
 *
 * Baselines live next to this spec (*-snapshots/). Regenerate deliberately
 * with: npx playwright test theme-visual --update-snapshots
 */

const themes = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const breakpoints = [
	{ label: 'mobile-320', width: 320, height: 640 },
	{ label: 'mobile-375', width: 375, height: 812 },
	{ label: 'tablet-768', width: 768, height: 1024 },
	{ label: 'desktop-1024', width: 1024, height: 768 },
	{ label: 'desktop-1280', width: 1280, height: 800 },
]

test.describe('shell visual regression', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	for (const theme of themes) {
		test(`due board shell: ${theme}`, async ({ page }, testInfo) => {
			test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run once')
			const creds = primaryCreds()
			test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

			await login(page, creds)
			await page.goto('/apps/maintenancecheck/')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
			await setUserTheme(page, theme)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

			for (const bp of breakpoints) {
				await page.setViewportSize({ width: bp.width, height: bp.height })
				await page.waitForTimeout(250) // allow reflow/drawer transition to settle
				await expect(page).toHaveScreenshot(`due-shell-${theme}-${bp.label}.png`, {
					fullPage: false,
					animations: 'disabled',
					caret: 'hide',
					// #mn-main-content renders live data; #notifications carries a
					// volatile unread-count badge; #user-menu embeds avatar +
					// presence dot. All three churn outside the asserted chrome.
					mask: [
						page.locator('#mn-main-content'),
						page.locator('#notifications'),
						page.locator('#user-menu'),
					],
					maxDiffPixelRatio: 0.002,
				})
			}
		})
	}

	test('reset theme after visual run', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run once')
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')
		await login(page, creds)
		await page.goto('/apps/maintenancecheck/')
		await resetUserTheme(page)
	})
})
