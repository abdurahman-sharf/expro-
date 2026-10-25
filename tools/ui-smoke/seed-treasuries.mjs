import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const treasuries = `${config.baseUrl}/accounting_treasuries`;
const transfers = `${treasuries}/transfers`;

const expect = async (page, selector, count, message) => {
    if (await page.locator(selector).count() !== count) {
        throw new Error(message);
    }
};

const submit = async (page) => {
    await page.click('.modal-footer [data-dialog-button="submit"]');
    await settle(page);
};

const addTreasury = async (page, name, kind) => {
    await page.goto(treasuries, { waitUntil: 'networkidle' });
    await page.click('#title_bar button[data-href="accounting_treasuries/view"]');
    await page.waitForSelector('.modal-body #treasury_form', { timeout: 15000 });
    await page.fill('.modal-body #name', name);
    await page.selectOption('.modal-body #kind', kind);
    await submit(page);
    await page.waitForSelector('.modal-body #treasury_form', { state: 'detached', timeout: 15000 });
};

try {
    const page = await session.openPage();

    await addTreasury(page, 'خزينة المعرض', 'cash');
    await addTreasury(page, 'البنك الأهلي', 'bank');

    await page.goto(treasuries, { waitUntil: 'networkidle' });
    await expect(page, '#table tbody tr', 3, 'The main treasury and the two added are not listed');

    await page.goto(transfers, { waitUntil: 'networkidle' });
    await page.click('#title_bar button[data-href="accounting_treasuries/transfer_form"]');
    await page.waitForSelector('.modal-body #transfer_form', { timeout: 15000 });
    await page.selectOption('.modal-body #from_treasury_id', { index: 1 });
    await page.selectOption('.modal-body #to_treasury_id', { index: 2 });
    await page.fill('.modal-body #amount', '25');
    await page.fill('.modal-body #note', 'smoke');
    await submit(page);
    await page.waitForSelector('.modal-body #transfer_form', { state: 'detached', timeout: 15000 });

    await page.goto(transfers, { waitUntil: 'networkidle' });
    await expect(page, '#table tbody tr', 1, 'The transfer is not listed');
    console.log('treasuries recorded: two added, one transfer');
} finally {
    await browser.close();
}
