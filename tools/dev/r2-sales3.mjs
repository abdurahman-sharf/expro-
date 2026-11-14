// Phase A (b), third part: credit limit, price list, discount ceilings, advances applied to invoices, the purchase order flow.
import { admin, Recorder, snap, deltaCheck, fixture, acct, ACC, sysCode, sql, one, num, alertOf, ringUp, readiness, visibleText, hasError, saleJournal } from './r2-lib.mjs';
import { formDefaults } from './r2-client.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-sales3.json');
const F = fixture();
const c = await admin();
const cashier = await admin('cashier', 'Cashier#2026');
const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const bal = (code) => num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.status IN ('posted','reversed') AND a.code='${code}'`);
const show = (r) => JSON.stringify(r.data ?? r.text.slice(0, 160));

// C1: credit limit
{
    const on = await c.post('/accounting_settings/save_controls', { credit_limit: '1' });
    rec.add('C1-on', 'turn the credit limit control on', 'saved', show(on), on.data?.success === true);
    const form = await c.get(`/customers/view/${F.cust2}`, { ajax: true });
    const f = formDefaults(form.text, 0);
    const body = {};
    for (const [n, v] of f.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (body[n] ??= []).push(v); else body[n] = v; }
    body.credit_limit = '100';
    const save = await c.post(f.action.replace('http://127.0.0.1:8082', ''), body);
    rec.add('C1-limit', 'set the credit limit of customer 2 to 100', 'saved', show(save), save.data?.success === true);
    const owed = bal(acct('customer', F.cust2));
    const out = await ringUp(cashier, { customer: F.cust2, lines: [{ code: 'R2-Q', qty: '2' }], payments: [['Due', '100']] });
    rec.add('C1-refused', `cashier sells 100 on credit to customer 2 who already owes ${r2(owed)} (limit 100)`, 'refused in words, nothing saved', `${out.saleId ?? 'not saved'} ${out.errors.join(' | ')}`, out.saleId === null && out.errors.length > 0);
    const small = await ringUp(cashier, { customer: F.cust2, lines: [{ code: 'R2-P', qty: '1' }], payments: [['Due', '25']] });
    rec.add('C1-within', 'a sale of 25 that stays under the limit is accepted when the customer owes 90 less... (checked against the rule)', owed + 25 > 100 ? 'refused (90+25 > 100)' : 'accepted', `${small.saleId ?? 'not saved'} ${small.errors.join(' | ')}`, (owed + 25 > 100) === (small.saleId === null));
    const admin_ = await ringUp(c, { customer: F.cust2, lines: [{ code: 'R2-Q', qty: '2' }], payments: [['Due', '100']] });
    rec.add('C1-override', 'the administrator, who may override the credit limit, sells the same 100 on credit', 'accepted (override recorded)', `${admin_.saleId ?? 'not saved'} ${admin_.errors.join(' | ')}`, admin_.saleId !== null);
    const audit = num("SELECT COUNT(*) FROM ospos_accounting_audit_log WHERE action IN ('credit_limit_override','credit_limit_refused')");
    rec.add('C1-audit', 'the refusal and the override are in the audit log', '2 rows', String(audit), audit >= 2);
    await c.post('/accounting_settings/save_controls', { credit_limit: '0' });
}

// C2: price list
{
    const list = await c.post('/price_lists/save', { name: 'R2 Wholesale', status: '1' });
    rec.add('C2-list', 'create a price list through the save route without an id (the form posts /-1, see the finding)', 'saved', show(list), list.data?.success === true);
    const listId = list.data?.id;
    await c.post('/accounting_settings/save_controls', { price_lists: '1' });
    const itemForm = await c.get(`/items/view/${F.P}`, { ajax: true });
    const f = formDefaults(itemForm.text, 0);
    const body = {};
    for (const [n, v] of f.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (body[n] ??= []).push(v); else body[n] = v; }
    body[`price_list_prices[${listId}]`] = '20';
    const save = await c.post(f.action.replace('http://127.0.0.1:8082', ''), body);
    rec.add('C2-price', 'item P gets the price 20 in the list', 'saved', show(save), save.data?.success === true);
    const cf = await c.get(`/customers/view/${F.cust3}`, { ajax: true });
    const cff = formDefaults(cf.text, 0);
    const cbody = {};
    for (const [n, v] of cff.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (cbody[n] ??= []).push(v); else cbody[n] = v; }
    cbody.price_list_id = String(listId);
    const csave = await c.post(cff.action.replace('http://127.0.0.1:8082', ''), cbody);
    rec.add('C2-assign', 'assign the list to customer 3', 'saved', show(csave), csave.data?.success === true);
    const before = snap([F.P]);
    const out = await ringUp(c, { customer: F.cust3, lines: [{ code: 'R2-P', qty: '2', price: '20' }], payments: [['Cash', '40']] });
    rec.add('C2-sale', 'sell 2 P to customer 3 at the list price 20 (the price is copied to the line)', 'saved', `${out.saleId} ${out.errors.join('|')}`, !!out.saleId && out.errors.length === 0);
    const line = one(`SELECT item_unit_price FROM ospos_sales_items WHERE sale_id=${out.saleId}`);
    rec.eq('C2-line', 'the stored line price is the list price', 20, line);
    const reg = await c.post('/sales/changeMode', { mode: 'sale' });
    const sel = await c.post('/sales/selectCustomer', { customer: F.cust3 });
    const add = await c.post('/sales/add', { item: 'R2-P' });
    const shown = add.text.match(/name="price" value="([^"]*)"/)?.[1];
    rec.eq('C2-register', 'the register prices the line from the list as soon as the customer is chosen', 20, shown);
    await c.post('/sales/cancel', {});
    await c.post('/accounting_settings/save_controls', { price_lists: '0' });
}

// C3: advance payment applied to a later invoice
{
    const cust = F.cust1;
    const code = acct('customer', cust);
    const b0 = bal(code);
    const adv = await c.submitForm(`/accounting_receivables/advance_form/${cust}`, { amount: '500', payment_type: 'Cash' });
    rec.add('C3-adv', 'customer 1 pays 500 in advance', 'saved', show(adv), adv.data?.success === true);
    const b1 = bal(code);
    const applications = num(`SELECT COUNT(*) FROM ospos_customer_credit_applications WHERE customer_id=${cust}`);
    rec.note('C3-state', `customer 1 balance ${r2(b0)} -> ${r2(b1)}; applications so far ${applications}`);
    const out = await ringUp(c, { mode: 'sale_invoice', customer: cust, lines: [{ code: 'R2-Q', qty: '1' }], payments: [] });
    const b2 = bal(code);
    rec.add('C3-invoice', 'invoice of 50 to customer 1 who holds credit', 'saved', `${out.saleId} ${out.errors.join('|')}`, !!out.saleId);
    rec.eq('C3-balance', 'the customer balance rises by the invoice (50) whether or not the credit is applied', r2(b1 + 50), r2(b2));
}

// C4: purchase order flow
{
    const on = await c.submitFormByAction('/purchase_orders', 'settings', { enabled: '1', manual_purchase_order: '1' });
    rec.add('C4-on', 'turn the purchase order flow on', 'saved', show(on), on.data?.success === true || on.status === 200);
    const form = await c.get('/purchase_orders/add', { ajax: false });
    rec.add('C4-form', 'the purchase order form opens', '200', String(form.status), form.status === 200);
    if (form.status === 200) {
        const f = formDefaults(form.text, form.text.includes('purchase_orders/save') ? 0 : 0);
        rec.note('C4-fields', JSON.stringify((f?.fields ?? []).map((x) => x[0]).filter((n) => n !== 'csrf_ospos_v4' && n !== 'honeypot')));
    }
}

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id)).map((x) => x.id + ':' + x.status)));
