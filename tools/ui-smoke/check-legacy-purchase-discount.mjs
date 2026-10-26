import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

const ITEM = '6281001';
const SUPPLIER = 'النور';
const MIGRATION = 20261029000000;
const ROOT = resolve(fileURLToPath(new URL('.', import.meta.url)), '../..');
const DB = process.env.UI_DB_NAME ?? 'ospos_ui';
const PREFIX = process.env.UI_DB_PREFIX ?? 'ospos_';

const config = loadConfig([]);

const sql = (statement) => execFileSync(process.env.UI_MYSQL ?? 'mysql', ['--default-character-set=utf8mb4', DB, '-N', '-B', '-e', statement], { encoding: 'utf8' }).trim();
const spark = (...args) => {
    try {
        return { code: 0, output: execFileSync('php', ['spark', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }) };
    } catch (error) {
        return { code: error.status ?? 1, output: `${error.stdout ?? ''}${error.stderr ?? ''}` };
    }
};
const amountOf = (text) => Number(text.replace(/[^0-9.\-]/g, ''));

const failures = [];
const expect = (label, actual, wanted) => {
    if (actual !== wanted) {
        failures.push(`${label}: wanted ${wanted}, got ${actual}`);
    }
};

const browser = await launchBrowser(config);
const session = await newSession(browser, config, { width: 1440, height: 900 });

const edit = async (page, name, value) => {
    await page.fill(`form.cart-line [name="${name}"]`, value);
    await page.press(`form.cart-line [name="${name}"]`, 'Tab');
    await settle(page);
};

const lineTotal = async (page) => amountOf(await page.locator('form.cart-line .cart-total .num').first().innerText());

const openDebitNoteOf = async (page, receivingId) => {
    await page.goto(`${config.baseUrl}/reports/detailed_receivings/2020-01-01/2099-12-31/all/all`, { waitUntil: 'networkidle' });
    await page.click(`a.modal-dlg[href$="receivings/edit/${receivingId}"]`);
    await page.waitForSelector('#create_debit_note', { timeout: 15000 });
    await page.click('#create_debit_note');
    await page.waitForURL((url) => url.pathname.endsWith('/receivings'), { timeout: 15000 });
    await settle(page);
};

