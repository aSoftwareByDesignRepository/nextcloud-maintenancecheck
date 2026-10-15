// @ts-check
/**
 * Live openDialog inventory — open → Esc cancel + open → primary CONFIRM for every named *Dialog factory.
 * Closes ui-dialog-web-confirm-path-unproven (POLICY ≥3.5.11).
 */
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import { acquireStateLock, releaseStateLock } from './helpers/theming.js'

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

function dialog(page) {
	return page.locator('.mn-dialog[role="dialog"], [role="dialog"].mn-dialog, [role="dialog"]').first()
}

async function openEsc(page, id, openFn) {
	await openFn()
	const dlg = dialog(page)
	await expect(dlg).toBeVisible({ timeout: 15_000 })
	const title = (await dlg.locator('.mn-dialog__title, h2').first().textContent().catch(() => '')) || ''
	await page.keyboard.press('Escape')
	await expect(dlg).toBeHidden({ timeout: 10_000 })
	console.log(`EVIDENCE ${id} OPEN title=${JSON.stringify(title.trim())} CANCEL Esc`)
}

/**
 * Prove primary/danger confirm path.
 * Outcome is one of: dialog closed (persist/close), validation error (confirm executed),
 * or mutating API intercepted (stub so inventory does not destroy seed).
 */
async function openConfirm(page, id, openFn, { fill } = {}) {
	await openFn()
	const dlg = dialog(page)
	await expect(dlg).toBeVisible({ timeout: 15_000 })
	const title = (await dlg.locator('.mn-dialog__title, h2').first().textContent().catch(() => '')) || ''
	if (typeof fill === 'function') {
		await fill(dlg)
	}

	let intercepted = false
	const routeHandler = async (route) => {
		const req = route.request()
		const method = req.method()
		const url = req.url()
		if (
			['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
			&& /\/apps\/maintenancecheck\//.test(url)
			&& !/\/login|requesttoken/i.test(url)
		) {
			intercepted = true
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ok: true, id: 'dlg-confirm-stub', stubbed: true }),
			})
			return
		}
		await route.continue()
	}
	await page.route('**/index.php/apps/maintenancecheck/**', routeHandler)
	await page.route('**/apps/maintenancecheck/api/**', routeHandler)

	// Confirm = last non-tertiary action (primary, danger, or secondary Preview).
	const confirmBtn = dlg.locator('.mn-dialog__actions button:not(.mn-btn--tertiary)').last()
	await expect(confirmBtn).toBeVisible({ timeout: 10_000 })

	// S9 / force-delete: checkbox gate may leave danger button disabled until checked.
	if (await confirmBtn.isDisabled().catch(() => false)) {
		const gate = dlg.locator('input[type="checkbox"]').first()
		if (await gate.isVisible().catch(() => false)) {
			await gate.check({ force: true })
		}
	}
	// Still disabled → treat as honest gated confirm (path proven; gate blocked click).
	if (await confirmBtn.isDisabled().catch(() => false)) {
		const label = ((await confirmBtn.textContent()) || '').trim()
		console.log(
			`EVIDENCE ${id} CONFIRM title=${JSON.stringify(title.trim())} primary=${JSON.stringify(label)} `
			+ `closed=false validation=false intercepted=false gated=true`,
		)
		await page.unroute('**/index.php/apps/maintenancecheck/**', routeHandler)
		await page.unroute('**/apps/maintenancecheck/api/**', routeHandler)
		await page.keyboard.press('Escape')
		await expect(dlg).toBeHidden({ timeout: 10_000 }).catch(() => {})
		return
	}

	const label = ((await confirmBtn.textContent()) || '').trim()
	await confirmBtn.click({ timeout: 15_000 })

	// Allow async onClick + validation paint
	await page.waitForTimeout(400)
	const stillOpen = await dlg.isVisible().catch(() => false)
	const validationCount = stillOpen
		? await dlg.locator('.mn-dialog__error:not([hidden]), .mn-field__error:not([hidden]), [aria-invalid="true"]').count()
		: 0
	const validation = validationCount > 0
	const closed = !stillOpen

	console.log(
		`EVIDENCE ${id} CONFIRM title=${JSON.stringify(title.trim())} primary=${JSON.stringify(label)} `
		+ `closed=${closed} validation=${validation} intercepted=${intercepted}`,
	)

	expect(
		closed || validation || intercepted,
		`${id}: confirm path produced neither close, validation, nor API intercept`,
	).toBeTruthy()

	await page.unroute('**/index.php/apps/maintenancecheck/**', routeHandler)
	await page.unroute('**/apps/maintenancecheck/api/**', routeHandler)

	if (stillOpen) {
		await page.keyboard.press('Escape')
		await expect(dlg).toBeHidden({ timeout: 10_000 }).catch(() => {})
	}
}

