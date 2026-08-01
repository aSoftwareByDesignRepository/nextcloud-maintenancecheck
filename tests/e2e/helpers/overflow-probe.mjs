// Ad-hoc diagnostic: find elements wider than #app-content at 320px.
// Usage: node tests/e2e/helpers/overflow-probe.mjs /apps/maintenancecheck/customers
import { chromium } from '@playwright/test'
import { login, primaryCreds } from './auth.js'

const path = process.argv[2] || '/apps/maintenancecheck/customers'
const browser = await chromium.launch()
const page = await browser.newPage({
	baseURL: process.env.NC_BASE_URL || 'http://localhost:8081',
	viewport: { width: 320, height: 640 },
})
await login(page, primaryCreds())
await page.goto(path)
await page.waitForSelector('#mn-main-content', { timeout: 30_000 })
await page.waitForTimeout(1500)
const report = await page.evaluate(() => {
	const app = document.querySelector('#app-content')
	const limit = app.clientWidth
	const bad = []
	for (const el of app.querySelectorAll('*')) {
		const rect = el.getBoundingClientRect()
		if (rect.width > limit + 1 || rect.right > limit + 1) {
			bad.push({
				el: `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${[...el.classList].join('.')}`,
				width: Math.round(rect.width),
				right: Math.round(rect.right),
				scrollParent: el.parentElement ? getComputedStyle(el.parentElement).overflowX : '',
			})
		}
	}
	return { limit, appScroll: app.scrollWidth, bad: bad.slice(0, 25) }
})
console.log(JSON.stringify(report, null, 2))
await browser.close()
