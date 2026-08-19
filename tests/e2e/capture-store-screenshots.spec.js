// @ts-check
/**
 * One-shot App Store screenshot capture for MaintenanceCheck.
 * Seeds a realistic German field-ops demo via the live API, dismisses
 * Quick-start tips, purges obvious test junk, then shoots key surfaces
 * at DutyCheck-comparable desktop size (1920×1040).
 *
 * Run:
 *   npx playwright test tests/e2e/capture-store-screenshots.spec.js --project=chromium-store
 */
import { test, expect } from '@playwright/test'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login, primaryCreds } from './helpers/auth.js'

const outDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../screenshots')
const MARKER = 'Demo RheinMain'

const QUICKSTART_KEYS = [
	'due_quickstart_v1',
	'due_quickstart_v2',
	'due_quickstart_v3',
	'customers_quickstart_v1',
	'customer_detail_quickstart_v1',
	'equipment_quickstart_v1',
	'visits_quickstart_v3',
	'work_orders_quickstart_v1',
	'work_order_detail_quickstart_v3',
	'dispatch_quickstart_v2',
	'tours_quickstart_v3',
	'kpi_quickstart_v1',
	'exceptions_quickstart_v1',
	'catalogs_quickstart_v1',
]

function isJunkName(name) {
	const n = String(name || '')
	if (n.includes(MARKER) || n.startsWith('Demo ')) return false
	return /UJ\d|UJTour|W[0-9]|E2E |E2E_|AX\d|mn_|Gate Ladder|Done ladder|TechShould|Bachus|PMatrix|smoke_|W6 |W7 |uj1-|uj2-|Intake Co|Ladder Co|Visit Gate|Photo |Unique |Warn Co|Block Roll|Skill Co|Reminder Co|Fail Co|Comment Co|Exc Co|Docs Co|Warranty Co|Boiler A|No heat/i.test(n)
}

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

function expectOk(result, label) {
	expect([200, 201, 204].includes(result.status), `${label}: ${JSON.stringify(result.data)}`).toBeTruthy()
}

function ymd(offsetDays) {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() + offsetDays)
	return d.toISOString().slice(0, 10)
}

async function dismissHints(page) {
	await page.evaluate((keys) => {
		const uid =
			(window.OC && window.OC.currentUser)
			|| document.getElementById('app-content')?.getAttribute('data-mn-current-user')
			|| ''
		for (const key of keys) {
			try {
				localStorage.setItem('mn:hint:' + key, '1')
				if (uid) localStorage.setItem('mn:hint:' + uid + ':' + key, '1')
			} catch (e) { /* ignore */ }
		}
	}, QUICKSTART_KEYS)
}

async function openApp(page, path) {
	await page.goto(path)
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 90_000 })
	// Re-apply hide if cards already rendered
	await page.locator('.mn-hint-dismiss').evaluateAll((btns) => btns.forEach((b) => b.click())).catch(() => {})
	await page.waitForTimeout(500)
}

async function shot(page, name) {
	mkdirSync(outDir, { recursive: true })
	await page.screenshot({
		path: resolve(outDir, name),
		fullPage: false,
	})
}

