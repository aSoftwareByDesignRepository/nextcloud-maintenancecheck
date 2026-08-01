// @ts-check
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'

/**
 * Interaction-level WCAG 2.1 AA checks that axe cannot cover:
 * skip link, visible focus indicators (2.4.7), keyboard accordion
 * operation (2.1.1), touch target sizes (2.5.5) and reduced motion.
 */

test('skip link appears on focus and moves focus to main content', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const skipLink = page.locator('.mn-skip-link[href="#mn-main-content"]')
	await skipLink.focus()
	const box = await skipLink.boundingBox()
	expect(box, 'focused skip link must be on-screen').not.toBeNull()
	expect(box.x, 'focused skip link must be inside the viewport').toBeGreaterThanOrEqual(0)
	expect(box.height, 'skip link touch target').toBeGreaterThanOrEqual(43)

	await page.keyboard.press('Enter')
	await expect(page.locator('#mn-main-content')).toBeFocused()
})

test('keyboard focus produces a visible ring on app controls', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const targets = [
		page.locator('#app-navigation .nav-menu a').first(),
		page.locator('#app-navigation .nav-parent-toggle').first(),
		page.locator('.mn-breadcrumb__link').first(),
	]
	for (const target of targets) {
		if (!await target.isVisible().catch(() => false)) {
			continue
		}
		// Keyboard-originated focus (Tab from a programmatic anchor) triggers :focus-visible
		await target.evaluate((el) => {
			el.focus()
		})
		await page.keyboard.press('Shift')
		const outline = await target.evaluate((el) => {
			const style = getComputedStyle(el)
			return { width: style.outlineWidth, style: style.outlineStyle }
		})
		// Focus ring must be at least 2px and actually drawn (design system §5, WCAG 2.4.7)
		if (outline.style !== 'none') {
			expect(parseFloat(outline.width), 'focus ring width').toBeGreaterThanOrEqual(2)
		} else {
			// Some controls draw the ring via box-shadow instead
			const shadow = await target.evaluate((el) => getComputedStyle(el).boxShadow)
			expect(shadow, 'control must have outline or box-shadow focus indicator').not.toEqual('none')
		}
	}
})

test('sidebar accordion is fully keyboard operable', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#app-navigation')).toBeVisible({ timeout: 30_000 })

	const toggle = page.locator('#app-navigation .nav-parent-toggle').first()
	test.skip(!await toggle.isVisible().catch(() => false), 'No accordion for this role')

	const submenuId = await toggle.getAttribute('aria-controls')
	const submenu = page.locator(`#${submenuId}`)
	const initiallyExpanded = (await toggle.getAttribute('aria-expanded')) === 'true'

	await toggle.focus()
	await page.keyboard.press('Enter')
	await expect(toggle).toHaveAttribute('aria-expanded', String(!initiallyExpanded))
	if (!initiallyExpanded) {
		await expect(submenu).toBeVisible()
		// Child links must be reachable and expose accessible names
		const firstChild = submenu.locator('a').first()
		await expect(firstChild).toBeVisible()
	}
	await page.keyboard.press('Enter')
	await expect(toggle).toHaveAttribute('aria-expanded', String(initiallyExpanded))
})

test('touch targets are at least 44px on mobile and desktop viewports', async ({ page }, testInfo) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	for (const path of ['/apps/maintenancecheck/', '/apps/maintenancecheck/work-orders']) {
		await page.goto(path)
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const undersized = await page.evaluate(() => {
			const selectors = [
				'#app-content .mn-btn',
				'#app-content .mn-btn--sm',
				'#app-content .mn-input',
				'#app-content select',
				'#app-content .mn-breadcrumb__link',
				'#app-navigation .nav-menu > li > a',
				'#app-navigation .nav-parent-toggle',
				'#app-navigation .nav-submenu a',
			]
			const bad = []
			for (const el of document.querySelectorAll(selectors.join(','))) {
				const rect = el.getBoundingClientRect()
				if (rect.width === 0 && rect.height === 0) {
					continue // hidden (collapsed drawer / inactive section)
				}
				// 43.5 tolerance for subpixel layout rounding
				if (rect.height < 43.5 || rect.width < 43.5) {
					bad.push(`${el.tagName.toLowerCase()}.${[...el.classList].join('.')} ${Math.round(rect.width)}x${Math.round(rect.height)} "${(el.textContent || '').trim().slice(0, 30)}"`)
				}
			}
			return bad
		})
		expect(undersized, `undersized touch targets on ${path} (${testInfo.project.name})`).toEqual([])
	}
})

test('shell header uses design-system column grid (not legacy flex wrap)', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const layout = await page.evaluate(() => {
		const header = document.querySelector('.mn-page-header')
		const main = document.querySelector('.mn-page-header__main')
		if (!header || !main) {
			return null
		}
		const hs = getComputedStyle(header)
		const ms = getComputedStyle(main)
		return {
			headerDisplay: hs.display,
			headerDirection: hs.flexDirection,
			mainDisplay: ms.display,
			mainColumns: ms.gridTemplateColumns,
		}
	})
	expect(layout, 'page header must exist').not.toBeNull()
	expect(layout.headerDirection).toBe('column')
	expect(layout.mainDisplay).toBe('grid')
})

test('toast region sits below the Nextcloud header via overlay token', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const ok = await page.evaluate(() => {
		const region = document.getElementById('mn-toast-region')
		if (!region) {
			return false
		}
		const top = parseFloat(getComputedStyle(region).top) || 0
		const header = parseFloat(getComputedStyle(document.body).getPropertyValue('--header-height')) || 50
		// Must clear the NC header (token + space-2), never sit under it
		return top >= header
	})
	expect(ok, 'toast region top must be ≥ header height').toBe(true)
})

test('prefers-reduced-motion collapses app transitions', async ({ page }) => {
	const creds = primaryCreds()
	test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

	await page.emulateMedia({ reducedMotion: 'reduce' })
	await login(page, creds)
	await page.goto('/apps/maintenancecheck/')
	await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

	const btn = page.locator('.mn-btn').first()
	test.skip(!await btn.isVisible().catch(() => false), 'No button rendered')
	const duration = await btn.evaluate((el) => getComputedStyle(el).transitionDuration)
	const maxSeconds = Math.max(...duration.split(',').map((d) => parseFloat(d) || 0))
	expect(maxSeconds, '.mn-btn transition under reduced motion').toBeLessThanOrEqual(0.02)
})
