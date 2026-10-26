import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const statement = `${config.baseUrl}/accounting_payables/supplier/6`;

const expect = async (page, selector, count, message) => {
    if (await page.locator(selector).count() !== count) {
        throw new Error(message);
    }
};

const submit = async (page) => {
    await page.click('.modal-footer [data-dialog-button="submit"]');
    await settle(page, 1500);
};

try {
    const page = await session.openPage();

    await page.goto(statement, { waitUntil: 'networkidle' });
    await expect(page, '#payable_advances tbody tr', 1, 'The advances table has no row for the empty message');

    await page.click('#title_bar button[data-href^="accounting_payables/opening_form/"]');
    await page.waitForSelector('.modal-body #supplier_opening_form', { timeout: 15000 });
    await page.selectOption('.modal-body #side', 'we_owe');
    await page.fill('.modal-body #amount', '1000');
    await page.fill('.modal-body #advance_date', '2026-01-01');
    await page.fill('.modal-body #note', 'smoke opening');
    await submit(page);
    await page.waitForSelector('#payable_purchases', { timeout: 15000 });
    await expect(page, '#payable_purchases a[href*="cancel_form/opening/"]', 1, 'The opening balance is not offered to be cancelled');

    await page.click('#title_bar button[data-href^="accounting_payables/payment_form/"]');
    await page.waitForSelector('.modal-body #advance_payment_form', { timeout: 15000 });
    await page.fill('.modal-body #amount', '250');
    await page.fill('.modal-body #reference_code', 'SMOKE-1');
    await submit(page);
    await page.waitForSelector('#payable_advances', { timeout: 15000 });
    await settle(page);
    await expect(page, '#payable_advances tbody tr td a[href*="cancel_form/supplier_advance/"]', 1, 'The advance is not listed with a way to cancel it');

    console.log('supplier advances recorded: an opening balance and a payment without a purchase');
} finally {
    await browser.close();
}
