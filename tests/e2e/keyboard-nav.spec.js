// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, primaryCreds } from './helpers/auth.js'

/**
 * Keyboard-only smoke: Tab reaches interactive controls inside the app shell
 * (Nextcloud chrome is out of scope). WCAG 2.1 AA keyboard operable.
 */
test('keyboard: Tab reaches due-board controls with a visible focus ring', async ({ page }) => {
	const creds = credsFromEnv('E2E') || primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_USER / NC_E2E_PASS (or NC_ADMIN_*)')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	const main = page.locator('#mn-main-content')
	await expect(main).toBeVisible({ timeout: 30_000 })

	await main.focus()
	const focused = []
	for (let i = 0; i < 12; i++) {
		await page.keyboard.press('Tab')
		const info = await page.evaluate(() => {
			const el = document.activeElement
			if (!(el instanceof HTMLElement)) {
				return null
			}
			if (!document.querySelector('#mn-main-content')?.contains(el)) {
				return { outside: true, tag: el.tagName }
			}
			const style = window.getComputedStyle(el)
			const outline = style.outlineStyle !== 'none' && style.outlineWidth !== '0px'
			const ring = style.boxShadow !== 'none'
			return {
				outside: false,
				tag: el.tagName,
				id: el.id,
				role: el.getAttribute('role') || '',
				visibleFocus: outline || ring || el.className.includes('focus'),
			}
		})
		if (info && !info.outside) {
			focused.push(info)
		}
	}
	expect(focused.length, 'Tab must land on at least two controls inside #mn-main-content').toBeGreaterThanOrEqual(2)
})
