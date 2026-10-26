import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const ITEM = '6281001';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const amountOf = async (locator) => Number((await locator.first().innerText()).replace(/[^0-9.]/g, ''));

const edit = async (page, name, value) => {
    await page.fill(`form.cart-line [name="${name}"]`, value);
    await page.press(`form.cart-line [name="${name}"]`, 'Tab');
    await settle(page);
};

try {
    const page = await session.openPage();
    await page.goto(`${config.baseUrl}/receivings`, { waitUntil: 'networkidle' });
    await page.fill('#item', ITEM);
    await page.press('#item', 'Enter');
    await settle(page);

    await edit(page, 'price', '100');
    await edit(page, 'quantity', '10');
    await page.locator('form.cart-line [name="discount_toggle"]').evaluate((toggle) => toggle.click());
    await settle(page);
    await edit(page, 'discount', '50');

    const line = await amountOf(page.locator('form.cart-line .cart-total .num'));
    const total = await amountOf(page.locator('#pos_total_value'));

    await page.click('#cancel_receiving_button');
    await settle(page);

    if (line !== 500 || total <= 0 || total < line) {
        throw new Error(`A fixed discount of 50 on 10 units at 100 should leave a line of 500.00 (every unit loses 50), got line ${line} and total ${total}`);
    }
    console.log(`fixed purchase discount per unit: line ${line.toFixed(2)}, document total ${total.toFixed(2)}`);
} finally {
    await browser.close();
}
