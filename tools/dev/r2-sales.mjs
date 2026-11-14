// Phase A (b), first part: POS and invoice sales through the real register, checked against hand arithmetic.
import { admin, Receiving, Recorder, snap, deltaCheck, fixture, acct, ACC, sysCode, sql, one, num, alertOf, hasError, ringUp, readiness, visibleText } from './r2-lib.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-sales.json');
const F = fixture();
const c = await admin();
const items = [F.P, F.Q, F.T, F.U];
const REV = sysCode('sales_revenue');
const TAXOUT = sysCode('tax_output');
const COGS = sysCode('cogs');
const COUNTER = sysCode('counter_customers');
const DISC = sysCode('sales_discount');
const cust = (id) => acct('customer', id);
const r4 = (n) => Math.round(n * 1e4) / 1e4;
const cost = (before, id) => before.items[id].cost;

const sale = async (id, steps, spec, build) => {
    const before = snap(items);
    const out = await ringUp(c, spec);
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add(id + '-reached', steps, 'sale saved', `saleId=${out.saleId} errors=${out.errors.join(' | ')}`, false);
    const { bal, itm } = build(before);
    deltaCheck(rec, id, steps, before, after, bal, itm);
    return out;
};

// S0: stock for the taxed item
{
    const rv = new Receiving(c);
    await rv.open();
    await rv.supplier(F.supB);
    await rv.add('R2-T');
    await rv.editLine(1, { price: '40', quantity: '20', discount: '0', discount_type: 0, tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' });
    const before = snap(items);
    await rv.complete({ payment_type: 'Cash', amount_tendered: '800' });
    deltaCheck(rec, 'S0', 'receive 20 T @40 for cash', before, snap(items), { [ACC.inventory]: 800, [ACC.cash]: -800 }, { [F.T]: { qty: 20, cost: 40 } });
}

// S1: counter cash sale
await sale('S1', 'POS: 2 P @25 + 1 Q @50, Cash 100', { lines: [{ code: 'R2-P', qty: '2' }, { code: 'R2-Q', qty: '1' }], payments: [['Cash', '100']] }, (b) => {
    const cg = r4(2 * cost(b, F.P) + cost(b, F.Q));
    return { bal: { [ACC.cash]: 100, [REV]: -100, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.P]: { qty: -2 }, [F.Q]: { qty: -1 } } };
});

// S2: sales tax excluded
await sale('S2', 'POS: 2 T @100 (15% added), Cash 230', { lines: [{ code: 'R2-T', qty: '2' }], payments: [['Cash', '230']] }, (b) => {
    const cg = r4(2 * cost(b, F.T));
    return { bal: { [ACC.cash]: 230, [REV]: -200, [TAXOUT]: -30, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.T]: { qty: -2 } } };
});

// S3: mixed payments cash and card
{
    const before = snap(items);
    const out = await ringUp(c, { lines: [{ code: 'R2-T', qty: '1' }, { code: 'R2-Q', qty: '1' }], payments: [['Cash', '100'], ['Credit Card', '65', 'AB123']] });
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add('S3-reached', 'cash 100 + card 65 on 165', 'saved', `saleId=${out.saleId} ${out.errors.join(' | ')}`, false);
    const cg = r4(cost(before, F.T) + cost(before, F.Q));
    const moved = {};
    for (const code of new Set([...Object.keys(before.bal), ...Object.keys(after.bal)])) moved[code] = r4((after.bal[code] ?? 0) - (before.bal[code] ?? 0));
    const cardCode = sql("SELECT a.code FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.source_type='sale_payment' AND j.source_id=" + out.saleId + " AND l.debit=65").flat()[0];
    rec.add('S3-split', 'cash 100 + card 65 on 165 (150 net + 15 tax)', 'cash +100, card account +65', `cash ${moved[ACC.cash]}, card(${cardCode}) ${moved[cardCode]}`, moved[ACC.cash] === 100 && moved[cardCode] === 65);
    deltaCheck(rec, 'S3', 'mixed payments: all other accounts', before, after, { [ACC.cash]: 100, [cardCode]: 65, [REV]: -150, [TAXOUT]: -15, [COGS]: cg, [ACC.inventory]: -cg }, { [F.T]: { qty: -1 }, [F.Q]: { qty: -1 } });
}

// S4: credit sale to customer 1
await sale('S4', 'sale on Due to customer 1: 3 P @25', { customer: F.cust1, lines: [{ code: 'R2-P', qty: '3' }], payments: [['Due', '75']] }, (b) => {
    const cg = r4(3 * cost(b, F.P));
    return { bal: { [cust(F.cust1)]: 75, [REV]: -75, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.P]: { qty: -3 } } };
});

// S6: part cash part due
await sale('S6', 'sale to customer 2: 4 P @25 = 100, Cash 40 + Due 60', { customer: F.cust2, lines: [{ code: 'R2-P', qty: '4' }], payments: [['Cash', '40'], ['Due', '60']] }, (b) => {
    const cg = r4(4 * cost(b, F.P));
    return { bal: { [ACC.cash]: 40, [cust(F.cust2)]: 60, [REV]: -100, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.P]: { qty: -4 } } };
});

// S7: percent and fixed line discounts
{
    const steps = 'sale: 2 Q @50 with 10% off + 1 P @25 with a fixed 5 off; Cash 110';
    const before = snap(items);
    const out = await ringUp(c, { lines: [{ code: 'R2-Q', qty: '2', discount: '10', dtype: 0 }, { code: 'R2-P', qty: '1', discount: '5', dtype: 1 }], payments: [['Cash', '110']] });
    const after = snap(items);
    if (!out.saleId || out.errors.length) rec.add('S7-reached', steps, 'saved', `saleId=${out.saleId} ${out.errors.join(' | ')}`, false);
    const moved = {};
    for (const code of new Set([...Object.keys(before.bal), ...Object.keys(after.bal)])) moved[code] = r4((after.bal[code] ?? 0) - (before.bal[code] ?? 0));
    rec.eq('S7-cash', steps, 110, moved[ACC.cash]);
    rec.eq('S7-net-revenue', steps + ' (revenue net of discount account)', -110, (moved[REV] ?? 0) + (moved[DISC] ?? 0));
    const cg = r4(2 * cost(before, F.Q) + cost(before, F.P));
    rec.eq('S7-cogs', steps, cg, moved[COGS]);
    out.discPosted = { rev: moved[REV], disc: moved[DISC] };
    rec.note('S7-how', `revenue ${moved[REV]}, discount account ${moved[DISC]}`);
}

// S8: invoice discount percent
await sale('S8', 'sale: 2 P @25 with a 10% invoice discount, Cash 45', { lines: [{ code: 'R2-P', qty: '2' }], invoiceDiscount: { value: '10', type: 0 }, payments: [['Cash', '45']] }, (b) => {
    const cg = r4(2 * cost(b, F.P));
    return { bal: { [ACC.cash]: 45, [REV]: -45, [COGS]: cg, [ACC.inventory]: -cg }, itm: { [F.P]: { qty: -2 } } };
});

rec.save();
const bad = readiness();
console.log('READINESS non-pass:', JSON.stringify(bad.filter((x) => !['sensitive_grants', 'clock_zones'].includes(x.id))));
