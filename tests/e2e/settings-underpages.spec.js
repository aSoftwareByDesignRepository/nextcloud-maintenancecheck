/**
 * E2E: Settings underpages — no hub; /settings redirects to Access.
 *
 * @param {import('@playwright/test').Page} page
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'

async function axeMain(page) {
	const results = await new AxeBuilder({ page })
		.include('#mn-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

test.describe('Settings underpages', () => {
	test('/settings redirects to access; policies underpage is full-width', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/settings')
		await expect(page).toHaveURL(/\/settings\/access\/?$/, { timeout: 30_000 })
		await expect(page.locator('#mn-settings-access, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
		if (await page.locator('.mn-empty').count()) {
			test.skip(true, 'Signed-in user is not an app admin')
		}
		await expect(page.locator('#mn-settings-subnav')).toBeVisible()
		await expect(page.getByRole('link', { name: /^overview$/i })).toHaveCount(0)
		await expect(page.locator('#mn-admin-subnav')).toHaveCount(0)

		await page.getByRole('link', { name: /work policies|arbeitsrichtlinien/i }).first().click()
		await expect(page).toHaveURL(/\/settings\/policies/)
		await expect(page.locator('#mn-settings-policies')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-settings-access')).toHaveCount(0)
		await expect(page.locator('#mn-policy-fail-blocks-roll')).toBeVisible({ timeout: 15_000 })

		const widthOk = await page.evaluate(() => {
			const root = document.querySelector('.mn-settings')
			if (!root) return false
			const main = document.querySelector('#mn-main-content')
			if (!main) return false
			const rw = root.getBoundingClientRect().width
			const mw = main.getBoundingClientRect().width
			// Full-width: settings stack uses nearly the main content width (allow small padding slack).
			return rw >= mw * 0.92
		})
		expect(widthOk, 'settings underpage must use full main width').toBe(true)
		await axeMain(page)
	})

	test('access underpage mounts picker host only', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/settings/access')
		await expect(page.locator('#mn-settings-access, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
		if (await page.locator('.mn-empty').count()) {
			test.skip(true, 'Signed-in user is not an app admin')
		}
		await expect(page.locator('#mn-settings-access')).toBeVisible()
		await expect(page.locator('#mn-settings-license')).toHaveCount(0)
		await expect(page.locator('.mn-settings-subnav__link.is-active, .mn-settings-subnav__link[aria-current="page"]').first()).toBeVisible()
	})

	test('invalid section redirects to access', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/settings/not-a-real-section')
		await expect(page).toHaveURL(/\/apps\/maintenancecheck\/settings\/access\/?$/)
		await expect(page.locator('#mn-settings-access, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
	})
})
