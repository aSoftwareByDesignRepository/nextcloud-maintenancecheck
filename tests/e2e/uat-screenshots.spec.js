// @ts-check
import { test, expect } from '@playwright/test'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Archive a due-board screenshot for release notes / App Store UAT.
 */
const outDir = resolve(dirname(fileURLToPath(import.meta.url)), 'artifacts')

test('archive MaintenanceCheck due board screenshot', async ({ page }) => {
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
})
