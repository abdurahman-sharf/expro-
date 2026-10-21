import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession } from './lib/session.mjs';

const config = loadConfig([]);
const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const encode = (journal) => {
    const body = new URLSearchParams({ date: journal.date, description: journal.description });
    journal.lines.forEach((line, index) => {
        for (const [field, value] of Object.entries(line)) {
            body.set(`lines[${index}][${field}]`, value);
        }
    });
    return body.toString();
};

try {
    const page = await session.openPage();
    await page.goto(`${config.baseUrl}/accounting_chart`, { waitUntil: 'networkidle' });

    const accountId = async (code) => {
        const response = await page.request.get(`${config.baseUrl}/accounting_chart/search?search=${code}`);
        const row = (await response.json()).rows.find((item) => item.code === code);
        if (!row) {
            throw new Error(`Account ${code} not found`);
        }
        return String(row.account_id);
    };

    const costCenterId = async (code) => {
        const response = await page.request.get(`${config.baseUrl}/accounting_cost_centers/search`);
        const row = (await response.json()).rows.find((item) => item.code === code);
        return row ? String(row.cost_center_id) : '';
    };

    const postJson = (url, data) => page.evaluate(
        ({ target, body }) => new Promise((resolve, reject) => {
            $.post(target, body, resolve, 'json').fail((request) => reject(new Error(`HTTP ${request.status}`)));
        }),
        { target: url, body: data }
    );

    const send = async (path, journal) => {
        const result = await postJson(`accounting_journals/${path}`, encode(journal));
        if (!result.success) {
            throw new Error(`${path} failed: ${result.message}`);
        }
        return result;
    };

    const cash = await accountId('1101');
    const sales = await accountId('4101');
    const front = await costCenterId('21');

    const opening = await send('post', {
        date: '2026-10-01',
        description: 'إيداع نقدي في الصندوق',
        lines: [
            { account_id: cash, description: 'إيداع', cost_center_id: '', debit: '5000.00', credit: '' },
            { account_id: sales, description: 'مبيعات نقدية', cost_center_id: front, debit: '', credit: '5000.00' }
        ]
    });
    console.log('Posted journal', opening.id);

    const wrong = await send('post', {
        date: '2026-10-05',
        description: 'قيد بمبلغ خاطئ',
        lines: [
            { account_id: cash, description: '', cost_center_id: '', debit: '750.50', credit: '' },
            { account_id: sales, description: '', cost_center_id: '', debit: '', credit: '750.50' }
        ]
    });
    const reversal = await postJson(`accounting_journals/reverse/${wrong.id}`, new URLSearchParams({ date: '2026-10-06', reason: 'مبلغ خاطئ' }).toString());
    console.log('Reversed journal', wrong.id, reversal.success);

    const draft = await send('save_draft', {
        date: '2026-10-20',
        description: 'مسودة قيد قيد الإعداد',
        lines: [
            { account_id: cash, description: '', cost_center_id: '', debit: '320.00', credit: '' },
            { account_id: sales, description: '', cost_center_id: '', debit: '', credit: '300.00' }
        ]
    });
    console.log('Saved draft', draft.id);
} finally {
    await browser.close();
}