try {
    const page = await session.openPage();

    const buy = async (discountPerUnit) => {
        await page.goto(`${config.baseUrl}/receivings`, { waitUntil: 'networkidle' });
        await page.fill('#item', ITEM);
        await page.press('#item', 'Enter');
        await settle(page);
        await edit(page, 'price', '100');
        await edit(page, 'quantity', '10');
        await page.locator('form.cart-line [name="discount_toggle"]').evaluate((toggle) => toggle.click());
        await settle(page);
        await edit(page, 'discount', discountPerUnit);
        await page.locator('#supplier').pressSequentially(SUPPLIER, { delay: 90 });
        await page.waitForSelector('.ui-autocomplete li', { timeout: 10000 });
        await page.click('.ui-autocomplete li:first-child');
        await settle(page);
        const due = await page.locator('#payment_types option').evaluateAll((options) => options.map((option) => option.textContent.trim()).find((text) => ['Due', 'مستحق'].includes(text)));
        await page.selectOption('#payment_types', { label: due });
        await page.click('#finish_receiving_button');
        await settle(page);

        return Number(sql(`SELECT MAX(receiving_id) FROM ${PREFIX}receivings`));
    };

    const perUnitId = await buy('5');
    const receivingId = await buy('5');
    const journalTotal = (id) => sql(`SELECT total_debit FROM ${PREFIX}accounting_journals WHERE source_type = 'purchase' AND source_id = ${id} AND status = 'posted' ORDER BY revision DESC LIMIT 1`);
    const typeOf = (id) => sql(`SELECT discount_type FROM ${PREFIX}receivings_items WHERE receiving_id = ${id}`);

    expect('posted at 5 off each of 10 units', journalTotal(receivingId), '950.0000');
    expect('the purchase saved afterwards is posted the same', journalTotal(perUnitId), '950.0000');

    sql(`UPDATE ${PREFIX}receivings_items SET discount = 50.00, discount_type = 1 WHERE receiving_id = ${receivingId}`);

    sql(`INSERT INTO ${PREFIX}receivings (employee_id, comment) VALUES (1, 'never reached the books')`);
    const undecidedId = Number(sql(`SELECT MAX(receiving_id) FROM ${PREFIX}receivings`));
    sql(`INSERT INTO ${PREFIX}receivings_items (receiving_id, item_id, line, quantity_purchased, receiving_quantity, item_cost_price, item_unit_price, discount, discount_type, item_location) SELECT ${undecidedId}, item_id, 1, 10, 1, 0, 100, 50, 1, 1 FROM ${PREFIX}items WHERE item_number = '${ITEM}'`);
    sql(`DELETE FROM ${PREFIX}migrations WHERE version = ${MIGRATION}`);

    const scan = spark('accounting:purchase_discount');
    expect('the scan reads one document by what the books posted for the whole line', /legacy_by_books\s+1/.test(scan.output), true);
    expect('the scan reads one document by what the books posted for every unit', /per_unit_by_books\s+[1-9]/.test(scan.output), true);
    expect('the scan leaves one document undecided', /undetermined\s+1/.test(scan.output), true);
    expect('the scan names the undecided document', scan.output.includes(`purchase #${undecidedId} line 1`), true);
    expect('the scan fails while a document is undecided', scan.code, 1);

    const refused = spark('migrate');

    if (process.env.UI_SHOW) {
        console.log(`${scan.output}\n${refused.output}`);
    }

    expect('the migration stops', /nothing was changed/.test(refused.output), true);
    expect('the whole-line document is untouched', typeOf(receivingId), '1');
    expect('the undecided document is untouched', typeOf(undecidedId), '1');

    expect('the decision is recorded', spark('accounting:purchase_discount', 'resolve', '--none-legacy').code, 0);
    spark('migrate');
    expect('marked as a discount for the line', typeOf(receivingId), '2');
    expect('the document saved afterwards stays a discount for every unit', typeOf(perUnitId), '1');
    expect('the undecided document stays as the operator decided', typeOf(undecidedId), '1');
    expect('journal untouched by the migration', journalTotal(receivingId), '950.0000');

    spark('accounting:purchase_discount', 'resolve', '--clear');
    sql(`DELETE FROM ${PREFIX}receivings_items WHERE receiving_id = ${undecidedId}`);
    sql(`DELETE FROM ${PREFIX}receivings WHERE receiving_id = ${undecidedId}`);

    await page.goto(`${config.baseUrl}/receivings/receipt/${receivingId}`, { waitUntil: 'networkidle' });
    expect('receipt line total', amountOf(await page.locator('#receipt_wrapper .total-value').first().innerText()), 950);
    expect('receipt discount', /50\.00/.test(await page.locator('#receipt_wrapper td.discount').first().innerText()), true);

    await page.goto(`${config.baseUrl}/reports/detailed_receivings/2020-01-01/2099-12-31/all/all`, { waitUntil: 'networkidle' });
    const row = page.locator('tr', { has: page.locator(`a[href$="receivings/edit/${receivingId}"]`) }).first();
    expect('purchase report total', /950\.00/.test(await row.innerText()), true);

    await page.goto(`${config.baseUrl}/receivings/edit/${receivingId}`, { waitUntil: 'networkidle' });
    expect('supplier still owed on the edit form', Number(await page.inputValue('#payment_amount_new')), 950);

    await openDebitNoteOf(page, receivingId);
    expect('return of every unit takes the whole discount', await lineTotal(page), -950);
    await edit(page, 'quantity', '-6');
    await page.click('#finish_receiving_button');
    await settle(page);

    await openDebitNoteOf(page, receivingId);
    expect('return of what remains takes its share of the discount', await lineTotal(page), -380);
    expect('its discount is 4 tenths of 50', Number(await page.inputValue('form.cart-line [name="discount"]')), 20);
    await page.click('#cancel_receiving_button');
    await settle(page);
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}

console.log('a purchase saved with a fixed discount for the whole line keeps its journal, receipt, report, edit form and returns');
