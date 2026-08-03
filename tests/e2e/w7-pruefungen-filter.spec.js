// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginWithFallback, credsCandidates } from './helpers/auth.js'

/**
 * W7 / AC-W7-2: Prüfungen filter chip is keyboard/ARIA operable + axe-clean.
 */
test('W7 Prüfungen filter: aria-pressed + axe on due board', async ({ page }) => {
	if (!credsCandidates().length) {
		if (process.env.CI) {
			throw new Error('CI requires NC_E2E_* or NC_ADMIN_*')
		}
		test.skip(true, 'Requires NC_E2E_* or NC_ADMIN_*')
		return
	}

	await loginWithFallback(page)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-due-board, .mn-empty').first()).toBeVisible({ timeout: 30_000 })

	const allChip = page.locator('#mn-due-kind-all')
	const inspChip = page.locator('#mn-due-kind-inspection')
	await expect(allChip).toBeVisible()
	await expect(inspChip).toBeVisible()
	await expect(allChip).toHaveAttribute('aria-pressed', 'true')
	await expect(inspChip).toHaveAttribute('aria-pressed', 'false')

	await inspChip.click()
	await expect(inspChip).toHaveAttribute('aria-pressed', 'true')
	await expect(allChip).toHaveAttribute('aria-pressed', 'false')
	await expect(inspChip).toHaveClass(/is-active/)

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.include('#mn-main-content')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})

/**
 * CORE I3 / AC-W7-3: obligation visits must not offer Complete/Skip — open/create WO instead.
 */
test('W7 visit gate: inspection due row hides Complete/Skip', async ({ page }) => {
	if (!credsCandidates().length) {
		if (process.env.CI) {
			throw new Error('CI requires NC_E2E_* or NC_ADMIN_*')
		}
		test.skip(true, 'Requires NC_E2E_* or NC_ADMIN_*')
		return
	}

	await loginWithFallback(page)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-due-board, .mn-empty').first()).toBeVisible({ timeout: 30_000 })

	const stamp = Date.now()
	const api = async (method, path, body) =>
		page.evaluate(
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

	const types = await api('GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
	expect(types.status).toBeLessThan(400)
	const classes = await api('GET', '/index.php/apps/maintenancecheck/api/equipment-classes')
	expect(classes.status).toBeLessThan(400)
	const customer = await api('POST', '/index.php/apps/maintenancecheck/api/customers', {
		name: `E2E Gate ${stamp}`,
		active: true,
	})
	expect(customer.status).toBeLessThan(400)
	const customerId = customer.data.id
	const equipment = await api('POST', '/index.php/apps/maintenancecheck/api/equipment', {
		label: `Gate ladder ${stamp}`,
		customerId,
		equipTypeId: types.data.data[0].id,
		equipmentClass: 'ladder',
		active: true,
	})
	expect(equipment.status).toBeLessThan(400)
	const obl = await api('POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/obligations`, {
		classCode: 'ladder',
		firstDueOn: '2020-01-01',
	})
	expect(obl.status).toBeLessThan(400)
	expect(obl.data.openVisit?.id).toBeTruthy()

	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-due-board, .mn-empty').first()).toBeVisible({ timeout: 30_000 })
	await page.locator('#mn-due-kind-inspection').click()
	await expect(page.locator('#mn-due-kind-inspection')).toHaveAttribute('aria-pressed', 'true')

	const row = page.locator('#mn-due-board table.mn-table tbody tr').filter({
		hasText: new RegExp(`Gate ladder ${stamp}`),
	}).first()
	await expect(row).toBeVisible({ timeout: 20_000 })

	// Primary Complete must not appear on inspection rows.
	await expect(row.getByRole('button', { name: /^Complete$/i })).toHaveCount(0)
	await expect(row.getByRole('button', { name: /Create inspection work order|Open inspection work order/i })).toBeVisible()

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.include('#mn-main-content')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])

	await api('DELETE', `/index.php/apps/maintenancecheck/api/customers/${customerId}?force=1`)
})

