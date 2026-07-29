// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import { setUserTheme, resetUserTheme } from './helpers/theming.js'

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
					mask: [page.locator('#mn-main-content')],
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
