// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, primaryCreds } from './helpers/auth.js'

/**
 * Web has no service worker. Fetch failures must still surface as a toast
 * (SPEC UI error envelope), not a silent empty board.
 */
test('offline API abort shows a network-error toast on customers', async ({ page }) => {
	const creds = credsFromEnv('E2E') || primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_USER / NC_E2E_PASS (or NC_ADMIN_*)')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	await page.route('**/apps/maintenancecheck/api/**', (route) => route.abort())
	await page.goto('/apps/maintenancecheck/customers')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('.mn-toast--error, .toast--error').first()).toBeVisible({ timeout: 15_000 })
	await expect(page.locator('.mn-toast__message, .toast-content').first()).toContainText(/could not reach the server|verbindung/i)
})
