// Phase A (a): the purchase cycle through the real HTTP application, checked against hand arithmetic.
import { admin, Receiving, Recorder, snap, deltaCheck, fixture, acct, ACC, sql, one, num, alertOf, hasError, readiness, visibleText } from './r2-lib.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-purchases.json');
const F = fixture();
const c = await admin();
const rv = new Receiving(c);
const payA = acct('supplier', F.supA);
const items = [F.P, F.Q, F.T, F.U];
const lineFields = (o) => ({ price: o.price, quantity: o.qty, discount: o.discount ?? '0', discount_type: o.dtype ?? 0, tax_percent: o.tax ?? '0', receiving_quantity: '1', description: '', serialnumber: '', ...o.extra });

let lastReceiving = 0;
const receive = async (id, steps, supplier, lines, pay) => {
    await rv.open();
    if (supplier) await rv.supplier(supplier);
    let n = 0;
    for (const l of lines) {
        const r = await rv.add(l.code);
        n += 1;
        const e = await rv.editLine(n, lineFields(l));
        if (alertOf(e.text)) console.log('  edit alert', alertOf(e.text));
    }
    const before = snap(items);
    const done = await rv.complete(pay);
    lastReceiving = num('SELECT MAX(receiving_id) FROM ospos_receivings');
    if (hasError(done)) rec.add(id + '-http', steps, 'no error page', `status ${done.status}`, false);
    return { before, done, err: alertOf(done.text) };
};

let p1Receiving = 0;
// P1: credit purchase, two items
{
    const steps = 'receive 10 P @10 and 5 Q @20 from supplier A, payment Due';
    const { before, err } = await receive('P1', steps, F.supA, [{ code: 'R2-P', price: '10', qty: '10' }, { code: 'R2-Q', price: '20', qty: '5' }], { payment_type: 'Due' });
    const after = snap(items);
    p1Receiving = lastReceiving;
    if (err) rec.add('P1-err', steps, 'saved', err, false);
    deltaCheck(rec, 'P1', steps, before, after, { [ACC.inventory]: 200, [payA]: -200 }, { [F.P]: { qty: 10, cost: 10 }, [F.Q]: { qty: 5, cost: 20 } });
}

// P2: cash purchase at a different cost, moving average
{
    const steps = 'receive 10 P @12 from supplier A, payment Cash (the amount tendered is 120)';
    const { before, err } = await receive('P2', steps, F.supA, [{ code: 'R2-P', price: '12', qty: '10' }], { payment_type: 'Cash', amount_tendered: '120' });
    const after = snap(items);
    if (err) rec.add('P2-err', steps, 'saved', err, false);
    deltaCheck(rec, 'P2', steps, before, after, { [ACC.inventory]: 120, [ACC.cash]: -120 }, { [F.P]: { qty: 10, cost: 11 } });
}

// P3: supplier payment, partial
{
    const steps = 'pay 80 to supplier A in cash from the payment dialog';
    const before = snap(items);
    const r = await c.submitForm(`/accounting_payables/payment_form/${F.supA}`, { amount: '80', payment_type: 'Cash' });
    const after = snap(items);
    rec.add('P3-http', steps, 'success', JSON.stringify(r.data ?? r.text.slice(0, 200)), r.data?.success === true);
    deltaCheck(rec, 'P3', steps, before, after, { [payA]: 80, [ACC.cash]: -80 });
}

// P3b: balance of supplier A in the ledger screen equals 200-80
{
    const owed = -num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.status='posted' AND a.code='${payA}'`);
    rec.eq('P3b', 'supplier A balance after P1..P3', 120, owed);
    const page = await c.get(`/accounting_payables/supplier/${F.supA}`, { ajax: false });
    const text = visibleText(page.text);
    rec.add('P3b-screen', 'open the supplier statement screen', 'shows 120.00', text.includes('120.00') ? 'shows 120.00' : text.slice(0, 200), text.includes('120.00'));
}

// P4: receiving return (debit note) of 4 P at the average cost
{
    const steps = 'return mode: 4 P to supplier A (cost typed 11), no payment';
    await rv.open('return');
    await rv.supplier(F.supA);
    const o = await c.post('/receivings/setReturnOrigin', { recv_return_origin: p1Receiving });
    console.log('  origin', JSON.stringify(o.data));
    await rv.add('R2-P');
    const e = await rv.editLine(1, lineFields({ price: '11', qty: '-4' }));
    console.log('  return edit', alertOf(e.text));
    const before = snap(items);
    const done = await rv.complete({ payment_type: 'Due' });
    const after = snap(items);
    const err = alertOf(done.text);
    if (err) rec.add('P4-err', steps, 'saved', err, false);
    deltaCheck(rec, 'P4', steps, before, after, { [ACC.inventory]: -44, [payA]: 44 }, { [F.P]: { qty: -4, cost: 11 } });
}

// P5: percentage discount on the line, average over the remaining stock
{
    const steps = 'receive 10 Q @20 with a 10% line discount from supplier B, Due';
    const payB = acct('supplier', F.supB);
    const { before, err } = await receive('P5', steps, F.supB, [{ code: 'R2-Q', price: '20', qty: '10', discount: '10', dtype: 0 }], { payment_type: 'Due' });
    const after = snap(items);
    if (err) rec.add('P5-err', steps, 'saved', err, false);
    deltaCheck(rec, 'P5', steps, before, after, { [ACC.inventory]: 180, [payB]: -180 }, { [F.Q]: { qty: 10, cost: Math.round(((5 * 20 + 180) / 15) * 1e6) / 1e6 } });
}

// P6: fixed discount per unit
{
    const steps = 'receive 10 P @12 with a fixed discount of 2 per unit from supplier A, Due';
    const { before, err } = await receive('P6', steps, F.supA, [{ code: 'R2-P', price: '12', qty: '10', discount: '2', dtype: 1 }], { payment_type: 'Due' });
    const after = snap(items);
    if (err) rec.add('P6-err', steps, 'saved', err, false);
    // stock before 16 @ 11 = 176; add 10 @ 10 = 100; average = 276/26
    deltaCheck(rec, 'P6', steps, before, after, { [ACC.inventory]: 100, [payA]: -100 }, { [F.P]: { qty: 10, cost: Math.round((276 / 26) * 1e6) / 1e6 } });
}

// P7: purchase with a tax percent on the line
{
    const steps = 'receive 10 U @8 with tax 5% from supplier A, Due';
    const { before, err } = await receive('P7', steps, F.supA, [{ code: 'R2-U', price: '8', qty: '10', tax: '5' }], { payment_type: 'Due' });
    const after = snap(items);
    if (err) rec.add('P7-err', steps, 'saved', err, false);
    const inputVat = one("SELECT code FROM ospos_accounting_accounts WHERE system_key='tax_input'");
    deltaCheck(rec, 'P7', steps, before, after, { [ACC.inventory]: 80, [inputVat]: 4, [payA]: -84 }, { [F.U]: { qty: 10, cost: 8 } });
}

rec.save();
const bad = readiness();
console.log('READINESS non-pass:', JSON.stringify(bad));
