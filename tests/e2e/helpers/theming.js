// @ts-check
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..')

/** Selectable NC user themes (theming app theme ids). */
export const USER_THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

/**
 * Enable exactly one user theme through Nextcloud's own OCS API
 * (PUT /ocs/v2.php/apps/theming/api/v1/theme/{id}/enable) as the logged-in
 * user, then reload and wait for body[data-theme-*] to prove the switch.
 *
 * @param {import('@playwright/test').Page} page logged-in page
 * @param {string} themeId one of USER_THEMES
 */
export async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			})
			// 400 = theme was not enabled — acceptable idempotent outcome
			if (!res.ok && res.status !== 400) {
				problems.push(`disable ${id}: HTTP ${res.status}`)
			}
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, {
			method: 'PUT', credentials: 'same-origin', headers,
		})
		if (!res.ok && res.status !== 400) {
			problems.push(`enable ${target}: HTTP ${res.status}`)
		}
		return problems
	}, { target: themeId, all: USER_THEMES })
	if (failures.length > 0) {
		throw new Error(`Theme switch to "${themeId}" failed: ${failures.join('; ')}`)
	}
	await page.reload({ waitUntil: 'domcontentloaded' })
	await page.waitForSelector(`body[data-theme-${themeId}]`, { timeout: 15_000 })
}

/**
 * Back to system default: disable every explicit theme.
 *
 * @param {import('@playwright/test').Page} page logged-in page
 */
export async function resetUserTheme(page) {
	await page.evaluate(async (all) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		for (const id of all) {
			await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			}).catch(() => {})
		}
	}, USER_THEMES)
	await page.reload({ waitUntil: 'domcontentloaded' })
}

/** @param {string[]} occArgs */
function occ(occArgs) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...occArgs,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
}

/**
 * Set the instance-wide accent (primary) colour via occ theming:config.
 *
 * @param {string} hexColor e.g. '#B02E1C'
 */
export function setAccentColor(hexColor) {
	occ(['theming:config', 'primary_color', hexColor])
}

/** Remove the custom accent colour so the instance falls back to NC default.
 * Must go through theming:config --reset: a bare config:app:delete would not
 * bump the theming cachebuster and browsers would keep the stale accent CSS. */
export function resetAccentColor() {
	occ(['theming:config', 'primary_color', '--reset'])
}
