// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Design-system §3.7: dense office lists must render real data tables
 * (not orphaned mn-row / mn-list / mn-tour-stops card stacks).
 */
const tableRoutes = [
	{ path: '/apps/maintenancecheck/customers', list: '#mn-customer-list' },
	{ path: '/apps/maintenancecheck/equipment', list: '#mn-equipment-list' },
	{ path: '/apps/maintenancecheck/visits', list: '#mn-visit-list' },
	{ path: '/apps/maintenancecheck/work-orders', list: '#mn-wo-list' },
	{ path: '/apps/maintenancecheck/', list: '#mn-due-board [data-bucket-list]', kind: 'due' },
	{ path: '/apps/maintenancecheck/exceptions', list: '#mn-exceptions-board', kind: 'exceptions' },
	{ path: '/apps/maintenancecheck/dispatch', list: '#mn-dispatch-board', kind: 'dispatch' },
	{ path: '/apps/maintenancecheck/tours', list: '#mn-tours-board', kind: 'tours' },
	{ path: '/apps/maintenancecheck/kpi', list: '#mn-kpi-snapshot', kind: 'kpi' },
]

for (const route of tableRoutes) {
	test(`table parity: ${route.path}`, async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

		await login(page, creds)
		await page.goto(route.path)
		const kind = route.kind || 'list'
		const list = page.locator(route.list).first()

		if (kind === 'due') {
			await expect(page.locator('#mn-due-toolbar')).toBeVisible({ timeout: 30_000 })
			await expect(page.locator('#mn-due-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
			const boardHidden = await page.locator('#mn-due-board').isHidden()
			if (boardHidden) {
				await expect(page.locator('#mn-due-empty')).toBeVisible()
				return
			}
		} else if (kind === 'kpi') {
			await expect(list).toBeVisible({ timeout: 30_000 })
			await expect(list).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
			await expect(list.locator('.mn-kpi-card').first()).toBeVisible({ timeout: 15_000 })
			await expect(list.locator('ul.mn-list')).toHaveCount(0)
			const statusTable = list.locator('table.mn-table.table.table--hover.mn-table--responsive')
			const statusEmpty = list.locator('.mn-kpi-status .mn-muted')
			expect((await statusTable.count()) + (await statusEmpty.count())).toBeGreaterThan(0)
			if (await statusTable.count()) {
				await expect(statusTable.first().locator('th[scope="col"]').first()).toBeVisible()
			}
			return
		} else {
			await expect(list).toBeVisible({ timeout: 30_000 })
			await expect(list).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		}

		const empty = list.locator('.mn-empty, .mn-empty-state')
		const table = page.locator(
			kind === 'due'
				? '#mn-due-board table.mn-table.table.table--hover.mn-table--responsive'
				: `${route.list} table.mn-table.table.table--hover.mn-table--responsive`,
		)
		const mutedEmpty = list.getByText(/nothing to dispatch|no tours|no exceptions|nothing due/i)
		const hasEmpty = await empty.count()
		const hasTable = await table.count()
		const hasMuted = await mutedEmpty.count()

		if (kind === 'due') {
			expect(hasTable).toBeGreaterThan(0)
			await expect(page.locator('.mn-visit-card')).toHaveCount(0)
		} else if (kind === 'exceptions' || kind === 'dispatch' || kind === 'tours') {
			expect(hasEmpty + hasTable + hasMuted).toBeGreaterThan(0)
			await expect(list.locator('ul.mn-list')).toHaveCount(0)
			await expect(list.locator('ol.mn-tour-stops')).toHaveCount(0)
		} else {
			expect(hasEmpty + hasTable).toBeGreaterThan(0)
		}

		if (hasTable > 0) {
			await expect(table.first()).toBeVisible()
			await expect(table.locator('th[scope="col"]').first()).toBeVisible()
			const firstDataCell = table.locator('tbody td').first()
			if (await firstDataCell.count()) {
				await expect(firstDataCell).toHaveAttribute('data-label', /.+/);
			}
			const scope = kind === 'due' ? '#mn-due-board' : route.list
			await expect(page.locator(`${scope} .mn-row`)).toHaveCount(0)
		}
	})
}
