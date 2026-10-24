import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const row = '#stock_location_1';
const ready = `${row} .badge.text-bg-primary`;
const posted = `${row} .badge.text-bg-success`;

try {
    const page = await session.openPage();
    await page.goto(`${config.baseUrl}/accounting_periods/opening?branch=1`, { waitUntil: 'networkidle' });

    if (await page.locator(ready).count() !== 1 || await page.locator(`${row} .stock-post`).count() !== 1) {
        throw new Error('The stock location is not ready to post');
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
        page.click(`${row} .stock-post`)
    ]);
    await settle(page);

    if (await page.locator(posted).count() !== 1 || await page.locator(`${row} .stock-post`).count() !== 0) {
        throw new Error('The opening stock was not posted from the screen');
    }
    if (await page.locator(`${row} button[data-href*="opening/stock/reverse_form"]`).count() !== 1) {
        throw new Error('The posted location does not offer to reverse');
    }
    console.log('opening stock recorded');
} finally {
    await browser.close();
}
