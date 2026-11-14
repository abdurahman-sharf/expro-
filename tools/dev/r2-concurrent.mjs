// Phase B: two browser contexts editing the same record, and two cashiers on one stock figure.
import { admin, Recorder, one, num, sql, fixture } from './r2-lib.mjs';
import { formDefaults } from './r2-client.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-concurrent.json');
const F = fixture();
const a = await admin();
const b = await admin('admin', 'pointofsale', true);
const body = (html) => { const f = formDefaults(html, 0); const o = {}; for (const [n, v] of f.fields) { if (n === 'csrf_ospos_v4' || n === 'honeypot') continue; if (n.endsWith('[]')) (o[n] ??= []).push(v); else o[n] = v; } return { action: f.action.replace('http://127.0.0.1:8082', ''), body: o }; };

// the same item opened in two windows
{
    const formA = body((await a.get(`/items/view/${F.Q}`, { ajax: true })).text);
    const formB = body((await b.get(`/items/view/${F.Q}`, { ajax: true })).text);
    formB.body.unit_price = '77';
    const rb = await b.post(formB.action, formB.body);
    formA.body.description = 'edited in window A';
    const ra = await a.post(formA.action, formA.body);
    const price = one(`SELECT unit_price FROM ospos_items WHERE item_id=${F.Q}`);
    rec.add('CC1', 'two windows open the same item; B saves a new price 77, then A saves a new description with its older form', 'A is told the record changed, or B price kept', `price now ${price}; A answered ${JSON.stringify(ra.data)}`, price === '77.00' || ra.data?.success === false);
    await b.post(formB.action, { ...formB.body, unit_price: '50' });
}

// the same customer in two windows
{
    const formA = body((await a.get(`/customers/view/${F.cust3}`, { ajax: true })).text);
    const formB = body((await b.get(`/customers/view/${F.cust3}`, { ajax: true })).text);
    formB.body.phone_number = '0500000001';
    await b.post(formB.action, formB.body);
    formA.body.address_1 = 'window A street';
    const ra = await a.post(formA.action, formA.body);
    const phone = one(`SELECT phone_number FROM ospos_people WHERE person_id=${F.cust3}`);
    rec.add('CC2', 'two windows open the same customer; B saves a phone, A then saves an address with its older form', 'A is told the record changed, or B phone kept', `phone now ${phone}; A answered ${JSON.stringify(ra.data)}`, phone === '0500000001' || ra.data?.success === false);
}

// two windows paying the same invoice at once
{
    const form = await a.get(`/accounting_receivables/advance_form/${F.cust2}`, { ajax: true });
    const pa = body(form.text);
    const formb = await b.get(`/accounting_receivables/advance_form/${F.cust2}`, { ajax: true });
    const pb = body(formb.text);
    pa.body.amount = '10'; pa.body.payment_type = 'Cash';
    pb.body.amount = '10'; pb.body.payment_type = 'Cash';
    const before = num(`SELECT COUNT(*) FROM ospos_customer_advances WHERE customer_id=${F.cust2}`);
    const [x, y] = await Promise.all([a.post(pa.action, pa.body), b.post(pb.action, pb.body)]);
    const made = num(`SELECT COUNT(*) FROM ospos_customer_advances WHERE customer_id=${F.cust2}`) - before;
    rec.add('CC3', 'two windows record a customer advance of 10 at the same moment (different request keys)', 'two advances, books consistent', `${made} advance(s)`, made === 2);
}
rec.save();
