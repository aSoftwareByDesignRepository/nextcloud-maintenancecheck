import { defineConfig, devices } from '@playwright/test'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const configDir = dirname(fileURLToPath(import.meta.url))
const envFile = resolve(configDir, 'tests/e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) {
			continue
		}
		const eq = trimmed.indexOf('=')
		if (eq <= 0) {
			continue
		}
		const key = trimmed.slice(0, eq).trim()
		let value = trimmed.slice(eq + 1).trim()
		if (
			(value.startsWith('"') && value.endsWith('"'))
			|| (value.startsWith("'") && value.endsWith("'"))
		) {
			value = value.slice(1, -1)
		}
		if (process.env[key] === undefined) {
			process.env[key] = value
		}
	}
}

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'

export default defineConfig({
	testDir: 'tests/e2e',
	timeout: 60_000,
	expect: { timeout: 15_000 },
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
		{ name: 'chromium-320', use: { ...devices['Desktop Chrome'], viewport: { width: 320, height: 640 } } },
	],
})
