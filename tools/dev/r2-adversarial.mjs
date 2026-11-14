// Phase B: what breaks when a hostile but realistic user does awkward things (http level).
import { admin, Recorder, snap, fixture, acct, ACC, sysCode, sql, one, num, alertOf, hasError, ringUp, returnOf, readiness, visibleText, Receiving, Register } from './r2-lib.mjs';
import { Client, formDefaults } from './r2-client.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-adversarial.json');
const F = fixture();
const c = await admin();
const cashier = await admin('cashier', 'Cashier#2026');
const show = (r) => JSON.stringify(r.data ?? r.text.slice(0, 200)).slice(0, 260);
const ok = (r) => r.data?.success === true;
const bal = (code) => num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.status IN ('posted','reversed') AND a.code='${code}'`);
const sales = () => num('SELECT COUNT(*) FROM ospos_sales');
const only = (fn) => fn().catch((e) => rec.add('ERR', 'section crashed', 'no crash', String(e.message).slice(0, 300), false));

// B1: stale and missing CSRF token, expired session
await only(async () => {
    const before = sales();
    const noToken = await c.post('/sales/add', { item: 'R2-P' }, { csrf: false });
    rec.add('B1a', 'POST without any token', '403/redirect, no 500, no state change', `${noToken.status} ${show(noToken).slice(0, 120)}`, noToken.status < 500 && (noToken.status === 403 || noToken.status === 302 || noToken.status === 200 && /not allowed|csrf|security/i.test(noToken.text)));
    const old = c.token;
    c.token = 'deadbeef';
    const bad = await c.post('/sales/add', { item: 'R2-P' });
    c.token = old;
    rec.add('B1b', 'POST with a wrong token', '403 in words', `${bad.status} ${show(bad).slice(0, 160)}`, bad.status === 403);
    const gone = new Client('gone');
    gone.cookies.set('ospos_session', 'doesnotexist');
    const page = await gone.get('/sales', { ajax: false });
    rec.add('B2a', 'GET a screen with a session that does not exist', 'redirect to login', `${page.status} -> ${page.location}`, page.status === 302 && /login/.test(page.location ?? ''));
    gone.token = old;
    const ajax = await gone.post('/sales/add', { item: 'R2-P' });
    rec.add('B2b', 'ajax POST from a form left open after the session expired', '401 (the page script sends the user to login) or a redirect to login, never a 500', `${ajax.status} ${ajax.location ?? ''} ${show(ajax).slice(0, 100)}`, [401, 302, 403].includes(ajax.status));
    rec.add('B2c', 'nothing saved by the refused posts', 'same number of sales', `${sales() - before}`, sales() === before);
});

// B3: double click on the finish button (two requests at once on one session)
await only(async () => {
    const reg = new Register(c);
    await reg.openSales('sale');
    await reg.add('R2-P');
    await reg.editLine(1, { price: '25', quantity: '1', discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 });
    await reg.pay('Cash', '25');
    const before = sales();
    const cashBefore = bal('120101');
    const [a, b] = await Promise.all([reg.complete(), reg.complete()]);
    const made = sales() - before;
    rec.add('B3a', 'finish the same sale twice at the same moment', 'exactly one sale', `${made} sale(s); statuses ${a.status}/${b.status}`, made === 1, `cash moved ${bal('120101') - cashBefore}`);
    const replay = await reg.complete();
    rec.add('B3b', 'press back and finish again after the receipt', 'no second sale', `${sales() - before} sale(s) in total`, sales() - before === 1);
    rec.eq('B3c', 'cash moved once', 25, bal('120101') - cashBefore);
});
await only(async () => {
    const rv = new Receiving(c);
    await rv.open(); await rv.supplier(F.supA); await rv.add('R2-P');
    await rv.editLine(1, { price: '10', quantity: '1', discount: '0', discount_type: 0, tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' });
    const before = num('SELECT COUNT(*) FROM ospos_receivings');
    const [a, b] = await Promise.all([rv.complete({ payment_type: 'Due' }), rv.complete({ payment_type: 'Due' })]);
    rec.add('B3d', 'finish the same receiving twice at the same moment', 'exactly one receiving', `${num('SELECT COUNT(*) FROM ospos_receivings') - before}`, num('SELECT COUNT(*) FROM ospos_receivings') - before === 1);
});
await only(async () => {
    const form = await c.get(`/accounting_receivables/advance_form/${F.cust3}`, { ajax: true });
    const f = formDefaults(form.text, 0);
    const body = {};
    for (const [n, v] of f.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; body[n] = v; }
    body.amount = '3'; body.payment_type = 'Cash';
    const action = f.action.replace('http://127.0.0.1:8082', '');
    const before = num(`SELECT COUNT(*) FROM ospos_customer_advances WHERE customer_id=${F.cust3}`);
    const rs = await Promise.all([c.post(action, body), c.post(action, body), c.post(action, body)]);
    const made = num(`SELECT COUNT(*) FROM ospos_customer_advances WHERE customer_id=${F.cust3}`) - before;
    rec.add('B3e', 'customer advance posted three times with the same request key', 'one advance', `${made} advance(s): ${rs.map((r) => r.data?.success).join('/')}`, made === 1);
    const pform = await c.get(`/accounting_payables/payment_form/${F.supB}`, { ajax: true });
    const pf = formDefaults(pform.text, 0);
    const pbody = {};
    for (const [n, v] of pf.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; pbody[n] = v; }
    pbody.amount = '2'; pbody.payment_type = 'Cash';
    const paction = pf.action.replace('http://127.0.0.1:8082', '');
    const pb = num('SELECT COUNT(*) FROM ospos_supplier_advances');
    await Promise.all([c.post(paction, pbody), c.post(paction, pbody)]);
    const pm = num('SELECT COUNT(*) FROM ospos_supplier_advances') - pb;
    rec.add('B3f', 'supplier payment posted twice at the same moment (the form has no request key)', 'one payment', `${pm} payment(s)`, pm === 1);
    const eform = await c.get('/expenses/view', { ajax: true });
    const ef = formDefaults(eform.text, 0);
    const ebody = {};
    for (const [n, v] of ef.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; ebody[n] = v; }
    ebody.amount = '4.00'; ebody.payment_type = 'Cash'; ebody.expense_category_id = '1'; ebody.description = 'R2 double';
    const eb = num('SELECT COUNT(*) FROM ospos_expenses');
    await Promise.all([c.post('/expenses/save/-1', ebody), c.post('/expenses/save/-1', ebody)]);
    const em = num('SELECT COUNT(*) FROM ospos_expenses') - eb;
    rec.add('B3g', 'expense saved twice at the same moment', 'one expense (or the page blocks the second click)', `${em} expense(s)`, em === 1);
});

// B4: concurrent sales of the last unit with negative stock refused
await only(async () => {
    await c.submitFormByAction('/stock_units', 'settings', { negative_stock: 'refuse' });
    const code = `R2-RACE${Date.now() % 100000}`;
    const made0 = await c.submitForm('/items/view', { item_number: code, name: 'R2 race item', category: 'R2', cost_price: '5', unit_price: '9', 'tax_names[]': ['Zero', 'Zero2'], 'tax_percents[]': ['0', '0'] });
    const rv = new Receiving(c);
    await rv.open(); await rv.supplier(F.supA); await rv.add(code);
    await rv.editLine(1, { price: '5', quantity: '3', discount: '0', discount_type: 0, tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' });
    await rv.complete({ payment_type: 'Cash', amount_tendered: '15' });
    const itemId = num(`SELECT item_id FROM ospos_items WHERE item_number='${code}'`);
    const have = num(`SELECT COALESCE(SUM(quantity),0) FROM ospos_item_quantities WHERE item_id=${itemId} AND location_id=1`);
    const other = await admin('cashier', 'Cashier#2026');
    const cartFor = async (cl) => { const rg = new Register(cl); await rg.openSales('sale'); await rg.add(code); await rg.editLine(1, { price: '9', quantity: String(have), discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 }); await rg.pay('Cash', String(have * 9)); return rg; };
    const r1 = await cartFor(c); const r2 = await cartFor(other);
    const before = sales();
    await Promise.all([r1.complete(), r2.complete()]);
    const made = sales() - before;
    const left = num(`SELECT COALESCE(SUM(quantity),0) FROM ospos_item_quantities WHERE item_id=${itemId} AND location_id=1`);
    rec.add('B4', `two cashiers sell all ${have} remaining units at the same moment while negative stock is refused`, 'one sale, stock never below zero', `${made} sale(s), stock left ${left}`, made === 1 && left >= 0);
    await c.submitFormByAction('/stock_units', 'settings', { negative_stock: 'allow' });
});

// B5: odd inputs at the register
await only(async () => {
    const trials = [['zero', '0'], ['negative', '-3'], ['huge', '999999999999'], ['tiny', '0.001'], ['text', 'abc'], ['arabic digit', '٣'], ['arabic decimal', '١٫٥'], ['comma decimal', '1,5'], ['exponent', '1e3'], ['blank', ' '], ['long digits', '1'.repeat(40)]];
    for (const [name, qty] of trials) {
        const reg = new Register(c);
        await reg.openSales('sale');
        await reg.add('R2-Q');
        const r = await reg.editLine(1, { price: '50', quantity: qty, discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 });
        const shownQty = r.text.match(/name="quantity" value="([^"]*)"/)?.[1];
        const total = r.text.match(/id="pos_total_value">([^<]*)/)?.[1];
        rec.add(`B5-qty-${name}`, `quantity "${qty}" on a line`, 'refused in words or a sane number, never an error page', `${r.status} qty=${shownQty} total=${total} err=${alertOf(r.text) ?? ''}`.slice(0, 220), !hasError(r) && r.status < 500 && !/NaN|INF/.test(total ?? ''));
        await c.post('/sales/cancel', {});
    }
    for (const [name, price] of [['negative price', '-5'], ['text price', 'abc'], ['arabic price', '٢٥'], ['huge price', '99999999999999']]) {
        const reg = new Register(c);
        await reg.openSales('sale');
        await reg.add('R2-Q');
        const r = await reg.editLine(1, { price, quantity: '1', discount: '0', discount_type: 0, description: '', serialnumber: '', location: 1 });
        const total = r.text.match(/id="pos_total_value">([^<]*)/)?.[1];
        rec.add(`B5-${name.replace(/ /g, '-')}`, `price "${price}" on a line`, 'refused or sane', `${r.status} total=${total} err=${alertOf(r.text) ?? ''}`.slice(0, 200), !hasError(r) && !/NaN|INF/.test(total ?? ''));
        await c.post('/sales/cancel', {});
    }
    for (const [name, disc, type] of [['percent over 100', '150', 0], ['percent negative', '-10', 0], ['fixed above the line', '9999', 1], ['percent text', 'abc', 0]]) {
        const reg = new Register(c);
        await reg.openSales('sale');
        await reg.add('R2-Q');
        const r = await reg.editLine(1, { price: '50', quantity: '1', discount: disc, discount_type: type, description: '', serialnumber: '', location: 1 });
        const total = r.text.match(/id="pos_total_value">([^<]*)/)?.[1];
        rec.add(`B5-disc-${name.replace(/ /g, '-')}`, `discount "${disc}" (${type ? 'fixed' : 'percent'})`, 'refused', `${r.status} total=${total} err=${alertOf(r.text) ?? ''}`.slice(0, 200), !hasError(r) && (alertOf(r.text) !== null || total === '$50.00') && !/-\$|NaN/.test(total ?? ''));
        await c.post('/sales/cancel', {});
    }
});

// B6: odd text in names
await only(async () => {
    const names = [['very long', 'N'.repeat(400)], ['emoji', 'قهوة ☕🧋 مخبز'], ['quotes', `O'Brien "Quoted" \`tick\``], ['angle brackets', '<script>alert(1)</script><img src=x onerror=alert(2)>'], ['sql', "x'; DROP TABLE ospos_items; --"]];
    for (const [label, name] of names) {
        const r = await c.submitForm('/items/view', { item_number: `ODD-${label.replace(/ /g, '')}`.slice(0, 20), name, category: 'R2', cost_price: '1', unit_price: '2', 'tax_names[]': ['Zero', 'Zero2'], 'tax_percents[]': ['0', '0'] });
        const id = num(`SELECT COALESCE(MAX(item_id),0) FROM ospos_items WHERE item_number='ODD-${label.replace(/ /g, '')}'`.slice(0, 120));
        rec.add(`B6-item-${label.replace(/ /g, '-')}`, `item named "${name.slice(0, 40)}"`, 'saved and shown escaped, or refused in words', `${r.status} ${show(r).slice(0, 140)}`, r.status < 500 && typeof r.data?.success === 'boolean');
    }
    const list = await c.get('/items/search?limit=100&offset=0', { ajax: true });
    const raw = JSON.stringify(list.data ?? {});
    rec.add('B6-escaping', 'the item list must carry no live script tag from a stored name', 'escaped', raw.includes('<script>') ? 'RAW <script> IN THE LIST' : 'escaped', !raw.includes('<script>alert(1)') && !raw.includes('<img src=x'));
    const cust = await c.submitForm('/customers/view', { first_name: '<b>Bold</b>', last_name: `O'Neil "Q"`, email: '' });
    rec.add('B6-customer', 'customer with markup and quotes in the name', 'saved or refused in words', show(cust).slice(0, 150), cust.status < 500);
    const cl = await c.get('/customers/search?limit=50&offset=0', { ajax: true });
    rec.add('B6-customer-escaping', 'customer list does not echo the tag raw', 'escaped', JSON.stringify(cl.data ?? {}).includes('<b>Bold</b>') ? 'RAW <b> IN THE LIST' : 'escaped', !JSON.stringify(cl.data ?? {}).includes('<b>Bold</b>'));
});

