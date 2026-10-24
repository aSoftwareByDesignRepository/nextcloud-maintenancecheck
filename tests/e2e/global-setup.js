import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'
import { resetE2eUserState } from '../../../_shared/e2e/reset-e2e-user-state.mjs'
// @ts-check
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { request } from '@playwright/test'
import { rememberSessionCookies } from './helpers/auth.js'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..')

/**
 * Clear leftover Nextcloud login throttling before the suite starts.
 *
 * Probe scripts or a crashed previous run may have left bruteforce attempts /
 * ratelimit buckets behind, making POST /login answer 429. The rate limiter
 * uses the DatabaseBackend on this dev stack (oc_ratelimit_entries +
 * oc_bruteforce_attempts), so truncating both tables resets it. This is
 * test-environment hygiene only — the dev MariaDB is truncated, never
 * production data.
 */
function clearLoginThrottling() {
	const sql =
		'DELETE FROM oc_bruteforce_attempts;' +
		' DELETE FROM oc_ratelimit_entries;'
	try {
		const out = execFileSync(
			'docker',
			[
				'compose', 'exec', '-T', 'mariadb',
				'bash', '-lc',
				`mariadb -u root -p"\${MYSQL_ROOT_PASSWORD}" nextcloud -e '${sql}'`,
			],
			{ cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 },
		)
		process.stdout.write(`[e2e globalSetup] login throttling cleared: ${String(out).trim()}\n`)
	} catch (err) {
		// Non-fatal: a non-docker host running against an unmanaged NC simply skips.
		process.stdout.write(
			`[e2e globalSetup] could not clear login throttling (${err && err.message ? err.message : err})\n`,
		)
	}
}

/**
 * Re-sync a fixture password to the .env SSOT.
 *
 * Defect class: e2e_mutated_instance_config_leftover — sibling farm suites
 * share the `admin` uid but pin DIFFERENT passwords in their own .env; a
 * lane that re-asserts its credential mid-farm leaves ours invalid until
 * re-synced (observed 2026-10-24: admin flipped to a sibling's pass → every
 * spec + the live API probe 401'd). Re-asserting under the instance lock is
 * symmetric with their setup and keeps the suite self-healing.
 * Non-fatal: a non-docker host running against an unmanaged NC simply skips.
 */
function occ(args, env = {}) {
	const envArgs = []
	for (const [k, v] of Object.entries(env)) {
		envArgs.push('-e', `${k}=${v}`)
	}
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', ...envArgs,
		'nextcloud', 'php', 'occ', ...args,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] })
}

function syncPassword(userId, password) {
	try {
		occ(['user:resetpassword', '--password-from-env', userId], { OC_PASS: password })
		return true
	} catch {
		return false
	}
}

/**
 * Canonicalize the shared instance's host config to OUR baseURL.
 *
 * Same leftover class as the credential flip: sibling suites pin
 * overwritehost + overwrite.cli.url to THEIR base and leave the residue
 * (observed 2026-10-24: a sibling left overwritehost=127.0.0.1:8081 while
 * our pages load localhost:8081 — NC-emitted absolute icon URLs crossed
 * origin and every console-error leg died on CSP img-src 'self').
 * Re-asserting under the instance lock is symmetric with their setup.
 * Non-fatal: unmanaged/remote instances simply skip.
 */
function canonicalizeHostConfig(baseURL) {
	try {
		const host = new URL(baseURL).host
		occ(['config:system:set', 'overwrite.cli.url', '--value', baseURL])
		occ(['config:system:set', 'overwritehost', '--value', host])
		process.stdout.write(`[e2e globalSetup] host config canonicalized to ${baseURL}\n`)
	} catch (err) {
		process.stdout.write(
			`[e2e globalSetup] host canonicalization skipped (${err && err.message ? err.message : err})\n`,
		)
	}
}

/**
 * Perform ONE real login per configured user and cache the session cookies.
 *
 * Every POST /login counts against the anonymous-IP rate limit; with ~370
 * specs the suite otherwise DoS-es itself mid-run. Workers reuse these cookies
 * via login() in helpers/auth.js and only fall back to the form when the
 * cached session is missing or rejected.
 */
async function primeSession(baseURL, username, password) {
	const context = await request.newContext({ baseURL })
	try {
		const loginPage = await context.get('/login')
		const html = await loginPage.text()
		const tokenMatch = html.match(/data-requesttoken="([^"]*)"/)
		const requesttoken = tokenMatch ? tokenMatch[1] : ''
		const res = await context.post('/login', {
			form: { user: username, password, requesttoken },
			headers: { Origin: baseURL, requesttoken },
			maxRedirects: 0,
		})
		const location = res.headers()['location'] || ''
		if (res.status() !== 303 || location.includes('/login')) {
			throw new Error(`login POST for "${username}" returned ${res.status()} -> ${location || '(no location)'}`)
		}
		const state = await context.storageState()
		rememberSessionCookies(username, state.cookies)
		process.stdout.write(`[e2e globalSetup] primed session for "${username}"\n`)
	} finally {
		await context.dispose()
	}
}

export default async function globalSetup(config) {
	await acquireSharedInstanceLock('maintenancecheck')
	resetE2eUserState()
	clearLoginThrottling()

	const baseURL = (config.projects[0] && config.projects[0].use && config.projects[0].use.baseURL)
		|| process.env.NC_BASE_URL
		|| 'http://localhost:8081'

	canonicalizeHostConfig(baseURL)

	const users = [
		[process.env.NC_E2E_USER, process.env.NC_E2E_PASS],
		[process.env.NC_ADMIN_USER, process.env.NC_ADMIN_PASS],
		[process.env.NC_TECH_USER, process.env.NC_TECH_PASS],
	].filter(([u, p]) => u && p)

	for (const [username, password] of users) {
		try {
			if (!syncPassword(username, password)) {
				process.stdout.write(`[e2e globalSetup] password sync skipped/failed for "${username}"\n`)
			}
			await primeSession(baseURL, username, password)
		} catch (err) {
			// Non-fatal: login() still works via the form for whichever user failed.
			process.stdout.write(
				`[e2e globalSetup] session priming failed for "${username}" (${err && err.message ? err.message : err})\n`,
			)
		}
	}
}
