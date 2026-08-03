// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Bachus: list/board tables must be table-solo (no soft-band header glued onto thead).
 * Filter panels stay separate when present.
 */
const listPages = [
	{
		path: '/apps/maintenancecheck/visits',
		filterTitle: null,
		listTitle: '#mn-visit-list-title',
		list: '#mn-visit-list',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/customers',
		filterTitle: '#mn-customer-filter-title',
		listTitle: '#mn-customer-list-title',
		list: '#mn-customer-list',
		requireFilter: true,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/equipment',
		filterTitle: '#mn-equipment-filter-title',
		listTitle: '#mn-equipment-list-title',
		list: '#mn-equipment-list',
		requireFilter: true,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/work-orders',
		filterTitle: '#mn-wo-filter-title',
		listTitle: '#mn-wo-list-title',
		list: '#mn-wo-list',
		requireFilter: true,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/dispatch',
		filterTitle: null,
		listTitle: '#mn-dispatch-title',
		list: '#mn-dispatch-board',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/tours',
		filterTitle: null,
		listTitle: '#mn-tours-title',
		list: '#mn-tours-board',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/exceptions',
		filterTitle: null,
		listTitle: '#mn-exceptions-title',
		list: '#mn-exceptions-board',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: true,
	},
	{
		path: '/apps/maintenancecheck/kpi',
		filterTitle: null,
		listTitle: '#mn-kpi-title',
		list: '#mn-kpi-snapshot',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: false,
	},
	{
		path: '/apps/maintenancecheck/catalogs',
		filterTitle: null,
		listTitle: '#mn-equip-types-title',
		list: '#mn-equip-types',
		requireFilter: false,
		tableSolo: true,
		srOnlyTitle: false,
	},
	{
		path: '/apps/maintenancecheck/settings/access',
		filterTitle: null,
		listTitle: '#mn-access-title',
		list: '#mn-settings-access',
		requireFilter: false,
		tableSolo: false,
	},
]

for (const route of listPages) {
	test(`filter/list card chrome parity: ${route.path}`, async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

		await login(page, creds)
		await page.goto(route.path)

		const main = page.locator('#mn-main-content')
		await expect(main, `expected MN shell at ${route.path}`).toBeVisible({ timeout: 30_000 })
		if (route.requireFilter && route.filterTitle) {
			await expect(page.locator(route.filterTitle)).toBeVisible({ timeout: 30_000 })
		}
		await expect(page.locator(route.listTitle)).toBeAttached({ timeout: 30_000 })

		const metrics = await page.evaluate(({ filterTitle, listTitle, requireFilter, tableSolo }) => {
			const listCard = document.querySelector(listTitle)?.closest('.mn-card')
			if (!listCard) {
				return { ok: false, reason: 'missing list card nodes' }
			}
			const lc = getComputedStyle(listCard)
			const body = listCard.querySelector('.mn-card__body, .mn-card__body--table')
			const wrap = listCard.querySelector('.mn-table-wrap, .table-container')
			const bodyPad = body ? getComputedStyle(body).paddingTop : null
			const wrapBorder = wrap ? getComputedStyle(wrap).borderTopWidth : null
			if (tableSolo) {
				// Only the outer list card — nested day/lane cards may use headers.
				const stackedHead = Array.from(listCard.children).find((el) => el.classList && el.classList.contains('mn-card__header'))
				if (stackedHead) {
					return { ok: false, reason: 'table-solo card still has stacked soft-band header' }
				}
				if (!listCard.classList.contains('mn-card--table-solo')) {
					return { ok: false, reason: 'missing mn-card--table-solo' }
				}
				const lead = Array.from(listCard.querySelectorAll('.mn-card__lead')).find((el) => {
					return el.closest('.mn-card') === listCard
				})
				if (lead) {
					return { ok: false, reason: 'table-solo still has lead glued above table' }
				}
				return {
					ok: true,
					tableSolo: true,
					listPad: lc.paddingTop,
					bodyPad,
					wrapBorder,
				}
			}
			const listHead = document.querySelector(listTitle)?.closest('.mn-card__header')
			if (!listHead) {
				return { ok: false, reason: 'missing list card header' }
			}
			const lh = getComputedStyle(listHead)
			const base = {
				ok: true,
				listPad: lc.paddingTop,
				listBg: lh.backgroundColor,
				listBorder: lh.borderBottomWidth,
				bodyPad,
				wrapBorder,
			}
			if (!requireFilter || !filterTitle) {
				return base
			}
			const filterHead = document.querySelector(filterTitle)?.closest('.mn-filter-panel__head, header')
			const filterPanel = document.querySelector(filterTitle)?.closest('.mn-filter-panel')
			if (!filterHead || !filterPanel) {
				return { ok: false, reason: 'missing filter nodes' }
			}
			const fh = getComputedStyle(filterHead)
			const fp = getComputedStyle(filterPanel)
			return {
				...base,
				filterPad: fp.paddingTop,
				filterBg: fh.backgroundColor,
				filterBorder: fh.borderBottomWidth,
			}
		}, route)

		expect(metrics.ok, metrics.reason || 'nodes').toBeTruthy()
		expect(metrics.listPad).toBe('0px')
		if (route.tableSolo) {
			const card = page.locator(route.listTitle).locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " mn-card ")][1]')
			await expect(card).toHaveClass(/mn-card--table-solo/)
			await expect(card.locator(':scope > .mn-card__header')).toHaveCount(0)
			await expect(card.locator(':scope > .mn-card__body .mn-card__lead, :scope > .mn-card__lead')).toHaveCount(0)
			const gluedLead = await card.evaluate((el) => {
				return Array.from(el.children).some((c) => c.classList && c.classList.contains('mn-card__lead'))
					|| Array.from(el.querySelectorAll(':scope > .mn-card__header .mn-card__lead')).length > 0
			})
			expect(gluedLead).toBe(false)
			if (route.srOnlyTitle) {
				await expect(page.locator(route.listTitle)).toHaveClass(/mn-sr-only/)
			}
			// Single frame: body flush + wrap border stripped (no box-in-a-box).
			if (metrics.bodyPad != null) {
				expect(Number.parseFloat(metrics.bodyPad), 'table-solo body must be flush').toBe(0)
			}
			const wrap = card.locator('.mn-table-wrap, .table-container').first()
			if (await wrap.count()) {
				const border = await wrap.evaluate((el) => getComputedStyle(el).borderTopWidth)
				expect(Number.parseFloat(border), 'table-solo wrap must not draw a second box').toBe(0)
			}
		} else {
			// Headed cards: CustomerCheck inset.
			if (metrics.bodyPad != null) {
				expect(Number.parseFloat(metrics.bodyPad), 'headed card body must keep inset padding').toBeGreaterThanOrEqual(16)
			}
			expect(metrics.listBorder).not.toBe('0px')
			expect(metrics.listBg).not.toBe('rgba(0, 0, 0, 0)')
			if (route.requireFilter) {
				expect(metrics.filterPad).toBe('0px')
				expect(metrics.filterBorder).not.toBe('0px')
				expect(metrics.listBorder).toBe(metrics.filterBorder)
				expect(metrics.listBg).toBe(metrics.filterBg)
			}
		}

		const list = page.locator(route.list)
		await expect(list).toBeAttached({ timeout: 30_000 })
		if (await list.count()) {
			const busy = await list.first().getAttribute('aria-busy')
			if (busy === 'true') {
				await expect(list.first()).not.toHaveAttribute('aria-busy', 'true', { timeout: 45_000 })
			}
		}

		const axe = await new AxeBuilder({ page })
			.include('#mn-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()
		expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([])
	})
}
