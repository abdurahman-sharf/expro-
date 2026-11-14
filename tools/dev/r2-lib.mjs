// Helpers for the readiness scenarios: SQL access for independent checks, a recorder, and register flows over HTTP.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { Client } from './r2-client.mjs';

const ROOT = new URL('../../', import.meta.url).pathname;
export const DB = process.env.R2_DB ?? 'ospos_test_r2_ui';

const envPassword = () => {
    const line = readFileSync(ROOT + '.env', 'utf8').split('\n').find((l) => l.startsWith('database.tests.password'));
    return line.split('=').slice(1).join('=').trim().replace(/^['"]|['"]$/g, '');
};

export const sql = (query) => {
    const out = execFileSync('mysql', ['-h127.0.0.1', '-uadmin', '--default-character-set=utf8mb4', '-N', '-B', DB, '-e', query], {
        env: { ...process.env, MYSQL_PWD: envPassword() },
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024
    });
    return out.trim() === '' ? [] : out.trimEnd().split('\n').map((row) => row.split('\t'));
};
export const one = (query) => (sql(query)[0] ?? [null])[0];
export const num = (query) => Number(one(query) ?? 0);
export const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

export const readiness = () => {
    let out;
    try {
        out = execFileSync('php', ['spark', 'accounting:readiness', '--json', '--no-header'], {
            cwd: ROOT,
            env: { ...process.env, CI_ENVIRONMENT: 'development', MYSQL_DB_NAME: DB },
            encoding: 'utf8',
            maxBuffer: 64 * 1024 * 1024
        });
    } catch (e) {
        out = e.stdout?.toString() ?? '';
    }
    const data = JSON.parse(out.slice(out.indexOf('{')));
    return data.checks.filter((c) => c.status !== 'pass').map((c) => ({ id: c.id ?? c.name, status: c.status, summary: String(c.summary ?? c.message ?? '').slice(0, 400), findings: (c.findings ?? []).slice(0, 4) }));
};

export class Recorder {
    constructor(file) {
        this.file = file;
        this.rows = [];
    }

    add(id, steps, expected, actual, pass, evidence = '') {
        const row = { id, steps, expected: String(expected), actual: String(actual), result: pass ? 'PASS' : 'FAIL', evidence };
        this.rows.push(row);
        console.log(`${row.result} ${id} | exp=${row.expected} | act=${row.actual}${evidence ? ' | ' + evidence : ''}`);
        return pass;
    }

    eq(id, steps, expected, actual, eps = 0.005, evidence = '') {
        const ok = typeof expected === 'number' || typeof actual === 'number' ? Math.abs(Number(expected) - Number(actual)) <= eps : String(expected) === String(actual);
        return this.add(id, steps, expected, actual, ok, evidence);
    }

    note(id, text) {
        this.rows.push({ id, note: text });
        console.log(`NOTE ${id} ${text}`);
    }

    save() {
        mkdirSync(dirname(this.file), { recursive: true });
        writeFileSync(this.file, JSON.stringify(this.rows, null, 2));
    }
}

const stripTags = (html) => html.replace(/<script[\s\S]*?<\/script>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
export const visibleText = stripTags;
export const alertOf = (html) => {
    const m = html.match(/class="[^"]*(?:alert-danger|alert-error)[^"]*"[^>]*>([\s\S]*?)<\/div>/);
    return m ? stripTags(m[1]) : null;
};
export const hasError = (res) => res.status >= 500 || /Whoops|Fatal error|Stack trace|<b>Warning<\/b>|<b>Notice<\/b>|Deprecated:|ErrorException|Uncaught/.test(res.text);

import { existsSync } from 'node:fs';

// Logs in once and keeps the session in a file, because the login screen throttles an address to five tries a minute.
export async function admin(user = 'admin', pass = 'pointofsale', fresh = false) {
    const file = `/tmp/r2-session-${user}.json`;
    const c = new Client(user);
    if (!fresh && existsSync(file)) {
        const saved = JSON.parse(readFileSync(file, 'utf8'));
        c.cookies = new Map(saved.cookies);
        c.token = saved.token;
        const probe = await c.get('/sales', { ajax: false });
        if (probe.status === 200 && c.token) {
            writeFileSync(file, JSON.stringify({ cookies: [...c.cookies], token: c.token }));
            return c;
        }
        c.cookies = new Map();
    }
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            await c.login(user, pass);
            break;
        } catch (e) {
            if (!String(e.message).includes('429') || attempt === 2) throw e;
            await new Promise((r) => setTimeout(r, 65000));
        }
    }
    writeFileSync(file, JSON.stringify({ cookies: [...c.cookies], token: c.token }));
    return c;
}

export class Register {
    constructor(client) {
        this.c = client;
    }

    async openSales(mode = 'sale') {
        await this.c.get('/sales', { ajax: false });
        let r = await this.c.post('/sales/cancel', {});
        r = await this.c.post('/sales/changeMode', { mode });
        return r;
    }

    add(item, extra = {}) {
        return this.c.post('/sales/add', { item, ...extra });
    }

    editLine(line, fields) {
        return this.c.post(`/sales/editItem/${line}`, fields);
    }

    customer(id) {
        return this.c.post('/sales/selectCustomer', { customer: id });
    }

    pay(type, amount, extra = {}) {
        return this.c.post('/sales/addPayment', { payment_type: type, amount_tendered: amount, complete_after_payment: 0, ...extra });
    }

    complete() {
        return this.c.post('/sales/complete', {});
    }
}

export const saleJournal = (saleId) => sql(`SELECT j.source_type, a.code, l.debit, l.credit FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE (j.source_type='sale' AND j.source_id=${saleId}) OR (j.source_type='sale_payment' AND j.source_id IN (SELECT payment_id FROM ospos_sales_payments WHERE sale_id=${saleId})) ORDER BY j.journal_id, l.line_no`);
export const lastId = (table, col) => num(`SELECT COALESCE(MAX(${col}),0) FROM ospos_${table}`);

export const accountBalance = (where, extra = '') =>
    num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j ON j.journal_id=l.journal_id JOIN ospos_accounting_accounts a ON a.account_id=l.account_id WHERE j.status IN ('posted','reversed') AND (${where}) ${extra}`);
export const sysBalance = (key, extra = '') => accountBalance(`a.system_key='${key}'`, extra);
export const entityBalance = (type, id) => accountBalance(`a.entity_type='${type}' AND a.entity_id=${id}`);

export class Receiving {
    constructor(client) {
        this.c = client;
    }

    async open(mode = 'receive') {
        await this.c.get('/receivings', { ajax: false });
        await this.c.post('/receivings/cancelReceiving', {});
        return this.c.post('/receivings/changeMode', { mode });
    }

    add(item) {
        return this.c.post('/receivings/add', { item });
    }

    supplier(id) {
        return this.c.post('/receivings/selectSupplier', { supplier: id });
    }

    editLine(line, fields) {
        return this.c.post(`/receivings/editItem/${line}`, fields);
    }

    complete(fields = {}) {
        return this.c.post('/receivings/complete', fields);
    }
}

export const fixture = () => ({
    supA: Number(one("SELECT person_id FROM ospos_suppliers WHERE company_name='R2 Supplier A'")),
    supB: Number(one("SELECT person_id FROM ospos_suppliers WHERE company_name='R2 Supplier B'")),
    cust1: Number(one("SELECT person_id FROM ospos_people WHERE first_name='R2Cust' AND last_name='One'")),
    cust2: Number(one("SELECT person_id FROM ospos_people WHERE first_name='R2Cust' AND last_name='Two'")),
    cust3: Number(one("SELECT person_id FROM ospos_people WHERE first_name='R2Cust' AND last_name='Three'")),
    P: Number(one("SELECT item_id FROM ospos_items WHERE item_number='R2-P'")),
    Q: Number(one("SELECT item_id FROM ospos_items WHERE item_number='R2-Q'")),
    T: Number(one("SELECT item_id FROM ospos_items WHERE item_number='R2-T'")),
    U: Number(one("SELECT item_id FROM ospos_items WHERE item_number='R2-U'"))
});

// Balances by account code over posted journals, item stock and cost: a snapshot to take deltas against.
export const snap = (itemIds = []) => {
    const bal = {};
    for (const [code, b] of sql("SELECT a.code, SUM(l.debit-l.credit) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j ON j.journal_id=l.journal_id JOIN ospos_accounting_accounts a ON a.account_id=l.account_id WHERE j.status IN ('posted','reversed') GROUP BY a.code")) bal[code] = Number(b);
    const items = {};
    for (const id of itemIds) {
        items[id] = { qty: num(`SELECT COALESCE(SUM(quantity),0) FROM ospos_item_quantities WHERE item_id=${id}`), cost: num(`SELECT cost_price FROM ospos_items WHERE item_id=${id}`) };
    }
    return { bal, items };
};

export const acct = (type, id) => one(`SELECT code FROM ospos_accounting_accounts WHERE entity_type='${type}' AND entity_id=${id}`);
export const ACC = { cash: '120101', inventory: '1203001' };
export const sysCode = (key) => one(`SELECT code FROM ospos_accounting_accounts WHERE system_key='${key}'`);

// Compares the movement between two snapshots with the movement worked out by hand. Every account not named must not move.
export const deltaCheck = (rec, id, steps, before, after, expectBal = {}, expectItems = {}, eps = 0.01) => {
    const codes = new Set([...Object.keys(before.bal), ...Object.keys(after.bal), ...Object.keys(expectBal)]);
    const diffs = [];
    for (const code of codes) {
        const got = (after.bal[code] ?? 0) - (before.bal[code] ?? 0);
        const want = expectBal[code] ?? 0;
        if (Math.abs(got - want) > eps) diffs.push(`${code}: want ${want} got ${r2(got)}`);
    }
    for (const [itemId, want] of Object.entries(expectItems)) {
        const b = before.items[itemId];
        const a = after.items[itemId];
        if (want.qty !== undefined && Math.abs(a.qty - b.qty - want.qty) > 0.0005) diffs.push(`item ${itemId} qty: want ${want.qty} got ${a.qty - b.qty}`);
        if (want.cost !== undefined && Math.abs(a.cost - want.cost) > 0.0005) diffs.push(`item ${itemId} cost: want ${want.cost} got ${a.cost}`);
    }
    return rec.add(id, steps, 'books move by the hand-worked amounts, nothing else moves', diffs.length ? diffs.join('; ') : 'as expected', diffs.length === 0);
};

// Rings up a sale at the register the way the screens do. lines: {code, qty, price?, discount?, dtype?} (dtype 0 percent, 1 fixed).
// payments: [type, amount, reference?]. Returns the id of the sale when it reached the receipt.
export const ringUp = async (c, { mode = 'sale', customer = null, lines = [], payments = [], invoiceDiscount = null, comment = '', complete = true, invoiceNumber = null } = {}) => {
    const reg = new Register(c);
    await reg.openSales(mode);
    const out = { errors: [], warnings: [] };
    const note = (r) => {
        const e = alertOf(r.text);
        if (e) out.errors.push(e);
        const w = r.text.match(/alert alert-warning[^>]*>([\s\S]*?)<\/div>/);
        if (w) out.warnings.push(stripTags(w[1]));
        if (hasError(r)) out.errors.push(`HTTP ${r.status} error page`);
        out.last = r;
    };
    if (customer) note(await reg.customer(customer));
    let n = 0;
    for (const l of lines) {
        note(await reg.add(l.code));
        n += 1;
        note(await reg.editLine(n, { price: l.price ?? one(`SELECT unit_price FROM ospos_items WHERE item_number='${l.code}'`), quantity: l.qty, discount: l.discount ?? '0', discount_type: l.dtype ?? 0, description: '', serialnumber: '', location: 1, ...(l.extra ?? {}) }));
    }
    if (invoiceDiscount) note(await c.post('/sales/setInvoiceDiscount', { invoice_discount: invoiceDiscount.value, invoice_discount_type: invoiceDiscount.type }));
    if (invoiceNumber) note(await c.post('/sales/setInvoiceNumber', { sales_invoice_number: invoiceNumber }));
    if (comment) await c.post('/sales/setComment', { comment });
    for (const [type, amount, ref, treasury] of payments) note(await reg.pay(type, amount, { ...(ref ? { reference_code: ref } : {}), ...(treasury ? { treasury_id: treasury } : {}) }));
    out.before = num('SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales');
    if (complete) {
        const r = await reg.complete();
        note(r);
        out.receipt = r.text;
    }
    const after = num('SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales');
    out.saleId = after > out.before ? after : null;
    return out;
};

// Prepares a credit note or a refund invoice of an issued invoice and completes it. qty: negative quantity of line 1 to return only part.
export const returnOf = async (c, saleId, { kind = 'creditNote', qty = null, payments = [] } = {}) => {
    const reg = new Register(c);
    await c.get('/sales', { ajax: false });
    const prep = await c.post(`/sales/${kind}/${saleId}`, {});
    const out = { prep: prep.data, errors: [], warnings: [] };
    if (!prep.data?.success) {
        out.errors.push(prep.data?.message ?? `prepare ${prep.status}`);
        return out;
    }
    if (qty !== null) {
        const page = await c.get('/sales', { ajax: false });
        const price = page.text.match(/name="price" value="([^"]*)"/)?.[1];
        const e = await reg.editLine(1, { price, quantity: qty, discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 });
        if (alertOf(e.text)) out.errors.push(alertOf(e.text));
    }
    for (const [type, amount, ref] of payments) {
        const p = await reg.pay(type, amount, ref ? { reference_code: ref } : {});
        if (alertOf(p.text)) out.errors.push(alertOf(p.text));
    }
    out.before = num('SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales');
    const done = await reg.complete();
    if (alertOf(done.text)) out.errors.push(alertOf(done.text));
    if (hasError(done)) out.errors.push(`HTTP ${done.status}`);
    out.receipt = done.text;
    const after = num('SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales');
    out.saleId = after > out.before ? after : null;
    return out;
};
