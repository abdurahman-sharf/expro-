import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const SUPPLIER = 'النور';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const addItems = async (page, codes) => {
    for (const code of codes) {
        await page.fill('#item', code);
        await page.press('#item', 'Enter');
        await settle(page);
    }
};

const pickSupplier = async (page) => {
    await page.locator('#supplier').pressSequentially(SUPPLIER, { delay: 90 });
    await page.waitForSelector('.ui-autocomplete li', { timeout: 10000 });
    await page.click('.ui-autocomplete li:first-child');
    await settle(page);
};

const finish = async (page, name) => {
    await page.click('#finish_receiving_button');
    await settle(page);

    if (await page.locator('#receipt_wrapper').count() === 0) {
        throw new Error(`The ${name} did not reach the receipt page`);
    }
    console.log(`${name} recorded`);
};

try {
    const purchase = await session.openPage();
    await purchase.goto(`${config.baseUrl}/receivings`, { waitUntil: 'networkidle' });
    await addItems(purchase, ['6281001', '6281005']);
    await pickSupplier(purchase);

    const label = await purchase.locator('#payment_types option').evaluateAll((options) => options.map((option) => option.textContent.trim()).find((text) => ['Due', 'مستحق'].includes(text)));
    await purchase.selectOption('#payment_types', { label });
    await finish(purchase, 'purchase');
    await purchase.close();

    const giveBack = await session.openPage();
    await giveBack.goto(`${config.baseUrl}/receivings`, { waitUntil: 'networkidle' });
    await giveBack.selectOption('#register_mode', 'return');
    await settle(giveBack);
    await addItems(giveBack, ['6281001']);
    await giveBack.fill('#return_origin', '1');
    await giveBack.press('#return_origin', 'Tab');
    await settle(giveBack);
    await pickSupplier(giveBack);
    await finish(giveBack, 'purchase return');
    await giveBack.close();
} finally {
    await browser.close();
}
