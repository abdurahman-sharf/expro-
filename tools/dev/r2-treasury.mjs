// Phase A (d): treasuries, transfers, cheques, vouchers, expenses, cash-up and bank statement through the real screens.
import { admin, Recorder, snap, deltaCheck, fixture, acct, ACC, sysCode, sql, one, num, alertOf, ringUp, readiness, visibleText } from './r2-lib.mjs';

const rec = new Recorder(process.env.R2_OUT ?? '/tmp/r2-treasury.json');
const F = fixture();
const c = await admin();
const items = [F.P, F.Q, F.T, F.U];
const r4 = (n) => Math.round(n * 1e4) / 1e4;
const moved = (b, a) => {
    const m = {};
    for (const code of new Set([...Object.keys(b.bal), ...Object.keys(a.bal)])) m[code] = r4((a.bal[code] ?? 0) - (b.bal[code] ?? 0));
    return m;
};
const ok = (r) => r.data?.success === true;
const show = (r) => JSON.stringify(r.data ?? r.text.slice(0, 160));

// T1: treasuries
const tid = (name) => num(`SELECT treasury_id FROM ospos_treasuries WHERE name='${name}'`);
if (!tid('Counter cash')) {
    const a = await c.submitForm('/accounting_treasuries/view', { name: 'Counter cash', kind: 'cash', description: '' });
    const b = await c.submitForm('/accounting_treasuries/view', { name: 'Bank Alpha', kind: 'bank', bank_code: '', bank_name: 'Alpha Bank', account_number: 'SA0380000000608010167519', description: '' });
    rec.add('T1', 'create a cash treasury and a bank account', 'both saved', show(a) + ' ' + show(b), ok(a) && ok(b));
}
const CASH2 = tid('Counter cash');
const BANK = tid('Bank Alpha');
const cashAcct = acct('treasury', 1);
const cash2Acct = acct('treasury', CASH2);
const bankAcct = acct('treasury', BANK);

// T2: card sale into the bank account
{
    const before = snap(items);
    const out = await ringUp(c, { lines: [{ code: 'R2-P', qty: '2' }], payments: [['Credit Card', '50', 'CC1234', BANK]] });
    const after = snap(items);
    rec.add('T2-reached', 'sale of 2 P paid by card into Bank Alpha', 'saved', `${out.saleId} ${out.errors.join('|')}`, !!out.saleId && out.errors.length === 0);
    const m = moved(before, after);
    rec.eq('T2-bank', 'bank account rises by the card payment', 50, m[bankAcct]);
    rec.eq('T2-cash', 'the main cash account does not move', 0, m[cashAcct] ?? 0);
}

// T3: treasury transfer and its cancellation
{
    const before = snap(items);
    const r = await c.submitForm('/accounting_treasuries/transfer_form', { from_treasury_id: 1, to_treasury_id: BANK, amount: '100', to_amount: '', note: 'R2 deposit' });
    const after = snap(items);
    rec.add('T3-http', 'transfer 100 from the main cash to Bank Alpha', 'saved', show(r), ok(r));
    deltaCheck(rec, 'T3', 'transfer moves 100 from cash to bank', before, after, { [cashAcct]: -100, [bankAcct]: 100 });
    const tr = num('SELECT MAX(transfer_id) FROM ospos_treasury_transfers');
    const b2 = snap(items);
    const can = await c.post(`/accounting_treasuries/cancel_transfer/${tr}`, { reason: 'R2 test' });
    const a2 = snap(items);
    rec.add('T3c-http', 'cancel the transfer', 'cancelled', show(can), ok(can));
    deltaCheck(rec, 'T3c', 'cancelling puts both accounts back', b2, a2, { [cashAcct]: 100, [bankAcct]: -100 });
}

// T4: expense (cash) and expense on credit
{
    const before = snap(items);
    const r = await c.submitForm('/expenses/view', { amount: '100.00', tax_amount: '0.00', payment_type: 'Cash', expense_category_id: 1, description: 'R2 rent' });
    const after = snap(items);
    rec.add('T4-http', 'cash expense 100 under the rent category', 'saved', show(r), ok(r));
    const m = moved(before, after);
    rec.eq('T4-cash', 'cash falls by 100', -100, m[cashAcct]);
    rec.eq('T4-expense', 'expense accounts rise by 100 in total', 100, Object.entries(m).filter(([k]) => /^5/.test(k)).reduce((s, [, v]) => s + v, 0));
}

