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
    const out = execFileSync('php', ['spark', 'accounting:readiness', '--json', '--no-header'], {
        cwd: ROOT,
        env: { ...process.env, CI_ENVIRONMENT: 'development', MYSQL_DB_NAME: DB },
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024
    });
    const data = JSON.parse(out.slice(out.indexOf('{')));
    return data.checks.filter((c) => c.status !== 'pass').map((c) => ({ id: c.id ?? c.name, status: c.status, summary: String(c.summary ?? c.message ?? '').slice(0, 400) }));
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
    const m = html.match(/class="[^"]*(?:alert-danger|alert-error|alert alert-warning)[^"]*"[^>]*>([\s\S]*?)<\/div>/);
    return m ? stripTags(m[1]) : null;
};
export const hasError = (res) => res.status >= 500 || /Whoops|Fatal error|Stack trace|<b>Warning<\/b>|<b>Notice<\/b>|Deprecated:|ErrorException|Uncaught/.test(res.text);

export async function admin() {
    const c = new Client('admin');
    await c.login();
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

export const lastId = (table, col) => num(`SELECT COALESCE(MAX(${col}),0) FROM ospos_${table}`);

export const accountBalance = (where, extra = '') =>
    num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j ON j.journal_id=l.journal_id JOIN ospos_accounting_accounts a ON a.account_id=l.account_id WHERE j.status='posted' AND (${where}) ${extra}`);
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
