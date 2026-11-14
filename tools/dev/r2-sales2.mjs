// Phase A (b), second part: tax included, invoices, credit notes, refunds, cancel, advances, credit control, stock refusal.
import { admin, Recorder, snap, deltaCheck, fixture, acct, ACC, sysCode, sql, one, num, alertOf, hasError, ringUp, returnOf, readiness, visibleText, entityBalance, Register } from './r2-lib.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-sales2.json');
const F = fixture();
const c = await admin();
const items = [F.P, F.Q, F.T, F.U];
const REV = sysCode('sales_revenue');
const RET = sysCode('sales_returns');
const TAXOUT = sysCode('tax_output');
const COGS = sysCode('cogs');
const cust = (id) => acct('customer', id);
const r4 = (n) => Math.round(n * 1e4) / 1e4;
const cost = (b, id) => b.items[id].cost;
const moved = (b, a) => {
    const m = {};
    for (const code of new Set([...Object.keys(b.bal), ...Object.keys(a.bal)])) m[code] = r4((a.bal[code] ?? 0) - (b.bal[code] ?? 0));
    return m;
};
const run = async (id, steps, spec, expect) => {
    const before = snap(items);
    const out = await ringUp(c, spec);
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add(id + '-reached', steps, 'saved', `saleId=${out.saleId} ${out.errors.join(' | ')}`, false);
    const e = expect(before, out);
    deltaCheck(rec, id, steps, before, after, e.bal, e.itm);
    return out;
};
const taxIncluded = (on) => c.submitFormByAction('/config', 'saveTax', { tax_included: on ? '1' : null });

// S9: tax included in the price
{
    const r = await taxIncluded(true);
    rec.add('S9-config', 'switch tax included on', 'saved', JSON.stringify(r.data), r.data?.success === true);
    const net = 60 / 1.05;
    const tax = 60 - net;
    await run('S9', 'tax included: 3 U @20 (5% inside the price), Cash 60', { lines: [{ code: 'R2-U', qty: '3' }], payments: [['Cash', '60']] }, (b) => {
        const cg = r4(3 * cost(b, F.U));
        return { bal: { [ACC.cash]: 60, [REV]: -r4(net), [TAXOUT]: -r4(tax), [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.U]: { qty: -3 } } };
    });
    await taxIncluded(false);
}

// S10: invoice with a partial payment
let inv10;
{
    const out = await run('S10', 'invoice to customer 3: 2 Q @50, Cash 30', { mode: 'sale_invoice', customer: F.cust3, lines: [{ code: 'R2-Q', qty: '2' }], payments: [['Cash', '30']] }, (b) => {
        const cg = r4(2 * cost(b, F.Q));
        return { bal: { [ACC.cash]: 30, [cust(F.cust3)]: 70, [REV]: -100, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.Q]: { qty: -2 } } };
    });
    inv10 = out.saleId;
}

// S11: credit note of one unit, credited to the customer account
{
    const before = snap(items);
    const out = await returnOf(c, inv10, { qty: '-1', payments: [['Due', '-50']] });
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add('S11-reached', 'credit note 1 Q of invoice', 'saved', `${out.saleId} ${out.errors.join(' | ')}`, false);
    const cg = r4(cost(before, F.Q));
    deltaCheck(rec, 'S11', `credit note of 1 Q (@50) of invoice ${inv10}, credited to the account`, before, after, { [cust(F.cust3)]: -50, [RET]: 50, [COGS]: -cg, [ACC.inventory]: cg }, { [F.Q]: { qty: 1 } });
}

// S12: returning more than was sold is refused
{
    const out = await returnOf(c, inv10, { qty: '-5', payments: [['Due', '-250']] });
    rec.add('S12', `credit note of 5 Q when 2 were invoiced and 1 already returned`, 'refused', out.errors.join(' | ') || `saved ${out.saleId}`, out.saleId === null && out.errors.length > 0);
}

// S13: refund invoice paid in cash
{
    const inv = await ringUp(c, { mode: 'sale_invoice', customer: F.cust2, lines: [{ code: 'R2-Q', qty: '2' }], payments: [['Cash', '100']] });
    const before = snap(items);
    const out = await returnOf(c, inv.saleId, { kind: 'refundInvoice', qty: '-1', payments: [['Cash', '-50']] });
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add('S13-reached', 'refund invoice 1 Q', 'saved', `${out.saleId} ${out.errors.join(' | ')}`, false);
    const cg = r4(cost(before, F.Q));
    deltaCheck(rec, 'S13', 'refund invoice of 1 Q (@50) paid back in cash', before, after, { [ACC.cash]: -50, [RET]: 50, [COGS]: -cg, [ACC.inventory]: cg }, { [F.Q]: { qty: 1 } });
}

// S14: cancel (delete) a completed invoice sale
{
    const inv = await ringUp(c, { mode: 'sale_invoice', customer: F.cust2, lines: [{ code: 'R2-P', qty: '2' }], payments: [['Cash', '20']] });
    const before = snap(items);
    const r = await c.post(`/sales/delete/${inv.saleId}`, {});
    const after = snap(items);
    rec.add('S14-http', 'cancel an issued invoice with a payment', 'a clear answer (cancelled or refused in words)', `${r.status} ${JSON.stringify(r.data ?? r.text.slice(0, 200))}`, r.status < 500 && !hasError(r));
    const status = one(`SELECT sale_status FROM ospos_sales WHERE sale_id=${inv.saleId}`);
    const cg = r4(2 * cost(before, F.P));
    if (status === '1') deltaCheck(rec, 'S14', 'cancelled invoice reverses revenue, receivable, cash, stock', before, after, { [ACC.cash]: -20, [cust(F.cust2)]: -30, [REV]: 50, [COGS]: -cg, [ACC.inventory]: cg }, { [F.P]: { qty: 2 } });
    else rec.note('S14', `sale_status after delete = ${status} (0 completed, 1 suspended, 2 cancelled)`);
}

// S15: negative stock refused
{
    const out = await ringUp(c, { lines: [{ code: 'R2-U', qty: '1000' }], payments: [['Cash', '20000']] });
    rec.add('S15', 'sell 1000 U with 7 in stock', 'refused in words', out.errors.join(' | ') + (out.warnings.length ? ' [warning: ' + out.warnings.join(' | ') + ']' : '') + (out.saleId ? ` SAVED ${out.saleId}` : ''), out.saleId === null);
}

rec.save();
const bad = readiness();
console.log('READINESS non-pass:', JSON.stringify(bad.filter((x) => !['sensitive_grants', 'clock_zones', 'activation'].includes(x.id))));
