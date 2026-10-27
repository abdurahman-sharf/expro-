# D1 PLAN (sales documents, tag «افتراضي-D1»), written before the first write

Account: opes.daftra.com only. Authority: E47. Batch file: daftra-batch-D1.md. Tools: /home/user/daftra-tools/d1-*.mjs (guard = d1-lib.mjs `open()`, every non-GET request is logged to `_nonget-requests.jsonl`, every write gets an entry in `write-log.json`).
Restart safety: PLAN.md first, write-log.json after every write, NOTES.md / facts.json / scenarios.json updated after every scenario.

## 0. Read before the plan (observed in this session, GET only)
- Plan page /owner/owners/account_info (r01-account-info.txt): «الفواتير وعروض الاسعار 35 من 50» (so 15 left for all batches), «العملاء 4 من 15», «الخزن النقدية و البنوك 2 من ∞», «المستودعات 1 من 2», plan Free, ends 04/11/2026. 35 = 27 invoices + 6 credit notes + 1 refund + 1 estimate, so credit notes, refunds and estimates DO count (inference from the lists: 27, 6, 1, 1).
- Only ONE tax exists: «القيمة المضافة» 15 %, «غير متضمن» (st-taxes.json). The invoice form has no per-line or per-document price-includes-tax option: inclusion is the flag `data[Tax][n][included]` of the tax record (global). => question 2 (inclusive tax) is **needs C** (creating/editing a tax is a global record) and is skipped. Different tax RATES are impossible without batch C: the mix is 15 % lines and a non-taxed line.
- Invoice view = /owner/invoices/view/{id}; credit note from an invoice = /owner/invoices/add_creditnote/{invoice id}; credit note view = /owner/invoices/view_creditnote/{id}; journals are linked on the document page («قيد 000127#» -> /owner/journals/view/127); stored values are read from /owner/journals/edit/{id} (d1-journal.mjs reads inputs, no save).
- The orphan credit note (view_creditnote/3, number 000001, 253.00, client #000003) was read again (r07-cn3.json): journals #000012 (Dr 412 مردودات المبيعات 220, Dr 215001 33, Cr customer 12041003 253) and #000013 (Dr 1203001 Primary Warehouse 120, Cr 511 120). It answers F-10 (accepted without invoice; stock returns at 120 = the item's cost; applied; no cash-refund control on the page). A new orphan credit note is created only as the credit source of F-12 (below).
- Expro reference: WP16 branch (/home/user/wt/WP16 app/Libraries/InvoiceDiscount.php + Accounting/InvoiceRounding.php, run read-only by php/expro_expect.php).

## 1. Quota (hard cap 7 invoice-family documents; 1 client; 1 stock item; 0 services)
| # | doc | purpose |
|---|---|---|
| 1 | Invoice B (number 000028, date 25/10) | percent document discount 3.3 % on base 355.55, payment term 1 day (past due) |
| 2 | Invoice A (000029, date 20/10 = older date, higher number) | fixed document discount 20 over mixed lines after a line discount; no payment term |
| 3 | Invoice C (000030, date 27/10, undelivered, term 7 days) | F-11, due-date (future) |
| 4 | Credit note on A, one line, 1 of 3 units | proportional return of the discount, tax, revenue and COGS |
| 5 | Credit note on C (undelivered) | F-11 (may be refused: then nothing is created) |
| 6 | F-13 attempt: credit note on A with quantity above what A sold | refused = nothing created; accepted = document, and its excess tests F-12 |
| 7 | reserve: orphan credit note for the F-12 credit source, or one more invoice if the credit only applies to a NEW invoice | |
Expected use 6 to 7; never above 7. Stop and report if the page shows more than 35 + 7 = 42 used.
Records besides documents: client «افتراضي-D1 عميل» (client quota 4 -> 5), stock item «افتراضي-D1 سلعة» (type product, tracked, price 100, buy price 60, initial stock 20, VAT 15 %). Free-text lines (no product) are used for the non-stock lines so no service item is created.

## 2. Settings
None toggled. Settings read only. If a scenario needs a toggle it is marked "needs C" (inclusive tax: needs C).

## 3. Scenarios, inputs, Expro expectation (computed BEFORE looking at Daftra)
Client for all: «افتراضي-D1 عميل». Currency SAR. Method «طباعة»/save without sending (no e-mail, no print dialog, no gateway).

### S1 (WP16/WP34/WP25 question 1a) Invoice A, fixed invoice discount
Lines: L1 stock item ×3 @100.00, line discount 10 % (-> 270.00), VAT 15 %; L2 free text «افتراضي-D1 بند 2» ×2 @33.33 (66.66), VAT 15 %; L3 free text «افتراضي-D1 بند 3» ×1 @55.55 (55.55), no tax. Invoice discount: fixed amount 20 (tab الخصم والتسوية). Date 20/10/2026, no payment term.
Expro (WP16): discount 20.00 exactly; shares rounded to 2 decimals, remainder on the last line: 13.77 / 3.40 / 2.83; tax per line on the discounted line: 38.4345 + 9.4890 + 0 = 47.9235 (4 decimals, not rounded); net 372.21; total 420.13; revenue = total - tax = 372.2065; journal: Dr customer 420.13, Cr 411 372.2065, Cr 215001 47.9235, no discount line; plus a COGS journal (Dr 511 / Cr stock) for the 3 units of the stock item at the item's cost.
Daftra hypothesis from the page script (to be tested, not assumed): unrounded shares 13.7682 / 3.3995 / 2.8325; tax 38.43477 + 9.48910 = 47.92387; total 420.13; revenue 372.20613.
Reads: invoice view/preview (lines, totals), journal(s) raw, payments tab, customer balance, plan page.

### S2 (WP16 question 1b) Credit note on A, 1 of 3 units of L1
Expro (WP16 forReturn): the share of the unit = round(13.77 x 1/3, 2) = 4.59; net 90 - 4.59 = 85.41; tax 12.8115; total 98.22; returns journal Dr 412 85.4115-ish, Dr 215001 12.8115, Cr customer 98.22; COGS return at the original sale cost; credit applied to A as a «رصيد العميل» payment; A partially paid.
Daftra: read how the invoice discount comes back (proportional or none), tax, 412 line, COGS return line, A's status.

### S3 (WP16 question 1c) Invoice B, percent invoice discount 3.3 % on 355.55
Lines: free text ×1 @200.00 VAT 15 %, free text ×1 @155.55 VAT 15 %. Discount percent 3.3. Date 25/10/2026, payment term (شروط الدفع) 1 day.
Expro: discount amount round(355.55 x 0.033 = 11.73315) = 11.73; shares 6.60 / 5.13; tax 29.01 + 22.563 = 51.573; total 395.39; revenue 343.817.
Daftra hypothesis: discount 11.73315 unrounded; tax 51.5725275; total 395.39; revenue 343.8174725.

### S4 (WP13, question 5) Due date / overdue
B: date 25/10 + 1 day = due 26/10 (past); A: no term; C: date 27/10 + 7 days = due 03/11 (future). Read the list tabs «متأخر / مستحقة الدفع», the due date shown on each document, the aging reports. Expro expectation (WP13 not built): no due date = due on the invoice date; overdue when due < today.

### S5 (F-11, WP23) Credit note on an undelivered invoice
Invoice C: stock item ×2 @100.00 VAT 15 % (230.00), tab التسليم «تأكيد التسليم» UNCHECKED (the per-document option, no global setting), date 27/10, term 7 days. Then credit note on C for 1 unit.
Expro (E23): refused until delivery is confirmed (a credit note on an undelivered invoice is not accepted; no stock or cost journal exists to reverse).
Daftra: read whether it is accepted, the journals (is there a COGS return line when no COGS was posted), stock.

### S6 (F-13, WP23) Return above what was sold
Credit note on A with L1 quantity 5 (A sold 3, 1 already returned).
Expro: refused by ReturnLimit (a return gives back at most what the sale brought minus earlier returns).
Daftra: record the message verbatim and whether anything is saved (if saved: document is permanent, quota counted; stop pressing save).

### S7 (F-12 / F-10, OO-RSC-13) Order in which customer credit is applied
Setup after S1-S6: open invoices of the client: B (date 25/10, number 000028), A (date 20/10, number 000029; date order is the OPPOSITE of number order), C (27/10). A credit larger than A's open balance and smaller than A + B arrives: first source = S6 if accepted (its excess), else an orphan credit note for the client (stock item ×5 @100 = 575.00) = F-10 too.
Expro: credit goes first to its origin invoice, then to the open invoices ordered by [date, id] (CreditSettlement::apply), immediately; excess stays as a credit balance.
Date order => A closed first then B; number order => B first. Read the payments tab of A, B and C. If the credit does not apply to the open invoices by itself, the reserve doc (an invoice) tests application at creation.

### Q2 inclusive tax: needs C (see section 0). Q6 read-through: only if time remains: nothing written.

## 4. Stop conditions
- A page shows a document I did not intend; the plan page shows more than 42 used; any request to a host other than opes.daftra.com; any BLOCKED-DENY request in the guard log (send/ZATCA/gateway) during a save: stop the scenario and log.
- Whatever fails before saving is not retried blindly: read the lists and the plan page first.
- Never edit or delete anything that is not tagged «افتراضي-D1» (the F/S35 records, #000001..#000027, credit notes 000001..000006, the refund, the estimate and the owner's records are only read).

## 5. Reversibility
Issued invoices and credit notes cannot be deleted (S-10: «يمنع حذف أو تعديل الفاتورة بعد إصدارها»), so every document in the table is permanent and consumes quota. The client and the item are kept (their deletion is not attempted: the client would be blocked by documents anyway). No setting is touched, so nothing needs reverting.

## 6. Execution status (written at the end of the run)
- Executed: S1 (invoice A), S2 (credit note on A), S3 (invoice B), S4 (due/overdue, via two edits of the payment term of B), S5 (invoice C undelivered, credit note on it, delivery confirmation afterwards), S6 (F-13: two refused attempts), S7 (orphan credit note = F-10 and the F-12 order test), plus read-only reports.
- Not executed: question 2 (inclusive tax) = needs C; the F-12 tie-break between two invoices of the same date needs 3 more documents (over quota).
- Deviations from the plan: (1) CN2 on the undelivered invoice was accepted, so S6's credit-source role was taken by the orphan credit note, and the F-13 attempts (5 then 3 units) were both refused; (2) B's payment term was edited twice (1 -> 2 -> 30) to find the overdue boundary and to separate date order from due-date order; (3) delivery of C was confirmed at the end to see the order credit note then delivery; (4) the first guard run blocked static GETs of cdn.daftra.com for a few minutes (client page only); (5) the container restarted once, the login state was rebuilt, the state was re-checked with GETs before continuing; no write was repeated.
- Quota used 6 of 7; plan counter 35 -> 41.
