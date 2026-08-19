// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv, primaryCreds, adminCreds } from './helpers/auth.js'

/**
 * Settings admin sections render only for app admins (NC admin / app_admin list).
 * Due + customers are reachable for any L2-allowed user.
 */
const a11yRoutes = [
	{ path: '/apps/maintenancecheck/', ready: '#mn-due-board, .mn-empty', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/customers', ready: '#mn-customer-list, .mn-empty', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/equipment', ready: '#mn-equipment-list, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/visits', ready: '#mn-visit-list, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/catalogs', ready: '#mn-equip-types, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/work-orders', ready: '#mn-wo-list, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/dispatch', ready: '#mn-dispatch-board, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/tours', ready: '#mn-tours-board, .mn-empty, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/kpi', ready: '#mn-kpi-snapshot, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/exceptions', ready: '#mn-exceptions-board, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/access', ready: '#mn-settings-access, .mn-empty', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/roles', ready: '#mn-settings-roles, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/inventory', ready: '#mn-settings-inventory-flange, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/policies', ready: '#mn-settings-policies, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/capacity', ready: '#mn-settings-capacity, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/license', ready: '#mn-settings-license, #mn-main-content', creds: 'E2E' },
	{ path: '/apps/maintenancecheck/settings/support', ready: '#mn-support-us, [data-support-us="1"]', creds: 'E2E' },
]

test('a11y smoke WCAG 2.1 AA: work-order detail', async ({ page }) => {
	const creds = credsFromEnv('E2E') || primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_USER / NC_E2E_PASS (or NC_ADMIN_*)')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/work-orders')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	// Resolve a real WO id via API (pool/office sees all). Prefer OC.requestToken —
	// NC 32+ puts the token on <head data-requesttoken>, not a meta[name=requesttoken].
	const list = await page.evaluate(async () => {
		const token =
			(typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| document.querySelector('head > meta[name="requesttoken"]')?.getAttribute('content')
			|| ''
		const res = await fetch('/index.php/apps/maintenancecheck/api/work-orders?limit=1&offset=0', {
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			},
		})
		return res.json()
	})
	let id = list?.data?.[0]?.id

	// Bootstrap a preventive WO from an open visit when the instance is empty.
	if (!id) {
		const boot = await page.evaluate(async () => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				'Content-Type': 'application/json',
				requesttoken: token,
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			}
			const due = await fetch('/index.php/apps/maintenancecheck/api/visits/due', {
				credentials: 'same-origin',
				headers,
			}).then((r) => r.json())
			const buckets = due?.data || due || {}
			const visit =
				(buckets.overdue && buckets.overdue[0])
				|| (buckets.today && buckets.today[0])
				|| (buckets.next7 && buckets.next7[0])
				|| null
			if (!visit?.id) {
				return null
			}
			const procs = await fetch('/index.php/apps/maintenancecheck/api/procedures?limit=5&offset=0', {
				credentials: 'same-origin',
				headers,
			}).then((r) => r.json())
			const procedureId = procs?.data?.[0]?.id
			const body = procedureId
				? { procedureId }
				: { procedureSkipped: true, procedureSkipReason: 'A11y smoke bootstrap without procedure' }
			const wo = await fetch(`/index.php/apps/maintenancecheck/api/visits/${visit.id}/work-orders`, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify(body),
			}).then((r) => r.json())
			return wo?.id || wo?.data?.id || null
		})
		id = boot
	}
	test.skip(!id, 'No work order available for detail a11y smoke')

	await page.goto(`/apps/maintenancecheck/work-orders/${id}`)
	const main = page.locator('#mn-main-content')
	await expect(main).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('#mn-wo-detail, #mn-main-content h1').first()).toBeVisible({ timeout: 30_000 })

	const results = await new AxeBuilder({ page })
		.include('#mn-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()

	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})

for (const route of a11yRoutes) {
	test(`a11y smoke WCAG 2.1 AA: ${route.path}`, async ({ page }) => {
		const creds = credsFromEnv(route.creds) || primaryCreds()
		test.skip(!creds, `Requires NC_${route.creds}_USER / NC_${route.creds}_PASS (or NC_E2E_* / NC_ADMIN_*)`)

		await login(page, creds)
		await page.goto(route.path)

		const main = page.locator('#mn-main-content')
		await expect(main, `expected MaintenanceCheck shell at ${route.path} (is the app enabled?)`).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 })

		// Scope to our shell — Nextcloud chrome is out of app ownership.
		const results = await new AxeBuilder({ page })
			.include('#mn-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()

		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
}