test('W7 settings: inspection policy controls + axe', async ({ page }) => {
	if (!credsCandidates().length) {
		if (process.env.CI) {
			throw new Error('CI requires NC_E2E_* or NC_ADMIN_*')
		}
		test.skip(true, 'Requires NC_E2E_* or NC_ADMIN_*')
		return
	}

	await loginWithFallback(page)
	await page.goto('/apps/maintenancecheck/settings/policies')
	await expect(page.locator('#mn-settings-policies, .mn-empty').first()).toBeVisible({ timeout: 30_000 })

	const failBlocksBox = page.locator('#mn-policy-fail-blocks-roll')
	const inspResultBox = page.locator('#mn-policy-inspection-result-required')
	const defectFollow = page.locator('#mn-policy-defect-follow-up')
	await expect(failBlocksBox).toBeVisible({ timeout: 15_000 })
	await expect(inspResultBox).toBeVisible()
	await expect(defectFollow).toBeVisible()
	await expect(failBlocksBox).toHaveAttribute('type', 'checkbox')
	await expect(inspResultBox).toHaveAttribute('type', 'checkbox')

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.include('#mn-main-content')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})

/**
 * UC-PRUEF Done sheet: result/inspector/defect picker dialog is axe-clean (WCAG 2.1 AA).
 */
test('W7 inspection Done dialog: defect code picker + axe', async ({ page }) => {
	if (!credsCandidates().length) {
		if (process.env.CI) {
			throw new Error('CI requires NC_E2E_* or NC_ADMIN_*')
		}
		test.skip(true, 'Requires NC_E2E_* or NC_ADMIN_*')
		return
	}

	await loginWithFallback(page)
	// Prefer work-order route directly — due board may stay hidden while empty buckets load.
	await page.goto('/apps/maintenancecheck/work-orders')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const stamp = Date.now()
	const api = async (method, path, body) =>
		page.evaluate(
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

	const types = await api('GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
	expect(types.status).toBeLessThan(400)
	const customer = await api('POST', '/index.php/apps/maintenancecheck/api/customers', {
		name: `E2E Done ${stamp}`,
		active: true,
	})
	expect(customer.status).toBeLessThan(400)
	const customerId = customer.data.id
	const equipment = await api('POST', '/index.php/apps/maintenancecheck/api/equipment', {
		label: `Done ladder ${stamp}`,
		customerId,
		equipTypeId: types.data.data[0].id,
		equipmentClass: 'ladder',
		active: true,
	})
	expect(equipment.status).toBeLessThan(400)
	const obl = await api('POST', `/index.php/apps/maintenancecheck/api/equipment/${equipment.data.id}/obligations`, {
		classCode: 'ladder',
		firstDueOn: '2020-01-01',
	})
	expect(obl.status).toBeLessThan(400)
	const visitId = obl.data.openVisit.id
	const wo = await api('POST', `/index.php/apps/maintenancecheck/api/visits/${visitId}/work-orders`, {
		procedureSkipped: true,
		procedureSkipReason: 'E2E inspection Done axe fixture without pack',
	})
	expect(wo.status).toBeLessThan(400)
	const woId = wo.data.id
	const toProgress = await api('POST', `/index.php/apps/maintenancecheck/api/work-orders/${woId}/transition`, { to: 'in_progress' })
	expect(toProgress.status).toBeLessThan(400)

	await page.goto(`/apps/maintenancecheck/work-orders/${woId}`)
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
	const doneBtn = page.getByRole('button', { name: /^Complete$|^Abschließen$/i }).first()
	await expect(doneBtn).toBeVisible({ timeout: 20_000 })
	await doneBtn.click()
	const dialog = page.locator('[role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	const resultSelect = dialog.locator('select[aria-label="Inspection result"], select[aria-label="Prüfergebnis"]').first()
	await expect(resultSelect).toBeVisible()
	await resultSelect.selectOption('fail')
	// Defect picker is revealed only for fail/conditional (progressive disclosure).
	const defectCode = dialog.locator('select[aria-label="Defect code"], select[aria-label="Mangelcode"]').first()
	await expect(defectCode).toBeVisible({ timeout: 10_000 })

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.include('[role="dialog"]')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])

	await page.keyboard.press('Escape')
	await api('DELETE', `/index.php/apps/maintenancecheck/api/customers/${customerId}?force=1`)
})