// B7: the same invoice number twice
await only(async () => {
    const first = await ringUp(c, { mode: 'sale_invoice', customer: F.cust1, lines: [{ code: 'R2-P', qty: '1' }], payments: [], invoiceNumber: 'DUP-100' });
    const second = await ringUp(c, { mode: 'sale_invoice', customer: F.cust1, lines: [{ code: 'R2-P', qty: '1' }], payments: [], invoiceNumber: 'DUP-100' });
    rec.add('B7', 'two invoices typed with the number DUP-100', 'second refused in words', `first ${first.saleId} ${first.errors.join('|')}; second ${second.saleId ?? 'refused'} ${second.errors.join('|')}`, !!first.saleId && second.saleId === null && second.errors.length > 0);
});

// B8: an edit with a garbage date
await only(async () => {
    const sid = num("SELECT MAX(sale_id) FROM ospos_sales WHERE sale_status=0 AND sale_type=0");
    const r = await c.post(`/sales/save/${sid}`, { date: 'not a date', customer_id: '', employee_id: '1', comment: 'x', invoice_number: '', payment_terms_days: '' });
    rec.add('B8', 'edit form of a sale with the date "not a date"', 'refused in words, never a 500', `${r.status} ${show(r).slice(0, 160)}`, r.status < 500);
});

// B9: the cashier writes to lookups it cannot open elsewhere
await only(async () => {
    const r = await cashier.post('/item_brands/save', { name: 'Cashier brand' });
    const n = num("SELECT COUNT(*) FROM ospos_item_brands WHERE name='Cashier brand'");
    rec.add('B9', 'a cashier who holds the items and customers modules posts a new item brand', 'accepted (the lookup belongs to those modules)', `${r.status} ${show(r).slice(0, 120)}; rows ${n}`, n === 1);
    const d = await cashier.post('/customer_classifications/save', { name: 'Cashier class' });
    const n2 = num("SELECT COUNT(*) FROM ospos_customer_classifications WHERE name='Cashier class'");
    rec.add('B9b', 'a cashier who holds the customers module posts a new customer classification', 'accepted (it belongs to that module)', `${d.status} ${show(d).slice(0, 120)}; rows ${n2}`, n2 === 1);
});

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id)).map((x) => x.id + ':' + x.status)));
