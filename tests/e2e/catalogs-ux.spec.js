// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Catalogs UX gauntlet — one list via chips, no five-card wall, no dead Status
 * column, click-name-to-edit, procedure More overflow.
 */
test.describe('catalogs Bachus UX', () => {
	test('chip panels, flows, and WCAG 2.1 AA', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

		await login(page, creds)
		await page.goto('/apps/maintenancecheck/catalogs')

		const equip = page.locator('#mn-equip-types')
		const maint = page.locator('#mn-maint-types')
		const procedures = page.locator('#mn-procedures')
		const skills = page.locator('#mn-skills')
		const kits = page.locator('#mn-kit-templates')

		await expect(page.locator('.mn-catalogs')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-catalogs-toolbar')).toBeVisible()
		await expect(page.locator('.mn-catalogs__pair')).toHaveCount(0)
		await expect(page.locator('.mn-columns')).toHaveCount(0)
		await expect(page.locator('[data-mn-catalog]')).toHaveCount(5)

		await expect(page.locator('#mn-catalog-panel-equip')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#mn-catalog-panel-maint')).toBeHidden()
		await expect(equip).toBeVisible({ timeout: 30_000 })
		await expect(equip).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		// Switch chips — only one panel visible.
		await page.locator('[data-mn-catalog="procedures"]').click()
		await expect(page.locator('#mn-catalog-panel-procedures')).toBeVisible()
		await expect(page.locator('#mn-catalog-panel-equip')).toBeHidden()
		await expect(procedures).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('[data-mn-catalog="skills"]').click()
		await expect(page.locator('#mn-catalog-panel-skills')).toBeVisible()
		await expect(skills).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('[data-mn-catalog="kits"]').click()
		await expect(page.locator('#mn-catalog-panel-kits')).toBeVisible()
		await expect(kits).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('[data-mn-catalog="maint"]').click()
		await expect(page.locator('#mn-catalog-panel-maint')).toBeVisible()
		await expect(maint).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

		await page.locator('[data-mn-catalog="equip"]').click()
		await expect(page.locator('#mn-catalog-panel-equip')).toBeVisible()

		// Dead Status column must not appear on the visible catalog table.
		await expect(equip.locator('th', { hasText: /^Status$/i })).toHaveCount(0)

		// Equipment: clickable name opens edit (office) — no Edit staircase.
		const equipTable = equip.locator('table.mn-table')
		if (await equipTable.count()) {
			await expect(equip.locator('td .mn-btn', { hasText: /^Edit$/i })).toHaveCount(0)
			const nameBtn = equip.locator('button.mn-table-link').first()
			if (await nameBtn.count()) {
				await nameBtn.click()
				const dialog = page.locator('[role="dialog"], .mn-dialog, .oc-dialog').first()
				await expect(dialog).toBeVisible({ timeout: 10_000 })
				await page.getByRole('button', { name: /cancel|abbrechen/i }).first().click()
				await expect(dialog).toBeHidden({ timeout: 10_000 })
			}
		}

		// Procedures: primary Edit + More overflow (not Fork/Deactivate staircase).
		await page.locator('[data-mn-catalog="procedures"]').click()
		await expect(page.locator('#mn-catalog-panel-procedures')).toBeVisible()
		const procTable = procedures.locator('table.mn-table')
		if (await procTable.count()) {
			await expect(procedures.locator('td .mn-btn', { hasText: /^Fork$/i })).toHaveCount(0)
			await expect(procedures.locator('td .mn-btn', { hasText: /^Deactivate$/i })).toHaveCount(0)
			const moreToggle = procedures.locator('.mn-overflow__toggle').first()
			if (await moreToggle.count()) {
				await moreToggle.click()
				const menu = page.locator('.mn-overflow__menu:not([hidden])').first()
				await expect(menu).toBeVisible()
				await expect(menu.getByRole('menuitem', { name: /fork|abzweigen/i })).toBeVisible()
				await page.keyboard.press('Escape')
				await expect(menu).toBeHidden()
			}
		}

		// Header: New procedure + pack More — not three primary header buttons.
		const procActions = page.locator('#mn-procedures-actions')
		await expect(procActions.getByRole('button', { name: /new procedure|neues verfahren/i })).toBeVisible()
		await expect(procActions.locator('> .mn-btn', { hasText: /^Export pack$/i })).toHaveCount(0)
		await expect(procActions.locator('> .mn-btn', { hasText: /^Import pack$/i })).toHaveCount(0)

		const axe = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.exclude('#header')
			.exclude('#content-vue')
			.analyze()
		expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([])
	})
})