// T5: revenue voucher
{
    const before = snap(items);
    const r = await c.submitForm('/receipt_vouchers/view', { amount: '200.00', tax_amount: '0.00', payment_type: 'Cash', treasury_id: 0, category_id: 0, account_id: 0, description: 'R2 other income' }, { action: '/receipt_vouchers/save' });
    const after = snap(items);
    rec.add('T5-http', 'receipt voucher 200 cash, automatic revenue account', 'saved', show(r), ok(r));
    deltaCheck(rec, 'T5', 'voucher: cash up 200, other revenue up 200', before, after, { [cashAcct]: 200, [sysCode('revenues_default')]: -200 });
}

// T6: cheque received from a customer through its statuses
{
    const form = '/accounting_cheques/form/received';
    const b0 = snap(items);
    const r = await c.submitForm(form, { party_id: F.cust1, treasury_id: BANK, cheque_number: 'CHQ-R2-1', amount: '300', issue_date: '2026-11-14', due_date: '2026-11-14', drawer_name: 'R2Cust One', description: 'R2 cheque' }, { action: '/accounting_cheques/save/received' });
    rec.add('T6-save', 'receive a cheque of 300 from customer 1', 'saved', show(r), ok(r));
    const chequeId = num("SELECT MAX(cheque_id) FROM ospos_cheques");
    const a0 = snap(items);
    const notes = sysCode('notes_receivable');
    const cu = acct('customer', F.cust1);
    const mv0 = moved(b0, a0);
    rec.add('T6-received', 'receiving the cheque: customer credited 300, notes receivable debited 300', `${cu}:-300 ${notes}:300`, `${cu}:${mv0[cu]} ${notes}:${mv0[notes]}`, mv0[cu] === -300 && mv0[notes] === 300);
    const dep = await c.submitForm(`/accounting_cheques/action_form/deposit/${chequeId}`, { date: '2026-11-14' }, { action: `/accounting_cheques/act/deposit/${chequeId}` });
    const a1 = snap(items);
    rec.add('T6-deposit', 'deposit the cheque', 'done', show(dep), ok(dep));
    const mv1 = moved(a0, a1);
    rec.add('T6-deposit-books', 'deposit moves 300 from notes receivable to under collection', 'one account -300, another +300', JSON.stringify(Object.fromEntries(Object.entries(mv1).filter(([, v]) => v !== 0))), Object.values(mv1).filter((v) => v !== 0).sort().join() === '-300,300');
    const col = await c.submitForm(`/accounting_cheques/action_form/collect/${chequeId}`, { date: '2026-11-14', fees: '5' }, { action: `/accounting_cheques/act/collect/${chequeId}` });
    const a2 = snap(items);
    rec.add('T6-collect', 'collect the cheque with 5 bank fees', 'done', show(col), ok(col));
    const mv2 = moved(a1, a2);
    rec.add('T6-collect-books', 'collect: bank +295 (300 less fees 5), fees expense +5, under collection -300', 'bank +295', `bank ${mv2[bankAcct]}; ${JSON.stringify(Object.fromEntries(Object.entries(mv2).filter(([, v]) => v !== 0)))}`, mv2[bankAcct] === 295);
}

// T7: a rejected cheque goes back to the customer
{
    const b0 = snap(items);
    const r = await c.submitForm('/accounting_cheques/form/received', { party_id: F.cust2, treasury_id: BANK, cheque_number: 'CHQ-R2-2', amount: '150', issue_date: '2026-11-14', due_date: '2026-11-14', drawer_name: 'R2Cust Two' }, { action: '/accounting_cheques/save/received' });
    const id = num('SELECT MAX(cheque_id) FROM ospos_cheques');
    await c.submitForm(`/accounting_cheques/action_form/deposit/${id}`, { date: '2026-11-14' }, { action: `/accounting_cheques/act/deposit/${id}` });
    const rj = await c.submitForm(`/accounting_cheques/action_form/reject/${id}`, { date: '2026-11-14' }, { action: `/accounting_cheques/act/reject/${id}` });
    const a0 = snap(items);
    rec.add('T7-reject', 'receive, deposit and reject a cheque of 150 from customer 2', 'rejected', show(rj), ok(rj));
    const cu = acct('customer', F.cust2);
    const m = moved(b0, a0);
    rec.eq('T7-customer', 'after the rejection the customer owes what he owed before (net 0 movement)', 0, m[cu]);
    rec.eq('T7-bank', 'the bank does not move', 0, m[bankAcct] ?? 0);
}

