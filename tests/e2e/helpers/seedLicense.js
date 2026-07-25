import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const nextcloudRoot = resolve(appRoot, '../..')
const scriptInContainer = '/var/www/html/custom_apps/maintenancecheck/tests/e2e/helpers/seed-license.php'

/** Deterministic test public key (Mn2TestSigning / license_mn2.json). */
const TEST_PUBLIC_KEY_B64 = 'x8Ekm43mhV5gYygbwnmkCA_-9DTsRSufH_OoRB6pis0'

/**
 * Apply or clear an MN2 fixture through Docker CLI with the test trust anchor.
 * Does not weaken the Apache/FPM production key (override is CLI-process only).
 *
 * @param {'expired'|'valid'|'clear'} action
 */
export function seedLicenseViaCli(action) {
	const args = [
		'compose', 'exec', '-T',
		'-e', 'MN_ALLOW_VENDOR_KEY_OVERRIDE=1',
		'-e', `MN_VENDOR_PUBLIC_KEY_B64=${TEST_PUBLIC_KEY_B64}`,
		'nextcloud',
		'php', scriptInContainer, action,
	]
	const out = execFileSync('docker', args, {
		cwd: nextcloudRoot,
		encoding: 'utf8',
		timeout: 60_000,
	})
	return out.trim()
}
