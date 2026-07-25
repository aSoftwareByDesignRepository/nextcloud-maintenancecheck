export async function login(page, { username, password }) {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	// Maintenance / upgrade interstitial has no login fields — fail fast with a clear signal.
	const maintenance = page.getByText(/maintenance mode|update is in progress|needs to be updated/i)
	if (await maintenance.first().isVisible({ timeout: 1500 }).catch(() => false)) {
		throw new Error('Nextcloud is in maintenance/upgrade mode — finish `occ upgrade` before E2E')
	}

	const userInput = page
		.getByRole('textbox', { name: /account name|email|benutzername|e-mail/i })
		.or(page.locator('#user'))
		.or(page.locator('input[name="user"]'))
		.first()
	const passInput = page
		.getByRole('textbox', { name: /^password$|^passwort$/i })
		.or(page.locator('#password'))
		.or(page.locator('input[name="password"]'))
		.first()

	await userInput.waitFor({ state: 'visible', timeout: 30_000 })
	await userInput.fill(username)
	await passInput.fill(password)
	await page.getByRole('button', { name: /log in|anmelden/i }).or(page.locator('input[type="submit"]')).first().click()

	// Fail fast on wrong credentials instead of waiting the full navigation timeout.
	const wrong = page.getByText(/wrong login or password|falscher login|ungültige anmeldedaten/i)
	const leftLogin = page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	const sawWrong = wrong.first().waitFor({ state: 'visible', timeout: 30_000 }).then(() => 'wrong')
	const winner = await Promise.race([
		leftLogin.then(() => 'ok'),
		sawWrong,
	])
	if (winner === 'wrong') {
		throw new Error(`Login failed for user "${username}" — wrong login or password`)
	}
}

export function credsFromEnv(prefix = 'E2E') {
	const username = process.env[`NC_${prefix}_USER`]
	const password = process.env[`NC_${prefix}_PASS`]
	if (!username || !password) {
		return null
	}
	return { username, password }
}

/** Prefer E2E user (stable in shared Docker), then ADMIN. */
export function primaryCreds() {
	return credsFromEnv('E2E') || credsFromEnv('ADMIN')
}

/** App-admin capable creds — prefer E2E when it is an NC admin (shared Docker). */
export function adminCreds() {
	return credsFromEnv('E2E') || credsFromEnv('ADMIN')
}
