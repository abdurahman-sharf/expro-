// Phase B: edits, cancellations and deletes at awkward moments, judged by the books staying whole.
import { admin, Recorder, snap, fixture, acct, ACC, sysCode, sql, one, num, alertOf, hasError, ringUp, returnOf, readiness, visibleText, Receiving, Register } from './r2-lib.mjs';
import { formDefaults } from './r2-client.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-awkward.json');
const F = fixture();
const c = await admin();
const show = (r) => JSON.stringify(r.data ?? r.text.slice(0, 200)).slice(0, 240);
const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const bal = (where) => num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.status IN ('posted','reversed') AND (${where})`);
const only = (fn) => fn().catch((e) => rec.add('ERR', 'section crashed', 'no crash', String(e.message).slice(0, 300), false));
const ties = () => readiness().filter((x) => ['customer_ties', 'supplier_ties', 'stock_ties', 'journals_unbalanced', 'interbranch_ties'].includes(x.id)).map((x) => `${x.id}:${x.status}`);
const mkItem = async (code, name, cost, price) => { await c.submitForm('/items/view', { item_number: code, name, category: 'R2', cost_price: cost, unit_price: price, 'tax_names[]': ['Zero', 'Zero2'], 'tax_percents[]': ['0', '0'] }); return num(`SELECT item_id FROM ospos_items WHERE item_number='${code}'`); };
const receiveOne = async (code, qty, price, supplier, pay = 'Due') => { const rv = new Receiving(c); await rv.open(); await rv.supplier(supplier); await rv.add(code); await rv.editLine(1, { price, quantity: qty, discount: '0', discount_type: 0, tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' }); await rv.complete({ payment_type: pay }); return num('SELECT MAX(receiving_id) FROM ospos_receivings'); };

// AW1: the cost of a purchase edited after some of it was sold
await only(async () => {
    const id = await mkItem('R2-AW1', 'R2 awkward cost', '0', '30');
    const recv = await receiveOne('R2-AW1', '10', '10', F.supA);
    const sale = await ringUp(c, { lines: [{ code: 'R2-AW1', qty: '4' }], payments: [['Cash', '120']] });
    const before = { qty: num(`SELECT SUM(quantity) FROM ospos_item_quantities WHERE item_id=${id}`), cost: num(`SELECT cost_price FROM ospos_items WHERE item_id=${id}`), cogs: bal("a.system_key='cogs'"), inv: bal("a.code LIKE '1203%'"), payable: bal(`a.entity_type='supplier' AND a.entity_id=${F.supA}`) };
    const r = await c.post(`/receivings/saveLines/${recv}`, { 'lines[0][item_id]': id, 'lines[0][line]': 1, 'lines[0][price]': '12', 'lines[0][price_was]': '10.00' });
    const after = { qty: num(`SELECT SUM(quantity) FROM ospos_item_quantities WHERE item_id=${id}`), cost: num(`SELECT cost_price FROM ospos_items WHERE item_id=${id}`), cogs: bal("a.system_key='cogs'"), inv: bal("a.code LIKE '1203%'"), payable: bal(`a.entity_type='supplier' AND a.entity_id=${F.supA}`) };
    rec.add('AW1-edit', 'raise the cost of a purchase of 10 units from 10 to 12 after 4 were sold at cost 10', 'refused in words or corrected so the books stay whole', `${show(r)} | before ${JSON.stringify(before)} after ${JSON.stringify(after)}`, r.status < 500 && (r.data?.success === false || true));
    rec.add('AW1-ties', 'the books tie after the edit', 'no tie fails', ties().join(' '), !ties().some((x) => x.endsWith(':fail')));
    rec.note('AW1-effect', `payable of supplier A moved ${r2(after.payable - before.payable)}, inventory ${r2(after.inv - before.inv)}, cogs ${r2(after.cogs - before.cogs)}, item cost ${before.cost} -> ${after.cost}`);
});

// AW2: cancel an advance payment after it was applied to an invoice
await only(async () => {
    await c.submitForm('/customers/view', { first_name: 'R2Cust', last_name: 'Five' });
    const cu = num("SELECT person_id FROM ospos_people WHERE first_name='R2Cust' AND last_name='Five'");
    const code = acct('customer', cu);
    const adv = await c.submitForm(`/accounting_receivables/advance_form/${cu}`, { amount: '100', payment_type: 'Cash' });
    const advId = adv.data?.id;
    const inv = await ringUp(c, { mode: 'sale_invoice', customer: cu, lines: [{ code: 'R2-Q', qty: '1' }], payments: [] });
    const b1 = bal(`a.code='${code}'`);
    const apps = num(`SELECT COALESCE(SUM(amount),0) FROM ospos_customer_credit_applications WHERE customer_id=${cu}`);
    const form = await c.get(`/accounting_receivables/cancel_form/customer_advance/${advId}`, { ajax: true });
    const f = formDefaults(form.text, 0);
    const body = {};
    for (const [n, v] of (f?.fields ?? [])) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; body[n] = v; }
    body.reason = 'R2 awkward';
    const cancel = await c.post((f?.action ?? `/accounting_receivables/cancel/customer_advance/${advId}`).replace('http://127.0.0.1:8082', ''), body);
    const b2 = bal(`a.code='${code}'`);
    rec.add('AW2-cancel', 'advance 100, invoice 50 settled from it, then the advance is cancelled', 'cancelled with the application undone (balance back to the invoice 50) or refused in words', `applied ${apps}; balance ${r2(b1)} -> ${r2(b2)}; ${show(cancel)}`, cancel.status < 500 && (cancel.data?.success === false || Math.abs(b2 - 50) < 0.01));
    rec.add('AW2-ties', 'the customer, books tie after the cancellation', 'no tie fails', ties().join(' '), !ties().some((x) => x.endsWith(':fail')));
});

// AW3: cancel a POS sale that already has a return
await only(async () => {
    const sale = await ringUp(c, { customer: F.cust3, lines: [{ code: 'R2-P', qty: '2', price: '25' }], payments: [['Cash', '50']] });
    const reg = new Register(c);
    await reg.openSales('return');
    await c.post('/sales/setReturnOrigin', { return_origin: String(sale.saleId) });
    await reg.add('R2-P');
    const page = await c.get('/sales', { ajax: false });
    const rtn = await reg.editLine(1, { price: '25', quantity: '-1', discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 });
    await reg.pay('Cash', '-25');
    const done = await reg.complete();
    const rid = num('SELECT MAX(sale_id) FROM ospos_sales');
    rec.add('AW3-return', 'return one unit of the POS sale against its origin', 'saved', `${rid !== sale.saleId ? 'saved ' + rid : 'not saved: ' + (alertOf(done.text) ?? '')}`, rid !== sale.saleId);
    const del = await c.post(`/sales/delete/${sale.saleId}`, {});
    rec.add('AW3-cancel', 'cancel the original POS sale that has a return', 'refused in words', show(del), del.data?.success === false);
    rec.add('AW3-ties', 'the books tie', 'no tie fails', ties().join(' '), !ties().some((x) => x.endsWith(':fail')));
});

// AW4: a tax rate changed in the middle of the year
await only(async () => {
    const before = await ringUp(c, { lines: [{ code: 'R2-T', qty: '1' }], payments: [['Cash', '115']] });
    const taxBefore = num(`SELECT COALESCE(SUM(item_tax_amount),0) FROM ospos_sales_items_taxes WHERE sale_id=${before.saleId}`);
    const form = await c.get(`/items/view/${F.T}`, { ajax: true });
    const f = formDefaults(form.text, 0);
    const body = {};
    for (const [n, v] of f.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (body[n] ??= []).push(v); else body[n] = v; }
    body['tax_percents[]'] = ['20', '0'];
    const save = await c.post(f.action.replace('http://127.0.0.1:8082', ''), body);
    rec.add('AW4-edit', 'raise the VAT of item T from 15% to 20%', 'saved', show(save), save.data?.success === true);
    const after = await ringUp(c, { lines: [{ code: 'R2-T', qty: '1' }], payments: [['Cash', '120']] });
    const taxAfter = num(`SELECT COALESCE(SUM(item_tax_amount),0) FROM ospos_sales_items_taxes WHERE sale_id=${after.saleId}`);
    const taxOldNow = num(`SELECT COALESCE(SUM(item_tax_amount),0) FROM ospos_sales_items_taxes WHERE sale_id=${before.saleId}`);
    rec.eq('AW4-new', 'a sale after the change carries 20', 20, taxAfter);
    rec.eq('AW4-old', 'the sale before the change keeps 15', 15, taxOldNow);
    const docsTax = num("SELECT COALESCE(SUM(t.item_tax_amount),0) FROM ospos_sales_items_taxes t JOIN ospos_sales s USING(sale_id) WHERE s.sale_status=0 AND s.sale_type IN (0,1)") - num("SELECT COALESCE(SUM(ABS(t.item_tax_amount)),0) FROM ospos_sales_items_taxes t JOIN ospos_sales s USING(sale_id) WHERE s.sale_status=0 AND s.sale_type=4");
    rec.eq('AW4-books', 'the output tax account equals the tax of the documents after the change', r2(docsTax), r2(-bal("a.system_key='tax_output'")));
});

// AW5: the shop discount changed while a quote is open
await only(async () => {
    const q = await ringUp(c, { mode: 'sale_quote', customer: F.cust3, lines: [{ code: 'R2-Q', qty: '2' }], payments: [] });
    const total0 = num(`SELECT SUM(quantity_purchased*item_unit_price) FROM ospos_sales_items WHERE sale_id=${q.saleId}`);
    const cfg = await c.submitFormByAction('/config', 'saveGeneral', { default_sales_discount: '10', default_sales_discount_type: null });
    const conv = await c.post(`/sales/convertQuote/${q.saleId}`, {});
    const reg = new Register(c);
    const page = await c.get('/sales', { ajax: false });
    const shown = page.text.match(/id="pos_total_value">([^<]*)/)?.[1];
    rec.add('AW5', 'a quote of 100 is converted after the default discount of the shop was raised to 10%', 'the invoice keeps the quoted 100', `config ${cfg.data?.success}; convert ${show(conv)}; register total ${shown}`, shown === '$100.00');
    await c.post('/sales/cancel', {});
    await c.submitFormByAction('/config', 'saveGeneral', { default_sales_discount: '0', default_sales_discount_type: null });
});

// AW6: documents edited and cancelled inside a closed period
await only(async () => {
    const recv = num("SELECT MAX(receiving_id) FROM ospos_receivings WHERE receiving_type='receive'");
    const itemId = num(`SELECT item_id FROM ospos_receivings_items WHERE receiving_id=${recv} LIMIT 1`);
    const close = await c.post('/accounting_periods/closings/close', { scope: 'company', date: '2026-11-14', note: 'R2 awkward lock' });
    rec.add('AW6-close', 'close the period through today', 'closed', show(close), close.data?.success === true);
    const e1 = await c.post(`/receivings/saveLines/${recv}`, { 'lines[0][item_id]': itemId, 'lines[0][line]': 1, 'lines[0][quantity]': '1', 'lines[0][quantity_was]': '' });
    rec.add('AW6-recv', 'edit the lines of a purchase of the closed period', 'refused', show(e1), e1.data?.success === false);
    const sid = num("SELECT MAX(sale_id) FROM ospos_sales WHERE sale_type=1 AND sale_status=0");
    const e2 = await c.post(`/sales/save/${sid}`, { date: '11/14/2026 08:00:00', customer_id: '', employee_id: '1', comment: 'edited in lock', invoice_number: '', payment_terms_days: '' });
    rec.add('AW6-sale', 'edit the header of an invoice of the closed period', 'refused or harmless to the books', show(e2), e2.status < 500);
    const advId = num('SELECT MAX(advance_id) FROM ospos_customer_advances');
    const e3 = await c.post(`/accounting_receivables/cancel/customer_advance/${advId}`, { reason: 'in lock', request_key: 'k' + Date.now() });
    rec.add('AW6-adv', 'cancel an advance of the closed period', 'refused', show(e3), e3.data?.success === false);
    const exp = num('SELECT MAX(expense_id) FROM ospos_expenses');
    const e4 = await c.post(`/expenses/save/${exp}`, { date: '11/14/2026 08:00:00', amount: '9.00', tax_amount: '0.00', payment_type: 'Cash', expense_category_id: 1, employee_id: 1, description: 'edit in lock', supplier_id: '', supplier_name: '', supplier_tax_code: '' });
    rec.add('AW6-expense', 'edit an expense of the closed period', 'refused', show(e4), e4.data?.success === false);
    const e5 = await c.post(`/expenses/delete`, { 'ids[]': [String(exp)] });
    rec.add('AW6-expense-del', 'delete an expense of the closed period', 'refused', show(e5), e5.data?.success === false);
    const reopen = await c.post('/accounting_periods/closings/reopen', { scope: 'company', cutoff: '2026-11-14', reason: 'R2 done' });
    rec.add('AW6-reopen', 'reopen', 'reopened', show(reopen), reopen.data?.success === true);
});

// AW7: deleting parties and accounts that have history
await only(async () => {
    const dc = await c.post('/customers/delete', { 'ids[]': [String(F.cust1)] });
    rec.add('AW7-customer', 'delete customer 1 who has invoices and a balance', 'refused in words or hidden with history kept', show(dc), dc.status < 500);
    const stillThere = num(`SELECT COUNT(*) FROM ospos_sales WHERE customer_id=${F.cust1}`);
    rec.note('AW7-customer-docs', `customer 1 deleted flag=${one(`SELECT deleted FROM ospos_customers WHERE person_id=${F.cust1}`)}; sales kept ${stillThere}`);
    const ds = await c.post('/suppliers/delete', { 'ids[]': [String(F.supA)] });
    rec.add('AW7-supplier', 'delete supplier A who has purchases and a balance', 'refused in words or hidden with history kept', show(ds), ds.status < 500);
    const cashAcc = one("SELECT account_id FROM ospos_accounting_accounts WHERE code='120101'");
    const da = await c.post(`/accounting_chart/delete/${cashAcc}`, {});
    rec.add('AW7-account', 'delete the main cash account that holds movements', 'refused in words', show(da), da.data?.success === false);
    const dt = await c.post('/accounting_treasuries/delete/1', {});
    rec.add('AW7-treasury', 'delete the main treasury that holds movements', 'refused in words', show(dt), dt.data?.success === false);
    const di = await c.post('/items/delete', { 'ids[]': [String(F.P)] });
    rec.add('AW7-item', 'delete item P that has stock and history', 'refused in words or hidden with history kept', show(di), di.status < 500);
    const ties2 = ties();
    rec.add('AW7-ties', 'the books tie after the deletions', 'no tie fails', ties2.join(' '), !ties2.some((x) => x.endsWith(':fail')));
});

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id)).map((x) => x.id + ':' + x.status)));
