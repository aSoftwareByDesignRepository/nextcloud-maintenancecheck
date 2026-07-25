// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv, primaryCreds, adminCreds } from './helpers/auth.js'

/**
 * Settings admin sections render only for app admins (NC admin / app_admin list).
 * Due + customers are reachable for any L2-allowed user.
 */
const a11yRoutes = [
	{ path: '/apps/maintenancecheck/', ready: '#mn-due-board, .mn-empty', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/customers', ready: '#mn-customer-list, .mn-empty', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/equipment', ready: '#mn-equipment-list, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/visits', ready: '#mn-visit-list, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/catalogs', ready: '#mn-equip-types, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings', ready: '#mn-settings-access, .mn-empty', creds: 'E2E' },
]

for (const route of a11yRoutes) {
	test(`a11y smoke WCAG 2.1 AA: ${route.path}`, async ({ page }) => {
		const creds = credsFromEnv(route.creds) || primaryCreds()
		test.skip(!creds, `Requires NC_${route.creds}_USER / NC_${route.creds}_PASS (or NC_E2E_* / NC_ADMIN_*)`)

		await login(page, creds)
		await page.goto(route.path)

		const main = page.locator('#mn-main-content')
		await expect(main, `expected MaintenanceCheck shell at ${route.path} (is the app enabled?)`).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 })

		// Scope to our shell — Nextcloud chrome is out of app ownership.
		const results = await new AxeBuilder({ page })
			.include('#mn-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()

		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
}
