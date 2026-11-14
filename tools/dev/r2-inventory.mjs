// Phase A (c): inventory documents through the real screens, checked against hand arithmetic.
import { admin, Recorder, snap, deltaCheck, fixture, acct, ACC, sysCode, sql, one, num, alertOf, ringUp, readiness, visibleText, Receiving } from './r2-lib.mjs';
import { formDefaults } from './r2-client.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-inventory.json');
const F = fixture();
const c = await admin();
const items = [F.P, F.Q, F.T, F.U];
const r4 = (n) => Math.round(n * 1e4) / 1e4;
const cost = (b, id) => b.items[id].cost;
const qtyAt = (id, loc) => num(`SELECT COALESCE(SUM(quantity),0) FROM ospos_item_quantities WHERE item_id=${id} AND location_id=${loc}`);
const moved = (b, a) => {
    const m = {};
    for (const code of new Set([...Object.keys(b.bal), ...Object.keys(a.bal)])) m[code] = r4((a.bal[code] ?? 0) - (b.bal[code] ?? 0));
    return m;
};
const locId = (name) => num(`SELECT location_id FROM ospos_stock_locations WHERE location_name='${name}' AND deleted=0`);

// locations: Warehouse in the head office, JED store in the Jeddah branch
if (!locId('Warehouse')) await c.submitFormByAction('/config', 'saveLocations', { 'stock_location[]': ['Warehouse'] });
if (!locId('JED store')) await c.submitFormByAction('/config', 'saveLocations', { 'stock_location[]': ['JED store'] });
const WH = locId('Warehouse');
const JED = locId('JED store');
{
    const form = await c.get('/branches/view/2', { ajax: false });
    const parsed = formDefaults(form.text, 0);
    const body = {};
    for (const [n, v] of parsed.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (body[n] ??= []).push(v); else body[n] = v; }
    body['locations[]'] = [String(JED)];
    const r = await c.post('/branches/save/2', body);
    rec.add('I0', 'create Warehouse and JED store, assign JED store to the Jeddah branch', 'saved', JSON.stringify(r.data), r.data?.success === true && one(`SELECT branch_id FROM ospos_stock_locations WHERE location_id=${JED}`) === '2');
}
const whAcct = acct('stock_location', WH);
const jedAcct = acct('stock_location', JED);

const requisition = async (from, to, lines) => {
    await c.get('/receivings', { ajax: false });
    await c.post('/receivings/cancelReceiving', {});
    await c.post('/receivings/changeMode', { mode: 'requisition' });
    const m = await c.post('/receivings/changeMode', { mode: 'requisition', stock_source: from, stock_destination: to });
    const out = { errors: [] };
    if (alertOf(m.text)) out.errors.push(alertOf(m.text));
    for (const l of lines) {
        const a = await c.post('/receivings/add', { item: l.code });
        if (alertOf(a.text)) out.errors.push(alertOf(a.text));
        const e = await c.post('/receivings/editItem/1', { price: '0', quantity: l.qty, discount: '0', tax_percent: '0', receiving_quantity: '1', description: '', serialnumber: '' });
        if (alertOf(e.text)) out.errors.push(alertOf(e.text));
    }
    const done = await c.post('/receivings/requisitionComplete', {});
    if (alertOf(done.text)) out.errors.push(alertOf(done.text));
    return out;
};

// I1: transfer between two locations of one branch, 5 P
{
    const before = snap(items);
    const q0 = [qtyAt(F.P, 1), qtyAt(F.P, WH)];
    const out = await requisition(1, WH, [{ code: 'R2-P', qty: '5' }]);
    const after = snap(items);
    const q1 = [qtyAt(F.P, 1), qtyAt(F.P, WH)];
    rec.add('I1-http', 'requisition 5 P from stock to Warehouse', 'no refusal', out.errors.join(' | ') || 'ok', out.errors.length === 0);
    rec.eq('I1-qty-source', 'stock of P at the source falls by 5', q0[0] - 5, q1[0]);
    rec.eq('I1-qty-dest', 'stock of P at the destination rises by 5', q0[1] + 5, q1[1]);
    const cg = r4(5 * cost(before, F.P));
    deltaCheck(rec, 'I1', 'transfer inside a branch moves value between the two location accounts at the average cost', before, after, { [ACC.inventory]: -cg, [whAcct]: cg }, { [F.P]: { qty: 0 } });
}

