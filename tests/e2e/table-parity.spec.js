// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Design-system §3.7: dense office lists must render real data tables
 * (not orphaned mn-row card stacks).
 */
const tableRoutes = [
	{ path: '/apps/maintenancecheck/customers', list: '#mn-customer-list' },
	{ path: '/apps/maintenancecheck/equipment', list: '#mn-equipment-list' },
	{ path: '/apps/maintenancecheck/visits', list: '#mn-visit-list' },
	{ path: '/apps/maintenancecheck/work-orders', list: '#mn-wo-list' },
]

for (const route of tableRoutes) {
	test(`table parity: ${route.path}`, async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

		await login(page, creds)
		await page.goto(route.path)
		const list = page.locator(route.list)
		await expect(list).toBeVisible({ timeout: 30_000 })
		await expect(list).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		const empty = list.locator('.mn-empty, .mn-empty-state')
		const table = list.locator('table.mn-table.table.table--hover.mn-table--responsive')
		const hasEmpty = await empty.count()
		const hasTable = await table.count()
		expect(hasEmpty + hasTable).toBeGreaterThan(0)

		if (hasTable > 0) {
			await expect(table.first()).toBeVisible()
			await expect(table.locator('th[scope="col"]').first()).toBeVisible()
			const firstDataCell = table.locator('tbody td').first()
			if (await firstDataCell.count()) {
				await expect(firstDataCell).toHaveAttribute('data-label', /.+/);
			}
			// Card-row listing must not coexist with the table on list pages.
			await expect(list.locator('.mn-row')).toHaveCount(0)
		}
	})
}