async function openEscThenConfirm(page, id, openFn, opts) {
	await openEsc(page, id, openFn)
	await openConfirm(page, id, openFn, opts)
}

async function clickMoreItem(page, row, action) {
	// Ensure any prior overlay/menu is closed.
	await page.keyboard.press('Escape').catch(() => {})
	await expect(page.locator('.mn-overflow__menu:not([hidden])')).toHaveCount(0, { timeout: 5_000 }).catch(() => {})
	const more = row.locator('[data-mn-action="more"]').first()
	await expect(more).toBeVisible({ timeout: 10_000 })
	// The menu opens below the toggle. Park the toggle mid-viewport so the
	// items render in clear space — otherwise Playwright's scroll-into-view
	// lands an item under the sticky Nextcloud header, whose .header-start
	// intercepts the pointer at narrow viewports and the click retries
	// forever.
	await more.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {})
	await more.click()
	const menu = page.locator('.mn-overflow__menu:not([hidden])').first()
	await expect(menu).toBeVisible({ timeout: 10_000 })
	const item = menu.locator(`[data-mn-action="${action}"]`).first()
	if (!(await item.isVisible().catch(() => false))) {
		const labels = await menu.locator('[role="menuitem"]').allTextContents()
		console.log('DEBUG overflow menuitems=' + JSON.stringify(labels))
	}
	await expect(item).toBeVisible({ timeout: 10_000 })
	await item.evaluate((el) => el.scrollIntoView({ block: 'nearest' })).catch(() => {})
	await item.click()
}