async function purgeJunkAndOldDemo(page) {
	for (let offset = 0; offset < 500; offset += 100) {
		const existing = await api(page, 'GET', `/index.php/apps/maintenancecheck/api/customers?limit=100&offset=${offset}`)
		if (existing.status !== 200 || !Array.isArray(existing.data?.data) || existing.data.data.length === 0) {
			break
		}
		for (const row of existing.data.data) {
			const name = String(row.name || '')
			if (isJunkName(name) || name.includes(MARKER) || name.startsWith('Demo ')) {
				await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${row.id}?force=1`)
			}
		}
		if (existing.data.data.length < 100) break
	}
}

async function seedDemo(page, techUid) {
	await purgeJunkAndOldDemo(page)

	const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=20&offset=0')
	expectOk(types, 'equip-types')
	const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=20&offset=0')
	expectOk(maint, 'maint-types')
	const equipTypeId = types.data.data[0].id
	const maintTypeId = maint.data.data[0].id

	const customers = [
		{
			name: `${MARKER} Facility Service GmbH`,
			city: 'Frankfurt am Main',
			country: 'de',
			phone: '+49 69 1200 4400',
			email: 'disposition@rheinmain-facility.example',
		},
		{
			name: 'Demo Klinik Nordpark gGmbH',
			city: 'Wiesbaden',
			country: 'de',
			phone: '+49 611 880 220',
			email: 'technik@nordpark.example',
		},
		{
			name: 'Demo Campus Technik AG',
			city: 'Darmstadt',
			country: 'de',
			phone: '+49 6151 700 100',
			email: 'fm@campus-technik.example',
		},
	]

	/** @type {Array<{customerId:number, equipmentId:number, label:string}>} */
	const assets = []

	for (const c of customers) {
		const created = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', c)
		expectOk(created, `customer ${c.name}`)
		const customerId = created.data.id

		const site = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/customers/${customerId}/sites`, {
			name: c.city === 'Frankfurt am Main' ? 'Zentrale Opernplatz' : c.city === 'Wiesbaden' ? 'Haus B Technik' : 'Halle 3',
			city: c.city,
			country: 'de',
		})
		const siteId = site.status < 300 ? site.data?.id : undefined

		const equipSpecs =
			c.city === 'Frankfurt am Main'
				? [
					{ label: 'Klima Zentralgerät K-01', serial: 'RM-HZ-2401' },
					{ label: 'Aufzug Haus A', serial: 'RM-EL-118' },
					{ label: 'Brandmeldezentrale BMZ-2', serial: 'RM-BM-902' },
				]
				: c.city === 'Wiesbaden'
					? [
						{ label: 'Notstromaggregat NSG-3', serial: 'KN-NS-77' },
						{ label: 'Feuerlöscher Bestand Flur 2', serial: 'KN-FE-12' },
					]
					: [
						{ label: 'Hydraulikpresse HP-4', serial: 'CT-HP-4' },
						{ label: 'Druckluftanlage DL-1', serial: 'CT-DL-1' },
					]

		for (const eq of equipSpecs) {
			const body = {
				label: eq.label,
				customerId,
				equipTypeId,
				serialNumber: eq.serial,
			}
			if (siteId) body.siteId = siteId
			const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', body)
			expectOk(equipment, `equipment ${eq.label}`)
			const equipmentId = equipment.data.id
			await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/equipment/${equipmentId}`, {
				manufacturer: c.city === 'Frankfurt am Main' ? 'Carrier' : c.city === 'Wiesbaden' ? 'Kohler' : 'Bosch Rexroth',
				model: eq.serial,
				serialNumber: eq.serial,
				label: eq.label,
			})
			await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipmentId}/qr/rotate`, {})
			assets.push({ customerId, equipmentId, label: eq.label })
		}
	}

	const schedule = [
		{ asset: assets[0], firstDueOn: ymd(-5), intervalUnit: 'month', intervalCount: 3 },
		{ asset: assets[1], firstDueOn: ymd(0), intervalUnit: 'month', intervalCount: 6 },
		{ asset: assets[2], firstDueOn: ymd(2), intervalUnit: 'month', intervalCount: 12 },
		{ asset: assets[3], firstDueOn: ymd(-3), intervalUnit: 'year', intervalCount: 1 },
		{ asset: assets[4], firstDueOn: ymd(1), intervalUnit: 'month', intervalCount: 3 },
		{ asset: assets[5], firstDueOn: ymd(6), intervalUnit: 'month', intervalCount: 1 },
		{ asset: assets[6], firstDueOn: ymd(-2), intervalUnit: 'month', intervalCount: 3 },
	]

	/** @type {number[]} */
	const visitIds = []
	for (const row of schedule) {
		if (!row.asset) continue
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${row.asset.equipmentId}/plans`, {
			maintTypeId,
			intervalUnit: row.intervalUnit,
			intervalCount: row.intervalCount,
			firstDueOn: row.firstDueOn,
		})
		expectOk(plan, `plan ${row.asset.label}`)
		const visitId = plan.data?.openVisit?.id
		if (visitId) {
			visitIds.push(visitId)
			await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/visits/${visitId}/assign`, {
				assigneeUserId: techUid,
			})
		}
	}

	let woId = null
	for (const visitId of visitIds.slice(0, 3)) {
		const wo = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${visitId}/work-orders`, {
			kind: 'preventive',
		})
		if ([200, 201].includes(wo.status)) {
			woId = wo.data?.id ?? wo.data?.workOrder?.id ?? woId
			if (woId) {
				await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/work-orders/${woId}/assign`, {
					primaryUserId: techUid,
				})
				await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${woId}/comments`, {
					body: 'Vor Ort: Zugang über Pforte Nord, Badge hinterlegen. Ersatzfilter mitnehmen.',
				})
			}
		}
	}

	if (assets[0]) {
		const corrective = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/work-orders', {
			kind: 'corrective',
			equipmentId: assets[0].equipmentId,
			customerId: assets[0].customerId,
			title: 'Klima K-01 — ungewöhnliche Vibration',
			description: 'Kundenmeldung: Vibration seit Wochenende. Filter und Lager prüfen.',
		})
		if ([200, 201].includes(corrective.status)) {
			const cid = corrective.data?.id
			if (cid) {
				await api(page, 'PUT', `/index.php/apps/maintenancecheck/api/work-orders/${cid}/assign`, {
					primaryUserId: techUid,
				})
				woId = woId || cid
			}
		}
	}

	return { assets, woId, visitIds }
}

async function typeSearch(page, text) {
	const search = page.locator('input[type="search"], input[placeholder*="Such"], input[placeholder*="Search"], input[placeholder*="Name"]').first()
	if (await search.isVisible({ timeout: 2000 }).catch(() => false)) {
		await search.fill(text)
		await page.waitForTimeout(700)
	}
}

test.describe('App Store screenshots', () => {
	test.setTimeout(240_000)
	test('seed demo and capture store screenshots', async ({ page }, testInfo) => {
		test.skip(
			testInfo.project.name !== 'chromium-store',
			'App-store screenshot capture is only baselined for chromium-store viewport/project',
		)
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_E2E_* or NC_ADMIN_*')

		await login(page, admin)
		await openApp(page, '/apps/maintenancecheck/')

		const seeded = await seedDemo(page, admin.username)
		expect(seeded.assets.length).toBeGreaterThanOrEqual(5)

		// 01 — Due board
		await openApp(page, '/apps/maintenancecheck/')
		await expect(page.getByText(/RheinMain|Klima|Aufzug|Campus|Nordpark/i).first()).toBeVisible({ timeout: 25_000 })
		await shot(page, 'maintenancecheck-screenshot-01.png')

		// 02 — Customers
		await openApp(page, '/apps/maintenancecheck/customers')
		await typeSearch(page, 'Demo')
		await expect(page.getByText(MARKER).first()).toBeVisible({ timeout: 20_000 })
		await shot(page, 'maintenancecheck-screenshot-02.png')

		// 03 — Equipment
		await openApp(page, '/apps/maintenancecheck/equipment')
		await typeSearch(page, 'Klima')
		await expect(page.getByText(/Klima Zentralgerät/i).first()).toBeVisible({ timeout: 20_000 })
		await shot(page, 'maintenancecheck-screenshot-03.png')

		// 04 — Equipment detail
		const eqId = seeded.assets[0].equipmentId
		await openApp(page, `/apps/maintenancecheck/equipment/${eqId}`)
		await expect(page.getByText(/Klima Zentralgerät/i).first()).toBeVisible({ timeout: 20_000 })
		await shot(page, 'maintenancecheck-screenshot-04.png')

		// 05 — Work orders
		await openApp(page, '/apps/maintenancecheck/work-orders')
		await typeSearch(page, 'Klima')
		await page.waitForTimeout(800)
		await shot(page, 'maintenancecheck-screenshot-05.png')

		// 06 — Work order detail
		if (seeded.woId) {
			await openApp(page, `/apps/maintenancecheck/work-orders/${seeded.woId}`)
			await expect(page.locator('#mn-main-content')).toBeVisible()
			await page.waitForTimeout(900)
			await shot(page, 'maintenancecheck-screenshot-06.png')
		}

		// 07 — Dispatch
		await openApp(page, '/apps/maintenancecheck/dispatch')
		await expect(page.locator('#mn-main-content')).toBeVisible()
		await page.waitForTimeout(900)
		await shot(page, 'maintenancecheck-screenshot-07.png')

		// 08 — Tours
		await openApp(page, '/apps/maintenancecheck/tours')
		await shot(page, 'maintenancecheck-screenshot-08.png')

		// 09 — Exceptions
		await openApp(page, '/apps/maintenancecheck/exceptions')
		await shot(page, 'maintenancecheck-screenshot-09.png')

		// 10 — KPI
		await openApp(page, '/apps/maintenancecheck/kpi')
		await shot(page, 'maintenancecheck-screenshot-10.png')
	})
})
