// @ts-check
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import {
	acquireThemeLock,
	deleteUserLang,
	getUserLang,
	releaseThemeLock,
	setUserLang,
} from './helpers/theming.js'

/**
 * Shell grid contract — r40 app-layout pack re-sync (shape-B bridge).
 *
 * css/common/app-layout.css re-synced donor-verbatim to the check-shell GRID
 * pack, which places DIRECT children of #content on grid tracks. This app's
 * shell keeps a single #maintenancecheck-app wrapper child (required by
 * js/common/mobile-nav.js restoreDomOrder), so shell-chrome.css carries a
 * documented `grid-column: 1 / -1` bridge; the interior layout stays the
 * pack's own descendant flex arm. This spec asserts the composited geometry
 * the pack was written to produce — and would have caught the verbatim
 * shape-B break (wrapper auto-placed into the 280px first track).
 *
 * Also writes the r40-packresync desktop-shell craft matrix:
 *   craft/web/r40-packresync/mn-shell-<view>-<locale>-<width>.png
 *   widths 1024/1200/1280/1440 (+ 768 drawer state), locales en + de.
 */

const OUT = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/maintenancecheck/craft/web/r40-packresync',
)

const DESKTOP_WIDTHS = [1024, 1200, 1280, 1440]
const LOCALES = ['en', 'de']

const VIEWS = [
	{ name: 'due', path: '/apps/maintenancecheck/', ready: '#mn-due-board, .mn-empty, #mn-main-content' },
	{ name: 'work-orders', path: '/apps/maintenancecheck/work-orders', ready: '#mn-wo-list, .mn-empty, #mn-main-content' },
	{ name: 'settings-access', path: '/apps/maintenancecheck/settings/access', ready: '#mn-settings-access, #mn-main-content' },
]

const capturedFiles = []

async function shot(page, name) {
	mkdirSync(OUT, { recursive: true })
	await page.waitForTimeout(300)
	await page.screenshot({ path: resolve(OUT, name), fullPage: false })
	capturedFiles.push(name)
}

/** Geometry of the composited shell, measured in the page. */
async function shellGeometry(page) {
	return page.evaluate(() => {
		const r = (sel) => {
			const el = document.querySelector(sel)
			if (!el) return null
			const b = el.getBoundingClientRect()
			const cs = getComputedStyle(el)
			return {
				x: b.x, y: b.y, w: b.width, h: b.height,
				display: cs.display, gridCols: cs.gridTemplateColumns,
				flexBasis: cs.flexBasis, position: cs.position,
				overflowY: cs.overflowY,
			}
		}
		const ac = document.querySelector('#app-content')
		const acBound = ac ? ac.getBoundingClientRect() : null
		const leafOverflow = []
		if (ac && acBound) {
			for (const el of ac.querySelectorAll('*')) {
				const b = el.getBoundingClientRect()
				if (b.width === 0 || b.height === 0) continue
				const cs = getComputedStyle(el)
				if (cs.position === 'fixed' || cs.position === 'absolute') continue
				if (b.right <= acBound.right + 1.5 && b.left >= acBound.left - 1.5) continue
				// Intentional inner scroll: a scrollable ancestor owns the extra
				// width — the leaf is reachable, not clipped by the track.
				let anc = el.parentElement
				let innerScroll = false
				while (anc && anc !== ac) {
					const ox = getComputedStyle(anc).overflowX
					if ((ox === 'auto' || ox === 'scroll') && anc.scrollWidth > anc.clientWidth + 1) {
						innerScroll = true
						break
					}
					anc = anc.parentElement
				}
				if (!innerScroll) {
					leafOverflow.push(`${el.tagName}.${String(el.className).split(' ')[0]}`)
					if (leafOverflow.length >= 6) break
				}
			}
		}
		return {
			content: r('#content.app-maintenancecheck'),
			wrapper: r('#maintenancecheck-app'),
			nav: r('#app-navigation'),
			appContent: r('#app-content'),
			skipLinkNav: r('a.mn-skip-link--nav'),
			h1A11y: r('h1#page-heading-level-1'),
			leafOverflow,
			acScrolls: ac ? ac.scrollHeight > ac.clientHeight + 2 : null,
			bodyScrolls: document.documentElement.scrollHeight > window.innerHeight + 2,
		}
	})
}

