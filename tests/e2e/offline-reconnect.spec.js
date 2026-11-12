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
	const toast = page.locator('.mn-toast--error, .toast--error').first()
	await expect(toast).toBeVisible({ timeout: 15_000 })
	// Rendered leg: toBeVisible() counts opacity:0 as visible — the toast is
	// only honest once the computed opacity has animated to 1.
	await expect(toast).toHaveCSS('opacity', '1')
	// i18n: locale-safe — the aborted fetch is the only toast source on this
	// surface, so a non-empty message inside the error toast is the proof.
	await expect(page.locator('.mn-toast--error .mn-toast__message, .toast--error .toast-content').first()).not.toBeEmpty()
})
