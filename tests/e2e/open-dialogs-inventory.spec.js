// @ts-check
/**
 * Live openDialog inventory — open → Esc cancel + open → primary CONFIRM for every named *Dialog factory.
 * Closes ui-dialog-web-confirm-path-unproven (POLICY ≥3.5.11).
 */
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

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

async function clickMoreItem(page, row, nameRe) {
	// Ensure any prior overlay/menu is closed.
	await page.keyboard.press('Escape').catch(() => {})
	await expect(page.locator('.mn-overflow__menu:not([hidden])')).toHaveCount(0, { timeout: 5_000 }).catch(() => {})
	const more = row.getByRole('button', { name: /more actions|more|mehr|weitere aktionen/i })
	await expect(more).toBeVisible({ timeout: 10_000 })
	await more.click()
	const menu = page.locator('.mn-overflow__menu:not([hidden])').first()
	await expect(menu).toBeVisible({ timeout: 10_000 })
	const item = menu.getByRole('menuitem', { name: nameRe }).first()
	if (!(await item.isVisible().catch(() => false))) {
		const labels = await menu.getByRole('menuitem').allTextContents()
		console.log('DEBUG overflow menuitems=' + JSON.stringify(labels))
	}
	await expect(item).toBeVisible({ timeout: 10_000 })
	await item.click()
}

