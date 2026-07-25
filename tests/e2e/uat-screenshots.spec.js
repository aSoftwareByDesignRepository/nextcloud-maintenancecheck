// @ts-check
import { test, expect } from '@playwright/test'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * AC-20 / README §2a: archive a due-board screenshot for release notes UAT.
 * MobilityCheck side-by-side is captured when that app is enabled on the instance.
 */
const outDir = resolve(dirname(fileURLToPath(import.meta.url)), 'artifacts')

test('AC-20 archive MaintenanceCheck due board screenshot', async ({ page }) => {
	const admin = primaryCreds()
	test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
	mkdirSync(outDir, { recursive: true })

	await login(page, admin)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
	await page.screenshot({
		path: resolve(outDir, 'maintenancecheck-due-board-1280.png'),
		fullPage: true,
	})

	const mobility = await page.goto('/apps/mobilitycheck/').then(() => true).catch(() => false)
	if (mobility) {
		const shell = page.locator('#app-content, main, #content')
		if (await shell.first().isVisible({ timeout: 8_000 }).catch(() => false)) {
			await page.screenshot({
				path: resolve(outDir, 'mobilitycheck-shell-1280.png'),
				fullPage: true,
			})
		}
	}
})
