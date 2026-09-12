// @ts-check
/**
 * ATLAS_MOBILE_NAV_CONTRACT — in-page Menu opens drawer at phone width (no h-scroll).
 * overflow:hidden shell requires #mn-nav-toggle (core toggle absent/clipped).
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasMobileNav } = require(
	join(dirname(fileURLToPath(import.meta.url)), '../../../_shared/e2e/atlas-mobile-nav-contract.js'),
)

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
	await page.setViewportSize({ width: 375, height: 812 })
	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await page.waitForSelector('#mn-nav-toggle, [data-mn-nav-toggle]', { timeout: 30_000 })
	await assertAtlasMobileNav(page, {
		toggle: page.locator('#mn-nav-toggle, [data-mn-nav-toggle]').first(),
		nav: page.locator('#app-navigation'),
		openClass: /mn-nav--open/,
	})
})