// I2: transfer between branches, 4 Q to the Jeddah store
{
    const before = snap(items);
    const q0 = [qtyAt(F.Q, 1), qtyAt(F.Q, JED)];
    const out = await requisition(1, JED, [{ code: 'R2-Q', qty: '4' }]);
    const after = snap(items);
    const q1 = [qtyAt(F.Q, 1), qtyAt(F.Q, JED)];
    rec.add('I2-http', 'requisition 4 Q from stock (HQ) to JED store (Jeddah)', 'no refusal', out.errors.join(' | ') || 'ok', out.errors.length === 0);
    rec.eq('I2-qty-source', 'Q at source', q0[0] - 4, q1[0]);
    rec.eq('I2-qty-dest', 'Q at destination', q0[1] + 4, q1[1]);
    const cg = r4(4 * cost(before, F.Q));
    const m = moved(before, after);
    const ib = Object.entries(m).filter(([k, v]) => v !== 0 && ![ACC.inventory, jedAcct].includes(k)).map(([k, v]) => `${k}:${v}`);
    rec.add('I2-books', 'between branches: inventory out of HQ, inventory into Jeddah, inter-branch accounts offset', `inventory ${-cg} / ${jedAcct} ${cg} / inter-branch legs sum to 0`, `inventory ${m[ACC.inventory]} / ${jedAcct} ${m[jedAcct]} / others ${ib.join(' ')}`, m[ACC.inventory] === -cg && m[jedAcct] === cg && Math.abs(Object.entries(m).filter(([k]) => ![ACC.inventory, jedAcct].includes(k)).reduce((s, [, v]) => s + v, 0)) < 0.01);
}

// I3: stock add permit
{
    const before = snap(items);
    const r = await c.submitForm('/stock_documents/permits/form/add', { location_id: 1, 'lines[0][item_id]': F.Q, 'lines[0][quantity]': '10', 'lines[0][unit_cost]': '25', note: 'R2 permit in' });
    const after = snap(items);
    rec.add('I3-http', 'add permit: 10 Q @25 against the automatic account (other payables)', 'saved', JSON.stringify(r.data), r.data?.success === true);
    // stock before this permit: qty q, avg a; new avg = (q*a + 250)/(q+10)
    const q = before.items[F.Q].qty;
    const a = before.items[F.Q].cost;
    const newCost = r4((q * a + 250) / (q + 10));
    deltaCheck(rec, 'I3', 'stock add permit moves inventory up and the chosen account down, and re-averages the cost', before, after, { [ACC.inventory]: 250, [sysCode('other_payables')]: -250 }, { [F.Q]: { qty: 10, cost: newCost } });
}

// I4: stock issue permit
{
    const before = snap(items);
    const r = await c.submitForm('/stock_documents/permits/form/issue', { location_id: 1, 'lines[0][item_id]': F.P, 'lines[0][quantity]': '3', 'lines[0][unit_cost]': '', note: 'R2 permit out' });
    const after = snap(items);
    rec.add('I4-http', 'issue permit: 3 P', 'saved', JSON.stringify(r.data), r.data?.success === true);
    const cg = r4(3 * cost(before, F.P));
    const m = moved(before, after);
    rec.eq('I4-inventory', 'inventory falls by 3 x average cost', -cg, m[ACC.inventory]);
    rec.eq('I4-qty', 'P falls by 3', before.items[F.P].qty - 3, after.items[F.P].qty);
    rec.eq('I4-offset', 'the other side of the journal equals the inventory fall', cg, Object.entries(m).filter(([k]) => k !== ACC.inventory).reduce((s, [, v]) => s + v, 0));
}

