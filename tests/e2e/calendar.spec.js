// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, primaryCreds } from './helpers/auth.js'

/**
 * FA-2 technician calendar — page structure, role-scoped UI, responsive
 * compact-cell rule (F-2), keyboard operability (MH-11).
 *
 * Data-dependent assertions are resilient: they verify the rendering
 * contract (one element per span day, +k more dialog, agenda cards)
 * whenever fixture events exist, and degrade to structure checks on an
 * empty fixture.
 */

const techCreds = credsFromEnv('TECH')
const officeCreds = credsFromEnv('ADMIN') || primaryCreds()

/** Y-m-d for a date `days` from today. */
function ymd(days = 0) {
	const d = new Date()
	d.setDate(d.getDate() + days)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * Seed a real customer + work order via the API inside the page context —
 * the calendar then renders an actual event instead of an empty fixture.
 */
async function seedWorkOrder(page, { dueOn, dueUntil, title }) {
	return page.evaluate(async ({ dueOn, dueUntil, title }) => {
		const token = document.documentElement.getAttribute('data-requesttoken')
			|| document.querySelector('head meta[name="requesttoken"]')?.getAttribute('content') || ''
		const r = await fetch('/apps/maintenancecheck/api/customers', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: token },
			credentials: 'same-origin',
			body: JSON.stringify({ name: `CalE2E ${Date.now()}` }),
		})
		const customer = await r.json()
		if (!customer.id) {
			return { ok: false, error: `customer: ${JSON.stringify(customer)}` }
		}
		const r2 = await fetch('/apps/maintenancecheck/api/work-orders', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: token },
			credentials: 'same-origin',
			body: JSON.stringify({
				customerId: customer.id,
				title,
				kind: 'preventive',
				status: 'planned',
				dueOn,
				dueUntil,
			}),
		})
		const wo = await r2.json()
		return { ok: !!wo.id, customer, wo }
	}, { dueOn, dueUntil, title })
}

