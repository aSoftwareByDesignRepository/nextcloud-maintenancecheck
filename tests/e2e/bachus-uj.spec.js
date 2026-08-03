// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds, adminCreds } from './helpers/auth.js'

/**
 * Bachus UX journeys — one-tap complete, empty-bucket hide, overflow a11y, settings underpages.
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| ''
			const res = await fetch(path, {
				method,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let data = null
			try {
				data = text ? JSON.parse(text) : null
			} catch {
				data = { raw: text }
			}
			return { status: res.status, data }
		},
		{ method, path, body },
	)
}

function expectOk(result, label = 'API') {
	expect([200, 201, 204].includes(result.status), `${label}: ${JSON.stringify(result.data)}`).toBeTruthy()
}

async function axeMain(page) {
	const results = await new AxeBuilder({ page })
		.include('#mn-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

test.describe('Bachus UX journeys', () => {
	test('one-tap Complete removes visit; empty buckets stay hidden; axe', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const marker = `bachus-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		expectOk(types, 'types')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		expectOk(maint, 'maint')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `Bachus ${marker}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: `Pump ${marker}`,
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'month',
			intervalCount: 1,
			firstDueOn: serverToday,
		}).then((plan) => expectOk(plan, 'plan'))

		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-due-toolbar')).toBeVisible({ timeout: 15_000 })
		// Ensure "All" filter (not Inspections-only) so preventive visits are visible.
		await page.locator('#mn-due-kind-all').click()
		const row = page.locator('#mn-due-board table.mn-table tbody tr', { hasText: marker }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })

		// Empty buckets must be hidden (no "Nothing here." noise).
		await expect(page.locator('.mn-bucket__empty')).toHaveCount(0)
		const emptyBuckets = page.locator('.mn-bucket.is-empty')
		const emptyCount = await emptyBuckets.count()
		for (let i = 0; i < emptyCount; i += 1) {
			await expect(emptyBuckets.nth(i)).toBeHidden()
		}
		await expect(page.locator('.mn-bucket:not(.is-empty)').first()).toBeVisible()
		await expect(page.locator('#mn-due-board table.mn-table.table.table--hover.mn-table--responsive').first()).toBeVisible()
		await expect(page.locator('.mn-visit-card')).toHaveCount(0)

		const completeBtn = row.getByRole('button', { name: /^complete$|^abschließen$/i })
		await expect(completeBtn).toBeVisible()
		const box = await completeBtn.boundingBox()
		expect(box, 'Complete touch target').toBeTruthy()
		expect(box.height).toBeGreaterThanOrEqual(40)

		await completeBtn.click()
		await expect(page.locator('[role="dialog"]')).toHaveCount(0)
		await expect(page.locator('#mn-due-board table.mn-table tbody tr', { hasText: marker })).toHaveCount(0, { timeout: 20_000 })

		await axeMain(page)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('Settings underpages nav + policy controls remain reachable with axe', async ({ page }) => {
		const admin = adminCreds() || primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/settings/policies')
		await expect(page.locator('#mn-settings-policies, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-admin-subnav')).toHaveCount(0)
		await expect(page.locator('#mn-settings-subnav')).toBeVisible()
		await expect(page.getByRole('navigation', { name: /settings|einstellungen/i }).or(page.locator('#mn-settings-subnav')).first()).toBeVisible()
		await expect(page.locator('#mn-policy-fail-blocks-roll')).toBeVisible({ timeout: 30_000 })
		await axeMain(page)
	})

	test('overflow More menu exposes Complete with details + Skip with keyboard', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const marker = `ov-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', { name: marker })
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: marker,
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		}).then((plan) => expectOk(plan, 'plan'))
		await page.goto('/apps/maintenancecheck/')
		await page.locator('#mn-due-kind-all').click()
		const row = page.locator('#mn-due-board table.mn-table tbody tr', { hasText: marker }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })

		// Bachus: one primary only — Create work order is under More, not a second button.
		await expect(row.getByRole('button', { name: /^complete$|^abschließen$/i })).toBeVisible()
		await expect(row.getByRole('button', { name: /create work order|arbeitsauftrag anlegen/i })).toHaveCount(0)

		const more = row.getByRole('button', { name: /more actions|more|mehr|weitere aktionen/i })
		await expect(more).toBeVisible()
		await more.click()
		const menu = page.locator('.mn-overflow__menu:not([hidden])').first()
		await expect(menu).toBeVisible({ timeout: 10_000 })
		await expect(menu.getByRole('menuitem', { name: /create work order|arbeitsauftrag anlegen/i })).toBeVisible()
		await expect(menu.getByRole('menuitem', { name: /complete with details|mit details abschließen/i })).toBeVisible()
		await expect(menu.getByRole('menuitem', { name: /^skip$|^überspringen$/i })).toBeVisible()
		await expect(menu.getByRole('menuitem', { name: /skip with reason|mit grund überspringen/i })).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.locator('.mn-overflow__menu:not([hidden])')).toHaveCount(0)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('Exceptions: no soft-band lead, chip filter aria + empty CTA, axe', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/exceptions')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-exceptions-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await expect(page.locator('#mn-exceptions-title')).toBeAttached()
		await expect(page.locator('#mn-exceptions-title')).toHaveClass(/mn-sr-only/)
		await expect(page.locator('.mn-card--table-solo .mn-card__header')).toHaveCount(0)
		await expect(page.locator('.mn-card--table-solo .mn-card__lead')).toHaveCount(0)
		await expect(page.getByText(/work that is blocked, overdue/i)).toHaveCount(0)

		const toolbar = page.locator('#mn-exceptions-toolbar')
		await expect(toolbar).toBeVisible()
		const allChip = toolbar.getByRole('button', { name: /^all$|^alle$/i })
		await expect(allChip).toHaveAttribute('aria-pressed', 'true')
		const allBox = await allChip.boundingBox()
		expect(allBox.height).toBeGreaterThanOrEqual(40)

		const blocked = toolbar.getByRole('button', { name: /^blocked$|^blockiert$/i })
		await blocked.click()
		await expect(blocked).toHaveAttribute('aria-pressed', 'true')
		await expect(allChip).toHaveAttribute('aria-pressed', 'false')
		await expect(page.locator('#mn-exceptions-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		const empty = page.locator('#mn-exceptions-board .mn-empty')
		const table = page.locator('#mn-exceptions-board table.mn-table')
		await expect(empty.or(table).first()).toBeVisible({ timeout: 15_000 })
		if (await empty.count()) {
			await expect(page.getByText(/no blocked|keine blockierten/i)).toBeVisible()
			const cta = empty.getByRole('link', { name: /open work orders|arbeitsaufträge öffnen/i })
			await expect(cta).toBeVisible()
			const ctaBox = await cta.boundingBox()
			expect(ctaBox.height).toBeGreaterThanOrEqual(40)
		}

		await axeMain(page)
	})

	test('Day tours: empty CTA, date nav touch targets, create → open stop, axe', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)

		const stamp = Date.now()
		const marker = `tour-${stamp}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		expectOk(types, 'types')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		expectOk(maint, 'maint')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `Bachus Tour ${marker}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: `Van ${marker}`,
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const serverToday = await page.goto('/apps/maintenancecheck/').then(async () => {
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
			return page.locator('#app-content').getAttribute('data-mn-server-today')
		})
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')
		const wo = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/work-orders`, {
			procedureSkipped: true,
			procedureSkipReason: 'Bachus tour journey',
		})
		expectOk(wo, 'wo')

		// Isolate empty state on a unique far-future date (avoid leftover seeds).
		const emptyDate = `2099-${String((stamp % 12) + 1).padStart(2, '0')}-${String((stamp % 27) + 1).padStart(2, '0')}`
		await page.goto('/apps/maintenancecheck/tours')
		await expect(page.locator('#mn-tours-board')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-tours-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('.mn-tours-toolbar__date').evaluate((el, value) => {
			el.value = value
			el.dispatchEvent(new Event('change', { bubbles: true }))
		}, emptyDate)
		await expect(page.locator('.mn-tours-toolbar__date')).toHaveValue(emptyDate)
		await expect(page.locator('#mn-tours-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('#mn-tours-board .mn-empty')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByText(/no tours on this day|keine touren an diesem tag/i)).toBeVisible()
		await expect(page.getByText(/plan who drives|plane, wer heute fährt/i)).toBeVisible()
		await expect(page.getByText(/office can plan day tours under planning/i)).toHaveCount(0)

		const createInEmpty = page.locator('#mn-tours-board .mn-empty').getByRole('button', { name: /create tour|tour anlegen/i })
		await expect(createInEmpty).toBeVisible()
		const createBox = await createInEmpty.boundingBox()
		expect(createBox, 'Create tour empty CTA touch target').toBeTruthy()
		expect(createBox.height).toBeGreaterThanOrEqual(40)

		const prevNav = page.getByRole('button', { name: /previous day|vorheriger tag/i })
		const nextNav = page.getByRole('button', { name: /next day|nächster tag/i })
		await expect(prevNav).toBeVisible()
		await expect(nextNav).toBeVisible()
		const prevBox = await prevNav.boundingBox()
		expect(prevBox.height).toBeGreaterThanOrEqual(40)

		await axeMain(page)

		const tour = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/tours', {
			tourDate: emptyDate,
			techUid: admin.username,
		})
		expectOk(tour, 'tour')
		expect(tour.data.techDisplayName).toBeTruthy()
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/tours/${tour.data.id}/stops`, {
			workOrderId: wo.data.id,
		}).then((r) => expectOk(r, 'add stop'))

		await page.reload()
		await expect(page.locator('#mn-tours-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await page.locator('.mn-tours-toolbar__date').evaluate((el, value) => {
			el.value = value
			el.dispatchEvent(new Event('change', { bubbles: true }))
		}, emptyDate)
		await expect(page.locator('#mn-tours-board')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('.mn-tour').first()).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.mn-tour__title').first()).toBeVisible()
		const openStop = page.locator('.mn-tour').first().getByRole('link', { name: /^open$|^öffnen$/i }).first()
		await expect(openStop).toBeVisible()
		const openBox = await openStop.boundingBox()
		expect(openBox.height).toBeGreaterThanOrEqual(40)
		await openStop.click()
		await expect(page).toHaveURL(new RegExp(`/work-orders/${wo.data.id}`))
		await expect(page.locator('#mn-wo-detail')).toBeVisible({ timeout: 30_000 })
		await axeMain(page)

		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/tours/${tour.data.id}`)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('Visits list: no duplicate title, one primary action, axe clean', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const marker = `vt-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', { name: marker })
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: marker,
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		}).then((plan) => expectOk(plan, 'plan'))

		await page.goto('/apps/maintenancecheck/visits')
		await expect(page.locator('#mn-visit-list')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-visit-list')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('.mn-card--table-solo .mn-card__header')).toHaveCount(0)
		await expect(page.locator('.mn-card__lead')).toHaveCount(0)

		const row = page.locator('#mn-visit-list table.mn-table tbody tr', { hasText: marker }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		const primary = row.locator('.mn-table-primary, .mn-table-link').first()
		await expect(primary).toHaveText(marker)
		await expect(primary).not.toHaveText(new RegExp(`${marker}\\s*[—–-]\\s*${marker}`))

		const primaryComplete = row.getByRole('button', { name: /^complete$|^abschließen$/i })
		await expect(primaryComplete).toBeVisible()
		await expect(row.getByRole('button', { name: /create work order|arbeitsauftrag anlegen/i })).toHaveCount(0)

		const badge = row.locator('.mn-badge').first()
		await expect(badge).toBeVisible()
		await expect(badge.locator('.mn-badge__dot')).toHaveCount(0)
		await expect(badge.locator('.mn-badge__icon')).toHaveCount(1)

		await axeMain(page)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('Work order detail: one job sheet, hero CTA, More panel, axe + keyboard', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const marker = `wo-ux-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const procs = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/procedures?limit=1&offset=0')
		expectOk(types, 'types')
		expectOk(maint, 'maint')
		expectOk(procs, 'procs')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', { name: marker })
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: marker,
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')
		const wo = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/work-orders`, {
			procedureId: procs.data.data[0].id,
		})
		expectOk(wo, 'wo')

		await page.goto(`/apps/maintenancecheck/work-orders/${wo.data.id}`)
		await expect(page.locator('#mn-wo-detail')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-wo-detail')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('#mn-wo-detail .mn-wo-sheet')).toBeVisible()
		await expect(page.locator('#mn-wo-detail .mn-wo-hero')).toBeVisible()
		await expect(page.locator('#mn-wo-detail .mn-wo-hero__title')).toBeVisible()
		await expect(page.locator('#mn-wo-detail .mn-wo-checklist')).toBeVisible()
		await expect(page.locator('#mn-wo-detail .mn-wo-more')).toBeVisible()
		await expect(page.locator('#mn-wo-detail .mn-wo-evidence')).toHaveCount(0)
		await expect(page.getByRole('heading', { name: /^comments$/i })).toHaveCount(0)
		expect(await page.locator('#mn-wo-detail > .mn-card').count()).toBe(1)

		const primary = page.locator('#mn-wo-detail .mn-wo-hero__primary').first()
		await expect(primary).toBeVisible()
		const primaryBox = await primary.boundingBox()
		expect(primaryBox.height).toBeGreaterThanOrEqual(48)

		const more = page.locator('#mn-wo-detail .mn-wo-more').first()
		const summary = more.locator('summary').first()
		await expect(summary).toBeVisible()
		await expect(summary).toHaveText(/^(Details|Needs setup|Einrichtung nötig)$/i)
		const summaryBox = await summary.boundingBox()
		expect(summaryBox.height).toBeGreaterThanOrEqual(44)
		if (!(await more.evaluate((el) => el.open))) {
			await summary.focus()
			await page.keyboard.press('Enter')
		}
		await expect(more).toHaveAttribute('open', '')
		await expect(page.getByRole('heading', { name: /kit \/ parts/i }).first()).toBeVisible()
		await axeMain(page)

		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${wo.data.id}/transition`, { to: 'ready' })
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${wo.data.id}/transition`, { to: 'in_progress' })
		await page.reload()
		await expect(page.locator('#mn-wo-detail')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })
		await expect(page.locator('#mn-wo-detail .mn-wo-evidence')).toBeVisible()
		await expect(page.getByRole('heading', { name: /^checklist$/i }).first()).toBeVisible()
		await expect(page.getByRole('heading', { name: /^evidence$/i }).first()).toBeVisible()
		expect(await page.locator('#mn-wo-detail > .mn-card').count()).toBe(1)
		await expect(page.getByRole('button', { name: /complete|abschließen/i }).first()).toBeVisible()
		await axeMain(page)

		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})
})