// T8: a cheque paid to a supplier: book, issue, deliver
{
    const book = await c.submitForm('/accounting_cheques/book_form', { treasury_id: BANK, name: 'R2 book', first_serial: '1000', last_serial: '1020' }, { action: '/accounting_cheques/save_book' });
    rec.add('T8-book', 'open a cheque book 1000-1020 on Bank Alpha', 'saved', show(book), ok(book));
    const bookId = num('SELECT MAX(book_id) FROM ospos_cheque_books');
    const b0 = snap(items);
    const r = await c.submitForm('/accounting_cheques/form/paid', { party_id: F.supA, book_id: bookId, cheque_number: '1000', amount: '120', issue_date: '2026-11-14', due_date: '2026-11-14', drawer_name: '', deliver: '' }, { action: '/accounting_cheques/save/paid' });
    rec.add('T8-issue', 'issue paid cheque 1000 of 120 to supplier A', 'saved', show(r), ok(r));
    const id = num('SELECT MAX(cheque_id) FROM ospos_cheques');
    const del = await c.submitForm(`/accounting_cheques/action_form/deliver/${id}`, { date: '2026-11-14' }, { action: `/accounting_cheques/act/deliver/${id}` });
    rec.add('T8-deliver', 'deliver it', 'done', show(del), ok(del));
    const col = await c.submitForm(`/accounting_cheques/action_form/collect/${id}`, { date: '2026-11-14', fees: '' }, { action: `/accounting_cheques/act/collect/${id}` });
    rec.add('T8-clear', 'the bank clears it', 'done', show(col), ok(col));
    const a0 = snap(items);
    const m = moved(b0, a0);
    const sup = acct('supplier', F.supA);
    rec.eq('T8-supplier', 'supplier A owed less by 120', 120, m[sup]);
    rec.eq('T8-bank', 'bank down by 120', -120, m[bankAcct]);
}

// T9: cash-up
{
    const r = await c.submitForm('/cashups/view', { open_amount_cash: '100.00', transfer_amount_cash: '0.00', closed_amount_cash: '250.00', closed_amount_due: '0.00', closed_amount_card: '50.00', closed_amount_check: '0.00', closed_amount_total: '300.00', description: 'R2 cash-up' });
    rec.add('T9', 'save a cash-up (opening 100, closing 250 cash + 50 card = 300)', 'saved', show(r), ok(r));
    const row = sql('SELECT cashup_id, open_amount_cash, closed_amount_cash, closed_amount_total FROM ospos_cash_up ORDER BY cashup_id DESC LIMIT 1');
    rec.add('T9-row', 'the stored cash-up carries the figures typed', '100/250/200 (the total is closing cash less opening cash plus card)', JSON.stringify(row[0]), row[0]?.[1] === '100.00' && row[0]?.[2] === '250.00' && row[0]?.[3] === '200.00');
}

// T10: bank statement import
{
    const csv = 'Date,Description,Deposit,Withdrawal,Reference\n2026-11-14,Transfer from customer,300.00,,TRX-1001\n2026-11-14,Bank fee,,5.00,FEE-1\n2026-11-14,Deposit 100,100.00,,DEP-1\n';
    const r = await c.submitForm(`/accounting_treasuries/bank/${BANK}/import`, { statement_text: csv, delimiter: 'comma', date_order: 'ymd', skip_first_row: '1', date_column: '1', description_column: '2', deposit_column: '3', withdrawal_column: '4', reference_column: '5' });
    rec.add('T10-import', 'import a 3-line statement', 'imported', show(r) + ' ' + (r.data ? '' : visibleText(r.text).slice(0, 200)), ok(r) || r.status === 302);
    const lines = sql('SELECT COUNT(*) FROM ospos_bank_statement_lines');
    rec.add('T10-lines', 'three lines stored', '3', JSON.stringify(lines), lines[0]?.[0] === '3');
}

rec.save();
console.log('READINESS non-pass:', JSON.stringify(readiness().filter((x) => !['sensitive_grants', 'clock_zones', 'activation', 'return_document'].includes(x.id))));