test.describe('calendar: page structure', () => {
	test('calendar page renders toolbar + month grid for a technician', async ({ page }) => {
		test.skip(!techCreds, 'Requires NC_TECH_USER / NC_TECH_PASS')
		await login(page, techCreds)
		await page.goto('/apps/maintenancecheck/calendar')

		const board = page.locator('#mn-cal-board')
		await expect(board).toBeVisible({ timeout: 30_000 })
		// Toolbar: period nav + today + view toggle, all ≥ real buttons.
		await expect(page.locator('#mn-cal-prev')).toBeVisible()
		await expect(page.locator('#mn-cal-next')).toBeVisible()
		await expect(page.locator('#mn-cal-today')).toBeVisible()
		await expect(page.locator('#mn-cal-view-month')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.locator('#mn-cal-view-week')).toHaveAttribute('aria-pressed', 'false')
		// Grid or honest empty state — never a silent blank.
		const grid = page.locator('.mn-cal-grid')
		const empty = page.locator('.mn-cal-board .mn-empty')
		await expect(grid.or(empty)).toBeVisible()
		if (await grid.isVisible()) {
			// 42 Monday-first day cells, each a labelled <section>.
			const cells = page.locator('.mn-cal-cell')
			await expect(cells).toHaveCount(42)
			await expect(cells.first()).toHaveAttribute('aria-label', /.+/)
		}
		// Techs get NO office filter UI — scope is fixed server-side (US-CAL-01).
		await expect(page.locator('#mn-cal-filters')).toBeHidden()
	})

	test('office sees the filter panel; technician narrowing re-queries', async ({ page }) => {
		test.skip(!officeCreds, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
		await login(page, officeCreds)
		await page.goto('/apps/maintenancecheck/calendar')

		const filters = page.locator('#mn-cal-filters')
		await expect(filters).toBeVisible({ timeout: 30_000 })
		// Recipe-A panel: technician picker + kind + status selects.
		await expect(filters.locator('.mn-user-picker__input')).toBeVisible()
		await expect(filters.locator('select[aria-label]')).toHaveCount(2)
	})

	test('month ↔ week toggle persists choice and re-renders', async ({ page }) => {
		test.skip(!techCreds, 'Requires NC_TECH_USER / NC_TECH_PASS')
		await login(page, techCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await page.locator('#mn-cal-view-week').click()
		await expect(page.locator('#mn-cal-view-week')).toHaveAttribute('aria-pressed', 'true')
		const weekOrEmpty = page.locator('.mn-cal-week').or(page.locator('.mn-cal-board .mn-empty'))
		await expect(weekOrEmpty).toBeVisible()
		await page.locator('#mn-cal-view-month').click()
		await expect(page.locator('#mn-cal-view-month')).toHaveAttribute('aria-pressed', 'true')
	})
})

test.describe('calendar: responsive + a11y (F-2 / MH-11)', () => {
	test('<768px: cells are compact dots/counts, agenda below (never squeezed bars)', async ({ page }) => {
		test.skip(!techCreds, 'Requires NC_TECH_USER / NC_TECH_PASS')
		await page.setViewportSize({ width: 375, height: 800 })
		await login(page, techCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await expect(page.locator('#mn-cal-board')).toBeVisible({ timeout: 30_000 })

		const grid = page.locator('.mn-cal-grid')
		if (await grid.isVisible()) {
			// Compact rule: no visible event bars inside cells at 375px.
			const bars = page.locator('.mn-cal-cell .mn-cal-event:visible')
			await expect(bars).toHaveCount(0)
			// Any populated cell exposes the count indicator.
			const counts = page.locator('.mn-cal-cell__count:visible')
			const count = await counts.count()
			if (count > 0) {
				await expect(counts.first()).toHaveText(/\d+/)
			}
			// Agenda section is the single-interaction surface under the grid.
			await expect(page.locator('#mn-cal-agenda')).toBeVisible()
			await expect(page.locator('.mn-cal-agenda__title')).toBeVisible()
		}
		// Zero horizontal scroll at 375 (design-system responsive bar).
		const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
		expect(scrollWidth).toBeLessThanOrEqual(375)
	})

	test('keyboard: arrows move day focus, Enter opens the day dialog', async ({ page }) => {
		test.skip(!techCreds, 'Requires NC_TECH_USER / NC_TECH_PASS')
		await login(page, techCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		const grid = page.locator('.mn-cal-grid')
		if (!(await grid.isVisible({ timeout: 30_000 }).catch(() => false))) {
			test.skip(true, 'Empty fixture — no cells to navigate')
			return
		}
		const cell = page.locator('.mn-cal-cell').nth(8)
		await cell.focus()
		await page.keyboard.press('ArrowRight')
		const focusedDay = await page.evaluate(() => document.activeElement?.getAttribute('data-mn-cal-day'))
		expect(focusedDay).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		await page.keyboard.press('Enter')
		// Enter opens the in-page day dialog (native dialog recipe).
		const dialog = page.locator('.mn-dialog, [role="dialog"]')
		await expect(dialog.first()).toBeVisible()
	})

	test('multi-day span renders as covered-day items, not duplicates-per-day', async ({ page }) => {
		test.skip(!officeCreds, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
		await login(page, officeCreds)
		const seeded = await seedWorkOrder(page, {
			dueOn: ymd(0), dueUntil: ymd(2), title: 'CalE2E multi-day job',
		})
		test.skip(!seeded.ok, `WO seed failed: ${seeded.error ?? 'unknown'}`)
		await page.goto('/apps/maintenancecheck/calendar')
		await expect(page.locator('#mn-cal-board')).toBeVisible({ timeout: 30_000 })
		// Every event is a link to an existing detail page (click-through MH-07).
		const events = page.locator('.mn-cal-event', { hasText: 'CalE2E multi-day job' })
		// A 3-day span shows the job on each covered day cell.
		for (const day of [ymd(0), ymd(1), ymd(2)]) {
			const cell = page.locator(`.mn-cal-cell[data-mn-cal-day="${day}"]`)
			if (await cell.isVisible().catch(() => false)) {
				await expect(cell.locator('.mn-cal-event', { hasText: 'CalE2E' })).toHaveCount(1)
			}
		}
		const href = await events.first().getAttribute('href')
		expect(href).toMatch(/\/work-orders\/\d+/)
	})

	test('+k more affordance opens the day list dialog', async ({ page }) => {
		test.skip(!officeCreds, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
		await login(page, officeCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await expect(page.locator('#mn-cal-board')).toBeVisible({ timeout: 30_000 })
		const more = page.locator('.mn-cal-more').first()
		if (!(await more.isVisible().catch(() => false))) {
			test.skip(true, 'No day exceeds the 3-event cell cap in this fixture')
			return
		}
		await more.click()
		const dialog = page.locator('.mn-dialog, [role="dialog"]').first()
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('.mn-cal-day-list__link').first()).toBeVisible()
	})
})
