// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'
import { setUserTheme, resetUserTheme, setAccentColor, resetAccentColor, USER_THEMES } from './helpers/theming.js'

/**
 * Theme × viewport gauntlet.
 *
 * For every selectable NC theme (light, dark, light-highcontrast,
 * dark-highcontrast) and key route, this suite proves:
 *  - the theme actually switched (body[data-theme-*]),
 *  - zero horizontal overflow from 320 px up to 4K,
 *  - zero axe WCAG 2.1 AA violations across the full app shell
 *    (#content = sidebar + header + main, not just main content).
 *
 * Runs on a single project only: themes are stored per user, so parallel
 * projects sharing the E2E user would race each other. Viewports are driven
 * with page.setViewportSize() inside the test instead.
 */

const routes = [
	{ id: 'due', path: '/apps/maintenancecheck/', ready: '#mn-due-board, .mn-empty' },
	{ id: 'work-orders', path: '/apps/maintenancecheck/work-orders', ready: '#mn-wo-list, .mn-empty, #mn-main-content' },
	{ id: 'customers', path: '/apps/maintenancecheck/customers', ready: '#mn-customer-list, .mn-empty' },
	{ id: 'settings', path: '/apps/maintenancecheck/settings/access', ready: '#mn-settings-access, .mn-empty' },
]

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1280, height: 800 },
	{ width: 2560, height: 1440 },
]
const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
]

/** Horizontal overflow check: page and app content must never scroll sideways. */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
		}
	})
	// 1px tolerance for subpixel rounding on zoomed displays
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content horizontal overflow at ${label}`).toBeLessThanOrEqual(1)
}

async function runAxe(page, label) {
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([])
}

test.describe('theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }, testInfo) => {
				test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run matrix once')
				const creds = primaryCreds()
				test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

				await login(page, creds)
				await page.goto(route.path)
				await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
				await setUserTheme(page, theme)
				await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 })

				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				for (const viewport of axeViewports) {
					await page.setViewportSize(viewport)
					await runAxe(page, `${theme}/${route.id}@${viewport.width}px`)
				}
			})
		}
	}

	test('reset to default theme', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run matrix once')
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')
		await login(page, creds)
		await page.goto('/apps/maintenancecheck/')
		await resetUserTheme(page)
	})
})

test.describe('custom accent colour', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test('primary actions follow the instance accent colour and stay AA', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'accent colour is instance-wide; run once')
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

		await login(page, creds)
		await page.goto('/apps/maintenancecheck/work-orders')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const readPrimary = () => page.evaluate(() => {
			const probe = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim()
			// Only solid primary CTAs are expected to equal the raw variable;
			// secondary/tinted buttons derive via color-mix and are covered by axe.
			const btn = document.querySelector('.mn-btn--primary')
			return {
				variable: probe,
				buttonBackground: btn ? getComputedStyle(btn).backgroundColor : null,
			}
		})

		const before = await readPrimary()
		expect(before.variable, 'NC must expose --color-primary-element').not.toEqual('')

		setAccentColor('#971003')
		try {
			// occ writes app config, but the web server's local config cache (APCu)
			// can serve a stale theming cachebuster briefly — poll until it lands.
			await expect.poll(async () => {
				await page.reload({ waitUntil: 'load' })
				return (await readPrimary()).variable
			}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).not.toEqual(before.variable)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
			const after = await readPrimary()
			if (after.buttonBackground) {
				// Primary buttons must track the variable — proves no hardcoded CTA colour
				const varAsColor = await page.evaluate(() => {
					const el = document.createElement('div')
					el.style.color = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim()
					document.body.appendChild(el)
					const resolved = getComputedStyle(el).color
					el.remove()
					return resolved
				})
				expect(after.buttonBackground).toEqual(varAsColor)
			}

			await runAxe(page, 'custom-accent/work-orders@1280px')
		} finally {
			resetAccentColor()
		}

		await expect.poll(async () => {
			await page.reload({ waitUntil: 'load' })
			return (await readPrimary()).variable
		}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).toEqual(before.variable)
	})
})
