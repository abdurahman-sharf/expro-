import { AxeBuilder } from '@axe-core/playwright';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const usage = `usage: node check-customer-filter.mjs [--base-url URL] [--search TEXT]

Works the customer filter of the sales, payment and net position reports the way a person does, on an application whose
picker threshold is below the number of customers (set accounting_picker_threshold to 1 on the development database
first): the filter is a search box, typing finds the customer, choosing one fills the number the form sends, the report
opens for that customer and names it again, a name that is nobody blocks the form, and axe finds nothing at 360px.
It exits with 1 on a failure.`;

if (process.argv.includes('--help')) {
    console.log(usage);
    process.exit(0);
}

const option = (name, fallback) => {
    const index = process.argv.indexOf(`--${name}`);

    return index > 0 ? process.argv[index + 1] : fallback;
};
const config = { baseUrl: option('base-url', process.env.UI_BASE_URL ?? 'http://127.0.0.1').replace(/\/+$/, ''), user: process.env.UI_USER ?? 'admin', password: process.env.UI_PASS ?? 'pointofsale', executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH };
const term = option('search', 'Brown');
const screens = ['/accounting_reports/revenue', '/accounting_reports/payments', '/accounting_reports/net_position'];
const failures = [];
const check = (name, ok, detail = '') => {
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}${ok || detail === '' ? '' : ` (${detail})`}`);
    ok || failures.push(name);
};

const browser = await launchBrowser(config);

try {
    const session = await newSession(browser, config, { width: 360, height: 800, label: '360x800' });

    for (const screen of screens) {
        const page = await session.openPage();

        await page.goto(`${config.baseUrl}${screen}`, { waitUntil: 'networkidle' });
        await settle(page);

        check(`${screen}: the customer is a search box`, (await page.locator('#f_customer_text').count()) === 1 && (await page.locator('select#f_customer').count()) === 0);
        check(`${screen}: the box is a combobox once focused`, await page.locator('#f_customer_text').focus().then(() => page.getAttribute('#f_customer_text', 'role')).then((role) => role === 'combobox'));

        await page.fill('#f_customer_text', term);
        await page.waitForSelector('[role=option]', { timeout: 5000 });
        const label = (await page.locator('[role=option]').first().textContent()).trim();

        check(`${screen}: typing "${term}" lists a customer`, label.length > 0, label);

        await page.locator('[role=option]').first().dispatchEvent('mousedown');
        const chosen = await page.inputValue('#f_customer');

        check(`${screen}: choosing fills the number the form sends`, /^\d+$/.test(chosen), chosen);

        await Promise.all([page.waitForURL((url) => url.searchParams.get('customer') === chosen, { timeout: 15000 }), page.click('#report_run')]);
        await settle(page, 300);

        check(`${screen}: the report opens for that customer and names it again`, (await page.inputValue('#f_customer_text')) === label && (await page.inputValue('#f_customer')) === chosen);

        await page.fill('#f_customer_text', 'nobody of this name');
        await page.click('#report_run');
        await page.waitForTimeout(400);

        check(`${screen}: a name that is nobody blocks the form`, (await page.locator('#f_customer_text.is-invalid').count()) === 1 && page.url().includes(`customer=${chosen}`));

        await page.fill('#f_customer_text', '');
        await page.press('#f_customer_text', 'Tab');

        check(`${screen}: an empty box means every customer`, (await page.inputValue('#f_customer')) === '');

        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();

        check(`${screen}: axe finds nothing`, axe.violations.length === 0, axe.violations.map((violation) => violation.id).join(','));
        check(`${screen}: the page does not scroll sideways`, await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth));
        await page.close();
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(`\n${failures.length} check(s) failed`);
    process.exit(1);
}

console.log('All checks passed');
