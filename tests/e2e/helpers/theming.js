// @ts-check
import { execFileSync } from 'child_process'
import { existsSync, mkdirSync, readFileSync, rmSync, statSync, utimesSync, writeFileSync } from 'fs'
import { tmpdir } from 'os'
import { dirname, join, resolve } from 'path'
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

// ── Cross-worker shared-state mutex ──────────────────────────────────────
// User themes are per-user server state, the accent colour and the app
// policies/office lists are instance-wide. Every spec that flips them must
// run exclusively: otherwise parallel workers race — e.g. one project's
// resetUserTheme() wipes the theme another worker just enabled and waits on
// body[data-theme-*] for, or one project's config/policies reset flips
// skillsEnforcement back to 'warn' mid-assertion in a sibling worker.
const STATE_LOCK_STALE_MS = 5 * 60_000
const stateLockHeartbeats = new Map()

/** @param {string} key */
function lockDir(key) {
	return join(tmpdir(), `maintenancecheck-e2e-state-${key}.lock`)
}

/** @param {number} pid */
function pidAlive(pid) {
	if (!Number.isInteger(pid) || pid <= 0) {
		return false
	}
	try {
		process.kill(pid, 0)
		return true
	} catch (err) {
		return err.code === 'EPERM'
	}
}

/**
 * Acquire a named cross-worker mutex (blocking, tmpdir-based). Heartbeat
 * keeps the lock fresh so a long critical section is never mistaken for a
 * dead holder.
 * @param {string} key e.g. 'theme' | 'instance-config'
 * @param {{ timeoutMs?: number }} [opts]
 */
export async function acquireStateLock(key, { timeoutMs = 25 * 60_000 } = {}) {
	const dir = lockDir(key)
	const deadline = Date.now() + timeoutMs
	for (;;) {
		try {
			mkdirSync(dir)
			writeFileSync(join(dir, 'pid'), String(process.pid))
			const hb = setInterval(() => {
				try {
					utimesSync(dir, new Date(), new Date())
				} catch { /* released concurrently */ }
			}, 15_000)
			hb.unref()
			stateLockHeartbeats.set(key, hb)
			return
		} catch (err) {
			if (err.code !== 'EEXIST') {
				throw err
			}
		}
		// Lock exists: steal it when the holder is dead or the dir went stale.
		try {
			const pid = Number(readFileSync(join(dir, 'pid'), 'utf8'))
			const stale = Date.now() - statSync(dir).mtimeMs > STATE_LOCK_STALE_MS
			if (!pidAlive(pid) || stale) {
				rmSync(dir, { recursive: true, force: true })
				continue
			}
		} catch { /* lock vanished between checks — retry */ }
		if (Date.now() > deadline) {
			throw new Error(`Timed out waiting for the e2e shared-state lock "${key}"`)
		}
		await new Promise((r) => setTimeout(r, 500))
	}
}

/** Release a named cross-worker mutex. Safe to call when not held. */
export function releaseStateLock(key) {
	const hb = stateLockHeartbeats.get(key)
	if (hb) {
		clearInterval(hb)
		stateLockHeartbeats.delete(key)
	}
	const dir = lockDir(key)
	try {
		const pid = Number(readFileSync(join(dir, 'pid'), 'utf8'))
		if (pid === process.pid && existsSync(dir)) {
			rmSync(dir, { recursive: true, force: true })
		}
	} catch { /* already gone */ }
}

/** @param {{ timeoutMs?: number }} [opts] */
export async function acquireThemeLock(opts = {}) {
	return acquireStateLock('theme', opts)
}

export function releaseThemeLock() {
	releaseStateLock('theme')
}
