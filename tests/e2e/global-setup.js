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
	clearLoginThrottling()

	const baseURL = (config.projects[0] && config.projects[0].use && config.projects[0].use.baseURL)
		|| process.env.NC_BASE_URL
		|| 'http://localhost:8081'

	const users = [
		[process.env.NC_E2E_USER, process.env.NC_E2E_PASS],
		[process.env.NC_ADMIN_USER, process.env.NC_ADMIN_PASS],
		[process.env.NC_TECH_USER, process.env.NC_TECH_PASS],
	].filter(([u, p]) => u && p)

	for (const [username, password] of users) {
		try {
			await primeSession(baseURL, username, password)
		} catch (err) {
			// Non-fatal: login() still works via the form for whichever user failed.
			process.stdout.write(
				`[e2e globalSetup] session priming failed for "${username}" (${err && err.message ? err.message : err})\n`,
			)
		}
	}
}
