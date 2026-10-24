import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const row = '#stock_location_1';
const url = `${config.baseUrl}/accounting_periods/opening?branch=1`;
const differences = `${row} button[data-href*="stock/differences_form"]`;

const expect = async (page, selector, count, message) => {
    if (await page.locator(selector).count() !== count) {
        throw new Error(message);
    }
};

try {
    const page = await session.openPage();
    await page.goto(url, { waitUntil: 'networkidle' });

    await expect(page, `${row} .badge.text-bg-primary`, 1, 'The stock location is not ready to post');
    await expect(page, `${row} .stock-post`, 1, 'The stock location offers no post button');
    await expect(page, differences, 1, 'The item left out is not offered to be taken in');

    await page.click(differences);
    await page.waitForSelector('.modal-body #opening_stock_differences_form', { timeout: 15000 });
    await expect(page, '#differences_table tbody tr', 1, 'The item left out is not listed in the form');
    await page.fill('.modal-body #reason', '');
    await page.click('.modal-footer [data-dialog-button="submit"]');
    await settle(page);
    if ((await page.locator('.modal-body #stock_form_error_box').textContent()).trim() === '') {
        throw new Error('The form accepted an empty reason');
    }

    await page.goto(url, { waitUntil: 'networkidle' });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
        page.click(`${row} .stock-post`)
    ]);
    await settle(page);

    await expect(page, `${row} .badge.text-bg-warning`, 1, 'A location with an item left out is not shown as needing a person');
    await expect(page, `${row} .badge.text-bg-success`, 0, 'A location with an item left out is shown as done');
    await expect(page, `${row} .stock-post`, 0, 'The posted location still offers to post');
    await expect(page, differences, 0, 'The posted location offers to take in what was left out without reversing');
    await expect(page, `${row} button[data-href*="opening/stock/reverse_form"]`, 1, 'The posted location does not offer to reverse');
    console.log('opening stock recorded, one item left out');
} finally {
    await browser.close();
}
