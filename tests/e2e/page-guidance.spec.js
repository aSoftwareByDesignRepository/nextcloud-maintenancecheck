// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Page guidance journeys — DutyCheck-style quickstart on every surface.
 */

async function axeMain(page) {
	const results = await new AxeBuilder({ page })
		.include('#mn-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

const guidedRoutes = [
	{ path: '/apps/maintenancecheck/', qs: '#mn-due-quickstart' },
	{ path: '/apps/maintenancecheck/customers', qs: '#mn-customers-quickstart' },
	{ path: '/apps/maintenancecheck/equipment', qs: '#mn-equipment-quickstart' },
	{ path: '/apps/maintenancecheck/visits', qs: '#mn-visits-quickstart' },
	{ path: '/apps/maintenancecheck/catalogs', qs: '#mn-catalogs-quickstart' },
	{ path: '/apps/maintenancecheck/work-orders', qs: '#mn-wo-quickstart' },
	{ path: '/apps/maintenancecheck/dispatch', qs: '#mn-dispatch-quickstart' },
	{ path: '/apps/maintenancecheck/tours', qs: '#mn-tours-quickstart' },
	{ path: '/apps/maintenancecheck/kpi', qs: '#mn-kpi-quickstart' },
	{ path: '/apps/maintenancecheck/exceptions', qs: '#mn-exceptions-quickstart' },
	{ path: '/apps/maintenancecheck/settings/access', qs: '#mn-settings-section-quickstart' },
]

test.describe('Page guidance UX', () => {
	test.describe.configure({ timeout: 180_000 })

	test('every route shows DutyCheck-style quickstart; dismiss persists; axe on samples', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)

		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
		await page.evaluate(() => {
			try {
				Object.keys(localStorage)
					.filter((k) => k.startsWith('mn:hint:'))
					.forEach((k) => localStorage.removeItem(k))
			} catch {
				/* ignore */
			}
		})
		await page.reload()
		await expect(page.locator('#mn-due-quickstart')).toBeVisible({ timeout: 15_000 })

		for (const route of guidedRoutes) {
			await page.goto(route.path)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

			const lead = page.locator('.mn-page-header__lead')
			await expect(lead).toBeVisible()
			await expect(lead).not.toHaveText('')

			const qs = page.locator(route.qs)
			await expect(qs).toBeVisible({ timeout: 15_000 })
			await expect(qs).toHaveClass(/mn-quickstart-card/)
			await expect(qs.locator('.mn-section__header')).toBeVisible()
			await expect(qs.getByRole('heading', { name: /quick start|schnellstart/i })).toBeVisible()
			await expect(qs.locator('.mn-section__sub')).toBeVisible()
			await expect(qs.locator('.mn-quickstart__item')).toHaveCount(3)
			await expect(qs.getByRole('button', { name: /hide tips|tipps ausblenden/i })).toBeVisible()

			const ctas = qs.locator('.mn-quickstart__item .mn-btn, .mn-quickstart__item .mn-quickstart__cta, .mn-quickstart__item a.button')
			const ctaCount = await ctas.count()
			for (let i = 0; i < ctaCount; i++) {
				const align = await ctas.nth(i).evaluate((el) => getComputedStyle(el).alignSelf)
				expect(align, `quickstart CTA ${i} must be centered`).toMatch(/center/)
			}
		}

		await axeMain(page)
		await page.goto('/apps/maintenancecheck/visits')
		await expect(page.locator('#mn-visits-quickstart')).toBeVisible({ timeout: 15_000 })
		await axeMain(page)

		await page.goto('/apps/maintenancecheck/')
		const dueQs = page.locator('#mn-due-quickstart')
		await expect(dueQs).toBeVisible({ timeout: 15_000 })
		await dueQs.getByRole('button', { name: /hide tips|tipps ausblenden/i }).click()
		await expect(dueQs).toBeHidden()
		const stored = await page.evaluate(() =>
			Object.keys(localStorage).filter((k) => k.includes('due_quickstart_v1') && localStorage.getItem(k) === '1'),
		)
		expect(stored.length, 'dismiss must persist in localStorage').toBeGreaterThan(0)
		await page.reload()
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-due-quickstart')).toBeHidden({ timeout: 10_000 })

		await expect(page.locator('#app-navigation .mn-nav__hint').first()).toBeVisible()
	})

	test('settings policies underpage keeps guidance + axe', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/settings/policies')
		await expect(page.locator('#mn-settings-policies, #mn-policy-fail-blocks-roll').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-settings-section-quickstart.mn-quickstart-card')).toBeVisible()
		await expect(page.locator('.mn-page-header__lead')).toBeVisible()
		await axeMain(page)
	})
})
