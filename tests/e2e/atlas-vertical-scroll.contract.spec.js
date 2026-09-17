// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings page scrolls to end.
 * Catches unpaired overflow-x:clip shells that truncate bottom content.
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require(
	join(dirname(fileURLToPath(import.meta.url)), '../../../_shared/e2e/atlas-vertical-scroll-contract.js'),
)

test('ATLAS_VERTICAL_SCROLL_CONTRACT license settings scrolls to seats', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
	await page.setViewportSize({ width: 1280, height: 640 })
	await login(page, creds)
	await page.goto('/apps/maintenancecheck/settings/license')
	await page.waitForSelector('#mn-settings-license, .mn-empty', { timeout: 30_000 })
	if (await page.locator('.mn-empty').count()) {
		test.skip(true, 'Signed-in user is not an app admin')
	}
	await page.waitForSelector('#mn-settings-license:not([aria-busy="true"]), #mn-settings-license .mn-license-status, #mn-settings-license .mn-section__title', {
		timeout: 30_000,
	})
	await assertAtlasVerticalScrollReachable(page, {
		scrollport: '#app-content',
		target: '#mn-settings-license .mn-chips, #mn-settings-license .mn-inline-row, #mn-settings-license .mn-license-cta, #mn-settings-license',
	})
})
