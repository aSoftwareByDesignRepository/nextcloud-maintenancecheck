// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require(
	join(dirname(fileURLToPath(import.meta.url)), '../../../_shared/e2e/atlas-rendered-surface-contract.js'),
)

/**
 * Every authenticated page surface with lists/forms. Detail pages are covered
 * by per-journey specs (they need seeded entities); this contract sweeps the
 * list/form surfaces that break under shell CSS leaks.
 */
const SURFACES = [
	['/apps/maintenancecheck/', 'due board'],
	['/apps/maintenancecheck/customers', 'customers list'],
	['/apps/maintenancecheck/equipment', 'equipment list'],
	['/apps/maintenancecheck/visits', 'visits list'],
	['/apps/maintenancecheck/catalogs', 'catalogs'],
	['/apps/maintenancecheck/work-orders', 'work orders'],
	['/apps/maintenancecheck/dispatch', 'dispatch board'],
	['/apps/maintenancecheck/tours', 'tours board'],
	['/apps/maintenancecheck/kpi', 'kpi snapshot'],
	['/apps/maintenancecheck/exceptions', 'exceptions board'],
	['/apps/maintenancecheck/settings/access', 'settings access'],
	['/apps/maintenancecheck/settings/policies', 'settings policies'],
]

/**
 * Intentional marker-less designs: the checklist renders custom
 * result buttons per row (no bullets by design), the quick-start card is a
 * counter-styled step list, tour stops render order numbers inside the row,
 * and the chips "empty" row is a single hint row — none of them are
 * bullet-style content lists.
 */
const LIST_ALLOW = [
	'.mn-checklist',
	'.mn-quickstart',
	'.mn-tour-stops',
	'.mn-chips__empty',
	'.mn-wo-list__items',
].join(', ')

test.describe('Rendered-surface contract', () => {
	for (const [path, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			const creds = primaryCreds()
			test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
			await login(page, creds)
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await expect(page.locator('#mn-main-content')).toBeAttached({ timeout: 30_000 })
			await assertAtlasRenderedSurface(page, {
				content: '#mn-main-content',
				navExclude: '#app-navigation, nav, .mn-nav',
				listAllow: LIST_ALLOW,
			})
		})
	}
})
