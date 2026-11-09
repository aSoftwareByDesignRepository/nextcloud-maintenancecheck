// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, primaryCreds } from './helpers/auth.js'

/**
 * ATLAS Round-1 hardening probes — executed proofs, not reasoning.
 *
 * Lens 1: concurrent mutating requests, ground truth = response + follow-up read.
 * Lens 2: IDOR / role-boundary probes over the real session-authenticated API.
 *
 * Every call goes through window.MnApp.__dom.api — the production path
 * (handles requesttoken, error envelopes, status codes on ApiError).
 */

const techCreds = credsFromEnv('TECH')
const officeCreds = credsFromEnv('ADMIN') || primaryCreds()

/** Evaluate a dom.api call in the page, returning {status, data} without throwing. */
async function apiCall(page, method, url, body) {
	return page.evaluate(async ({ method, url, body }) => {
		try {
			const data = await window.MnApp.__dom.api(method, url, body)
			return { status: 200, data }
		} catch (e) {
			return { status: (e && e.status) || 0, error: String((e && (e.code || e.message)) || e) }
		}
	}, { method, url, body })
}

test.describe('Atlas Lens 1: concurrency', () => {
	test('two concurrent WO creates: no number collision, both persist or one 4xx', async ({ page }) => {
		test.skip(!officeCreds, 'Requires admin creds')
		await login(page, officeCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await page.waitForSelector('#mn-cal-board', { timeout: 30_000 })

		const customer = await apiCall(page, 'POST', '/apps/maintenancecheck/api/customers', { name: `RaceE2E ${Date.now()}` })
		test.skip(customer.status !== 200, `customer seed failed (${customer.status})`)
		const customerId = customer.data.id

		const mk = (n) => apiCall(page, 'POST', '/apps/maintenancecheck/api/work-orders', {
			customerId, title: `Race WO ${n}`, kind: 'preventive', dueOn: '2026-11-20',
		})
		const [r1, r2] = await Promise.all([mk(1), mk(2)])

		// Acceptable end states: both succeed with distinct numbers/ids, or one
		// is rejected — never both succeeding with the same number/id.
		const ok = [r1, r2].filter((r) => r.status === 200)
		if (ok.length === 2) {
			expect(ok[0].data.id).not.toBe(ok[1].data.id)
			expect(ok[0].data.number).not.toBe(ok[1].data.number)
		} else {
			// A rejected second write is a legal outcome; it must be a clean 4xx.
			const rejected = [r1, r2].find((r) => r.status !== 200)
			expect(rejected.status).toBeGreaterThanOrEqual(400)
			expect(rejected.status).toBeLessThan(500)
		}
	})

	test('two concurrent updates to the same WO leave a consistent row', async ({ page }) => {
		test.skip(!officeCreds, 'Requires admin creds')
		await login(page, officeCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await page.waitForSelector('#mn-cal-board', { timeout: 30_000 })

		const customer = await apiCall(page, 'POST', '/apps/maintenancecheck/api/customers', { name: `RaceUpd ${Date.now()}` })
		test.skip(customer.status !== 200, 'customer seed failed')
		const created = await apiCall(page, 'POST', '/apps/maintenancecheck/api/work-orders', {
			customerId: customer.data.id, title: 'Race update base', kind: 'preventive', dueOn: '2026-11-20',
		})
		test.skip(created.status !== 200, 'WO seed failed')
		const woId = created.data.id

		const upd = (title) => apiCall(page, 'PUT', `/apps/maintenancecheck/api/work-orders/${woId}`, { title })
		const [u1, u2] = await Promise.all([upd('Writer A'), upd('Writer B')])
		// Both writers may succeed (last-writer-wins is a documented REST shape)
		// or one may 409 — never a 5xx and never a torn row.
		for (const u of [u1, u2]) {
			expect(u.status === 200 || (u.status >= 400 && u.status < 500)).toBeTruthy()
		}
		const after = await apiCall(page, 'GET', `/apps/maintenancecheck/api/work-orders/${woId}`)
		expect(after.status).toBe(200)
		expect(['Writer A', 'Writer B']).toContain(after.data.title)
	})
})

test.describe('Atlas Lens 2: authz / IDOR', () => {
	test('technician cannot read another tech\'s assigned WO (IDOR)', async ({ page }) => {
		test.skip(!techCreds || !officeCreds, 'Requires both role creds')
		// Office finds an existing WO assigned to someone other than the tech —
		// create() ignores primaryUserId, so we must locate an assigned row.
		await login(page, officeCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await page.waitForSelector('#mn-cal-board', { timeout: 30_000 })
		const list = await apiCall(page, 'GET', '/apps/maintenancecheck/api/work-orders?limit=200')
		test.skip(list.status !== 200, 'WO list failed')
		const techUid = techCreds.username
		const assigned = (list.data.data || list.data || []).find(
			(w) => w.primaryUserId && w.primaryUserId !== techUid,
		)
		test.skip(!assigned, 'No assigned WO in fixture to probe')
		const woId = assigned.id

		// Tech session must not see it — 403 or 404, never 200.
		const techContext = await page.context().browser().newContext()
		const techPage = await techContext.newPage()
		await login(techPage, techCreds)
		await techPage.goto('/apps/maintenancecheck/calendar')
		await techPage.waitForSelector('#mn-cal-board', { timeout: 30_000 })
		const res = await apiCall(techPage, 'GET', `/apps/maintenancecheck/api/work-orders/${woId}`)
		await techContext.close()
		expect([403, 404]).toContain(res.status)
	})

	test('technician blocked from office-only mutation endpoints', async ({ page }) => {
		test.skip(!techCreds, 'Requires tech creds')
		await login(page, techCreds)
		await page.goto('/apps/maintenancecheck/calendar')
		await page.waitForSelector('#mn-cal-board', { timeout: 30_000 })
		// Probe representative office-only writes — all must 403, none 2xx.
		const probes = [
			apiCall(page, 'POST', '/apps/maintenancecheck/api/customers', { name: 'IdorX' }),
			apiCall(page, 'POST', '/apps/maintenancecheck/api/tours', { tourDate: '2026-11-20', techUid: 'x' }),
		]
		for (const p of probes) {
			const r = await p
			expect([401, 403, 404]).toContain(r.status)
		}
	})

	test('unauthenticated /api/calendar and WO list are closed', async ({ request }) => {
		for (const path of [
			'/apps/maintenancecheck/api/calendar?from=2026-11-01&to=2026-11-30',
			'/apps/maintenancecheck/api/work-orders',
		]) {
			const res = await request.get(path)
			expect([401, 403, 412]).toContain(res.status())
		}
	})
})
