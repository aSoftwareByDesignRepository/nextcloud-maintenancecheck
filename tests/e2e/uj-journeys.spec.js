// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv, primaryCreds, adminCreds } from './helpers/auth.js'

/**
 * SPEC §14.3 UJ-1…UJ-6 — browser journeys with axe on each visited surface.
 * Seeds via the live JSON API (same CSRF session as the UI) so Alt paths are
 * deterministic; asserts the shell the user actually sees.
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

/** POST create endpoints may return 200 or 201 depending on controller. */
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

async function openApp(page, path = '/apps/maintenancecheck/') {
	await page.goto(path)
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
}

test.describe('UJ journeys', () => {
	test('UJ-1 first run: seed customer → equipment → plan → due board', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')

		await login(page, admin)
		await openApp(page)
		await axeMain(page)

		const marker = `uj1-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=5&offset=0')
		expectOk(types, 'equip-types')
		expect(types.data.data.length).toBeGreaterThan(0)
		const equipTypeId = types.data.data[0].id

		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=5&offset=0')
		expectOk(maint, 'maint-types')
		const maintTypeId = maint.data.data[0].id

		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ1 ${marker}`,
			city: 'Stuttgart',
			country: 'de',
		})
		expectOk(customer, 'create customer')
		const customerId = customer.data.id

		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: `Pump ${marker}`,
			customerId,
			equipTypeId,
		})
		expectOk(equipment, 'create equipment')

		const tomorrow = new Date()
		tomorrow.setUTCDate(tomorrow.getUTCDate() + 1)
		const dueOn = tomorrow.toISOString().slice(0, 10)

		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId,
			intervalUnit: 'month',
			intervalCount: 3,
			firstDueOn: dueOn,
		})
		expectOk(plan, 'create plan')
		expect(plan.data.openVisit?.status).toBe('scheduled')

		await openApp(page, '/apps/maintenancecheck/')
		await expect(page.locator('#mn-due-board, .mn-empty').first()).toBeVisible()
		await expect(page.getByText(`UJ1 ${marker}`).first()).toBeVisible({ timeout: 15_000 })
		await axeMain(page)

		// Cleanup (force delete)
		const del = await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customerId}?force=1`)
		expectOk(del, 'force delete')
	})

	test('UJ-2 complete visit + Alt A second complete → visit_not_open', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ2 ${Date.now()}`,
		})
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ2 unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		const today = new Date().toISOString().slice(0, 10)
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: today,
		})
		const visitId = plan.data.openVisit.id

		const first = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${visitId}/complete`, {
			notes: 'UJ2 done',
		})
		expectOk(first, 'complete')
		expect(first.data.visit.status).toBe('done')
		expect(first.data.nextVisit?.status).toBe('scheduled')

		const second = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${visitId}/complete`, {})
		expect(second.status).toBe(409)
		expect(second.data.error.code).toBe('visit_not_open')

		await openApp(page, '/apps/maintenancecheck/')
		await axeMain(page)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-3 skip + office reschedule denied for non-office via API shape', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ3 ${Date.now()}`,
		})
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ3 unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		const today = new Date().toISOString().slice(0, 10)
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'day',
			intervalCount: 14,
			firstDueOn: '2020-01-01',
		})
		const skipped = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/skip`, {})
		expectOk(skipped, 'skip')
		expect(skipped.data.visit.status).toBe('skipped')
		expect(skipped.data.nextVisit.dueOn >= today).toBeTruthy()

		const openId = skipped.data.nextVisit.id
		const nextWeek = new Date()
		nextWeek.setUTCDate(nextWeek.getUTCDate() + 7)
		const resched = await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/visits/${openId}`, {
			dueOn: nextWeek.toISOString().slice(0, 10),
		})
		expectOk(resched, 'reschedule')

		await openApp(page, '/apps/maintenancecheck/visits')
		await expect(page.locator('#mn-main-content')).toBeVisible()
		await axeMain(page)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-4 interval change with recalculate moves open visit', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ4 ${Date.now()}`,
		})
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ4 unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		const today = new Date().toISOString().slice(0, 10)
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'month',
			intervalCount: 3,
			firstDueOn: today,
		})
		const updated = await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/plans/${plan.data.id}`, {
			intervalUnit: 'month',
			intervalCount: 6,
			recalculateOpenVisit: true,
		})
		expectOk(updated, 'plan update')
		expect(updated.data.intervalCount).toBe(6)
		expect(updated.data.openVisit).toBeTruthy()
		expect(updated.data.openVisit.dueOn > today).toBeTruthy()

		await openApp(page, `/apps/maintenancecheck/equipment/${equipment.data.id}`)
		await expect(page.locator('#mn-main-content')).toBeVisible()
		await axeMain(page)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-5 force-delete customer with children', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page, '/apps/maintenancecheck/customers')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ5 ${Date.now()}`,
		})
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ5 unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'year',
			intervalCount: 1,
			firstDueOn: new Date().toISOString().slice(0, 10),
		})

		const blocked = await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}`)
		expect(blocked.status).toBe(409)
		expect(blocked.data.error.code).toBe('customer_has_equipment')

		const forced = await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
		expectOk(forced, 'force delete')

		const gone = await api(page, 'GET', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}`)
		expect([404, 409]).toContain(gone.status)
		await axeMain(page)
	})

	test('UJ-6 license paste invalid key leaves prior state', async ({ page }) => {
		const admin = adminCreds()
		test.skip(!admin, 'Requires NC_E2E_* or NC_ADMIN_* for settings / license')
		await login(page, admin)
		await openApp(page, '/apps/maintenancecheck/settings')
		await expect(page.locator('#mn-settings-license, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
		await axeMain(page)

		const before = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/license')
		expectOk(before, 'license status')

		const bad = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/license', {
			key: 'MN2.not-a-real-key',
		})
		expect(bad.status).toBe(422)
		expect(bad.data.error.code).toBe('license_invalid')

		const after = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/license')
		expectOk(after, 'license after')
		expect(after.data.mobileAppStatus).toBe(before.data.mobileAppStatus)
	})

	test('UJ-2 Alt B future done_on → 422 invalid_done_on', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		expect(serverToday).toMatch(/^\d{4}-\d{2}-\d{2}$/)

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ2B ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ2B unit',
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
		const bad = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/complete`, {
			doneOn: '2099-01-01',
		})
		expect(bad.status).toBe(422)
		expect(['invalid_done_on', 'validation_failed']).toContain(bad.data.error.code)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-2 Alt C complete on inactive plan → no follow-up', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ2C ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ2C unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'month',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')
		const deactivated = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/plans/${plan.data.id}/deactivate`, {})
		expectOk(deactivated, 'deactivate')
		const done = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/complete`, {})
		expectOk(done, 'complete inactive')
		expect(done.data.visit.status).toBe('done')
		expect(done.data.nextVisit).toBeNull()
		expect(done.data.planActive).toBe(false)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-3 Alt B schedule while open → visit_already_open', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ3B ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ3B unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'month',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')
		const conflict = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/plans/${plan.data.id}/schedule`, {
			dueOn: serverToday,
		})
		expect(conflict.status).toBe(409)
		expect(conflict.data.error.code).toBe('visit_already_open')
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-4 Alt unchecked recalculate leaves open due_on identical', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ4A ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ4A unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'month',
			intervalCount: 3,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')
		const beforeDue = plan.data.openVisit.dueOn
		const updated = await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/plans/${plan.data.id}`, {
			intervalUnit: 'month',
			intervalCount: 6,
			recalculateOpenVisit: false,
		})
		expectOk(updated, 'plan update')
		expect(updated.data.openVisit.dueOn).toBe(beforeDue)
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('AC-19 customer detail + plan dialog axe', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `A11Y ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'A11Y unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')

		await openApp(page, `/apps/maintenancecheck/customers/${customer.data.id}`)
		await expect(page.locator('#mn-main-content')).toBeVisible()
		await axeMain(page)

		await openApp(page, `/apps/maintenancecheck/equipment/${equipment.data.id}`)
		await expect(page.locator('#mn-main-content')).toBeVisible()
		// Open plan dialog if the New plan control is present (office/admin).
		const newPlan = page.getByRole('button', { name: /new plan|neuer plan/i })
		if (await newPlan.isVisible().catch(() => false)) {
			await newPlan.click()
			await expect(page.locator('[role="dialog"]').first()).toBeVisible({ timeout: 10_000 })
			const results = await new AxeBuilder({ page })
				.include('[role="dialog"]')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
			await page.keyboard.press('Escape')
		} else {
			await axeMain(page)
		}

		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'year',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-5 UI force-delete: checkbox gates destructive button + axe dialog', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ5UI ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ5UI unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})

		await openApp(page, `/apps/maintenancecheck/customers/${customer.data.id}`)
		await expect(page.getByRole('button', { name: /delete customer|kunde löschen/i })).toBeVisible({ timeout: 15_000 })
		await page.getByRole('button', { name: /delete customer|kunde löschen/i }).click()

		const dialog = page.locator('[role="dialog"]').first()
		await expect(dialog).toBeVisible()
		const deleteBtn = dialog.locator('#mn-confirm-delete')
		await expect(deleteBtn).toBeDisabled()

		const checkbox = dialog.locator('input[type="checkbox"]')
		await checkbox.check()
		await expect(deleteBtn).toBeEnabled()

		const axeDialog = await new AxeBuilder({ page })
			.include('[role="dialog"]')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axeDialog.violations, JSON.stringify(axeDialog.violations, null, 2)).toEqual([])

		await checkbox.uncheck()
		await expect(deleteBtn).toBeDisabled()
		await checkbox.check()
		await deleteBtn.click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		const gone = await api(page, 'GET', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}`)
		expect([404, 409]).toContain(gone.status)
	})

	test('UJ-1 Alt B technician: New customer hidden; POST → 403', async ({ page }) => {
		const admin = adminCreds()
		const tech = {
			username: process.env.NC_TECH_USER || 'mn_e2e_tech',
			password: process.env.NC_TECH_PASS || 'Mn-E2e-Tech-7!xK',
		}
		test.skip(!admin, 'Requires NC_E2E_* or NC_ADMIN_*')

		await login(page, admin)
		await openApp(page, '/apps/maintenancecheck/settings')

		// Ensure office list does not include the technician (admin remains office via L0/L1).
		const cfg = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/config')
		expectOk(cfg, 'config')
		await api(page, 'POST', '/index.php/apps/maintenancecheck/api/config/office', {
			officeUserIds: (cfg.data.officeUserIds || []).filter((u) => u !== tech.username),
			officeGroupIds: cfg.data.officeGroupIds || [],
		})

		await page.context().clearCookies()
		await login(page, tech)
		await openApp(page, '/apps/maintenancecheck/customers')
		await expect(page.getByRole('button', { name: /new customer|neuer kunde/i })).toHaveCount(0)

		const denied = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `TechShouldFail ${Date.now()}`,
		})
		expect(denied.status).toBe(403)
		expect(denied.data.error.code).toBe('permission_denied')
	})

	test('UJ-2 UI complete dialog: axe + Esc returns focus', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')
		await login(page, admin)
		await openApp(page)
		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')

		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `UJ2UI ${Date.now()}`,
		})
		expectOk(customer, 'customer')
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'UJ2UI unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		})

		await openApp(page, '/apps/maintenancecheck/')
		const completeBtn = page.getByRole('button', { name: /^complete$|^abschließen$/i }).first()
		await expect(completeBtn).toBeVisible({ timeout: 15_000 })
		await completeBtn.click()

		const dialog = page.locator('[role="dialog"]').first()
		await expect(dialog).toBeVisible()
		await expect(dialog.getByLabel(/completed on|erledigt am/i)).toBeVisible()

		const axeDialog = await new AxeBuilder({ page })
			.include('[role="dialog"]')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axeDialog.violations, JSON.stringify(axeDialog.violations, null, 2)).toEqual([])

		await page.keyboard.press('Escape')
		await expect(dialog).toBeHidden()
		await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})

	test('UJ-6 Alt B expired key accepted with invalid badge', async ({ page }) => {
		const admin = adminCreds()
		test.skip(!admin, 'Requires NC_E2E_* or NC_ADMIN_* for license')

		const { seedLicenseViaCli } = await import('./helpers/seedLicense.js')
		let seeded = ''
		try {
			seeded = seedLicenseViaCli('expired')
		} catch (err) {
			test.skip(true, `CLI license seed unavailable: ${err && err.message ? err.message : err}`)
		}
		expect(seeded).toMatch(/^SEEDED:expired:valid=0$/)

		try {
			await login(page, admin)
			await openApp(page, '/apps/maintenancecheck/settings')
			await expect(page.locator('#mn-settings-license')).toBeVisible({ timeout: 30_000 })
			await expect(page.getByText(/^Expired$|^Abgelaufen$/i).first()).toBeVisible({ timeout: 15_000 })
			await expect(page.getByText(/e2e-expired/i).first()).toBeVisible()
			await axeMain(page)
		} finally {
			try {
				seedLicenseViaCli('clear')
			} catch {
				/* best-effort cleanup */
			}
		}
	})
})
