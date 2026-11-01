// @ts-check
import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { login, primaryCreds } from './helpers/auth.js'
import { deleteUserLang, getUserLang, setUserLang } from './helpers/theming.js'

/**
 * Localized nav-label proof — the sidebar "Register" group must render the
 * localized NOUN (registry), never the sign-up verb (class: l10n nav-group
 * label mistranslation; intake 20261101T181126Z). Prior stale-locale captures
 * showed 'Aanmelden' (nl) / "S'inscrire" (fr). Also proves the label survives
 * the word-break rule (nav menu labels keep word breaks, c5f52b7).
 *
 * Writes craft/web/mn-nav-register-<locale>-desktop.png.
 */

const OUT = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/maintenancecheck/craft/web',
)

const PROOFS = [
	{ lang: 'nl', expectLabel: 'Register', forbid: 'Aanmelden' },
	{ lang: 'fr', expectLabel: 'Registre', forbid: "S'inscrire" },
]

test.describe('Localized nav group label (Register noun)', () => {
	test.describe.configure({ mode: 'serial' })

	for (const { lang, expectLabel, forbid } of PROOFS) {
		test(`Register group label renders as noun in ${lang}`, async ({ page }) => {
			const creds = primaryCreds()
			test.skip(!creds, 'Requires NC_E2E_* creds (tests/e2e/.env)')

			const prevLang = getUserLang(creds.username)
			await login(page, creds)
			try {
				setUserLang(creds.username, lang)
				await page.goto('/apps/maintenancecheck/customers')
				await expect(
					page.locator('#mn-customer-list, .mn-empty').first(),
				).toBeVisible({ timeout: 30_000 })

				const group = page.locator('button.nav-parent-toggle[aria-controls="mn-register-subnav"]')
				await expect(group).toBeVisible()
				await expect(group).toContainText(expectLabel)
				await expect(group).not.toContainText(forbid)

				await page.setViewportSize({ width: 1440, height: 900 })
				await page.waitForTimeout(350)
				mkdirSync(OUT, { recursive: true })
				await page.screenshot({
					path: resolve(OUT, `mn-nav-register-${lang}-desktop.png`),
					fullPage: false,
				})
			} finally {
				if (prevLang) {
					setUserLang(creds.username, prevLang)
				} else {
					deleteUserLang(creds.username)
				}
			}
		})
	}
})
