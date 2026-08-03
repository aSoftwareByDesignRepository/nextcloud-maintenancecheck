// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds } from './helpers/auth.js'
import { setUserTheme, resetUserTheme, USER_THEMES } from './helpers/theming.js'

/**
 * WCAG 1.4.3: Cancel-visit danger CTA must keep solid element-error fill +
 * light on-fill text across every selectable NC theme (never white on the
 * pale --color-error tint).
 */

async function mountDangerDialog(page) {
	await page.evaluate(() => {
		document.querySelectorAll('[data-mn-contrast-probe]').forEach((n) => n.remove())
		const overlay = document.createElement('div')
		overlay.className = 'modal-backdrop mn-dialog-overlay'
		overlay.setAttribute('data-mn-contrast-probe', '1')
		const dialog = document.createElement('div')
		dialog.className = 'modal mn-dialog'
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		const title = document.createElement('h2')
		title.className = 'mn-dialog__title'
		title.textContent = 'Cancel visit'
		const text = document.createElement('p')
		text.className = 'mn-dialog__text'
		text.textContent = 'This ends the visit without a result. No follow-up visit is created — you can schedule one manually on the plan later.'
		const actions = document.createElement('div')
		actions.className = 'mn-dialog__actions'
		const keep = document.createElement('button')
		keep.type = 'button'
		keep.className = 'mn-btn mn-btn--tertiary button'
		keep.textContent = 'Keep visit'
		const danger = document.createElement('button')
		danger.type = 'button'
		danger.className = 'mn-btn mn-btn--danger button'
		danger.textContent = 'Cancel visit'
		actions.appendChild(keep)
		actions.appendChild(danger)
		dialog.appendChild(title)
		dialog.appendChild(text)
		dialog.appendChild(actions)
		overlay.appendChild(dialog)
		document.body.appendChild(overlay)
	})
}

function parseRgb(cssColor) {
	if (!cssColor) return null
	const rgb = cssColor.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/)
	if (rgb) {
		return { r: Number(rgb[1]), g: Number(rgb[2]), b: Number(rgb[3]) }
	}
	// color-mix() often serialises as color(srgb 0.56 0.20 0.20)
	const srgb = cssColor.match(/color\(\s*srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/i)
	if (srgb) {
		return {
			r: Math.round(Number(srgb[1]) * 255),
			g: Math.round(Number(srgb[2]) * 255),
			b: Math.round(Number(srgb[3]) * 255),
		}
	}
	return null
}


/** Relative luminance 0–1 (sRGB). */
function relLuminance({ r, g, b }) {
	const lin = [r, g, b].map((c) => {
		const s = c / 255
		return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2]
}

function contrastRatio(a, b) {
	const L1 = relLuminance(a)
	const L2 = relLuminance(b)
	const light = Math.max(L1, L2)
	const dark = Math.min(L1, L2)
	return (light + 0.05) / (dark + 0.05)
}

test.describe('Danger button contrast × all NC themes', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test('mn-btn--danger passes AA in light/dark/highcontrast (+ custom accent)', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run once')
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* / NC_ADMIN_*')

		await login(page, creds)
		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })

		const report = []

		for (const theme of USER_THEMES) {
			await setUserTheme(page, theme)
			await expect(page.locator('#mn-main-content')).toBeVisible({ timeout: 30_000 })
			await mountDangerDialog(page)

			const danger = page.locator('[data-mn-contrast-probe] button.mn-btn--danger').first()
			await expect(danger).toBeVisible({ timeout: 10_000 })

			const sample = await danger.evaluate((el) => {
				const s = getComputedStyle(el)
				const body = getComputedStyle(document.body)
				return {
					bg: s.backgroundColor,
					fg: s.color,
					tokenError: body.getPropertyValue('--color-error').trim(),
					tokenElementError: body.getPropertyValue('--color-element-error').trim(),
					tokenDangerFill: body.getPropertyValue('--mn-danger-fill').trim(),
					tokenOnFill: body.getPropertyValue('--mn-danger-on-fill').trim(),
				}
			})

			const bg = parseRgb(sample.bg)
			const fg = parseRgb(sample.fg)
			expect(bg, `${theme}: bad bg ${sample.bg}`).toBeTruthy()
			expect(fg, `${theme}: bad fg ${sample.fg}`).toBeTruthy()

			const ratio = contrastRatio(bg, fg)
			const fillLum = relLuminance(bg)

			report.push({
				theme,
				bg: sample.bg,
				fg: sample.fg,
				ratio: Number(ratio.toFixed(2)),
				fillLum: Number(fillLum.toFixed(3)),
				tokenError: sample.tokenError,
				tokenElementError: sample.tokenElementError || '(fallback)',
				tokenDangerFill: sample.tokenDangerFill,
			})

			// Pale tint failure mode: fill nearly white/pink (luminance ≳ 0.85).
			expect(fillLum, `${theme}: fill too light ${sample.bg} (tokens error=${sample.tokenError} element=${sample.tokenElementError})`).toBeLessThan(0.55)
			// WCAG AA normal text ≥ 4.5:1
			expect(ratio, `${theme}: contrast ${ratio.toFixed(2)}:1 bg=${sample.bg} fg=${sample.fg}`).toBeGreaterThanOrEqual(4.5)

			const results = await new AxeBuilder({ page })
				.include('[data-mn-contrast-probe]')
				.withTags(['wcag2aa', 'wcag21aa'])
				.analyze()
			const contrast = results.violations.filter((v) => v.id === 'color-contrast')
			expect(contrast, `${theme}:\n${JSON.stringify(contrast, null, 2)}`).toEqual([])

			await page.evaluate(() => {
				document.querySelectorAll('[data-mn-contrast-probe]').forEach((n) => n.remove())
			})
		}

		await resetUserTheme(page)
		// Helpful when debugging a matrix failure in CI logs.
		console.log('danger-contrast matrix:', JSON.stringify(report, null, 2))
	})
})