test.describe('r40 shell grid contract (shape-B bridge)', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(10 * 60_000)

	let priorLang = ''
	test.beforeAll(async ({}, testInfo) => {
		testInfo.setTimeout(10 * 60_000)
		await acquireThemeLock()
		const creds = primaryCreds()
		if (creds) priorLang = getUserLang(creds.username)
	})
	test.afterAll(async () => {
		const creds = primaryCreds()
		if (creds) {
			if (priorLang) setUserLang(creds.username, priorLang)
			else deleteUserLang(creds.username)
		}
		releaseThemeLock()
		if (capturedFiles.length > 0) {
			mkdirSync(OUT, { recursive: true })
			writeFileSync(
				resolve(OUT, '_manifest.json'),
				JSON.stringify(
					{
						app: 'maintenancecheck',
						lane: 'ds_chrome',
						pack: 'r40 app-layout grid re-sync + shape-B bridge',
						widths: [...DESKTOP_WIDTHS, 768],
						locales: LOCALES,
						files: capturedFiles,
					},
					null,
					2,
				),
			)
		}
	})

	for (const lang of LOCALES) {
		test(`desktop shell composited ${lang} @1024/1200/1280/1440`, async ({ page }) => {
			const creds = primaryCreds()
			test.skip(!creds, 'Requires NC_E2E_* creds')
			setUserLang(creds.username, lang)
			await login(page, creds)

			for (const w of DESKTOP_WIDTHS) {
				await page.setViewportSize({ width: w, height: 800 })
				for (const view of VIEWS) {
					await page.goto(view.path)
					await expect(page.locator(view.ready).first()).toBeVisible({ timeout: 30_000 })
					const g = await shellGeometry(page)
					// Grid shell composited: #content grid, two tracks ≥1024.
					expect(g.content.display, `${lang}@${w} ${view.name} #content display`).toBe('grid')
					const tracks = String(g.content.gridCols).split(' ').filter(Boolean)
					expect(tracks.length, `${lang}@${w} ${view.name} grid tracks`).toBe(2)
					// Shape-B bridge: wrapper spans every track — never confined to col 1.
					expect(g.wrapper.w, `${lang}@${w} ${view.name} wrapper width`).toBe(w)
					// Nav band: 240px mid-desktop (<1280), 280px ≥1280 — donor parity.
					const expectNav = w < 1280 ? 240 : 280
					expect(Math.abs(g.nav.w - expectNav), `${lang}@${w} ${view.name} nav width`).toBeLessThanOrEqual(2)
					expect(g.nav.x, `${lang}@${w} ${view.name} nav x`).toBeLessThanOrEqual(1)
					// Main pane beside nav, never wrapped to a second row.
					expect(Math.abs(g.appContent.x - g.nav.w), `${lang}@${w} ${view.name} content x`).toBeLessThanOrEqual(2)
					expect(Math.abs(g.appContent.w - (w - g.nav.w)), `${lang}@${w} ${view.name} content width`).toBeLessThanOrEqual(2)
					expect(g.appContent.y, `${lang}@${w} ${view.name} content y`).toBeLessThanOrEqual(60)
					// The core-bug killer: #app-content flex-basis pinned 0%, never 100vw.
					expect(g.appContent.flexBasis, `${lang}@${w} ${view.name} flex-basis`).toBe('0%')
					// Single vertical scroller; a11y siblings present + out of flow.
					expect(g.bodyScrolls, `${lang}@${w} ${view.name} body scroll`).toBe(false)
					expect(g.skipLinkNav).not.toBeNull()
					expect(g.h1A11y).not.toBeNull()
					expect(g.h1A11y.position, `${lang}@${w} ${view.name} h1 out of flow`).toBe('absolute')
					expect(g.leafOverflow, `${lang}@${w} ${view.name} leaf overflow ${g.leafOverflow}`).toEqual([])
					if (view.name === 'due') {
						await shot(page, `mn-shell-${view.name}-${lang}-${w}.png`)
					}
				}
				// one capture per additional surface per locale at each width
				for (const view of VIEWS.slice(1)) {
					await page.goto(view.path)
					await expect(page.locator(view.ready).first()).toBeVisible({ timeout: 30_000 })
					await shot(page, `mn-shell-${view.name}-${lang}-${w}.png`)
				}
			}
		})
	}

	test('mobile drawer ownership intact <1024 (portal + restore)', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* creds')
		setUserLang(creds.username, 'en')
		await login(page, creds)
		await page.setViewportSize({ width: 768, height: 1024 })
		await page.goto('/apps/maintenancecheck/')
		await expect(page.locator('#mn-due-board, .mn-empty, #mn-main-content').first()).toBeVisible({ timeout: 30_000 })

		const g0 = await shellGeometry(page)
		expect(g0.content.display).toBe('grid')
		expect(g0.wrapper.w).toBe(768)
		expect(g0.appContent.w).toBe(768)
		// nav parked off-canvas (drawer), content owns the full track
		expect(g0.nav.x + g0.nav.w).toBeLessThanOrEqual(0)

		await page.locator('#mn-nav-toggle').click()
		await expect(page.locator('#app-navigation.mn-nav--open')).toBeVisible()
		const portalState = await page.evaluate(() => ({
			navParentIsBody: document.querySelector('#app-navigation')?.parentElement === document.body,
			backdropVisible: !document.querySelector('#mn-nav-backdrop')?.hidden,
		}))
		expect(portalState.navParentIsBody, 'drawer portals to body').toBe(true)
		expect(portalState.backdropVisible, 'backdrop shown').toBe(true)
		await shot(page, 'mn-shell-drawer-open-en-768.png')

		await page.locator('#mn-nav-backdrop').click()
		await page.waitForTimeout(350)
		const restored = await page.evaluate(() => ({
			navParent: document.querySelector('#app-navigation')?.parentElement?.id,
			open: document.querySelector('#app-navigation')?.classList.contains('mn-nav--open'),
		}))
		expect(restored.navParent, 'nav restored into wrapper').toBe('maintenancecheck-app')
		expect(restored.open, 'drawer closed').toBe(false)
	})
})
