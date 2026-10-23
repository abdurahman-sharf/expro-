import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const SALES = [
    { items: ['6281001', '6281005', '6281010'], customer: 'أحمد' },
    { items: ['6281003', '6281006', '6281006', '6281012'], customer: 'Michael' },
    { items: ['6281002', '6281004'], customer: null },
    { items: ['6281009', '6281011', '6281008'], customer: 'سارة', due: true }
];

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

try {
    for (const [index, sale] of SALES.entries()) {
        const page = await session.openPage();
        await page.goto(`${config.baseUrl}/sales`, { waitUntil: 'networkidle' });

        for (const code of sale.items) {
            await page.fill('#item', code);
            await page.press('#item', 'Enter');
            await settle(page);
        }

        if (sale.customer) {
            await page.click('#customer');
            await page.fill('#customer', '');
            await page.locator('#customer').pressSequentially(sale.customer, { delay: 90 });
            await page.waitForSelector('.ui-autocomplete li', { timeout: 10000 });
            await page.click('.ui-autocomplete li:first-child');
            await settle(page);
        }

        if (sale.due) {
            const label = await page.locator('#payment_types option').evaluateAll((options) => options.map((option) => option.textContent.trim()).find((text) => ['Due', 'مستحق'].includes(text)));
            await page.selectOption('#payment_types', { label });
        }

        await page.click('#add_payment_button');
        await settle(page);
        await page.click('#finish_sale_button');
        await settle(page);

        if (await page.locator('#receipt_wrapper').count() === 0) {
            throw new Error(`Sale ${index + 1} did not reach the receipt page`);
        }
        console.log(`Sale ${index + 1} recorded`);
        await page.close();
    }
} finally {
    await browser.close();
}
