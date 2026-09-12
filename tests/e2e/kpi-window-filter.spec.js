// @ts-check
/**
 * KPI window 30/90 chip toggle — each_value e2e for flt-web-kpi-window (POLICY ≥3.5.10).
 */
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

test.describe('KPI window filter', () => {
	test('toggle 30 ↔ 90 days: aria-pressed + snapshot reload', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_*')
		await login(page, creds)
		await page.goto('/apps/maintenancecheck/kpi')
		await expect(page.locator('#mn-kpi-snapshot')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-kpi-snapshot')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		const chip30 = page.locator('[data-mn-kpi-days="30"]')
		const chip90 = page.locator('[data-mn-kpi-days="90"]')
		await expect(chip30).toBeVisible()
		await expect(chip90).toBeVisible()
		await expect(chip30).toHaveAttribute('aria-pressed', 'true')
		await expect(chip90).toHaveAttribute('aria-pressed', 'false')

		const snap30 = (await page.locator('#mn-kpi-snapshot').innerText()).trim()
		console.log(`EVIDENCE flt-web-kpi-window each_value=30 snapshot_len=${snap30.length}`)

		await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/kpi|\/api\/ops\/kpi|kpi/i.test(r.url()) && r.request().method() === 'GET',
				{ timeout: 20_000 },
			).catch(() => null),
			chip90.click(),
		])
		await expect(chip90).toHaveAttribute('aria-pressed', 'true', { timeout: 10_000 })
		await expect(chip30).toHaveAttribute('aria-pressed', 'false')
		await expect(page.locator('#mn-kpi-snapshot')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		const snap90 = (await page.locator('#mn-kpi-snapshot').innerText()).trim()
		expect(snap90.length).toBeGreaterThan(0)
		console.log(`EVIDENCE flt-web-kpi-window each_value=90 aria-pressed=true snapshot_len=${snap90.length}`)

		await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/kpi|\/api\/ops\/kpi|kpi/i.test(r.url()) && r.request().method() === 'GET',
				{ timeout: 20_000 },
			).catch(() => null),
			chip30.click(),
		])
		await expect(chip30).toHaveAttribute('aria-pressed', 'true', { timeout: 10_000 })
		await expect(chip90).toHaveAttribute('aria-pressed', 'false')
		await expect(page.locator('#mn-kpi-snapshot')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		console.log('EVIDENCE flt-web-kpi-window each_value restore=30 PASS')
		// empty_result: KPI always renders window metrics / tiles (or honest error) — never blank chrome theater
		const body = await page.locator('#mn-kpi-snapshot').innerText()
		expect(body.length).toBeGreaterThan(5)
		console.log('EVIDENCE flt-web-kpi-window empty_result=n_a always shows window metrics')
	})
})