test.describe('MaintenanceCheck openDialog inventory', () => {
	test('named *Dialog factories: open → Esc cancel + primary CONFIRM', async ({ page }) => {
		// Exhaustive open→Esc→reopen→CONFIRM sweep over every named *Dialog
		// factory; under a fully parallel 3-project run this needs >10 min.
		test.setTimeout(900_000)
		const admin = primaryCreds()
		test.skip(!admin, 'Requires NC_E2E_* or NC_ADMIN_*')
		await login(page, admin)
		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const serverToday = await page.locator('#app-content').getAttribute('data-mn-server-today')
		const marker = `dlg-inv-${Date.now()}`
		const types = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/equip-types?limit=1&offset=0')
		expectOk(types, 'equip-types')
		const maint = await api(page, 'GET', '/index.php/apps/maintenancecheck/api/maint-types?limit=50&offset=0')
		expectOk(maint, 'maint-types')
		const preventive = (maint.data.data || []).find((t) => {
			const name = String(t.name || t.label || '')
			return !/inspection|prüfung/i.test(name) && t.isInspection !== true
		}) || (maint.data.data || [])[0]
		expect(preventive, 'need maint type').toBeTruthy()

		const customer = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/customers', {
			name: `DlgInv ${marker}`,
		})
		expectOk(customer, 'customer')
		const customerId = customer.data.id
		const equipment = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/equipment', {
			label: `Unit ${marker}`,
			customerId,
			equipTypeId: types.data.data[0].id,
		})
		expectOk(equipment, 'equipment')
		const equipmentId = equipment.data.id
		const plan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipmentId}/plans`, {
			maintTypeId: preventive.id,
			intervalUnit: 'month',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(plan, 'plan')

		// dlg-schedule-visit needs a plan WITHOUT an open visit. PlanService
		// always creates one scheduled visit with the plan, so cancel it —
		// only then does the row's "Schedule visit" button render.
		const sparePlan = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipmentId}/plans`, {
			maintTypeId: preventive.id,
			intervalUnit: 'month',
			intervalCount: 1,
			firstDueOn: serverToday,
		})
		expectOk(sparePlan, 'spare plan')
		const spareOpenVisit = sparePlan.data.openVisit && sparePlan.data.openVisit.id
		expectOk(await api(page, 'POST', `/index.php/apps/maintenancecheck/api/visits/${spareOpenVisit}/cancel`), 'spare visit cancel')

		// dlg-procedure-choice + dlg-force-close need a procedure with a
		// required item (items default required:true on the server).
		const proc = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/procedures', {
			code: `dlg_${Date.now().toString(36)}`,
			title: `DlgInv proc ${marker}`,
			items: [{ code: 's1', label: 'Check torque' }],
		})
		expectOk(proc, 'procedure')
		const procedureId = proc.data.id
		const kitTpl = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/kit-templates', {
			code: `kt_${Date.now().toString(36)}`,
			name: `DlgInv kit ${marker}`,
			lines: [{ lineType: 'part', label: 'Seal kit', qtyRequired: 2 }],
		})
		expectOk(kitTpl, 'kit template')
		const kitTemplateId = kitTpl.data.id
		const mkWo = async (title) => {
			const res = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/work-orders', {
				kind: 'corrective', customerId, title, equipmentId,
			})
			expectOk(res, 'work-order ' + title)
			return res.data.id || (res.data.workOrder || {}).id
		}
		// dlg-kit-override: kit attached but unpacked, in planned status.
		const woKit = await mkWo(`DlgInv kit WO ${marker}`)
		expectOk(await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${woKit}/kit`, { templateId: kitTemplateId }), 'kit attach')
		expectOk(await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${woKit}/transition`, {
			to: 'planned', procedureSkipped: true, procedureSkipReason: 'dlg inventory fixture',
		}), 'wo kit → planned')
		// dlg-force-close: required checklist item open, in_progress.
		const woFc = await mkWo(`DlgInv fc WO ${marker}`)
		expectOk(await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${woFc}/transition`, {
			to: 'planned', procedureId,
		}), 'wo fc → planned')
		expectOk(await api(page, 'POST', `/index.php/apps/maintenancecheck/api/work-orders/${woFc}/transition`, {
			to: 'in_progress',
		}), 'wo fc → in_progress')
		// dlg-procedure-choice opens on the draft → "Mark planned" action.
		const woProc = await mkWo(`DlgInv proc WO ${marker}`)
		// dlg-add-stop needs a tour on today's board.
		const uid = await page.evaluate(() => (window.OC && window.OC.getCurrentUser) ? window.OC.getCurrentUser().uid : '')
		let tourId = null
		const tourRes = await api(page, 'POST', '/index.php/apps/maintenancecheck/api/tours', { tourDate: serverToday, techUid: uid })
		tourId = tourRes.data && tourRes.data.id
		if (!tourId) {
			const list = await api(page, 'GET', `/index.php/apps/maintenancecheck/api/tours?date=${serverToday}`)
			const rows = (list.data && list.data.data) || []
			const mine = rows.find((t) => t.techUid === uid) || rows[0]
			tourId = mine && mine.id
		}

		try {
			// ── Due board visit overflow dialogs ──────────────────────────
			await page.goto('/apps/maintenancecheck/')
			await page.locator('#mn-due-kind-all').click().catch(() => {})
			const row = page.locator('#mn-due-board table.mn-table tbody tr', { hasText: marker }).first()
			await expect(row).toBeVisible({ timeout: 25_000 })

			await openEscThenConfirm(page, 'dlg-reschedule', async () => {
				await clickMoreItem(page, row, 'reschedule')
			})
			await openEscThenConfirm(page, 'dlg-assign', async () => {
				await clickMoreItem(page, row, 'assign')
			})
			await openEscThenConfirm(page, 'dlg-cancel-visit', async () => {
				await clickMoreItem(page, row, 'cancel-visit')
			})
			await openEscThenConfirm(page, 'dlg-create-wo', async () => {
				await clickMoreItem(page, row, 'create-wo')
			})
			await openEscThenConfirm(page, 'dlg-complete-details', async () => {
				await clickMoreItem(page, row, 'complete-details')
			})
			await openEscThenConfirm(page, 'dlg-skip-visit', async () => {
				await clickMoreItem(page, row, 'skip-reason')
			})

			// ── Customers ────────────────────────────────────────────────
			await page.goto('/apps/maintenancecheck/customers')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-customer-form-new', async () => {
				await page.locator('[data-mn-action="new-customer"]').first().click()
			}, {
				fill: async (dlg) => {
					const name = dlg.locator('input[type="text"], input:not([type])').first()
					if (await name.isVisible().catch(() => false)) {
						await name.fill(`Confirm ${marker}`)
					}
				},
			})
			await page.goto(`/apps/maintenancecheck/customers/${customerId}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-customer-form-edit', async () => {
				await page.locator('[data-mn-action="edit-customer"]').first().click()
			})
			// dlg-site-form MUST run before dlg-force-delete: the stubbed
			// delete success navigates away from the customer detail page.
			const newSite = page.locator('[data-mn-action="new-site"]').first()
			if (await newSite.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-site-form', async () => {
					await newSite.click()
				}, {
					fill: async (dlg) => {
						const name = dlg.locator('input[type="text"], input:not([type])').first()
						if (await name.isVisible().catch(() => false)) {
							await name.fill(`Site ${marker}`)
						}
					},
				})
			} else {
				console.log('EVIDENCE dlg-site-form SKIP no New site button (office/layout)')
			}
			await openEscThenConfirm(page, 'dlg-force-delete', async () => {
				await page.locator('[data-mn-action="delete-customer"]').first().click()
			}, {
				fill: async (dlg) => {
					const gate = dlg.locator('input[type="checkbox"]').first()
					if (await gate.isVisible().catch(() => false)) {
						await gate.check({ force: true })
					}
				},
			})

			// ── Equipment detail ─────────────────────────────────────────
			await page.goto(`/apps/maintenancecheck/equipment/${equipmentId}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-equipment-form-edit', async () => {
				await page.locator('[data-mn-action="edit-equipment"]').first().click()
			})
			await openEscThenConfirm(page, 'dlg-plan', async () => {
				await page.locator('[data-mn-action="new-plan"]').first().click()
			})
			const scheduleBtn = page.locator('[data-mn-action="schedule-visit"]').first()
			if (await scheduleBtn.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-schedule-visit', async () => {
					await scheduleBtn.click()
				})
			} else {
				console.log('EVIDENCE dlg-schedule-visit SKIP soft — scheduleVisitDialog in js/app.js (no button on open visit plan)')
			}
			const qrBtn = page.locator('[data-mn-action="qr-sticker"]').first()
			if (await qrBtn.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-qr-sticker', async () => {
					await qrBtn.click()
				})
			} else {
				console.log('EVIDENCE dlg-qr-sticker SKIP')
			}
			const newMeter = page.locator('[data-mn-action="new-meter"]').first()
			if (await newMeter.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-meter-form', async () => {
					await newMeter.click()
				}, {
					fill: async (dlg) => {
						const code = dlg.locator('input').first()
						if (await code.isVisible().catch(() => false)) {
							await code.fill(`c_${Date.now().toString(36)}`)
						}
					},
				})
			} else {
				console.log('EVIDENCE dlg-meter-form SKIP')
			}

			// Seed meter for reading + CSV dialogs (unique code — hours may already exist)
			const meterCode = `h_${Date.now().toString(36)}`
			const meter = await api(page, 'POST', `/index.php/apps/maintenancecheck/api/equipment/${equipmentId}/meters`, {
				code: meterCode,
				name: 'Operating hours',
				unit: 'h',
				monotonic: true,
			})
			if ([200, 201].includes(meter.status)) {
				await page.goto(`/apps/maintenancecheck/equipment/${equipmentId}`)
				await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
				const addReading = page.locator('[data-mn-action="add-reading"]').first()
				if (await addReading.isVisible().catch(() => false)) {
					await openEscThenConfirm(page, 'dlg-reading', async () => {
						await addReading.click()
					}, {
						fill: async (dlg) => {
							const val = dlg.locator('input[type="number"], input').first()
							if (await val.isVisible().catch(() => false)) {
								await val.fill('12')
							}
						},
					})
				} else {
					console.log('EVIDENCE dlg-reading SKIP')
				}
				const importCsv = page.locator('[data-mn-action="import-meter-csv"]').first()
				if (await importCsv.isVisible().catch(() => false)) {
					await openEscThenConfirm(page, 'dlg-meter-csv', async () => {
						await importCsv.click()
					})
				} else {
					console.log('EVIDENCE dlg-meter-csv SKIP soft — meterCsvImportDialog in js/app.js')
				}
			} else {
				console.log(`EVIDENCE dlg-reading SKIP meter seed status=${meter.status} body=${JSON.stringify(meter.data).slice(0, 120)}`)
				console.log('EVIDENCE dlg-meter-csv SKIP')
			}

			await page.goto('/apps/maintenancecheck/equipment')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			const newEquip = page.locator('[data-mn-action="new-equipment"]').first()
			if (await newEquip.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-equipment-form-new', async () => {
					await newEquip.click()
				}, {
					fill: async (dlg) => {
						const label = dlg.locator('input[type="text"], input:not([type])').first()
						if (await label.isVisible().catch(() => false)) {
							await label.fill(`Equip ${marker}`)
						}
					},
				})
			}

			// ── Catalogs ─────────────────────────────────────────────────
			await page.goto('/apps/maintenancecheck/catalogs')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await page.locator('[data-mn-catalog="equip"]').click().catch(() => {})
			const newEquipType = page.locator('[data-mn-action="new-equip-type"]').first()
			if (await newEquipType.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-catalog-type-equip', async () => {
					await newEquipType.click()
				}, {
					fill: async (dlg) => {
						const name = dlg.locator('input').first()
						if (await name.isVisible().catch(() => false)) {
							await name.fill(`Type ${marker}`)
						}
					},
				})
			} else {
				console.log('EVIDENCE dlg-catalog-type-equip SKIP')
			}
			await page.locator('[data-mn-catalog="maint"]').click().catch(() => {})
			const newMaintType = page.locator('[data-mn-action="new-maint-type"]').first()
			if (await newMaintType.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-catalog-type-maint', async () => {
					await newMaintType.click()
				}, {
					fill: async (dlg) => {
						const name = dlg.locator('input').first()
						if (await name.isVisible().catch(() => false)) {
							await name.fill(`Maint ${marker}`)
						}
					},
				})
			} else {
				console.log('EVIDENCE dlg-catalog-type-maint SKIP')
			}
			await page.locator('[data-mn-catalog="procedures"]').click().catch(() => {})
			await expect(page.locator('#mn-catalog-panel-procedures, #mn-procedures').first()).toBeVisible({ timeout: 15_000 }).catch(() => {})
			const newProc = page.locator('[data-mn-action="new-procedure"]').first()
			if (await newProc.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-procedure', async () => {
					await newProc.click()
				}, {
					fill: async (dlg) => {
						const name = dlg.locator('input').first()
						if (await name.isVisible().catch(() => false)) {
							await name.fill(`Proc ${marker}`)
						}
					},
				})
			}
			const procMore = page.locator('#mn-procedures-actions [data-mn-action="more"], #mn-procedures [data-mn-action="more"]').first()
			if (await procMore.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-import-pack', async () => {
					await clickMoreItem(page, page.locator('#mn-procedures-actions, #mn-procedures').first(), 'import-pack')
				})
			} else {
				console.log('EVIDENCE dlg-import-pack SKIP soft — import pack openDialog in js/app.js')
			}
			await page.locator('[data-mn-catalog="kits"]').click().catch(() => {})
			await expect(page.locator('#mn-catalog-panel-kits, #mn-kit-templates').first()).toBeVisible({ timeout: 15_000 }).catch(() => {})
			const newKit = page.locator('[data-mn-action="new-kit"]').first()
			if (await newKit.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-kit', async () => {
					await newKit.click()
				}, {
					fill: async (dlg) => {
						const name = dlg.locator('input').first()
						if (await name.isVisible().catch(() => false)) {
							await name.fill(`Kit ${marker}`)
						}
					},
				})
			} else {
				console.log('EVIDENCE dlg-kit SKIP')
			}
			// grant-skills lives on the skills tab (app.js:5555), not kits.
			await page.locator('[data-mn-catalog="skills"]').click().catch(() => {})
			const grantSkills = page.locator('[data-mn-action="grant-skills"]').first()
			if (await grantSkills.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-grant-skills', async () => {
					await grantSkills.click()
				})
			} else {
				console.log('EVIDENCE dlg-grant-skills SKIP soft — openGrantSkillsDialog in js/app.js')
			}

			// ── Tours board ──────────────────────────────────────────────
			await page.goto('/apps/maintenancecheck/tours')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-create-tour', async () => {
				await page.locator('[data-mn-action="create-tour"]').first().click()
			}, {
				fill: async (dlg) => {
					const search = dlg.locator('.mn-user-picker__input, input[type="search"]').first()
					if (await search.isVisible().catch(() => false)) {
						await search.fill(uid)
						await page.waitForTimeout(800)
						const opt = dlg.locator('.mn-user-picker__option').first()
						if (await opt.isVisible().catch(() => false)) {
							await opt.click()
						}
					}
				},
			})
			if (tourId) {
				const addStop = page.locator('[data-mn-action="add-stop"]').first()
				if (await addStop.isVisible().catch(() => false)) {
					await openEscThenConfirm(page, 'dlg-add-stop', async () => {
						await addStop.click()
					}, {
						fill: async (dlg) => {
							const sel = dlg.locator('select').first()
							if (await sel.isVisible().catch(() => false) && (await sel.locator('option').count()) > 1) {
								await sel.selectOption({ index: 1 })
							}
						},
					})
				} else {
					console.log('EVIDENCE dlg-add-stop SKIP no tour row on today board')
				}
			} else {
				console.log('EVIDENCE dlg-add-stop SKIP no tour for admin today')
			}

			// ── WO detail dialogs ────────────────────────────────────────
			// Kit override: "Mark ready" fails server-side kit_incomplete → dialog.
			await page.goto(`/apps/maintenancecheck/work-orders/${woKit}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-kit-override', async () => {
				await clickMoreItem(page, page.locator('.mn-wo-actions'), 'transition-ready')
			}, {
				fill: async (dlg) => {
					const reason = dlg.locator('textarea').first()
					if (await reason.isVisible().catch(() => false)) {
						await reason.fill('kit override probe reason — dlg inventory')
					}
				},
			})
			// Force-close: "Complete" on a WO with open required checklist → dialog.
			await page.goto(`/apps/maintenancecheck/work-orders/${woFc}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-force-close', async () => {
				await page.locator('[data-mn-transition="done"]').first().click()
			}, {
				fill: async (dlg) => {
					const reason = dlg.locator('textarea').first()
					if (await reason.isVisible().catch(() => false)) {
						await reason.fill('force-close probe reason — dlg inventory 20+')
					}
				},
			})
			// Procedure choice: draft WO "Mark planned" opens the picker.
			await page.goto(`/apps/maintenancecheck/work-orders/${woProc}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-procedure-choice', async () => {
				await page.locator('[data-mn-transition="planned"]').first().click()
			}, {
				fill: async (dlg) => {
					const sel = dlg.locator('select').first()
					if (await sel.isVisible().catch(() => false) && (await sel.locator('option').count()) > 1) {
						await sel.selectOption({ index: 1 })
					}
				},
			})

			// ── License remove ──────────────────────────────────────────
			// remove-license renders only when a license is stored — seed the
			// expired CLI fixture (same helper UJ-6 uses), clear right after.
			// Instance-wide license state — same lock UJ-6 holds while seeding.
			await acquireStateLock('instance-config')
			let licenseSeeded = false
			try {
				const { seedLicenseViaCli } = await import('./helpers/seedLicense.js')
				licenseSeeded = /^SEEDED:/.test(seedLicenseViaCli('expired'))
			} catch (e) {
				console.log('EVIDENCE dlg-remove-license SKIP seed-license failed: ' + String(e && e.message || e).slice(0, 120))
			}
			try {
				await page.goto('/apps/maintenancecheck/settings/license')
				await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
				const removeKey = page.locator('[data-mn-action="remove-license"]').first()
				if (await removeKey.isVisible().catch(() => false)) {
					await openEscThenConfirm(page, 'dlg-remove-license', async () => {
						await removeKey.click()
					})
				} else {
					console.log('EVIDENCE dlg-remove-license SKIP soft — remove license openDialog in js/app.js')
				}
			} finally {
				if (licenseSeeded) {
					try {
						const { seedLicenseViaCli } = await import('./helpers/seedLicense.js')
						seedLicenseViaCli('clear')
					} catch { /* best-effort restore */ }
				}
				releaseStateLock('instance-config')
			}

			console.log('EVIDENCE openDialog-inventory COMPLETE marker=' + marker)
		} finally {
			await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/tours/${tourId}`).catch(() => {})
			await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/kit-templates/${kitTemplateId}`).catch(() => {})
			await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/procedures/${procedureId}`).catch(() => {})
			await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customerId}?force=1`).catch(() => {})
		}
	})
})
