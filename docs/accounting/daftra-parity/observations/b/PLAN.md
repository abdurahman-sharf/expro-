# Batch B (cheques + multi-currency + bank-statement tab): PLAN, written before the first write

Tag: «افتراضي-B» (name and note of every record). Account: opes.daftra.com only (E47). The batch runs ALONE. Date/time: 27/10/2026, account clock ~19:20 (UTC+3). Guard: `/home/user/daftra-tools/blib.mjs` (copy of the D2 guard: records every non-GET request with field names to `out/b/_nonget.jsonl`, blocks non-opes hosts, blocks send/share/zatca/api-key/webhook/backup/restore/renew/upgrade/integration/gateway words, blocks GET of `local_entities/.../create`; narrow exception allowed only for restoring MY OWN deleted purchase doc if ever needed). Every write is appended to `out/b/write-log.json` by hand-called `wlog()`; state-changing GET links (activate/deactivate, confirm, settle, restore, mark_*) are logged by hand too.

## 0. Baseline read before any write (27/10/2026 19:15-19:17, `ev/b-*.txt`)
| item | value |
|---|---|
| plan usage | «الفواتير وعروض الاسعار» **41 / 50** (D1 used 6, D2 0), «العملاء» 5 / 15, «الخزن النقدية و البنوك» 2 / ∞, warehouses 1 / 2 |
| apps manager `/v2/owner/plugin-manager/2` | «دورة الشيكات» **تم التعطيل** (original). Enabled: المبيعات، الفاتورة الإلكترونية السعودية، إدارة المخزون، المالية، الحسابات العامه & القيود اليومية، العملاء، الموظفين. There is one «حفظ» button for all toggles (form posts to `/owner/owners/plugin_manager/1`) |
| treasuries `/v2/owner/banks/treasury` | Main Treasury 84,624.25; S35 بنك 40.00 (active); S35 خزينة 20.00 (inactive); D2 خزينة 1 and D2 بنك (both inactive, 0.00). List shows the amount with the symbol «元» (odd, noted) |
| currency rates `/owner/local_currency_rates/index` («أسعار العملات المحلية», linked from /settings as «إدارة أسعار الصرف والعملات») | empty table (columns الرقم التعريفي، من العملة، إلى العملة، السعر، التاريخ من، الإجراءات); button «أضف سعر للعملة» |
| base currency | SAR (`/settings`: «العملة الافتراضية SAR ريال سعودي»; text says the base currency cannot change after financial transactions; other currencies can be used on invoices and are converted to the base in the day's journals) |
| D1 client | «افتراضي-D1 عميل» id 5, balance 275.02; invoices #000028 (id 36, open 45.02), #000029 (id 37, settled), #000030 (id 38, unpaid 230.00) |

## 1. Quota and limits (batch file)
| thing | limit | planned |
|---|---|---|
| sales invoices + credit notes | **3** | 1 foreign-currency invoice (F1) + at most 1 more (decimals / second rate) ; the third is a reserve, no credit note unless a foreign-currency one is needed; stop at 3 |
| new clients | 0 (1 max) | 0 (use D1 client for the foreign invoice and cheques) |
| purchase documents | 6 | P1 cheque-paid (SAR), P2 USD purchase, P3 non-2-decimal purchase, up to 3 spare/edit tests |
| new suppliers | 2 | «مورد افتراضي-B 1» (cheques), «مورد افتراضي-B 2» (FX) |
| products | 2 | 0-2 (free-text lines preferred, a product only if the purchase form requires one) |
| treasuries/banks | as needed | «افتراضي-B بنك» (bank account for deposits), «افتراضي-B خزينة USD» (foreign-currency treasury), maybe one more SAR treasury; cannot be deleted once they have movements -> will be deactivated |

Stop conditions: unexpected record created -> stop that scenario and log; any screen that would send data out (e-mail/SMS/gateway/ZATCA/bank-feed/integration/rate feed) -> do not press; quota would exceed 3 -> stop; `/dev/shm/daftra.cred` missing -> stop and report.

## 2. Settings / apps to toggle (original value -> change -> revert)
| setting | original | change | revert rule |
|---|---|---|---|
| App «دورة الشيكات» (global) | تم التعطيل | enable, press «حفظ» | disable at the end and re-read `/v2/owner/plugin-manager/2` (module is global; cheque documents created in it will block nothing but may keep rows) |
| Foreign currency | no switch known: the currency select is hidden behind «تعديل العملة» / `manual_currency_rate_hidden`; `update_journal_entries_currency_rates` shows «اخفاء» on /owner/invoices/settings and «عرض» on /owner/settings/accounting_general (disagreement) | find the real switch in the UI first; rate rows are records (add/delete) | record each setting's original value before changing; revert and re-read |
| Rate rows in «أسعار العملات المحلية» | none | add USD->SAR (and a second rate for the different-rate payment test, plus a 0/3-decimal currency if needed) | delete the rows; re-read the table is empty |
| Nothing else | | | no external rate feed, no integration, no bank feed |

## 3. Scenarios (inputs, Expro expectation stated BEFORE looking at Daftra's result, reversibility)

Expro today (read from the repo: backlog WP26/WP28/WP29, `DefaultChart.php`): accounts 1206 «أوراق القبض» and 212 «أوراق الدفع» are chart rows only; there is no cheque document, no cheque report, no statuses; a payment typed `check` is routed like any payment (treasury/bank account selected, method is only a tag, Daftra D6 = same); journals carry `currency_code` and `exchange_rate` columns but everything is posted in SAR (rate 1); no foreign-currency documents, no FX-difference journal, no per-currency decimals beyond two (G-25); no bank statement import or reconciliation (E47-BANKREC).

### B-1 enable the cheques module and read every screen (WP26/G-64)
Enable the app; read the new menu entries, cheque list, add received/paid cheque forms (field names), statuses, bank/treasury links, the two reports via the UI path (direct addresses returned 500 before), the journal source filter (9 cheque sources), the payment-method list on invoice and purchase payment forms (before/after enabling). Expectation: Expro has none of these (not comparable). Reversible: toggle.

### B-2 received cheque against an existing open invoice of «افتراضي-D1 عميل» (S-27a)
Use invoice #000030 (id 38, unpaid 230.00) and #000028 (id 36, open 45.02) of the D1 client, no new invoice. Cheque A: amount 100 on #000030, due date today; read cheque record, journal at receipt, invoice status, client balance (statement/ledger), treasury/bank balance. Then every transition offered in the UI (deposit to the B bank, collect, bounce/reject, also from deposited: collect / reject); read each journal (code, name, debit, credit, number, date, description, source) and the same five balances after each; test whether a transition can be undone, edited or deleted. Cheque B: second cheque on the other invoice to cover the reject path (receive -> reject, receive -> deposit -> reject) so every one of the nine sources can be mapped. Expro expectation: receipt = Dr 1206 / Cr customer 12041xxx (notes receivable) ; deposit = Dr bank / Cr 1206 only when collected (or Dr under-collection) ; reject = reverse; invoice stays unpaid until collected (E-rules unwritten: this is the accounting default and WP26's design: "each status change posts or reverses, never edits"). Reversible: unknown (payment delete rules to be tested; cheques may stay).

### B-3 paid cheque to a supplier against my purchase invoice (S-27b)
Create supplier «مورد افتراضي-B 1» and purchase invoice P1 («افتراضي-B»), 500 + no tax (free text line if allowed), received, unpaid. Paid cheque 200 from the bank account B: statuses (deliver, collect, reject), journals of each, supplier balance and bank balance after each, invoice payment state. Expectation: Dr supplier / Cr 212 on issue, then Dr 212 / Cr bank on collection, reject reverses. Reversible: purchase docs and payments are deletable (D2); cheque rows unknown.

### B-4 post-dated vs due-today cheque (S-27c)
Two received (or one received one paid) cheques: due today and due +30 days; does the due date alter any journal, status, report column or the invoice's paid state? Expectation: due date is data only; no automatic posting on the date.

### B-5 payment method independence (S35-D6, S-27d)
On the invoice and purchase payment forms: list of methods before/after enabling the module; whether choosing «شيك» as the method of a normal payment (not through the cheque module) changes the journal or forces a cheque record; whether a bank account can be picked with «نقدي». Expectation: method is a tag, treasury decides the debit (matches S35-D6).

### B-6 foreign currency (WP28/G-12/G-25/G-63/S-30)
Order: (a) read `/owner/local_currency_rates` add form, add USD->SAR rate row (manual, e.g. 3.75 from today) ; (b) open the invoice form, find the currency control, create ONE issued foreign-currency invoice F1 for the D1 client: 2 lines in USD with VAT 15%, e.g. 100.00 USD unit price x2, rate 3.75 -> SAR totals; read totals both currencies, journal (revenue/receivable/VAT lines in SAR, rate used, rounding); (c) payments on F1 at a different rate (partial payment 50 USD at a rate 3.80 and the rest at 3.70 if the form accepts a rate; else change the rate row) -> FX difference line/account, invoice status, client balance per currency (client statement with currency filter); (d) purchase P2 in USD with rate, supplier payment at another rate -> FX gain/loss line; (e) USD treasury «افتراضي-B خزينة USD» (currency field), transfer SAR treasury -> USD treasury with rate (controls visible only when multi-currency): journal lines, balances per currency; (f) currencies with 0 and 3 decimals (e.g. JPY, KWD/BHD) on a purchase P3: how amounts round in document and journal; (g) trial balance / general ledger / reports with the currency filter «الجميع إلى SAR / كل على حده / SAR». Expectation (Expro): not supported; textbook: receivable posted at the document rate, FX difference on settlement to a realised gain/loss account, trial balance in base currency only. Reversibility: purchase docs/payments/rates/treasury transfers (transfers have no delete button per D2) - the USD invoice is permanent.

### B-7 bank statement tab, read only (WP29/E47-BANKREC)
Open «كشف حساب البنك» on the bank account B (after it exists) and on S35 بنك (read only): what the empty state offers (import, formats, reconciliation, AI upload); read the documented CSV format; do NOT upload anything unless the screen documents a format and the account is mine (B bank) - then at most one tiny synthetic CSV tagged «افتراضي-B», no real bank data, no AI upload, no bank link («ربط»).

## 4. Records and reads per scenario
After every scenario: read the document, each journal (`/owner/journals/view/<id>` frame via `readJournal`), the client/supplier statement, treasury page balances, trial balance totals (`/owner/reports/...` trial balance) and the activity log (to confirm nothing unexpected was created). Evidence under `out/b/ev/`.

## 5. Cleanup / revert (end of batch)
1. Delete cheques/payments (if the UI allows), purchase documents (to the trash and then permanently), supplier(s), rate rows; deactivate bank/treasuries that cannot be deleted (D2 lesson: movements block deletion).
2. Disable «دورة الشيكات» and re-read the apps page; revert any currency-related setting and re-read it.
3. Read plan usage again; write `cleanup.md` (what remains: the issued foreign invoice(s), cheque rows that cannot be removed, treasuries, transfers, each toggle with revert status).

## 6. Plan changes
(appended below as the batch proceeds)

### Plan changes recorded after execution (28/10/2026 ~01:00)
1. **Order:** cheques first (module toggle, forms, receive/deposit/collect/reject/undo for R1, post-dated R2, paid cheque P-CHQ1/P-CHQ2), then payment-method independence, then currencies, then the bank-statement tab, then cleanup.
2. **The cheque form has no invoice field**, so scenario B-2 «against an existing open invoice» became: the cheque credits the client's ledger account and Daftra applies the credit to the open invoices of the D1 client (#000028 and #000030) by automatic payments; those invoices were touched only through that mechanism and returned to their D1 states at the end.
3. **Foreign-currency treasury impossible** (bank form offers only SAR; cash form has no currency): replaced by the currency TRANSFER form between my own bank B and a second cash treasury B2 (X1-X3). A currency switch does not exist: the second currency is chosen per document (modal) and rates come from an automatic source or from the manual «أسعار العملات المحلية» table.
4. **Quota actually used:** 1 sales invoice (F1, USD) of 3; 4 purchase documents of 6 (P1 SAR, P2 USD, P3 KWD, P4 JPY); 1 supplier of 2; 0 products; 0 clients. Extra records created: bank B, cash treasury B2, cheque book, 5 received and 2 paid cheques, 3 rate rows.
5. **Added scenarios:** USD cheque (R3), 1.00 SAR cheques R4/R5 to understand the orphan-journal behaviour, bank-statement CSV import/match with a 3-row SYNTHETIC file (the screen documents the format; account is mine), recalculation probes RC1/RC2.
6. **Not done:** foreign-currency expense/income (OO-15), foreign-currency credit note (quota kept), «إنهاء المطابقة وإغلاق», saving a receipt voucher from the match screen, endorsement beyond the label, the two disagreeing `update_journal_entries_currency_rates` settings (never opened, nothing toggled).
7. **Toggle record:** app «دورة الشيكات» was the only global setting changed (original تم التعطيل); reverted and confirmed by a fresh read.
