// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Proves the bug class that shipped: job-pack PDF opened via <a href>
 * (session cookie, NO requesttoken header) must return application/pdf,
 * not "CSRF check failed".
 *
 * Mutations without requesttoken must still be rejected.
 */

async function apiWithCsrf(page, method, path, body) {
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
				data = { raw: text.slice(0, 200) }
			}
			return { status: res.status, data, text: text.slice(0, 80) }
		},
		{ method, path, body },
	)
}

test.describe('Download CSRF posture (href PDFs)', () => {
	test('job-pack PDF works without requesttoken header (cookie session only)', async ({ page }) => {
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_ADMIN_* or NC_E2E_*')

		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const stamp = Date.now()
		const types = await apiWithCsrf(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		expect([200, 201].includes(types.status)).toBeTruthy()
		const maint = await apiWithCsrf(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=1&offset=0')
		expect([200, 201].includes(maint.status)).toBeTruthy()
		const procs = await apiWithCsrf(page, 'GET', '/index.php/apps/maintenancecheck/api/procedures?limit=1&offset=0')
		expect([200, 201].includes(procs.status)).toBeTruthy()

		const customer = await apiWithCsrf(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `CSRF-PDF ${stamp}`,
		})
		expect([200, 201].includes(customer.status), JSON.stringify(customer.data)).toBeTruthy()

		const equipment = await apiWithCsrf(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: 'CSRF PDF unit',
			customerId: customer.data.id,
			equipTypeId: types.data.data[0].id,
		})
		expect([200, 201].includes(equipment.status)).toBeTruthy()

		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const plan = await apiWithCsrf(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/plans`, {
			maintTypeId: maint.data.data[0].id,
			intervalUnit: 'week',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expect([200, 201].includes(plan.status)).toBeTruthy()

		const wo = await apiWithCsrf(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${plan.data.openVisit.id}/work-orders`, {
			procedureId: procs.data.data[0].id,
		})
		expect([200, 201].includes(wo.status), JSON.stringify(wo.data)).toBeTruthy()

		const pdfPath = `/index.php/apps/maintenancecheck/api/work-orders/${wo.data.id}/pdf/job-pack`
		// Cookie session only — deliberately omit requesttoken (simulates <a href>).
		const bare = await page.request.get(pdfPath)
		const body = await bare.body()
		const textHead = body.toString('utf8', 0, Math.min(80, body.length))
		expect(bare.status(), textHead).toBe(200)
		expect(bare.headers()['content-type'] || '').toContain('application/pdf')
		expect(textHead.startsWith('%PDF')).toBeTruthy()
		expect(textHead.toLowerCase()).not.toContain('csrf')

		// Control: state-changing POST without requesttoken must still fail.
		const mute = await page.request.post('/index.php/apps/maintenancecheck/api/customers', {
			data: { name: `CSRF-should-fail ${stamp}` },
			headers: { 'Content-Type': 'application/json' },
		})
		expect(mute.status(), await mute.text()).not.toBe(200)
		expect(mute.status()).not.toBe(201)
		const muteText = (await mute.text()).toLowerCase()
		expect(muteText.includes('csrf') || mute.status() === 412 || mute.status() === 401 || mute.status() === 403).toBeTruthy()

		await apiWithCsrf(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customer.data.id}?force=1`)
	})
})
