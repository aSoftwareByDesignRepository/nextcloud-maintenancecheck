// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginWithFallback, credsCandidates } from './helpers/auth.js'

/**
 * Bachus visits filter: flat live toolbar + date presets — no Filter card / Apply click.
 */
test.describe('Visits live filter UX', () => {
	test.beforeEach(async ({ page }) => {
		if (!credsCandidates().length) {
			if (process.env.CI) {
				throw new Error('CI requires NC_E2E_* or NC_ADMIN_*')
			}
			test.skip(true, 'Requires NC_E2E_* or NC_ADMIN_*')
			return
		}
		await loginWithFallback(page)
		await page.goto('/apps/maintenancecheck/visits')
		await expect(page.locator('#mn-visit-filters')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-visit-list')).toBeAttached({ timeout: 30_000 })
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('#mn-visit-list')).toBeVisible({ timeout: 30_000 })
	})

	test('flat toolbar: no Filter card / Apply; status chips are one-tap live filters', async ({ page }) => {
		await expect(page.locator('.mn-filter-panel')).toHaveCount(0)
		await expect(page.getByRole('button', { name: /apply filters|filter anwenden/i })).toHaveCount(0)
		await expect(page.locator('#mn-visit-filters')).toHaveClass(/mn-visits-toolbar/)

		const chips = page.locator('#mn-filter-status-chips .mn-chip')
		await expect(chips).toHaveCount(5)

		const allChip = page.locator('#mn-filter-status-chips [data-mn-status=""]')
		const doneChip = page.locator('#mn-filter-status-chips [data-mn-status="done"]')
		await expect(allChip).toHaveAttribute('aria-pressed', 'true')
		await expect(doneChip).toHaveAttribute('aria-pressed', 'false')

		const list = page.locator('#mn-visit-list')
		await doneChip.click()
		await expect(doneChip).toHaveAttribute('aria-pressed', 'true')
		await expect(allChip).toHaveAttribute('aria-pressed', 'false')
		await expect(page.locator('#mn-filter-status')).toHaveValue('done')
		await expect(page.locator('#mn-filter-reset')).toBeEnabled()
		await expect(list).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('#mn-filter-reset').click()
		await expect(allChip).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-filter-status')).toHaveValue('')
		await expect(page.locator('#mn-filter-when')).toHaveValue('')
		await expect(page.locator('#mn-filter-reset')).toBeDisabled()
	})

	test('date presets set from/to in one tap; custom reveals pickers with auto-swap', async ({ page }) => {
		const week = page.locator('#mn-filter-when-chips [data-mn-when="week"]')
		await week.click()
		await expect(week).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-filter-when')).toHaveValue('week')
		await expect(page.locator('#mn-filter-custom-dates')).toBeHidden()
		await expect(page.locator('#mn-filter-from')).not.toHaveValue('')
		await expect(page.locator('#mn-filter-to')).not.toHaveValue('')
		await expect(page.locator('#mn-filter-reset')).toBeEnabled()
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		const month = page.locator('#mn-filter-when-chips [data-mn-when="month"]')
		await month.click()
		await expect(month).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-filter-when')).toHaveValue('month')
		const fromMonth = await page.locator('#mn-filter-from').inputValue()
		const toMonth = await page.locator('#mn-filter-to').inputValue()
		expect(fromMonth.endsWith('-01')).toBeTruthy()
		expect(toMonth >= fromMonth).toBeTruthy()

		await page.locator('#mn-filter-when-chips [data-mn-when="custom"]').click()
		await expect(page.locator('#mn-filter-custom-dates')).toBeVisible()
		await expect(page.locator('#mn-filter-when')).toHaveValue('custom')

		await page.locator('#mn-filter-from').evaluate((el) => {
			el.value = '2026-12-31'
		})
		await page.locator('#mn-filter-to').evaluate((el) => {
			el.value = '2026-01-01'
			el.dispatchEvent(new Event('change', { bubbles: true }))
		})

		await expect(page.locator('#mn-filter-from')).toHaveValue('2026-01-01')
		await expect(page.locator('#mn-filter-to')).toHaveValue('2026-12-31')
		await expect(page.locator('#mn-filter-date-hint')).toBeVisible()
		await expect(page.locator('#mn-filter-date-hint')).toContainText(/swapped|getauscht/i)
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
	})

	test('mine toggle and keyboard status chips', async ({ page }) => {
		const mine = page.locator('#mn-filter-mine')
		await mine.check()
		await expect(page.locator('#mn-filter-reset')).toBeEnabled()
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		const scheduled = page.locator('#mn-filter-status-chips [data-mn-status="scheduled"]')
		await scheduled.focus()
		await page.keyboard.press('Enter')
		await expect(scheduled).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-filter-status')).toHaveValue('scheduled')

		const skipped = page.locator('#mn-filter-status-chips [data-mn-status="skipped"]')
		await skipped.focus()
		await page.keyboard.press(' ')
		await expect(skipped).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-filter-status')).toHaveValue('skipped')
	})

	test('touch targets ≥44px and axe WCAG 2.1 AA on toolbar + list', async ({ page }) => {
		const chipBox = await page.locator('#mn-filter-status-chips .mn-chip').first().boundingBox()
		expect(chipBox, 'status chip must render').not.toBeNull()
		expect(chipBox.height).toBeGreaterThanOrEqual(43)
		expect(chipBox.width).toBeGreaterThanOrEqual(43)

		const whenBox = await page.locator('#mn-filter-when-chips .mn-chip').first().boundingBox()
		expect(whenBox).not.toBeNull()
		expect(whenBox.height).toBeGreaterThanOrEqual(43)

		const resetBox = await page.locator('#mn-filter-reset').boundingBox()
		expect(resetBox).not.toBeNull()
		expect(resetBox.height).toBeGreaterThanOrEqual(43)

		const switchHit = await page.locator('label.mn-switch[for="mn-filter-mine"]').boundingBox()
		expect(switchHit).not.toBeNull()
		expect(switchHit.height).toBeGreaterThanOrEqual(43)

		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.include('#mn-main-content')
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('narrow viewport: toolbar stacks without horizontal overflow', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 640 })
		await page.goto('/apps/maintenancecheck/visits')
		await expect(page.locator('#mn-visit-filters')).toBeVisible({ timeout: 30_000 })

		const overflow = await page.locator('#mn-visit-filters').evaluate((el) => {
			return el.scrollWidth > el.clientWidth + 1
		})
		expect(overflow, 'visits toolbar must not force horizontal scroll at 320px').toBe(false)

		await page.locator('#mn-filter-status-chips [data-mn-status="cancelled"]').click()
		await expect(page.locator('#mn-filter-status')).toHaveValue('cancelled')
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('#mn-filter-when-chips [data-mn-when="week"]').click()
		await expect(page.locator('#mn-filter-when')).toHaveValue('week')
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
	})
})