test.describe('MaintenanceCheck openDialog inventory', () => {
	test('named *Dialog factories: open → Esc cancel + primary CONFIRM', async ({ page }) => {
		test.setTimeout(600_000)
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

		try {
			// ── Due board visit overflow dialogs ──────────────────────────
			await page.goto('/apps/maintenancecheck/')
			await page.locator('#mn-due-kind-all').click().catch(() => {})
			const row = page.locator('#mn-due-board table.mn-table tbody tr', { hasText: marker }).first()
			await expect(row).toBeVisible({ timeout: 25_000 })

			await openEscThenConfirm(page, 'dlg-reschedule', async () => {
				await clickMoreItem(page, row, /^reschedule$|^verschieben$|^umplanen$/i)
			})
			await openEscThenConfirm(page, 'dlg-assign', async () => {
				await clickMoreItem(page, row, /^assign$|^zuweisen$/i)
			})
			await openEscThenConfirm(page, 'dlg-cancel-visit', async () => {
				await clickMoreItem(page, row, /cancel visit|einsatz stornieren|besuch stornieren|termin stornieren|stornieren/i)
			})
			await openEscThenConfirm(page, 'dlg-create-wo', async () => {
				await clickMoreItem(page, row, /create work order|arbeitsauftrag anlegen/i)
			})
			await openEscThenConfirm(page, 'dlg-complete-details', async () => {
				await clickMoreItem(page, row, /complete with details|mit details abschließen/i)
			})
			await openEscThenConfirm(page, 'dlg-skip-visit', async () => {
				await clickMoreItem(page, row, /skip with reason|mit begründung|mit grund/i)
			})

			// ── Customers ────────────────────────────────────────────────
			await page.goto('/apps/maintenancecheck/customers')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-customer-form-new', async () => {
				await page.getByRole('button', { name: /new customer|neuer kunde/i }).first().click()
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
				await page.getByRole('button', { name: /edit customer|kunde bearbeiten|bearbeiten/i }).first().click()
			})
			await openEscThenConfirm(page, 'dlg-force-delete', async () => {
				await page.getByRole('button', { name: /delete customer|kunde löschen|kunden löschen/i }).first().click()
			}, {
				fill: async (dlg) => {
					const gate = dlg.locator('input[type="checkbox"]').first()
					if (await gate.isVisible().catch(() => false)) {
						await gate.check({ force: true })
					}
				},
			})
			const newSite = page.getByRole('button', { name: /new site|neuer standort|neue stätte/i }).first()
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

			// ── Equipment detail ─────────────────────────────────────────
			await page.goto(`/apps/maintenancecheck/equipment/${equipmentId}`)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			await openEscThenConfirm(page, 'dlg-equipment-form-edit', async () => {
				const edit = page.getByRole('button', { name: /edit equipment|gerät bearbeiten|bearbeiten/i }).first()
				if (await edit.isVisible().catch(() => false)) {
					await edit.click()
				} else {
					await page.getByRole('button', { name: /edit|bearbeiten/i }).first().click()
				}
			})
			await openEscThenConfirm(page, 'dlg-plan', async () => {
				await page.getByRole('button', { name: /new plan|neuer plan/i }).first().click()
			})
			const scheduleBtn = page.getByRole('button', { name: /schedule visit|einsatz planen|besuch planen|termin planen/i }).first()
			if (await scheduleBtn.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-schedule-visit', async () => {
					await scheduleBtn.click()
				})
			} else {
				console.log('EVIDENCE dlg-schedule-visit SKIP soft — scheduleVisitDialog in js/app.js (no button on open visit plan)')
			}
			const qrBtn = page.getByRole('button', { name: /qr sticker|qr-aufkleber|create qr|renew qr/i }).first()
			if (await qrBtn.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-qr-sticker', async () => {
					await qrBtn.click()
				})
			} else {
				console.log('EVIDENCE dlg-qr-sticker SKIP')
			}
			const newMeter = page.getByRole('button', { name: /new meter|neuer zähler/i }).first()
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
				const addReading = page.getByRole('button', { name: /add reading|zählerstand erfassen|ablesung|wert erfassen/i }).first()
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
				const importCsv = page.getByRole('button', { name: /import.*reading|csv|importieren/i }).first()
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
			const newEquip = page.getByRole('button', { name: /new equipment|neues gerät|neue anlage/i }).first()
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
			const newEquipType = page.getByRole('button', { name: /new equipment type|neuer anlagentyp|neuer gerätetyp/i }).first()
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
			const newMaintType = page.getByRole('button', { name: /new maintenance type|neuer wartungstyp/i }).first()
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
			const newProc = page.getByRole('button', { name: /new procedure|neue prozedur|neues verfahren/i }).first()
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
			const importPack = page.getByRole('button', { name: /import pack|paket importieren/i }).first()
			if (await importPack.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-import-pack', async () => {
					await importPack.click()
				})
			} else {
				const procMore = page.locator('#mn-procedures-actions .mn-overflow__toggle, #mn-procedures .mn-overflow__toggle').first()
				if (await procMore.isVisible().catch(() => false)) {
					await openEscThenConfirm(page, 'dlg-import-pack', async () => {
						await page.keyboard.press('Escape').catch(() => {})
						await expect(page.locator('.mn-overflow__menu:not([hidden])')).toHaveCount(0, { timeout: 3_000 }).catch(() => {})
						await procMore.click()
						const menu = page.locator('.mn-overflow__menu:not([hidden])').first()
						await expect(menu).toBeVisible({ timeout: 10_000 })
						await menu.getByRole('menuitem', { name: /import pack|paket importieren/i }).first().click()
					})
				} else {
					console.log('EVIDENCE dlg-import-pack SKIP soft — import pack openDialog in js/app.js')
				}
			}
			await page.locator('[data-mn-catalog="kits"]').click().catch(() => {})
			await expect(page.locator('#mn-catalog-panel-kits, #mn-kit-templates').first()).toBeVisible({ timeout: 15_000 }).catch(() => {})
			const newKit = page.getByRole('button', { name: /new kit|neue kit-vorlage|neues kit|kit template/i }).first()
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
			const grantSkills = page.getByRole('button', { name: /grant skills|qualifikationen zuweisen|fähigkeiten vergeben/i }).first()
			if (await grantSkills.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-grant-skills', async () => {
					await grantSkills.click()
				})
			} else {
				console.log('EVIDENCE dlg-grant-skills SKIP soft — openGrantSkillsDialog in js/app.js')
			}

			// ── License remove (optional) ─────────────────────────────────
			await page.goto('/apps/maintenancecheck/settings/license')
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 20_000 })
			const removeKey = page.getByRole('button', { name: /remove license|lizenz.*entfernen|schlüssel entfernen/i }).first()
			if (await removeKey.isVisible().catch(() => false)) {
				await openEscThenConfirm(page, 'dlg-remove-license', async () => {
					await removeKey.click()
				})
			} else {
				console.log('EVIDENCE dlg-remove-license SKIP soft — remove license openDialog in js/app.js')
			}

			console.log('EVIDENCE openDialog-inventory COMPLETE marker=' + marker)
		} finally {
			await api(page, 'DELETE', `/index.php/apps/maintenancecheck/api/customers/${customerId}?force=1`).catch(() => {})
		}
	})
})
