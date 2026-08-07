// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Bachus: filter grids must start LTR under the panel body — never shoved right
 * by legacy .mn-filterbar { align-items: flex-end } + column flex.
 */
test('Work order filter grid is left-aligned and axe clean', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/work-orders')
	await expect(page.locator('#mn-wo-filters')).toBeVisible({ timeout: 30_000 })

	const metrics = await page.evaluate(() => {
		const form = document.getElementById('mn-wo-filters')
		const body = form?.closest('.mn-filter-panel')?.querySelector('.mn-filter-panel__body')
		const grid = form?.querySelector('.mn-filter-grid')
		const first = grid?.children?.[0]
		if (!form || !body || !grid || !first) {
			return { ok: false, reason: 'missing nodes' }
		}
		const formCs = getComputedStyle(form)
		const bodyR = body.getBoundingClientRect()
		const firstR = first.getBoundingClientRect()
		const bodyPad = Number.parseFloat(getComputedStyle(body).paddingLeft) || 0
		const expectedLeft = bodyR.left + bodyPad
		return {
			ok: true,
			alignItems: formCs.alignItems,
			flexDirection: formCs.flexDirection,
			hasLegacyFilterbar: form.classList.contains('mn-filterbar'),
			delta: Math.abs(firstR.left - expectedLeft),
			firstLeft: firstR.left,
			expectedLeft,
		}
	})

	expect(metrics.ok, metrics.reason || 'nodes').toBeTruthy()
	expect(metrics.hasLegacyFilterbar, 'form must not carry legacy mn-filterbar').toBe(false)
	expect(metrics.alignItems).toBe('stretch')
	expect(metrics.flexDirection).toBe('column')
	expect(metrics.delta, `grid starts ${metrics.delta}px off body padding edge`).toBeLessThanOrEqual(2)

	const axe = await new AxeBuilder({ page })
		.include('#mn-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([])
})
