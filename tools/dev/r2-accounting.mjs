// Phase A (e): manual journals, reversal, the period lock and the financial reports against independent SQL over the documents.
import { admin, Recorder, snap, fixture, acct, ACC, sysCode, sql, one, num, alertOf, ringUp, readiness, visibleText, hasError, Receiving } from './r2-lib.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-accounting.json');
const F = fixture();
const c = await admin();
const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const money = (s) => Number(String(s).replace(/[,\s]/g, '').replace(/^\((.*)\)$/, '-$1'));
const rows = (html) => [...html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((m) => [...m[1].matchAll(/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/g)].map((x) => x[1].replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim())).filter((r) => r.length > 1);
const STAND = "j.status IN ('posted','reversed')";
const bal = (where) => num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE ${STAND} AND (${where})`);
const byType = (type) => bal(`a.account_type='${type}'`);
const ok = (r) => r.data?.success === true;
const show = (r) => JSON.stringify(r.data ?? r.text.slice(0, 160));
const tryLock = async () => { const v = one('SELECT MAX(date_to) FROM ospos_accounting_closed_periods WHERE active=1'); return v && v !== 'NULL' ? v : null; };
{
    const cut = await tryLock();
    if (cut) await c.post('/accounting_periods/closings/reopen', { scope: 'company', cutoff: cut, reason: 'R2 start' });
}
const tree = (code) => num(`WITH RECURSIVE t AS (SELECT account_id FROM ospos_accounting_accounts WHERE code='${code}' UNION ALL SELECT a.account_id FROM ospos_accounting_accounts a JOIN t ON a.parent_id=t.account_id) SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) WHERE ${STAND} AND l.account_id IN (SELECT account_id FROM t)`);

// ---- reports against SQL ----
{
    const tb = rows((await c.get('/accounting_reports', { ajax: false })).text);
    const total = tb.find((r) => r[0] === 'Total');
    rec.add('E1', 'trial balance: total debit equals total credit', 'equal', total ? `${total[1]} / ${total[2]}` : 'no total row', !!total && money(total[1]) === money(total[2]));
    let bad = [];
    for (const r of tb) {
        if (!/^\d+$/.test(r[0])) continue;
        const codeBal = tree(r[0]);
        const shown = money(r[4] || '0') - money(r[5] || '0');
        if (Math.abs(codeBal - shown) > 0.01) bad.push(`${r[0]}: report ${shown} vs sql ${r2(codeBal)}`);
    }
    rec.add('E2', 'trial balance: every account closing balance equals the SQL sum of its lines (with its children)', 'no difference', bad.join('; ') || `${tb.length} rows agree`, bad.length === 0);
}
{
    const rev = -byType('revenue');
    const exp = byType('expense');
    const net = r2(rev - exp);
    const pl = rows((await c.get('/accounting_statements/income_statement', { ajax: false })).text);
    const shown = money(pl.find((r) => r[0] === 'Net income')?.[1] ?? 'NaN');
    rec.eq('E3', 'income statement net income equals revenue less expenses by SQL', net, shown);
    const plm = rows((await c.get('/accounting_statements/profit_loss', { ajax: false })).text);
    const shown2 = money(plm.find((r) => r[0] === 'Total profit')?.slice(-1)[0] ?? 'NaN');
    rec.eq('E4', 'profit and loss (monthly) total profit equals the same figure', net, shown2);
    const bs = rows((await c.get('/accounting_statements/balance_sheet', { ajax: false })).text);
    const assets = money(bs.find((r) => r[1] === 'الأصول')?.[2] ?? 'NaN');
    const le = money(bs.find((r) => /Total liabilities and equity/.test(r[0] + ' ' + r[1]))?.[1] ?? bs.find((r) => /Total liabilities and equity/.test(r.join(' ')))?.slice(-1)[0] ?? 'NaN');
    rec.eq('E5', 'balance sheet: assets equal liabilities plus equity including the result of the period', assets, le);
    const sqlAssets = bal("a.account_type='asset'");
    rec.eq('E5b', 'balance sheet assets equal the SQL sum of asset accounts', r2(sqlAssets), assets);
    const equityProfit = money(bs.find((r) => /Profit \/ loss/.test(r.join(' ')))?.slice(-1)[0] ?? 'NaN');
    rec.eq('E6', 'balance sheet result of the period equals the net income (the movement on the equity side)', net, equityProfit);
    const cf = rows((await c.get('/accounting_statements/cash_flow', { ajax: false })).text);
    const dcash = money(cf.find((r) => r[0] === 'Total change in cash')?.[1] ?? 'NaN');
    const cashSql = bal("a.code LIKE '1201%' OR a.code LIKE '1202%'");
    rec.eq('E7', 'cash flow: total change in cash equals the movement of the treasury and bank accounts', r2(cashSql), dcash);
    const op = money(cf.find((r) => r[0] === 'Cash from operating activities')?.[1] ?? 'NaN');
    const inv = money(cf.find((r) => r[0] === 'Cash from investing activities')?.[1] ?? 'NaN');
    const fin = money(cf.find((r) => r[0] === 'Cash from financing activities')?.[1] ?? 'NaN');
    const other = money(cf.find((r) => r[0] === 'Total other changes')?.[1] ?? '0');
    rec.eq('E7b', 'cash flow: operating + investing + financing + other changes equals the total change', dcash, r2(op + inv + fin + other), 0.02, `operating ${op}, investing ${inv}, financing ${fin}, other ${other}`);
}
{
    // VAT report against the documents
    const outDoc = num("SELECT COALESCE(SUM(t.item_tax_amount),0) FROM ospos_sales_items_taxes t JOIN ospos_sales s USING(sale_id) WHERE s.sale_status=0 AND s.sale_type IN (0,1)") - num("SELECT COALESCE(SUM(ABS(t.item_tax_amount)),0) FROM ospos_sales_items_taxes t JOIN ospos_sales s USING(sale_id) WHERE s.sale_status=0 AND s.sale_type=4");
    const outBooks = -bal("a.system_key='tax_output'");
    const page = rows((await c.get('/accounting_reports/tax', { ajax: false })).text);
    const shown = money(page.find((r) => /Tax collected/.test(r[0]))?.[3] ?? 'NaN');
    rec.eq('E8', 'VAT report: tax collected equals the tax of the sales documents', r2(outDoc), shown);
    rec.eq('E8b', 'VAT report: tax collected equals the output tax account', r2(outBooks), shown);
    const inDoc = num("SELECT COALESCE(SUM(ROUND(ri.receiving_quantity_unused,4)),0) FROM (SELECT 0 AS receiving_quantity_unused) ri");
    const inBooks = bal("a.system_key='tax_input'");
    
    rec.note('E8c', `input tax account ${r2(inBooks)}; report line "${page.find((r) => /Tax paid|Input tax|Tax on purchases/.test(r[0]))?.join(' | ')}"`);
    rec.eq('E8d', 'VAT report: the input tax of the purchases (80 x 5% = 4) equals the input tax account', 4, r2(inBooks));
}
{
    // customer ledgers against the documents
    for (const [name, id] of [['customer 1', F.cust1], ['customer 2', F.cust2], ['customer 3', F.cust3]]) {
        const sales = num(`SELECT COALESCE(SUM(x.t),0) FROM (SELECT s.sale_id, SUM(si.quantity_purchased*si.item_unit_price*IF(si.discount_type=0,(100-si.discount)/100,1) - IF(si.discount_type=1,si.discount,0)) AS t FROM ospos_sales s JOIN ospos_sales_items si USING(sale_id) WHERE s.customer_id=${id} AND s.sale_status=0 AND s.sale_type IN (0,1) GROUP BY s.sale_id) x`);
        const taxes = num(`SELECT COALESCE(SUM(t.item_tax_amount),0) FROM ospos_sales_items_taxes t JOIN ospos_sales s USING(sale_id) WHERE s.customer_id=${id} AND s.sale_status=0 AND s.sale_type IN (0,1)`);
        const returns = num(`SELECT COALESCE(SUM(x.t),0) FROM (SELECT s.sale_id, SUM(si.quantity_purchased*si.item_unit_price) AS t FROM ospos_sales s JOIN ospos_sales_items si USING(sale_id) WHERE s.customer_id=${id} AND s.sale_status=0 AND s.sale_type=4 GROUP BY s.sale_id) x`);
        const paid = num(`SELECT COALESCE(SUM(p.payment_amount - p.cash_refund),0) FROM ospos_sales_payments p JOIN ospos_sales s USING(sale_id) WHERE s.customer_id=${id} AND s.sale_status=0 AND p.payment_type NOT IN ('Due')`);
        const adv = num(`SELECT COALESCE(SUM(amount),0) FROM ospos_customer_advances WHERE customer_id=${id} AND status='completed'`);
        const chq = num(`SELECT COALESCE(SUM(amount),0) FROM ospos_cheques WHERE direction='received' AND party_id=${id} AND status NOT IN ('rejected','cancelled')`);
        const docBal = r2(sales + taxes + returns - paid - adv - chq);
        const books = r2(bal(`a.entity_type='customer' AND a.entity_id=${id}`));
        rec.eq(`E9-${id}`, `${name}: balance by its documents (sales + tax + returns - payments - advances - cheques) against its ledger account`, docBal, books, 0.02, `sales ${r2(sales)} tax ${r2(taxes)} returns ${r2(returns)} paid ${r2(paid)} adv ${r2(adv)} cheques ${r2(chq)}`);
    }
}
rec.note('E9-shape', 'sales_payments.payment_type for returns can carry negative amounts: the documents formula treats them as signed');

// ---- manual journal and reversal ----
let jid = null;
{
    const rent = one("SELECT account_id FROM ospos_accounting_accounts WHERE code='5201'");
    const cash = one("SELECT account_id FROM ospos_accounting_accounts WHERE code='120101'");
    const before = bal("a.code='120101'");
    const r = await c.post('/accounting_journals/post', { date: '2026-11-14', description: 'R2 manual', 'lines[0][account_id]': rent, 'lines[0][debit]': '500', 'lines[0][credit]': '', 'lines[1][account_id]': cash, 'lines[1][debit]': '', 'lines[1][credit]': '500' });
    rec.add('E10', 'post a manual journal: Dr rent 500, Cr cash 500', 'posted', show(r), ok(r));
    jid = r.data?.id;
    rec.eq('E10b', 'cash account falls by 500', before - 500, bal("a.code='120101'"));
    const unbalanced = await c.post('/accounting_journals/post', { date: '2026-11-14', description: 'R2 unbalanced', 'lines[0][account_id]': rent, 'lines[0][debit]': '500', 'lines[1][account_id]': cash, 'lines[1][credit]': '400' });
    rec.add('E11', 'an unbalanced journal (500 / 400) is refused in words', 'refused', show(unbalanced), unbalanced.data?.success === false);
    const rev = await c.post(`/accounting_journals/reverse/${jid}`, { date: '2026-11-14', reason: 'R2 reverse' });
    rec.add('E12', 'reverse the manual journal', 'reversed', show(rev), ok(rev));
    rec.eq('E12b', 'cash is back after the reversal', before, bal("a.code='120101'"));
    const again = await c.post(`/accounting_journals/reverse/${jid}`, { date: '2026-11-14', reason: 'R2 twice' });
    rec.add('E13', 'reversing the same journal twice is refused', 'refused', show(again), again.data?.success === false);
}

// ---- period lock ----
{
    const rent = one("SELECT account_id FROM ospos_accounting_accounts WHERE code='5201'");
    const cash = one("SELECT account_id FROM ospos_accounting_accounts WHERE code='120101'");
    const cut = await tryLock();
    if (!cut) {
        const close = await c.post('/accounting_periods/closings/close', { scope: 'company', date: '2026-11-14', note: 'R2 lock' });
        rec.add('E14', 'close the period through today', 'closed', show(close), ok(close));
    } else rec.note('E14', `already closed through ${cut}`);
    const inside = await c.post('/accounting_journals/post', { date: '2026-11-14', description: 'R2 in lock', 'lines[0][account_id]': rent, 'lines[0][debit]': '10', 'lines[1][account_id]': cash, 'lines[1][credit]': '10' });
    rec.add('E15', 'a manual journal dated inside the closed period', 'refused in words', show(inside), inside.data?.success === false);
    const before = bal("a.code='120101'");
    const sale = await ringUp(c, { lines: [{ code: 'R2-Q', qty: '1' }], payments: [['Cash', '50']] });
    rec.add('E16', 'a sale dated today inside the lock', 'refused, nothing saved', `${sale.saleId ?? 'not saved'} ${sale.errors.join(' | ')}`, sale.saleId === null && sale.errors.length > 0);
    const rv = new Receiving(c);
    await rv.open(); await rv.supplier(F.supA); await rv.add('R2-P');
    await rv.editLine(1, { price: '10', quantity: '1', discount: '0', discount_type: 0, tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' });
    const rcv = await rv.complete({ payment_type: 'Due' });
    rec.add('E17', 'a purchase dated today inside the lock', 'refused in words', alertOf(rcv.text) ?? 'saved', !!alertOf(rcv.text));
    const pay = await c.submitForm(`/accounting_payables/payment_form/${F.supA}`, { amount: '1', payment_type: 'Cash' });
    rec.add('E18', 'a supplier payment inside the lock', 'refused in words', show(pay), pay.data?.success === false);
    const adv = await c.submitForm(`/accounting_receivables/advance_form/${F.cust1}`, { amount: '1', payment_type: 'Cash' });
    rec.add('E19', 'a customer advance inside the lock', 'refused in words', show(adv), adv.data?.success === false);
    const exp = await c.submitForm('/expenses/view', { amount: '5.00', payment_type: 'Cash', expense_category_id: 1, description: 'R2 in lock' });
    rec.add('E20', 'an expense inside the lock', 'refused in words', show(exp), exp.data?.success === false);
    const voucher = await c.submitForm('/receipt_vouchers/view', { amount: '5.00', payment_type: 'Cash', treasury_id: 0, category_id: 0, account_id: 0 }, { action: '/receipt_vouchers/save' });
    rec.add('E21', 'a receipt voucher inside the lock', 'refused in words', show(voucher), voucher.data?.success === false);
    const tr = await c.submitForm('/accounting_treasuries/transfer_form', { from_treasury_id: 1, to_treasury_id: 3, amount: '1', note: 'R2' });
    rec.add('E22', 'a treasury transfer inside the lock', 'refused in words', show(tr), tr.data?.success === false);
    const lastJ = num("SELECT MAX(journal_id) FROM ospos_accounting_journals WHERE source_type='manual' AND status IN ('posted','reversed')");
    const rv2 = await c.post(`/accounting_journals/reverse/${lastJ}`, { date: '2026-11-14', reason: 'R2 inside lock' });
    rec.add('E23', 'reverse a journal into the closed period', 'refused', show(rv2), rv2.data?.success === false);
    const sid = num("SELECT MAX(sale_id) FROM ospos_sales WHERE sale_type=0 AND sale_status=0");
    const del = await c.post(`/sales/delete/${sid}`, {});
    rec.add('E24', 'cancel a POS sale of the closed period', 'refused in words', show(del), del.data?.success === false);
    rec.eq('E25', 'nothing leaked into the books while the period was closed', before, bal("a.code='120101'"));
    const leaks = num(`SELECT COUNT(*) FROM ospos_accounting_journals j WHERE j.posted_at > (SELECT MAX(created_at) FROM ospos_accounting_closed_periods WHERE active=1) AND j.journal_date <= (SELECT MAX(date_to) FROM ospos_accounting_closed_periods WHERE active=1) AND j.status IN ('posted','reversed')`);
    rec.eq('E25b', 'no journal posted after the closing falls inside it', 0, leaks);
    const reopen = await c.post('/accounting_periods/closings/reopen', { scope: 'company', cutoff: '2026-11-14', reason: 'R2 done' });
    rec.add('E26', 'reopen the period', 'reopened', show(reopen), ok(reopen));
}

// ---- year-end preview ----
{
    const y = await c.get('/accounting_periods/year_end', { ajax: false });
    const t = visibleText(y.text);
    rec.add('E27', 'the year-end screen opens', '200 without an error page', `${y.status}`, y.status === 200 && !hasError(y));
    rec.note('E27-text', t.replace(/^.*Year/, 'Year').slice(0, 300));
}

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id))));