// I5: stocktake with a shortage of 2 P and a surplus of 1 Q at the main location
{
    const before = snap(items);
    const create = await c.submitForm('/stock_documents/stocktakes/form', { location_id: 1, all_items: '1', note: 'R2 count' });
    rec.add('I5-create', 'start a stocktake of the main location', 'saved', JSON.stringify(create.data), create.data?.success === true);
    const id = create.data?.id;
    const sheet = await c.get(`/stock_documents/stocktakes/view/${id}`, { ajax: false });
    const sysP = qtyAt(F.P, 1);
    const sysQ = qtyAt(F.Q, 1);
    const counts = {};
    for (const m of sheet.text.matchAll(/name="counted\[(\d+)\]"/g)) counts[`counted[${m[1]}]`] = String(qtyAt(Number(m[1]), 1));
    counts[`counted[${F.P}]`] = String(sysP - 2);
    counts[`counted[${F.Q}]`] = String(sysQ + 1);
    const cnt = await c.post(`/stock_documents/stocktakes/count/${id}`, counts);
    rec.add('I5-count', 'enter counts', 'saved', JSON.stringify(cnt.data), cnt.data?.success === true);
    const view = await c.get(`/stock_documents/stocktakes/view/${id}`, { ajax: false });
    const digest = view.text.match(/digest: "([0-9a-f]+)"/)?.[1] ?? '';
    const ap = await c.post(`/stock_documents/stocktakes/approve/${id}`, { digest });
    rec.add('I5-approve', 'approve the stocktake', 'approved', JSON.stringify(ap.data), ap.data?.success === true);
    const after = snap(items);
    rec.eq('I5-P', 'P at the main location equals the count', sysP - 2, qtyAt(F.P, 1));
    rec.eq('I5-Q', 'Q at the main location equals the count', sysQ + 1, qtyAt(F.Q, 1));
    const net = r4(-2 * cost(before, F.P) + cost(before, F.Q));
    const m = moved(before, after);
    rec.eq('I5-inventory', 'inventory value moves by -2 P + 1 Q at their costs', net, m[ACC.inventory], 0.02);
}

// I6: negative stock refused when the setting says so
{
    const settings = await c.submitFormByAction('/stock_units', 'settings', { negative_stock: 'refuse' });
    rec.add('I6-set', 'set negative stock to refuse', 'saved', JSON.stringify(settings.data ?? settings.text.slice(0, 120)), settings.data?.success === true || settings.status === 200);
    const have = qtyAt(F.U, 1);
    const out = await ringUp(c, { lines: [{ code: 'R2-U', qty: String(have + 5) }], payments: [['Cash', String(Math.round((have + 5) * 21 * 100) / 100)]] });
    rec.add('I6-sale', `sell ${have + 5} U with ${have} in stock`, 'refused in words', out.errors.join(' | ') || (out.saleId ? `SAVED ${out.saleId}` : 'nothing'), out.saleId === null && out.errors.length > 0);
    const p = await c.submitForm('/stock_documents/permits/form/issue', { location_id: 1, 'lines[0][item_id]': F.U, 'lines[0][quantity]': String(have + 5), 'lines[0][unit_cost]': '' });
    rec.add('I6-permit', 'issue permit above the stock', 'refused in words', JSON.stringify(p.data), p.data?.success === false);
    const t = await requisition(1, WH, [{ code: 'R2-U', qty: String(have + 5) }]);
    rec.add('I6-transfer', 'transfer above the stock', 'refused in words', t.errors.join(' | ') || 'not refused', t.errors.length > 0);
    await c.submitFormByAction('/stock_units', 'settings', { negative_stock: 'allow' });
}

// I7: valuation report against the stock accounts
{
    const page = await c.get('/accounting_reports/inventory_valuation', { ajax: false });
    const text = visibleText(page.text);
    const inv = num(`SELECT COALESCE(SUM(l.debit-l.credit),0) FROM ospos_accounting_journal_lines l JOIN ospos_accounting_journals j USING(journal_id) JOIN ospos_accounting_accounts a USING(account_id) WHERE j.status IN ('posted','reversed') AND a.code LIKE '1203%' AND a.is_group=0`);
    const shown = Number((text.match(/Total[^0-9-]{0,40}([-0-9,.]+)/)?.[1] ?? 'NaN').replace(/,/g, ''));
    rec.add('I7', 'valuation report total against the stock accounts (1203*)', 'equal', `report ${shown} vs accounts ${r4(inv)}`, Math.abs(inv - shown) < 0.01);
    const byCost = num(`SELECT SUM(q.quantity*i.cost_price) FROM ospos_item_quantities q JOIN ospos_items i USING(item_id) WHERE i.deleted=0`);
    rec.note('I7-itemcost', `quantity x cost_price of the item card = ${r4(byCost)} against the stock accounts ${r4(inv)}: the stocktake values a surplus at the average of the location while the card keeps the average of the item`);
}

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id))));
