# جرد الأعمال المتبقية وحزم التنفيذ (E47)

> التاريخ: 2026-10-26. **مصدره:** جرد آلي بخمسة محلّلين للقراءة فقط (الخطة والفجوات والمصفوفة والقرارات والتقارير) ثم مُركِّب أعاد التحقق من كل بند بالكود (`grep` و`read`) وأسقط ما هو منفَّذ فعلًا. **الترتيب:** (1) الصحة المحاسبية والمالية، (2) سلامة البيانات وقابلية التدقيق، (3) سلامة سير العمل التجاري، (4) التوافق مع البنية، (5) المطابقة المثبتة مع دفترة، (6) الاستخدام والصيانة والأداء. **الحزم WP1 إلى WP19 لا تحتاج رصدًا في دفترة**؛ WP20 إلى WP29 رصد في دفترة وبناء يعتمد عليه؛ WP30 إلى WP43 ملاءمة بنية ومطابقة؛ WP44 إلى WP50 الأدنى أولوية. **قيود باقية (E47):** لا دمج في `master` ولا نشر ولا تغيير بيانات إنتاج، ولا إعادة تسعير تاريخية ولا إعادة كتابة قيود قائمة في قاعدة إنتاجية دون إذن منفصل، ونفسه `E33` (خصم الفاتورة الكاملة للمشتريات) يُبنى على بيانات التطوير وتجارب دفترة فقط.

## 1. الحزم

| الحزمة | الاسم | الحجم | تعتمد على | رصد دفترة | البنود |
|---|---|:-:|---|---|---|
| **WP1** | Close control gaps: sensitive grants, branch scope on stock screens and legacy reports, destination-location grant, lock topology guard | M | — | none | P6-SENSITIVE-PERMS, A2A-STOCK-ACCESS, G-48, G41-A5, P5-LOCK-POOLING |
| **WP2** | Pre-deployment data checks on owner databases (owner-run) plus one read-only readiness command | M | WP1 | none (needs owner database access, not Daftra) | E38-SCAN, E40-BANK-DETAILS, E41-STAMP, OWNER-GRANTS, E26-ACTIVATION |
| **WP3** | Expenses and cash-up posted to the ledger (categories to accounts, tax split, treasury, branch) | L | WP1 | none for expenses (S-25 observed); revenue vouchers (FIN-03) and recurrence (FIN-06) are separate packages; cash-up has no Daftra counterpart and n… | FIN-05, FIN-02, FIN-04, A4 |
| **WP4** | Income statement and balance sheet with dynamic unclosed-years equity (policy re-based first) | L | WP3 | none | Y-DEC, REP-03, REP-04, Y8 |
| **WP5** | Ledger-based tax report (accrual first) | M | WP3 | cash-basis filter and the return form layout (R-02 in WP20); accrual slice needs none | REP-07 |
| **WP6** | Year-end closing entry per branch with preview, approval, reversal and guards | XL | WP4 | Y9 (how Daftra closes a year and whether it reopens) as a non-blocking read before the screen; service and ledger work needs none | YE-01, Y5, YE-03 |
| **WP7** | Inter-branch settlement, pairwise balances and cross-branch payments | L | WP1 | none | G-61, INTERBRANCH-SETTLEMENT, P6-ASSUMPTION-7 |
| **WP8** | Per-document numbering service | M | — | base sequence none; editing the next number and duplicate manual numbers need S-33 (WP21) | G-08, CUS-02 |
| **WP9** | Customer documents: payments list, credit notes list, refund invoice, journal order | L | WP8 | none | G-34, SAL-19, G-28, SAL-29 |
| **WP10** | Supplier documents: payments list, debit notes list, entered payment date, paid-by | M | WP8 | none | G-51 |
| **WP11** | Opening-data tools: customer opening balance, opening-stock cost correction option, negative-line decision, known limits (dev-only forms) | L | WP1 | CUS-03 customer journal is a provisional assumption until a customer-side equivalent of S-35 T-10/T-11 is observed (WP22); the other items need none | G-57, E26-NEG-LINES, E26-LIMITS, CUS-03 |
| **WP12** | Retroactive posting (backfill) with preview batches | XL | WP11, WP8 | none (OSPOS-specific, D3) | A6 |
| **WP13** | Invoice date, due date, payment terms, aging and balance-report columns | L | WP8 | none for dates and supplier aging; the customer bucket layout and by-ledger variant (CUS-09) are inferred from the supplier side and confirmed by R… | G-27, G-31, G-53, CUS-08, CUS-09, G-14 |
| **WP14** | Draft and quote lifecycle | M | WP8, WP13 | none | SAL-13, SAL-21 |
| **WP15** | Discount on the whole purchase invoice (E33 / G-59) | M | — | none (T-16 evidence) | G-59 |
| **WP16** | Invoice-level discount on sales (percent or fixed) | L | — | none | SAL-07 |
| **WP17** | Purchase flow completion: edit saved receiving lines, item purchase taxes, supplier registry field | M | WP1 | none (the average-cost effect of editing or deleting is the unobserved G-43 assumption, kept as 'average unchanged') | G-37, G-38, PUR-10 |
| **WP18** | Inventory valuation report from valued movements | M | WP1 | none for valuation; aging and turnover layouts are covered by WP33 | G-45 |
| **WP19** | Automatic-journal metadata edit and manual-journal parity | M | — | none (S-14 observed); line tax and currency need WP38 and WP28 | ACC-16, ACC-18 |
| **WP20** | Daftra observation batch 1: read-only reports, forms, prints, role matrix, year-end | M | — | This package is the observation: filtered ledger, journal movements, inventory movement, cost centres, cash flow, cash and accrual profit and loss,… | R-02, R-03, R-04, R-05, Y9 |
| **WP21** | Daftra write-observation batch A: closed periods and messages, settings scenarios, numbering toggle, inclusive tax | L | WP20 | This package is the observation (writes on opes.daftra.com only, each setting reverted afterwards) | E12-PKG-C, E12-PKG-D, ACC-19, SAL-05 |
| **WP22** | Daftra write-observation batch B: unobserved assumptions of the sales, purchase, stock and treasury flows | L | WP20 | This package is the observation: F-04c settle button, F-08 stock permit, F-09 stocktake, F-10 credit note without invoice, F-11 credit note on unde… | G-49, G-20, G-55, G-35, G-23, G-26, G-43, G-19, G-66, S35-T06, S35-D4, S35-RESIDUE, D1D4-ACCOUNT-NUMBER-RULE, P3-EVIDENCE-GAPS, PUR-08, SAL-37 |
| **WP23** | Credit flows and return exceptions: advance payments, cash refunds, return override, undelivered credit note | L | WP22, WP9 | F-12 (two open invoices and a covering balance, enable_advance_payment), F-10 (credit note without invoice), F-11 (credit note on undelivered invoi… | G-32, G-21, G-30, P5-CUSTOMER-CASH-REFUND, G-52 |
| **WP24** | Routing types per-item and main-account-per-entity | L | WP21 | S-28 (item-level journal accounts and product sales account) and S-29 (preview of types 2 and 4) in WP21 | G-02 |
| **WP25** | Per-tax inclusive option and default invoice tax mode | M | WP21 | S-03 and the tax-mode settings (WP21) | SAL-04, SAL-33 |
| **WP26** | Cheques, notes receivable and payable, receipt vouchers and revenue, payment method independent of treasury | XL | WP20, WP21, WP22, WP8, WP3 | Enable the cheques module in the trial account and run S-27; read the revenue and receipt-voucher forms (R-03); S-35 D6 (method independent of trea… | G-64, FIN-03, ACC-17, S35-D6 |
| **WP27** | Stock documents: numbered stock permits, stocktake document, stock requests | L | WP8, WP21, WP22 | F-08, F-09 and S-26 (WP21, WP22) | G-41, G-42, INV-12 |
| **WP28** | Multi-currency: document currency and rate, FX difference entries, treasury currency, non-two-decimal currencies | XL | WP21, WP22 | S-30 (currency selector hidden in the invoice form, payments at a different rate) and the treasury currency behaviour (WP21) | G-12, G-25, G-63 |
| **WP29** | Banking: bank reconciliation and bank directory | L | WP26 | Daftra's reconciliation (if any) and bank search list behaviour were never observed (R-03) | E47-BANKREC, D1D4-BANK-LIST |
| **WP30** | Roles and own-versus-all record scope | XL | WP1, WP20, WP21 | R-04 role matrix and S-32 restricted-employee behaviour and denial messages (WP20, WP21) | G-09, INV-09 |
| **WP31** | Report parity: Daftra filters and layouts, third trial-balance type, statement print header, cash-basis profit and loss, cash flow | L | WP20, WP4 | R-02 (filtered runs, cash flow, cash and accrual P&L), R-05 (print layouts), the 'review account' trial balance screen | REP-01, P8-TB3, REP-02, P8-PRINT, REP-05, REP-06, CHK-B |
| **WP32** | Operational report families: sales, items, payments, profit, net position | L | WP20, WP1 | R-02 column and filter reads (WP20) | REP-09, REP-10, REP-11, REP-12, REP-16 |
| **WP33** | Report families: purchases and suppliers, inventory aging and turnover, expenses and income splits, journal, chart and cost-centre reports, activity log | L | WP20, WP18, WP3, WP13 | R-02 (WP20) | REP-13, REP-14, REP-15, REP-08, REP-17 |
| **WP34** | Invoice header charges, sales debit note, journal description template | L | WP20, WP9 | R-03 forms and write observations of adjustment, shipping, debit note journals | SAL-08, SAL-09, SAL-20, SAL-34 |
| **WP35** | Order-to-cash documents: sales orders, recurring invoices, recurring expenses | L | WP20, WP8, WP3 | R-03 add forms and a controlled S-34 generation read | SAL-22, SAL-23, FIN-06 |
| **WP36** | Pricing and credit controls: price lists, minimum price, maximum discount, excluded categories, credit limit, preview and profit tab | L | WP20, WP13 | R-03 and the setting effects (messages, draft and return behaviour) | SAL-24, SAL-25, SAL-26, SAL-27, CUS-04, INV-02, SAL-32 |
| **WP37** | Product and customer master data | L | WP20 | meaning of 'suspended' versus 'inactive', category and brand screen contents, barcode merge setting (R-03) | INV-01, INV-03, INV-08, INV-16, INV-17, CUS-01, CUS-05 |
| **WP38** | Cost-centre distribution, journal settings, shipping-company accounts | L | WP20, WP19, WP24 | R-02/R-03 reads of the distribution forms and settings | ACC-20, ACC-21, ACC-05 |
| **WP39** | Purchase cycle documents: purchase request, quotation request, quotation, purchase order | XL | WP20, WP8 | R-03 forms and statuses | PUR-07 |
| **WP40** | Templates, print outputs and extra fields | L | WP20 | R-05 print outputs and R-03 | SET-06, SAL-31, SAL-35 |
| **WP41** | Integration settings: payment gateways and methods, secondary language, auto-send rules, files and API keys | XL | WP20 | scope of each setting (R-03) | SET-04, SET-07, SET-08, SET-09 |
| **WP42** | Multiple units, bundles and batch, serial and expiry tracking | XL | WP20, WP27 | R-03 and write observations of bundle cost, batch picking and unit conversion | INV-04, INV-05, INV-06 |
| **WP43** | Purchase received-state and draft (decision-gated) | M | WP17, WP10 | none | G-36 |
| **WP44** | Report export, reports-hub link, phone layout and review polish | M | WP4 | none | P8-EXPORT, README-HUB, P8-MOBILE, P8-MINOR, G41-A4 |
| **WP45** | Performance measurements, account picker and residual hardening | M | — | INV-07 blocking settings only (WP22); the rest none | P1-PICKER, PERF-LOAD-UNMEASURED, INV-07, G-56, E32-RETURN-ROUNDING, E22E32-BROWSER, CHK-F |
| **WP46** | E-invoicing (ZATCA phase 2) | XL | WP9 | none (official specification) | SET-10 |
| **WP47** | Fixed assets and depreciation | XL | WP20 | asset form and depreciation journals | FIN-08 |
| **WP48** | AI agent (proposals as drafts, anomaly detection, accounting_ai permission) | XL | WP30 | none | A9 |
| **WP49** | Contact list and customer portal (owner scope decision) | L | WP30 | portal behaviour (R-03) | CUS-06, CUS-10 |
| **WP50** | Umbrella rows (no own work; close when the children close) | S | WP4, WP5, WP6, WP8, WP9, WP10, WP15, WP16, WP17, WP25, WP33, WP34, WP35, WP36, WP40, WP41 | none | G-13, P7, P9, A8, PUR-01 |

## 2. ثوابت وفحوص كل حزمة

### WP1: Close control gaps: sensitive grants, branch scope on stock screens and legacy reports, destination-location grant, lock topology guard

**لماذا هنا:** Smallest slice, no Daftra access, and it closes real control weaknesses found in code: a non-admin employee editor can grant the permissions that create treasuries and move money; Items/stock-adjustment screens post journals into branches the employee does not work in; legacy reports leak other branches. Every later package adds money-moving screens and reports, so these guards must exist before them.

**ثوابت لا تُكسر:**
- Sensitive permissions are never granted automatically and only an administrator adds or removes them; a non-admin save keeps the sensitive grants the employee already holds
- No journal, balance, posted document or period lock is modified by this package
- Company-wide figures need all branches, partial scope is labelled partial (E24, E45); a single-branch employee sees only that branch in legacy reports
- A stock-adjustment journal belongs to the location's branch and the employee must work in that branch (the same rule Sales and Receivings already apply)
- With accounting disabled the legacy behaviour is unchanged (D1)
- Named customer/supplier locks keep double-spend protection or the request fails closed

**اختبارات الإثبات:**
- SensitivePermissionsTest: isSensitive covers acc_treasuries_manage and acc_payables_manage
- EmployeesControllerTest: non-admin cannot add or remove the two permissions, admin can, existing grants survive a non-admin save
- ItemsControllerTest + StockAdjustmentTest: employee confined to branch A cannot change quantity, import CSV or delete an item in a branch-B location (no inventory row, no journal); in branch A the journal carries A's branch and StockLedger::reconcile ties to the 1203xxx sub-account
- ReportsControllerTest plus tests over app/Models/Reports: single-branch employee totals exclude other branches, all-branch employee unchanged, legacy rows on the default branch still visible
- ReceivingsControllerTest: destination location outside the employee's grants is rejected and the cart is unchanged
- CustomerLockTest/SupplierLockTest: guard refuses when the lock is not held on the working connection

### WP2: Pre-deployment data checks on owner databases (owner-run) plus one read-only readiness command

**لماذا هنا:** The protections for historical data exist as separate commands (accounting:purchase_discount, accounting:bank_accounts, accounting:stamp_payments, accounting:fill_accounts) but nothing proves them on the owner's databases, and a blind migration run rewrites historical purchase-discount semantics. Engineering part: one aggregate read-only report and a runbook order; the rest is owner action. E47 forbids production data changes, merge to master and deploy without separate permission.

**ثوابت لا تُكسر:**
- Every check is read-only unless --apply is passed explicitly
- Stamping never touches a journal or a balance (only stamps where the treasury account equals the payment journal's account)
- Bank migration keeps account ids, journals and balances (journal-line fingerprint before and after)
- LegacyFixedPurchaseDiscount stops with no change on undetermined lines; rollback is by backup restore only (down() changes meaning)
- Accounting stays disabled on a real business until the owner orders activation; no merge to master, no deploy

**اختبارات الإثبات:**
- New aggregate-command test over a fixture DB: mixed legacy purchase lines give exit code 1 and zero rows changed; bank treasuries missing name/number are listed; unstamped payments are listed and not written
- LegacyPurchaseDiscountTest, PaymentTreasuryStampingTest, BankAccountPlacementTest stay green
- Migration up/down/up tests for 20261029000000, 20261031000000, 20261101000000 unchanged

### WP3: Expenses and cash-up posted to the ledger (categories to accounts, tax split, treasury, branch)

**لماذا هنا:** Largest remaining hole in financial correctness: every expense and every cash-up bypasses the ledger, so the trial balance, treasury balances and any profit figure are wrong whenever money leaves a treasury for an expense. Daftra's journal is already observed (S-25: 200 + 15% = 230, Dr 541 200, Dr 215002 30, Cr treasury 230). It is the prerequisite for the income statement.

**ثوابت لا تُكسر:**
- One balanced journal per expense inside the save transaction (D2) with failure logging and full rollback; idempotent on (source_type, source_id, entry_type, revision)
- Edit or delete reverses and reposts with a new revision (E15), never updates a posted journal
- Closed periods and branch scope are enforced (PeriodLocks, BranchGuard); the paying treasury's withdraw scope is enforced (TreasuryScopes) and treasury balance equals its account (TreasuryService::position)
- Tax goes to the routed input-tax account (215002) without rounding drift; amounts stored at 4 decimals
- Accounting disabled means no behaviour change (D1)
- HISTORICAL: expenses saved before activation get no journal and are not posted automatically (only the retroactive-posting package may do that)

**اختبارات الإثبات:**
- ExpensePostingBuilderTest/ExpensePostingTest: lines from a T-xx fixture of S-25, reversal on delete, revision 2 on edit, closed-period refusal, cross-branch refusal, withdraw-scope denial, treasury balance equals account balance
- ExpensesControllerTest (new canonical file): account required when FIN-04 on, falls back to the routed 541 when off, branch stamped
- CashupControllerTest: cash-up journal after the owner decision, shortage/overage account
- TrialBalanceTest: debits equal credits and branch balance holds after expenses
- Legacy rows: pre-activation expenses stay unposted and are listed as such

### WP4: Income statement and balance sheet with dynamic unclosed-years equity (policy re-based first)

**لماذا هنا:** Core financial statements derive from the ledger and need WP3 so revenue and expense are complete; no Daftra access needed (reference numbers 1,990 = 2,210 - 220 and the tree to level 6 are recorded). Y-DEC first because the policy still uses accounts 3102/3103 that no longer exist (retained earnings is 32, opening difference 214).

**ثوابت لا تُكسر:**
- Reads posted and reversed lines only (drafts and preview excluded); no writes
- Branch rules of E45: company view needs all branches, partial scope is labelled partial
- Assets = Liabilities + Equity before and after any year-end entry, using a dynamic 'result of unclosed years' line in equity
- year_end entries are excluded from the income statement (see YE-03) and included in the balance sheet

**اختبارات الإثبات:**
- IncomeStatementTest and BalanceSheetTest (new classes): A/B numbers 1,990 / 2,210 / 220, net -6,290 from the observed chart
- Equation test for one branch, several branches and the company
- Ties to TrialBalance, CustomerLedger (1204), SupplierLedger, StockLedger::reconcile (1203), TreasuryService::position
- Cancelled sale and reversed journals return the statements to prior values; Arabic-locale render; permissions and 404 in AccountingReportsControllerTest

### WP5: Ledger-based tax report (accrual first)

**لماذا هنا:** The existing tax summaries read sales tables, not journals, so they cannot tie to accounts 215001/215002 or show returns and input tax as separate sections. The accrual report needs no Daftra access (331.50 on 2,210, returns -33 on -220, balance 298.5); the cash-basis filter stays disabled until the read in WP20.

**ثوابت لا تُكسر:**
- Report totals equal the 215001 and 215002 account balances for the same period and branches
- Returns and input tax are separate sections; reversed documents net to zero
- Branch scope as in E45; reads posted journals only

**اختبارات الإثبات:**
- TaxReportTest (new): tie to journals after a sale, a credit note, a purchase with input tax
- Reversed sale zeroes the report; drafts excluded; branch scope; period boundaries
- Legacy sales before activation are not included and the screen says so

### WP6: Year-end closing entry per branch with preview, approval, reversal and guards

**لماذا هنا:** Revenue and expense accumulate from the start of the books, so there is no way to fix retained earnings or close a year; E46/E47 put it in scope. The core ledger logic does not wait for Daftra's behaviour (Y9), which is only a read before the screen design.

**ثوابت لا تُكسر:**
- One active closing entry per (branch, year), source year_end, no profit moved between branches
- Sensitive permission acc_year_end_close; precondition: company-level period closed to year end and no drafts; preview then approve
- Reverse, never edit or delete; reopening a period or reversing opening balances inside a closed-out year is refused until the closing entry is reversed
- year_end excluded from income statement and two-year comparison, included in balance sheet; trial balance has a before/after option defaulting to before; ledger tags the entry
- HISTORICAL: closing entries on a real database need separate permission; dev data only

**اختبارات الإثبات:**
- The seven acceptance tests of year-end-policy section 8: equation before and after, identical income statement, two concurrent runs produce one entry per branch (two real connections), refusals without period close/drafts/permission, reopen refused then allowed after reversal, limited-scope employee cannot run company close, loss/opposite balances/empty branch/no-activity year
- Legacy books with revenue accumulated since the start close correctly; closed periods untouched
- JournalEntryType and unique-key tests for the new source

### WP7: Inter-branch settlement, pairwise balances and cross-branch payments

**لماذا هنا:** Transfers post through 12044/2214 but nothing clears them (manual journal only) and the ledger cannot say which branch owes which; a payment on a document of branch A cannot go into a treasury of branch B. Internal design, no Daftra equivalent, medium-to-high accounting risk for consolidated figures.

**ثوابت لا تُكسر:**
- 12044 and 2214 net to zero for the company (InterBranchPosition::difference) after every operation
- Each journal is balanced inside its own branch; linked journals are posted and reversed in one transaction in branch-number lock order
- Employee must work in both branches (E43); treasury deposit and withdraw scopes are checked on both sides
- A closed period in either branch rejects the whole document
- HISTORICAL: pairwise balances are derived from treasury_transfers/receivings.to_branch_id; existing journals are never rewritten

**اختبارات الإثبات:**
- TreasuryTransfersTest-style tests for a settlement document clearing a pair and netting to zero
- Pairwise balance equals the sum of transfer documents; legacy transfers show as derived
- Payment of a branch-A document into a branch-B treasury posts the journal pair; reversal restores
- Closed period in one branch, scope denial, concurrent opposite transfers

### WP8: Per-document numbering service

**لماذا هنا:** Payments, credit notes, debit notes, refunds, receivings and customers have no sequence of their own (payment number is its row id); screens for those documents (WP9, WP10) and the aging work need it. Daftra's numbering style is observed (#000017, #00001); only the editable-next-number toggle needs S-33.

**ثوابت لا تُكسر:**
- Sequences are locked per (company, document type), seeded from the existing maximum, and a number is never reused
- New documents only: existing rows keep their ids and numbers; invoice counters (last_used_invoice_number) and journal series stay untouched
- Concurrent creation never duplicates a number (two real connections)
- Branch/company scope of the sequence follows the document

**اختبارات الإثبات:**
- DocumentNumberingTest (new class modelled on JournalNumberingTest): concurrency, seed from existing data, independence per type
- SalesControllerTest/ReceivingsControllerTest: displayed numbers, duplicate refusal
- CustomersControllerTest: auto number with prefix and digits, duplicate option
- Legacy rows without a number display the id-based fallback

### WP9: Customer documents: payments list, credit notes list, refund invoice, journal order

**لماذا هنا:** Auditability and usability of the receivables flow: payments and credit notes are not first-class numbered documents, there is no refund-invoice type and the paid-sale journal order differs from Daftra. All observed in Daftra (S-22, S-31, lstD), no observation needed.

**ثوابت لا تُكسر:**
- Payment journals keep amounts and accounts; only the posting order of a paid sale changes (invoice, payment, cost) and only for new journals, historical journals are not renumbered
- Credit notes still settle into the invoice (CreditSettlement) and the triple tie holds: customer balance equals aging equals account
- Issued documents stay locked (E21); payment edits are reverse and repost (E15); credit unwinds on payment deletion (E25)
- Refund invoice produces the same journals as a credit note with its own numbering and a 'returns' column
- Numbers come from WP8; closed periods and branch scope enforced

**اختبارات الإثبات:**
- SalePostingTest: order invoice, payment, cost with unchanged amounts; T-06 stays green with reordered journal numbers
- SalePostingBuilderTest/CreditSettlementTest (T-10) unchanged
- AccountingReceivablesControllerTest: payments and credit-note lists, collected-by, branch rules of E24
- Refund invoice fixture equal to the T-06 credit-note lines; closed period; reversal
- Legacy payments show the id-based number

### WP10: Supplier documents: payments list, debit notes list, entered payment date, paid-by

**لماذا هنا:** Supplier payments on a receiving are dated when saved and numbered by id; debit notes are return-mode receivings with no list. Daftra's date field, #000017 numbering, refund-as-negative list and #00001 debit-note numbering are observed (F-07).

**ثوابت لا تُكسر:**
- Entered payment date posts at that date and respects period locks
- Payment journals keep their keys (receiving key for immediate, payment id for later); no repost of historical payments
- Credit application timing stays immediate (E34) and unwinding stays (E30); triple tie supplier balance equals aging equals account
- Numbers from WP8

**اختبارات الإثبات:**
- ReceivingPostingTest: backdated payment posts at its date, locked-period date refused
- SupplierSettlementTest and T-13/T-18 unchanged
- AccountingPayablesControllerTest: lists, branch scope, paid-by
- Legacy payments without an entered date show the saved time

### WP11: Opening-data tools: customer opening balance, opening-stock cost correction option, negative-line decision, known limits (dev-only forms)

**لماذا هنا:** Lets an existing business start the books correctly: customer receivables cannot be carried in at all (control accounts are blocked from manual opening) and a wrong opening cost cannot be corrected without a manual journal. HISTORICAL RISK package: every form here must be additive and dev-only until the owner gives separate permission. CUS-03 mirrors the accepted provisional supplier design (G-66).

**ثوابت لا تُكسر:**
- Opening journals are reversed and reposted with a new revision, never edited; old revisions stay visible and reversed
- No historical repricing: valued stock movements of other documents are never changed and StockLedger::reconcile stays at zero
- 'At current item cost' re-post is an explicit per-location option, default off, with a reason and a before/after plan; refused when later movements exist at the location or the period is closed
- Customer opening balance posts to the routed customer sub-account and the triple tie (balance, aging, account) holds; manual opening of control accounts stays blocked
- Dev data only; no production run without separate permission (E47)

**اختبارات الإثبات:**
- OpeningStockTest: re-post at current cost on a location with no later movements changes only the opening revision and reconciles; refused when later sales or receipts exist; history preserved
- CustomerOpeningTest (new) and CustomerLedgerTest ties; supplier mirror unchanged
- OpeningBalanceServiceTest: control-account block remains for the manual route
- Closed-period refusal; branch scope; idempotency and concurrency (two real connections)

### WP12: Retroactive posting (backfill) with preview batches

**لماذا هنا:** Only the table, the batch column and the Preview status exist. It is how documents dated before activation could enter the books, but it is an XL feature with the highest historical-data risk, so it follows the opening-data tools and is design-only against dev data.

**ثوابت لا تُكسر:**
- Preview journals are excluded from every balance and report by default; approval must equal the preview exactly; cancel removes the whole batch
- Idempotent per source on (source_type, source_id, entry_type, revision); start date, period locks and branch per document are honoured
- HISTORICAL: never run on a production database without separate permission; dev-only dry run first, approval second
- Opening balances and the start date are fixed before the batch runs

**اختبارات الإثبات:**
- Dry run leaves TrialBalance and every report unchanged
- Approve produces journals identical to the preview; cancel leaves nothing
- Concurrent approval, closed-period refusal, documents without branch or treasury, large-batch chunking and resume

### WP13: Invoice date, due date, payment terms, aging and balance-report columns

**لماذا هنا:** Without a due date there is no due/overdue state and aging runs from the invoice date; the invoice date equals the completion time. All behaviour is observed in Daftra (SAL-11, F-07 six buckets, repL columns), no observation needed. Commercial-flow soundness and a prerequisite for credit limits.

**ثوابت لا تُكسر:**
- New nullable columns; defaults reproduce today's aging (due date equals invoice date) so existing figures do not move
- Triple tie preserved: customer balance equals aging equals account (same for suppliers)
- Invoice date separate from creation time; journal date equals invoice date and respects period locks (backdating into a closed period refused)
- Posted journals of existing documents are untouched

**اختبارات الإثبات:**
- CustomerLedgerTest/SupplierLedgerTest: ties with due dates; buckets today, 1-30, 31-60, 61-90, 91-120, over 120
- Backdated invoice into a closed period refused; draft with a typed date issues at that date (T-07 extended)
- Balance report columns before, sales, returns, net, payments, settlements reconcile (2,541.50 / 1,563.50 / -253 / 725 fixture style)
- Legacy rows keep their current aging

### WP14: Draft and quote lifecycle

**لماذا هنا:** Journal and stock structure of drafts already match Daftra; the lifecycle does not (temporary number, issue from the document page with the typed date, quote states and conversion). Fully observed (S-06, S-07).

**ثوابت لا تُكسر:**
- Drafts and quotes post no journal and move no stock; issue posts invoice and cost journals as today
- Temporary draft number never consumes the real sequence; issue takes the next number and the typed date within period locks
- Quote states (open, approved, converted) stored separately from sale_status; conversion posts nothing until the invoice is saved
- POS and suspended-sale behaviour unchanged

**اختبارات الإثبات:**
- SalePostingTest draft-then-issue (T-07) with typed date and closed period
- SalesControllerTest: issue action uses POST with CSRF, quote conversion pre-fills the form
- Numbering test: draft numbers do not advance the sequence
- Branch scope on drafts

### WP15: Discount on the whole purchase invoice (E33 / G-59)

**لماذا هنا:** Daftra applies an invoice-level discount after line discounts that enters stock cost and the tax base with no discount line (810 / 121.50 / 931.50). Observed, so no Daftra access is needed, but it is a HISTORICAL-RISK change: the recent fixed-discount incident (E32) showed that reinterpreting a stored column silently changes old documents.

**ثوابت لا تُكسر:**
- Additive nullable header column defaulting to 0; ReceivingAmounts output is byte-identical to today for discount 0 (golden test over T-11 to T-19)
- Applied after the line discount; enters stock cost, weighted average and tax base; no discount journal line
- Returns take the proportional share; historical receivings are never recalculated or re-posted
- LegacyFixedPurchaseDiscount markers keep their meaning; closed periods and branch scope unchanged
- Dev data only until the owner approves E33 (still not approved)

**اختبارات الإثبات:**
- ReceivingAmountsTest/ReceivingPostingTest: T-16 'discount-on-the-whole-purchase' flips to exact (810, 121.50, 931.50)
- Weighted average and return share with the header discount; reversal restores
- Receiving_libTest totals and receivings report agree with journals
- Existing receipts: totals and journals identical before and after the migration

### WP16: Invoice-level discount on sales (percent or fixed)

**لماذا هنا:** No invoice discount exists on sales although Daftra's behaviour is fully observed (S-05, T-04): applied after line discounts, tax on the discounted base, no discount line, tax stored at three decimals. HISTORICAL-RISK change: issued invoices are locked (E21) and must never be recomputed.

**ثوابت لا تُكسر:**
- Additive nullable columns on sales; SaleAmounts/Tax_lib results unchanged when the discount is null
- Discount after line discounts, tax on the discounted base, revenue net, E14 rounding with the accepted E19 half-cent difference
- Posted invoices and their credit notes are not recomputed; credit notes against a discounted invoice return a proportional share
- Receipts, PDF and reports show the discount; journals tie to the sales report

**اختبارات الإثبات:**
- Sale_libTest/Tax_libTest: 10% on 355 gives base 319.50; fixed 20 gives revenue 335, tax 50.25, total 385.25 (S-05)
- SalePostingBuilderTest/T-04 fixtures with the discount; proportional credit note
- Reports total equals the 411 account; legacy sales unchanged

### WP17: Purchase flow completion: edit saved receiving lines, item purchase taxes, supplier registry field

**لماذا هنا:** The accounting approach is decided (E15: reverse and repost); what is missing is the screen that edits saved lines and the item-level purchase taxes Daftra has (INV-02). No observation needed.

**ثوابت لا تُكسر:**
- Editing lines reverses and reposts with a new revision (E15); supplier and date stay locked after posting; a receiving with returns still cannot be deleted or edited
- Valued stock movements and StockLedger::reconcile stay consistent; the edit is refused if it would drive a location's stock negative or alter a cost already consumed by posted sales
- Receiving lines default tax from the item's purchase taxes but legacy rows keep their stored tax_percent
- Closed periods and branch scope enforced

**اختبارات الإثبات:**
- ReceivingPostingTest: edit 10 to 8 units reverses the old journal and movements and posts revision 2; refusal after returns and in closed periods; payments preserved
- ReceivingsControllerTest: edit form and permissions
- ItemsControllerTest/Item model tests for the purchase-tax fields
- Legacy receivings keep their stored tax

### WP18: Inventory valuation report from valued movements

**لماذا هنا:** The existing inventory report multiplies cost by quantity (1,320) while the ledger-based value is 1,314; the ledger-based value exists only in the library and tests. Daftra's valuation report ties to account 1203 (1,720) and is observed.

**ثوابت لا تُكسر:**
- Value comes from valued stock entries (StockLedger), never cost price times quantity
- Per location the total equals the 1203xxx sub-account (StockLedger::reconcile)
- Read-only; branch scope; units with no valued movement are flagged unposted, not silently valued

**اختبارات الإثبات:**
- StockLedgerTest tie plus InventoryValuationReportTest (new): 1,314 versus 1,320 case
- Opening-stock locations and partially posted locations show flagged units
- ReportsControllerTest permission and branch scope

### WP19: Automatic-journal metadata edit and manual-journal parity

**لماذا هنا:** Daftra lets description, line description and cost centre of an automatic journal be edited (S-14) while amounts, accounts, date and number stay locked (we keep numbers locked, E16); the manual journal lacks attachments and a required line description. Small, observed, no Daftra access needed. Line tax and currency parts wait for ACC-20 and multi-currency.

**ثوابت لا تُكسر:**
- Only description, line description and cost centre of a posted automatic journal are editable; amounts, accounts, date and number are locked
- Every edit writes an AuditLog entry and respects branch scope and closed periods
- Posted manual journals are still reversed, not edited
- Attachments are stored privately and tied to the journal

**اختبارات الإثبات:**
- JournalServiceTest: metadata edit audited, amounts unchanged, closed-period refusal, permission and branch scope
- AccountingJournalsControllerTest: edit form exposes only the allowed fields for automatic journals
- Legacy journals editable only for metadata

### WP20: Daftra observation batch 1: read-only reports, forms, prints, role matrix, year-end

**لماذا هنا:** Read-only evidence that unlocks the report parity and settings packages. Requires the owner's credentials through a 600 file in /dev/shm and the REA tool, neither available now, so it is scheduled after everything that can proceed without them.

**ثوابت لا تُكسر:**
- GET only, no saves, no deletes; trial account opes.daftra.com only
- Credentials only from a 600 file in /dev/shm shredded afterwards and never written to the repository
- Evidence ids and numbers only, no copied Daftra text, code or assets (E11)

**اختبارات الإثبات:**
- No product code: deliverable is evidence entries, matrix updates and fixtures T-25 onward
- ParityFixtureTest registers every new evidence id; conformance-matrix counts updated

### WP21: Daftra write-observation batch A: closed periods and messages, settings scenarios, numbering toggle, inclusive tax

**لماذا هنا:** Package C (S-15, S-17) and D (S-03, S-26, S-28, S-29, S-32, S-33, S-16, S-30) are authorized for the trial account only by E47 and unlock inclusive tax, routing types 2 and 4, stock permits, roles, numbering and multi-currency. Credentials are absent now.

**ثوابت لا تُكسر:**
- Trial account only; revert every changed setting; the closed-period scenario temporarily blocks posting in the whole account, so run it last and confirm the reopen
- No deletion of the account, no ownership or login changes, no real customer notification (E47)

**اختبارات الإثبات:**
- No product code first: fixtures T-25 onward, then tests for our behaviour (PeriodGuardTest message mapping, inclusive-tax fixture run against Tax_lib::get_included_tax for SAL-05)
- Classification flips (exact versus differs) only through ParityFixtureTest-backed fixtures

### WP22: Daftra write-observation batch B: unobserved assumptions of the sales, purchase, stock and treasury flows

**لماذا هنا:** Each item is an implemented assumption that is not counted as a match until Daftra is watched: the settle button, credit application order, unpaid-partially-returned, no-customer sale, sales return cap, average after receipt deletion, half-cent ties, supplier standalone payment and opening journals (S-35 T-10/T-11), treasury delete rules. It also executes the F second slice (F-04c, F-08 to F-13) that gates WP23 and WP27. Credentials are absent now.

**ثوابت لا تُكسر:**
- Trial account only; S35-RESIDUE cleanup touches only documents created by S-35 (invoice #000027, the disabled 'S35 treasury', S35 supplier, item and purchase)
- No product code changes until a fixture proves a mismatch; deliberate differences (E25, E29, E34) stay
- Stop conditions and reversibility recorded before each write

**اختبارات الإثبات:**
- Fixtures T-25 onward and ParityFixtureTest registration; existing T-09/T-10/T-13/T-17 classifications change only with evidence
- After G-66 observation: SupplierAdvancesTest journal expectations updated or confirmed

### WP23: Credit flows and return exceptions: advance payments, cash refunds, return override, undelivered credit note

**لماذا هنا:** A customer cannot pay without an invoice, credit cannot be refunded in cash, a stock return without an origin is only refused and an undelivered invoice can only be corrected by confirming delivery first. High accounting risk (customer and supplier liabilities), so it waits for the observations of WP22 (F-10, F-11, F-12).

**ثوابت لا تُكسر:**
- Triple tie: customer (supplier) balance equals aging equals account balance after every operation
- Advance payment is a customer credit with its own journal (Dr treasury, Cr customer), idempotent, reversed not deleted
- Cash refund respects treasury withdraw scope, branch, period locks and posts Dr customer (supplier) Cr treasury
- Return override needs a permission, an audit flag and a journal marker; ReturnLimit stays enforced; credit note on an undelivered invoice moves no stock and posts no cost journal
- Credit applications stay journal-less and unwind explicitly (E25, E30)

**اختبارات الإثبات:**
- CreditSettlementTest cases for advance payments and the observed application order (date versus number)
- SalePostingTest: override flag, audit entry, cost at current cost; credit note on undelivered invoice has no stock or cost journal
- Refund journal ties to TreasuryService::position; closed period; branch scope; reversal
- Legacy returns without origin unaffected; SupplierSettlementTest for the supplier refund

### WP24: Routing types per-item and main-account-per-entity

**لماذا هنا:** The settings exist (P2) but posting refuses the two types (RoutingResolver throws RoutingTypeUnsupported). Needs the Daftra previews S-28 and S-29 to know which line they replace.

**ثوابت لا تُكسر:**
- Documents with default (auto/cancelled) routing post byte-identical journals (golden tests over T-06 to T-19)
- The per-item account is an active postable leaf; a reversal mirrors the original journal's accounts and never re-resolves routing
- Historical documents keep the accounts they were posted with; settings changes are audited
- Readiness checker knows the new types

**اختبارات الإثبات:**
- RoutingResolverTest/RoutingKeyTest/SalePostingBuilderTest with item and entity overrides
- Reverse a sale posted under per-item routing after the setting changed: reversal mirrors
- Legacy documents and the readiness codes

### WP25: Per-tax inclusive option and default invoice tax mode

**لماذا هنا:** Inclusion is one system-wide flag while Daftra stores it per tax, and the invoice-level default tax mode and mandatory tax 1 do not exist. Inclusive-price behaviour was never exercised in Daftra (S-03).

**ثوابت لا تُكسر:**
- The per-tax flag defaults to the current global flag so existing taxes behave identically
- Stored tax amounts and journals of posted sales are never recomputed
- Net revenue and E14 rounding preserved; sales_taxes history untouched

**اختبارات الإثبات:**
- Tax_libTest/Sale_libTest included-tax fixtures from S-03; SalePostingBuilderTest with mixed included and excluded taxes
- Migration from the global flag to per-tax values keeps totals
- Reversal and credit note on an inclusive invoice

### WP26: Cheques, notes receivable and payable, receipt vouchers and revenue, payment method independent of treasury

**لماذا هنا:** No cheque document, no receipt voucher or revenue document and the two cheque reports do not exist; accounts 1206 and 212 are chart rows only. E47 lifted the earlier ban, but Daftra's cheque module is not enabled in the trial account, so its journals were never observed.

**ثوابت لا تُكسر:**
- Cheque lifecycle journals via 1206/212 are balanced and each status change posts or reverses, never edits in place
- Treasury scopes, branch scope and closed periods apply; numbering from WP8
- Payment method becomes independent of the chosen treasury without moving historical payments (stamped per E41)
- Every new journal source gets a label and filter entry (ACC-17)

**اختبارات الإثبات:**
- ChequeServiceTest: receive, deposit, clear, bounce, reversal; the two reports tie to 1206 and 212
- Voucher journals equal the observed fixtures; TreasuryScopes denial; closed period
- Legacy payments typed 'check' keep their routed account
- JournalServiceTest source filter and labels

### WP27: Stock documents: numbered stock permits, stocktake document, stock requests

**لماذا هنا:** Transfers and quantity adjustments post correctly but there is no numbered permit or stocktake document; E44 deliberately excludes them until Daftra's permit and journals are observed (F-08, F-09 need a second warehouse and enabled settings).

**ثوابت لا تُكسر:**
- Transfers through requisition receivings stay valid until a permit wraps them; valued movements and journals are unchanged
- Documents are numbered (WP8), carry a count date and use the stock_permits routing accounts
- StockLedger::reconcile stays at zero; branch scope and closed periods enforced; posted opening stock untouched

**اختبارات الإثبات:**
- ReceivingPostingTest regressions; StockPermitTest and StocktakeTest (new) tie to StockLedger::reconcile
- Branch scope and closed-period refusal
- Legacy StockAdjustment journals remain valid

### WP28: Multi-currency: document currency and rate, FX difference entries, treasury currency, non-two-decimal currencies

**لماذا هنا:** Accounting runs in the base currency only (reserved columns exist). E47 authorizes building it, but Daftra's FX-difference journals on payments were never observed (S-30). XL with high accounting risk.

**ثوابت لا تُكسر:**
- Base-currency journals unchanged (currency_code base, rate 1); foreign_amount optional
- FX-difference journals are balanced in base and tied to the payment
- Per-currency decimals (0, 2, 3) honoured by rounding
- Historical journals untouched; rate captured at document time; reports in base currency

**اختبارات الإثبات:**
- PostingServiceTest and SalePostingBuilderTest with three-decimal and zero-decimal currencies
- Payment at a different rate posts the FX difference (S-30 fixture)
- TreasuryService currency; legacy base-only data unchanged; trial balance base totals

### WP29: Banking: bank reconciliation and bank directory

**لماذا هنا:** Bank balances tie to the ledger but nothing matches statement lines; the bank name is free text. E47 prohibits paid external integrations without separate permission, so this stays manual or static.

**ثوابت لا تُكسر:**
- Reconciliation never edits a journal; matched lines are flagged
- Statement import is checksummed and idempotent
- No paid external service without separate permission (E47)

**اختبارات الإثبات:**
- BankReconciliationTest: match, unmatch, idempotent import
- Treasury scope and branch scope; legacy bank accounts missing details are flagged

### WP30: Roles and own-versus-all record scope

**لماذا هنا:** The app has per-employee module grants and an own/all switch only for manual journals; Daftra has roles with 130+ options scoped all or own. XL and cross-cutting, so it follows the control-gap fixes and the observation of the role matrix and a restricted employee (S-32). Also carries the default warehouse per employee.

**ثوابت لا تُكسر:**
- Existing per-employee grants keep working; roles are additive and existing employees default to scope 'all'
- Sensitive permissions stay administrator-only; branch scope unchanged
- Own-scope is enforced in services, not only screens
- The treasury 'roles' scope (rejected today in TreasuryScopesTest) becomes valid only when roles exist

**اختبارات الإثبات:**
- EmployeesControllerTest and per-document own/all tests for sales, receivings and journals (acc_journals_all precedent)
- TreasuryScopesTest updated for the roles mode
- Default warehouse per employee; denial messages after S-32

### WP31: Report parity: Daftra filters and layouts, third trial-balance type, statement print header, cash-basis profit and loss, cash flow

**لماذا هنا:** Trial balance and ledger shipped but cannot be called matching until identical inputs are compared; cash-basis recognition, the cash-flow layout, the third trial-balance type and the print header are unobserved. Needs the read-only evidence of WP20.

**ثوابت لا تُكسر:**
- Read-only; branch rules of E45; totals tie to the ledger
- Honest naming: the third shape keeps its own name until it matches Daftra's
- Parity fixtures record Daftra numbers only (122,478 = 122,478)

**اختبارات الإثبات:**
- Fixtures T-25 onward for trial balance and ledger on the A/B scenario documents
- TrialBalanceTest and AccountLedgerTest ties; print header and export controller tests
- a8-design.md written before UI code (phase checklist)

### WP32: Operational report families: sales, items, payments, profit, net position

**لماذا هنا:** Only date-range summaries exist; Daftra has daily, weekly, monthly and yearly variants by customer, user, representative, brand, method and profit from valued movements. Columns were only catalogued, not read.

**ثوابت لا تُكسر:**
- Read-only; branch scope from WP1
- Profit derives from valued movements and agrees with account 511
- Operational reports stay unchanged until replaced

**اختبارات الإثبات:**
- ReportsControllerTest per variant
- Profit total equals 511 for the period; payment report equals the treasury journals
- Branch scope and permissions

### WP33: Report families: purchases and suppliers, inventory aging and turnover, expenses and income splits, journal, chart and cost-centre reports, activity log

**لماذا هنا:** Purchase, inventory, expense and journal reports are partial; layouts were never read. REP-14 covers what WP18 leaves (count sheet, movements, warehouse and product balance, aging, turnover).

**ثوابت لا تُكسر:**
- Read-only; branch scope; supplier balances tie to SupplierLedger and 2211
- Inventory reports tie to 1203 per location
- Expense and income splits tie to the expense journals (WP3)

**اختبارات الإثبات:**
- ReportsControllerTest per report
- Ties to SupplierLedger, StockLedger::reconcile and expense journals
- Activity log filters and permissions

### WP34: Invoice header charges, sales debit note, journal description template

**لماذا هنا:** Adjustment, shipping, sales debit note and templated journal descriptions have no counterpart and their journal and tax treatment was never observed.

**ثوابت لا تُكسر:**
- Adjustment and shipping post through routing rows (SalesAdjustment is refused today by assertUnusedRows)
- New nullable columns only; posted invoices unchanged; E14 rounding
- Debit note aging and journal effect follow the observation

**اختبارات الإثبات:**
- SalePostingBuilderTest with adjustment and shipping fixtures
- Debit note journals tie to the customer ledger; description template tests
- Legacy descriptions unchanged

### WP35: Order-to-cash documents: sales orders, recurring invoices, recurring expenses

**لماذا هنا:** No sales order, subscription or expense recurrence exists; Daftra's generation timing and document behaviour were deliberately not observed (S-34 creates future documents).

**ثوابت لا تُكسر:**
- Generated documents follow the normal posting path and numbering
- Generation is idempotent per schedule and date
- No posting for orders until invoiced

**اختبارات الإثبات:**
- Generator command test with repeated runs and a closed period
- Conversion tests; branch scope

### WP36: Pricing and credit controls: price lists, minimum price, maximum discount, excluded categories, credit limit, preview and profit tab

**لماذا هنا:** Only the settings were seen in Daftra. Credit limit is a financial control (needs open amounts from the customer ledger and, for the period, due dates from WP13).

**ثوابت لا تُكسر:**
- Credit-limit check uses CustomerLedger open amounts inside the save transaction
- Price controls never change posted documents
- Permission to override is explicit

**اختبارات الإثبات:**
- SalesControllerTest for each control
- Credit-limit race test (two real connections)
- Item model tests for minimum price, discount and margin

### WP37: Product and customer master data

**لماذا هنا:** Field-level gaps in items, warehouses, categories, barcodes and customers; mostly schema and form work with the field lists already observed.

**ثوابت لا تُكسر:**
- Additive columns only; existing items and customers unchanged
- Warehouse status and primary flag never orphan stock or sub-accounts
- Customer type changes do not alter posted documents

**اختبارات الإثبات:**
- ItemsControllerTest/CustomersControllerTest field tests
- Warehouse model tests; migration up/down tests

### WP38: Cost-centre distribution, journal settings, shipping-company accounts

**لماذا هنا:** Cost centres exist one per journal line; Daftra distributes by percentage with over-100% and mandatory settings, tags, tax on lines and a cost-centre report. Rounding and messages were never observed.

**ثوابت لا تُكسر:**
- Distribution sums to 100% (or the observed allowance) and never changes debit or credit
- Existing single-centre lines unchanged

**اختبارات الإثبات:**
- CostCenterServiceTest distribution and rounding
- JournalServiceTest line tax and tags storage

### WP39: Purchase cycle documents: purchase request, quotation request, quotation, purchase order

**لماذا هنا:** None of the four documents or their manual statuses exists; only a settings list was seen in Daftra.

**ثوابت لا تُكسر:**
- No journal or stock until a purchase invoice is saved
- Numbering from WP8; conversion is explicit

**اختبارات الإثبات:**
- Document lifecycle and conversion tests; branch scope

### WP40: Templates, print outputs and extra fields

**لماذا هنا:** Fixed templates exist; Daftra has a template designer, vouchers, labels, copy invoice and custom invoice fields. Print layouts were never read.

**ثوابت لا تُكسر:**
- Printed documents never change posted figures
- Custom fields do not affect journals

**اختبارات الإثبات:**
- Document rendering tests; PdfLib tests
- Custom-field storage tests

### WP41: Integration settings: payment gateways and methods, secondary language, auto-send rules, files and API keys

**لماذا هنا:** Manual payment types with routing exist; gateways, entity second language, rules engine and API keys do not. E47 forbids paid external integrations without permission.

**ثوابت لا تُكسر:**
- Gateway payments post only on completed status through the existing payment journal path
- API keys are scoped and never logged
- No paid external integration without separate permission

**اختبارات الإثبات:**
- Payment-status tests for gateway results
- Rules-engine tests
- API authentication tests

### WP42: Multiple units, bundles and batch, serial and expiry tracking

**لماذا هنا:** Single unit with pack conversion, kits that deduct components, serial numbers only. High accounting risk: quantities, cost per unit and valued movements all become unit-aware, and Daftra behaviour was seen only as settings.

**ثوابت لا تُكسر:**
- Valued movements and StockLedger::reconcile stay exact in base units
- Historical movements keep their quantities and costs
- Bundle cost and journals follow the observation

**اختبارات الإثبات:**
- StockLedgerTest with unit conversion
- ReceivingPostingTest and SalePostingTest with batches
- Legacy items default to one unit

### WP43: Purchase received-state and draft (decision-gated)

**لماذا هنا:** Daftra books an unreceived purchase to 1210 and moves it to inventory in place; the owner declined it (E27) because it conflicts with E15 (reverse and repost). Do it last and only on the owner's word; behaviour is already evidenced (S-13).

**ثوابت لا تُكسر:**
- In-place Daftra behaviour is emulated by reverse and repost with a new revision, never by updating a posted journal (E15)
- Unreceived purchases move no stock; stock and journal move together on confirm
- Draft re-issue takes a new number

**اختبارات الإثبات:**
- ReceivingPostingTest: unreceive, confirm, draft, reissue
- StockLedger::reconcile through each state; closed periods; legacy receivings default to received

### WP44: Report export, reports-hub link, phone layout and review polish

**لماذا هنا:** Usability slice with no Daftra access needed: server-side PDF and Excel export that re-runs the report with branch scope (the client-posted table export would bypass it), a hub entry, stacked cards on phones and the leftover review findings. Lowest priority, schedulable in spare capacity while credentials are missing.

**ثوابت لا تُكسر:**
- Export re-runs the report with the employee's branch scope and the same balanced totals
- No change to report figures

**اختبارات الإثبات:**
- ExportsControllerTest and AccountingReportsControllerTest for the new export
- ui-smoke three sizes without horizontal scroll
- Arabic-locale render test

### WP45: Performance measurements, account picker and residual hardening

**لماذا هنا:** Maintenance slice: a search picker before activation on a business with many customers, benchmarks for the sale save with posting and the new reports, the sale-level oversell test, documenting accepted differences and syncing stale docs. No Daftra access needed except the INV-07 blocking settings.

**ثوابت لا تُكسر:**
- No behaviour change; measurements are recorded, not asserted against production
- Documented differences are listed as intentional

**اختبارات الإثبات:**
- Benchmark tests in tools/benchmarks for Sale::save_value with posting, settlement with thousands of open documents, opening-stock plan, trial balance on large journals
- SalePostingTest: sell 20 with 8 on hand saves, stock -12, cost journal 20 x cost
- AccountingJournalsControllerTest picker uses search

### WP46: E-invoicing (ZATCA phase 2)

**لماذا هنا:** Only reserved columns exist. Requirements must be fixed from the official specification when the phase starts, not from Daftra.

**ثوابت لا تُكسر:**
- Issued invoices are corrected by notes, never edited (D7)
- Signing keys never stored in the repository

**اختبارات الإثبات:**
- Document-level field tests; signing and submission against the official sandbox

### WP47: Fixed assets and depreciation

**لماذا هنا:** Only chart accounts exist. Deferred by D8; depreciation journals were never observed.

**ثوابت لا تُكسر:**
- Depreciation journals are balanced, idempotent per period and respect closed periods
- Historical journals untouched

**اختبارات الإثبات:**
- Depreciation run test with reversal and closed period

### WP48: AI agent (proposals as drafts, anomaly detection, accounting_ai permission)

**لماذا هنا:** Only the proposals table and the origin enum exist; design fixed by D9.

**ثوابت لا تُكسر:**
- Proposes only, human approval posts; every action audited; works with the agent disabled
- No paid external model without separate permission (E47)

**اختبارات الإثبات:**
- Proposal flow, permission and audit tests; kill switch test

### WP49: Contact list and customer portal (owner scope decision)

**لماذا هنا:** CRM-scope contact list and a portal that needs customer authentication; the portal is a security-sensitive new surface and both were only seen as navigation or one setting.

**ثوابت لا تُكسر:**
- Portal sees only the customer's own documents
- No journal effect

**اختبارات الإثبات:**
- Authentication, scoping and permission tests

### WP50: Umbrella rows (no own work; close when the children close)

**لماذا هنا:** Tracking rows from the docs that aggregate other items; each is closed by the packages named in dependsOn. A8 uniquely adds the Chart.js presentation of the financial reports and the Assets = Liabilities + Equity acceptance gate.

**ثوابت لا تُكسر:**
- Close only when every child item is closed

**اختبارات الإثبات:**
- A8 equation gate test already covered by WP4

## 3. البنود (الكل مرة واحدة)

| المعرّف | العنوان | الحزمة | الحالة | دفترة | الخطورة |
|---|---|---|---|:-:|---|
| P6-SENSITIVE-PERMS | Treasury and payables manager permissions are documented as sensitive but are not in the sensitive list | WP1 | open | لا | medium |
| A2A-STOCK-ACCESS | Items and stock-adjustment screens ignore the employee's branch scope | WP1 | open | لا | medium |
| G-48 | Operational reports are not restricted by the employee's branch | WP1 | open | لا | medium |
| G41-A5 | Transfer destination location is not checked against location grants | WP1 | open | لا | medium |
| P5-LOCK-POOLING | Customer and supplier locks rely on a named lock held by one database connection | WP1 | open | لا | medium |
| E38-SCAN | Scan every target database before LegacyFixedPurchaseDiscount runs | WP2 | blocked-needs-owner-credentials | لا | high |
| E40-BANK-DETAILS | Run accounting:bank_accounts before and after the bank-account migration and complete missing bank details | WP2 | blocked-needs-owner-credentials | لا | medium |
| E41-STAMP | Dry-run then apply accounting:stamp_payments on each database before E41 is deployed | WP2 | blocked-needs-owner-credentials | لا | medium |
| OWNER-GRANTS | New accounting modules and sensitive permissions are registered but granted to nobody | WP2 | blocked-needs-owner-credentials | لا | medium |
| E26-ACTIVATION | Production activation of accounting and opening-stock posting (owner-ordered) | WP2 | blocked-needs-owner-credentials | لا | high |
| FIN-05 | Expense categories have no account link and there is no revenue category screen | WP3 | partial | لا | medium |
| FIN-02 | Expenses never reach the ledger | WP3 | partial | لا | high |
| FIN-04 | Mandatory expense and revenue account settings | WP3 | open | لا | low |
| A4 | Cash-up (cash register closing) never posts to the ledger | WP3 | open | لا | high |
| Y-DEC | Record decisions Y1-Y9 and re-base year-end-policy.md on the current chart | WP4 | open | لا | low |
| REP-03 | Income statement over the journals | WP4 | open | لا | medium |
| REP-04 | Balance sheet with the dynamic result of unclosed years | WP4 | open | لا | medium |
| Y8 | Caption 'cumulative balance since the start' on the chart-of-accounts screen | WP4 | open | لا | low |
| REP-07 | Tax report and VAT return from the ledger (215001, 215002, returns, cash and accrual filter) | WP5 | partial | نعم | medium |
| YE-01 | Year-end closing entry per branch with preview, approval and reversal | WP6 | open | نعم | high |
| Y5 | Sensitive permission acc_year_end_close and guards (reopen blocked while a closing entry stands) | WP6 | open | لا | high |
| YE-03 | Report treatment of year_end entries (before/after option, ledger tag, comparison and income-statement exclusion) | WP6 | open | لا | medium |
| G-61 | Inter-branch: who owes whom between two specific branches (transfers themselves are done) | WP7 | partial | لا | medium |
| INTERBRANCH-SETTLEMENT | Settlement of inter-branch balances (who pays whom and when) | WP7 | open | لا | high |
| P6-ASSUMPTION-7 | Payment recorded in one branch into another branch's treasury | WP7 | open | لا | high |
| G-08 | Per-document numbering with an editable next number | WP8 | partial | نعم | low |
| CUS-02 | Automatic customer numbering | WP8 | open | لا | low |
| G-34 | Customer payment and credit-note documents with own numbering, list screens and collected-by | WP9 | partial | لا | low |
| SAL-19 | Refund invoice as a second return document | WP9 | open | لا | low |
| G-28 | Journal order of a paid-on-creation sale: invoice, payment, then cost | WP9 | open | لا | low |
| SAL-29 | Editing an issued document: non-financial fields and payment edits | WP9 | partial | لا | low |
| G-51 | Supplier payment and debit-note documents: list screens, numbering, entered date, paid-by | WP10 | partial | لا | low |
| G-57 | Opening-stock cost correction: explicit re-post at current item cost | WP11 | open | لا | high |
| E26-NEG-LINES | Owner decision on posting negative or excess-movement items as negative opening lines | WP11 | partial | لا | medium |
| E26-LIMITS | Known limits of opening-stock posting | WP11 | partial | لا | medium |
| CUS-03 | Customer opening balance | WP11 | open | نعم | high |
| A6 | Retroactive posting (backfill) with dry-run preview, opening balances and approve or cancel | WP12 | open | لا | high |
| G-27 | Invoice date separate from issue time, with permission to edit it | WP13 | open | لا | medium |
| G-31 | Customer due date, payment terms, due and overdue states | WP13 | open | لا | low |
| G-53 | Supplier aging by due date, six buckets and Daftra balance columns | WP13 | open | لا | low |
| CUS-08 | Customer balances report with Daftra period columns | WP13 | partial | لا | low |
| CUS-09 | Debt aging by invoices and by ledger | WP13 | partial | نعم | low |
| G-14 | Document and payment statuses: due, overdue, draft, quote lifecycle | WP13 | partial | لا | low |
| SAL-13 | Draft invoice lifecycle: temporary number, issue link, typed date | WP14 | partial | لا | medium |
| SAL-21 | Quotations: states and conversion to invoice | WP14 | partial | لا | low |
| G-59 | Discount on the whole purchase invoice (E33) | WP15 | open | لا | medium |
| SAL-07 | Invoice-level discount on sales (percent or fixed) and its effect on tax and journal | WP16 | open | لا | high |
| G-37 | Editing the lines of a saved receiving | WP17 | open | لا | medium |
| G-38 | Item purchase taxes (two purchase taxes per item) | WP17 | open | لا | medium |
| PUR-10 | Supplier commercial-registry field | WP17 | partial | لا | low |
| G-45 | Inventory value report from valued movements | WP18 | open | لا | low |
| ACC-16 | Edit description and cost centre of an automatic journal | WP19 | open | لا | medium |
| ACC-18 | Manual journal: attachments, required line description, line tax, currency, import | WP19 | partial | لا | medium |
| R-02 | Daftra read: filtered reports, cash flow, cash and accrual profit and loss, tax return, directories | WP20 | blocked-needs-owner-credentials | نعم | low |
| R-03 | Daftra read: add forms (sales order, subscription, treasury transfer, debit note, purchase order, price list, edit forms) | WP20 | blocked-needs-owner-credentials | نعم | low |
| R-04 | Daftra read: permission matrix of the two existing roles (edit without saving) | WP20 | blocked-needs-owner-credentials | نعم | low |
| R-05 | Daftra read: print and PDF outputs with headers (invoice, receipt, journal, statements) | WP20 | blocked-needs-owner-credentials | نعم | low |
| Y9 | Daftra read: year-end closing behaviour (closing entry, retained earnings, reopen after close) | WP20 | blocked-needs-owner-credentials | نعم | low |
| E12-PKG-C | Daftra package C: closed periods and validation messages (S-15, S-17) | WP21 | blocked-needs-owner-credentials | نعم | low |
| E12-PKG-D | Daftra package D: settings-changing scenarios (S-03, S-26, S-28, S-29, S-32, S-33, S-16, S-30) | WP21 | blocked-needs-owner-credentials | نعم | low |
| ACC-19 | Journal numbering toggle: behaviour when independent numbering is off | WP21 | blocked-needs-owner-credentials | نعم | low |
| SAL-05 | Tax-inclusive price decomposition parity | WP21 | blocked-needs-owner-credentials | نعم | medium |
| G-49 | Settle button on a purchase paid in excess (F-04c) | WP22 | blocked-needs-owner-credentials | نعم | low |
| G-20 | Customer credit auto-application: oldest-first by date or number not observed | WP22 | partial | نعم | low |
| G-55 | Supplier credit application order: purchase date versus document number | WP22 | partial | نعم | low |
| G-35 | Payment state 'unpaid, partially returned': when Daftra creates it | WP22 | blocked-needs-owner-credentials | نعم | low |
| G-23 | Sale without a customer: confirm Daftra behaviour | WP22 | partial | نعم | low |
| G-26 | Sales return quantity cap not observed in Daftra | WP22 | partial | نعم | low |
| G-43 | Average cost after deleting a receiving and after a transfer not observed | WP22 | partial | نعم | low |
| G-19 | Rounding rule: two exact half-cent cases still differ by one cent | WP22 | partial | نعم | medium |
| G-66 | Standalone supplier payment and supplier opening balance: Daftra journals not yet observed | WP22 | partial | نعم | medium |
| S35-T06 | S-35 T-06 (b), (c), (d): empty then disable, delete a treasury with movement, delete one without | WP22 | blocked-needs-owner-credentials | نعم | low |
| S35-D4 | Effect of deposit and withdraw scopes with a second restricted employee | WP22 | blocked-needs-owner-credentials | نعم | medium |
| S35-RESIDUE | S-35 leftovers in the Daftra trial account | WP22 | blocked-needs-owner-credentials | لا | low |
| D1D4-ACCOUNT-NUMBER-RULE | Bank account number validation rule is an assumption | WP22 | partial | نعم | low |
| P3-EVIDENCE-GAPS | Incomplete Daftra evidence behind fixtures: S-04, S-08, S-31 amounts, S-21 numbers, journal description text, T-01 purchase eviden | WP22 | partial | نعم | low |
| PUR-08 | Update product prices from purchase (update_product_prices setting) | WP22 | blocked-needs-owner-credentials | نعم | medium |
| SAL-37 | Overpayment banner and settle action on a sales invoice | WP22 | partial | نعم | low |
| G-32 | Customer advance payment without an invoice | WP23 | open | نعم | high |
| G-21 | Return of stock items without an original sale: manager override | WP23 | partial | نعم | medium |
| G-30 | Credit note on an invoice whose goods were not delivered | WP23 | partial | نعم | medium |
| P5-CUSTOMER-CASH-REFUND | Cash refund of a customer's credit balance from a treasury | WP23 | open | نعم | medium |
| G-52 | Cash refund of a standing supplier credit | WP23 | open | نعم | medium |
| G-02 | Per-item and main-per-entity routing types do not drive journals | WP24 | partial | نعم | high |
| SAL-04 | Per-tax included or excluded option on the tax table | WP25 | partial | نعم | high |
| SAL-33 | Default invoice tax mode (match product, none, fixed) and mandatory tax 1 | WP25 | partial | نعم | medium |
| G-64 | Cheques: documents, statuses, due dates, notes receivable and payable, two cheque reports | WP26 | open | نعم | high |
| FIN-03 | Revenue and receipt voucher | WP26 | open | نعم | high |
| ACC-17 | Journal types and sources for the unbuilt documents | WP26 | partial | لا | low |
| S35-D6 | Payment method independent of the chosen treasury (Daftra D6) | WP26 | blocked-needs-owner-credentials | نعم | medium |
| G-41 | Numbered stock permit document (add, issue, transfer) | WP27 | partial | نعم | medium |
| G-42 | Stocktake document and its accounts | WP27 | partial | نعم | medium |
| INV-12 | Stock requests | WP27 | open | نعم | medium |
| G-12 | Multi-currency: invoice currency, exchange rate and FX difference entries | WP28 | open | نعم | high |
| G-25 | Currencies with decimals other than two (tests and behaviour) | WP28 | open | نعم | medium |
| G-63 | Treasury and bank currency (bank placement under 1202 is done) | WP28 | partial | نعم | high |
| E47-BANKREC | Bank reconciliation | WP29 | open | نعم | medium |
| D1D4-BANK-LIST | Bank name picked from a bank-search list and bank linking | WP29 | open | نعم | low |
| G-09 | Roles and own-versus-all record scope for every document | WP30 | open | نعم | medium |
| INV-09 | Default warehouse per employee and employee profile documents | WP30 | partial | نعم | low |
| REP-01 | Trial balance: Daftra filter set, column layout and parity run | WP31 | partial | نعم | low |
| P8-TB3 | Third trial-balance type 'review account' matching Daftra | WP31 | open | نعم | low |
| REP-02 | Account statement: multi-account grouping, creator filter, Daftra print template | WP31 | partial | نعم | low |
| P8-PRINT | Print header and layout for the financial statements | WP31 | partial | نعم | low |
| REP-05 | Profit and loss, cash and accrual basis, monthly, quarterly, yearly | WP31 | open | نعم | medium |
| REP-06 | Cash flow statement | WP31 | open | نعم | medium |
| CHK-B | Design summary ui/a8-design.md and owner approval before further report UI | WP31 | open | نعم | low |
| REP-09 | Sales reports by customer, entry user and rep with daily, weekly, monthly, yearly variants | WP32 | partial | نعم | low |
| REP-10 | Item sales reports by item, category, brand, entry user, rep and customer | WP32 | partial | نعم | low |
| REP-11 | Payment reports (customer, entry user, method, period) and advance-payments report | WP32 | partial | نعم | low |
| REP-12 | Profit reports (daily, weekly, yearly) from stock movements | WP32 | partial | نعم | low |
| REP-16 | Net invoice position and invoice-versus-returns reconciliation reports | WP32 | open | نعم | low |
| REP-13 | Purchase and supplier reports (by supplier, user, product, directory, balances, aging, payments) | WP33 | partial | نعم | low |
| REP-14 | Inventory reports: count sheet, operations summary, movements, warehouse and product balance, aging, turnover | WP33 | partial | نعم | medium |
| REP-15 | Split expense and income reports (eight variants each) | WP33 | partial | نعم | low |
| REP-08 | Journal movements, chart-of-accounts and cost-centre reports | WP33 | partial | نعم | low |
| REP-17 | Account activity log beyond the accounting audit log | WP33 | partial | نعم | low |
| SAL-08 | Invoice adjustment (label and value) and its routing | WP34 | open | نعم | medium |
| SAL-09 | Shipping charge and shipping options on the invoice | WP34 | open | نعم | medium |
| SAL-20 | Sales debit note | WP34 | open | نعم | high |
| SAL-34 | Custom journal description template for sales and purchases | WP34 | open | نعم | low |
| SAL-22 | Sales orders (optional) and over-quantity allowance | WP35 | open | نعم | low |
| SAL-23 | Recurring invoices (subscriptions) | WP35 | open | نعم | medium |
| FIN-06 | Recurring expenses | WP35 | open | نعم | medium |
| SAL-24 | Price lists and changing the list on an invoice | WP36 | open | نعم | low |
| SAL-25 | Minimum price, last sale price, selling below average cost | WP36 | open | نعم | low |
| SAL-26 | Maximum discount percentage | WP36 | open | نعم | low |
| SAL-27 | Blocking sale of excluded product categories | WP36 | open | نعم | low |
| CUS-04 | Credit limit and credit period | WP36 | open | نعم | low |
| INV-02 | Item pricing: minimum price, default discount, margin (purchase taxes are G-38) | WP36 | partial | لا | medium |
| SAL-32 | Invoice preview before saving and profit tab | WP36 | open | نعم | low |
| INV-01 | Product data: auto SKU, supplier code, images | WP37 | partial | لا | low |
| INV-03 | Stock tracking, opening quantity and product status | WP37 | partial | نعم | low |
| INV-08 | Warehouses: status, primary flag, shipping address, role or branch permissions | WP37 | partial | لا | low |
| INV-16 | Multi-level categories and brands | WP37 | open | نعم | low |
| INV-17 | Barcode: weight-embedded codes and line-merge setting | WP37 | partial | نعم | low |
| CUS-01 | Customer type (individual, commercial, both) | WP37 | partial | نعم | low |
| CUS-05 | Optional customer fields (photo, ID, birthdate, barcode, addresses, map, categories, custom fields) | WP37 | partial | لا | low |
| ACC-20 | Journal settings: tax in journals, cost-centre options, tags, conversion factor | WP38 | open | نعم | low |
| ACC-21 | Cost centres: distribution by percentage, over-100% and mandatory settings, report | WP38 | partial | نعم | medium |
| ACC-05 | Automatic sub-accounts for shipping companies | WP38 | partial | نعم | low |
| PUR-07 | Purchase cycle: purchase request, quotation request, quotation, purchase order | WP39 | open | نعم | low |
| SET-06 | Template designer for print, invoice, email and terms | WP40 | partial | نعم | low |
| SAL-31 | Print, send and copy outputs (vouchers, parcel and shipping labels, receiving list, copy invoice) | WP40 | partial | نعم | low |
| SAL-35 | Extra invoice fields, line notes and order sources | WP40 | partial | نعم | low |
| SET-04 | Payment methods and online payment gateways | WP41 | partial | نعم | low |
| SET-07 | Secondary language for entities (secondary_language_enabled) | WP41 | open | نعم | low |
| SET-08 | Automatic send rules and alerts | WP41 | open | نعم | low |
| SET-09 | Documents and files library and public API keys | WP41 | open | نعم | low |
| INV-04 | Multiple units and unit templates | WP42 | open | نعم | high |
| INV-05 | Bundles: pre-assembled or on-the-fly modes | WP42 | partial | نعم | medium |
| INV-06 | Tracking by serial, batch and expiry | WP42 | partial | نعم | high |
| G-36 | Purchase receiving status: not received versus received, and draft purchase | WP43 | open | لا | high |
| P8-EXPORT | Server-side PDF and Excel export of the trial balance and account statement | WP44 | open | لا | low |
| README-HUB | Link the accounting reports from the Reports hub | WP44 | open | لا | low |
| P8-MOBILE | Phone layout of the trial balance and account statement tables | WP44 | open | لا | low |
| P8-MINOR | Residual minor review findings of P8 slice 1 | WP44 | open | لا | low |
| G41-A4 | Receiving screen does not show each location's branch in the location list | WP44 | partial | لا | low |
| P1-PICKER | Account pickers load every account (search picker before activating on large businesses) | WP45 | partial | لا | low |
| PERF-LOAD-UNMEASURED | Performance under load never measured | WP45 | open | لا | medium |
| INV-07 | Selling more than available: end-to-end behaviour and blocking options | WP45 | partial | نعم | medium |
| G-56 | Supplier payment methods accepted: residual after observation | WP45 | partial | لا | low |
| E32-RETURN-ROUNDING | Return share of an old per-line fixed discount is rounded to two decimals | WP45 | partial | لا | low |
| E22E32-BROWSER | Browser checks not done for editing a saved document's line discount | WP45 | partial | لا | low |
| CHK-F | Bring README status and counts up to date after P8 and E43-E47 | WP45 | open | لا | low |
| SET-10 | E-invoicing (ZATCA phase 2): integration, settings, submission report, government-entity fields | WP46 | open | لا | high |
| FIN-08 | Fixed assets and depreciation | WP47 | open | نعم | high |
| A9 | AI agent: proposals as drafts, anomaly detection, summaries, accounting_ai permission | WP48 | open | لا | medium |
| CUS-06 | Contact list screen | WP49 | open | نعم | low |
| CUS-10 | Customer portal and its permission setting | WP49 | open | نعم | medium |
| G-13 | Documents with no counterpart (umbrella of 56 matrix rows) | WP50 | partial | لا | medium |
| P7 | Advanced commercial and inventory features (umbrella) | WP50 | open | لا | medium |
| P9 | Settings and numbering (umbrella): document numbering, inclusive taxes, gateways, templates, auto-send, extra fields, second langu | WP50 | open | لا | medium |
| A8 | Phase A8 umbrella: financial reports, Chart.js and PDF, balance-sheet equation gate | WP50 | partial | لا | medium |
| PUR-01 | Purchase invoice (umbrella) | WP50 | partial | لا | medium |

## 4. أُسقط لأنه منفَّذ فعلًا (بدليله في الكود)

- G-01 (hybrid entity sub-accounts): EntityAccountService.php covers customer, supplier, stock_location and treasury; Customer.php:235 calls sync on save; fill_accounts command exists
- G-03 (separate cost journal): entry_type revenue/cogs in the unique key; SalePosting::postCost posts it in the sale transaction
- G-07 and G-10 (input tax under liabilities, Daftra default chart): DefaultChart.php adopts the Daftra chart incl. 215001/215002; DefaultChartTest
- G-11 and ACC-25 (accounting on/off switch): AccountingSettings.php accounting_enabled; intentional difference D1
- G-15 (edit and delete model): decided by E15 (reverse and repost); only the edit screen remains, tracked as G-37
- G-16, G-24, G-47, G-58 (cost and valuation precision): CostPrecision and ReceivingCostPrecision migrations widen to six decimals, T-11 and T-14 exact
- G-22 (cancel of issued invoice): SalePosting.php:164 throws issued_document_locked; E21; correction path is G-30
- G-29 and SAL-40 (delete customer payment, unwind of spent credit): intentional differences E15 and E25, T-10 case stays 'differs' by decision
- G-33 and G-54 (company-wide balances screens): intentional E24 and E31
- G-39 (purchase fixed discount per unit): E32 done, FIXED_LINE marks legacy lines (Constants.php:152); the whole-invoice part is G-59
- G-50 (supplier payment deletion unwind): matches Daftra (T-19, E30)
- G-60 (credit timing of a debit note): intentional E34, T-17 stays 'differs' by decision
- G-44 (opening stock for pre-existing inventory): OpeningStock.php implements plan, post, reverse; only production activation remains (E26-ACTIVATION)
- G-62 (treasury deposit and withdraw scopes): TreasuryScopes.php enforced on every money path; residuals are the roles scope (G-09) and the interpretation check (S35-D4)
- G-65 (treasury selection at the register): Sales.php:1597-1615 registerTreasury, carried through suspended sales and posting
- G-61 and G-41 transfer parts (money and stock between branches): TreasuryTransfers.php, migrations 20261102/20261103, InterBranchPosition.php; residuals kept as G-61 (pairwise), P6-ASSUMPTION-7, INTERBRANCH-SETTLEMENT and G-41 (permit document)
- CUS-07 (per-customer sub-account and statement): EntityAccountService, CustomerLedger, AccountingReceivables::getCustomer; the matrix cell is stale
- ACC-32 (editable automatic journal number): intentionally not copied (E16); no number-edit method in JournalService.php
- ACC-33 (reverse a journal): JournalService::reverse() links to the original, required reason and date
- PUR-11 (auto-settlement from supplier credit): SupplierSettlement.php; E29, E30, E34 are owner-approved differences; only the settle-button observation remains (G-49)
- R-01 and R-07 (Daftra reads of the chart and of the branches and cheques modules): executed; R-06 (routing option lists): read in rtP2 (ev_8c1f06a3...), the scenario table still says pending
- Matrix rows already 'exact and tested': SAL-01, SAL-02, SAL-03, SAL-06, SAL-28, SAL-30, SAL-41, INV-13, INV-14, INV-18, PUR-02, ACC-01, ACC-02, ACC-03, ACC-04, ACC-06, ACC-07, ACC-08, ACC-10, ACC-11, ACC-13, ACC-15

## 5. بنود خطرة على البيانات التاريخية وصورتها الآمنة

Items risky for historical data, with the safe dev-only form of each. (1) E33 / G-59 (whole-purchase discount) and its sales twin SAL-07: the E32 incident shows that reinterpreting a stored column silently changes old documents (LegacyFixedPurchaseDiscount had to mark them). Safe form: additive nullable header columns defaulting to 0 or null; a golden test that every existing fixture (T-11 to T-19, T-06 to T-10 for sales) produces byte-identical journals when the discount is empty; no recalculation, re-post or backfill of existing receivings or invoices; issued sales stay locked (E21); owner approval of E33 first; development data only. (2) G-57 (explicit re-post of opening stock at current item cost): re-pricing the opening journal changes the base of the weighted average and breaks StockLedger::reconcile for later movements and COGS. Safe form: per-location explicit option, default off, with a reason and a before/after plan; refused when any later movement exists at the location or the period is closed; old revision stays reversed and visible; never a batch or catalogue-wide reprice; development data only, production only by separate owner permission (E47). Also historical-risk and handled the same way: E38-SCAN (the legacy fixed-discount migration rewrites discount_type and its down() changes meaning, so scan first, back up, rollback by restore only); A6 (retroactive posting: preview journals excluded from all balances, dry run only on dev data); CUS-03 and E26-NEG-LINES / E26-LIMITS (opening balances: reverse-and-repost only, triple tie, provisional); YE-01 (closing entries: preview, reversal, never on production without permission); E26-ACTIVATION, E40-BANK-DETAILS, E41-STAMP (owner-run operations on real databases); G-27, G-31, G-08 (new nullable date and number columns whose defaults reproduce current aging and numbering; existing documents are never renumbered or redated); G-37 and G-36 (edit or unreceive of posted receivings must go through reverse-and-repost and must not reprice consumed cost); G-28 (journal-order swap applies to new journals only, historical journal numbers are not rewritten); G-12 and G-63 (base-currency journals unchanged, rate 1).

## 6. ملاحظات المُركِّب

Method and verification. Source: five analyst lists merged by meaning; all aliases are kept in the item's aliases field and repeated as '[aliases: ...]' at the start of the summary. 168 backlog items are listed, each exactly once, plus the dropped list; 50 packages. Items I re-verified against code (grep/read): G-28 journal order (SalePosting.php:81-86), G-57 (OpeningStock.php:201,207), G-48 (no 'branch' anywhere in Models/Reports, Controllers/Reports.php, report_helper.php), G-45/INV-15 (StockLedger/StockPosition referenced only inside app/Libraries/Accounting), P1-PICKER (AccountService::all() at AccountingJournals.php:40 and AccountingChart.php:91,203,245,270), expense and cash-up posting absent (no Accounting reference in Expense/Expenses/Cashup/Cashups), due_date/payment_term/purchase_tax/credit_limit/price_list/recurring/sales_order/invoice_discount absent, income statement/balance sheet/cash flow/year_end absent, backfill = schema only, cheques = payment-method label only, per-item and main-per-entity routing refused (RoutingType.php:28-32), customer opening balance blocked (OpeningBalanceService CONTROL_KEYS), no refund path in SupplierAdvances, no journal-metadata edit in JournalService, no description template, no tests using totals_decimals, no sale-level oversell test. Two NEW findings not in any analyst list: P6-SENSITIVE-PERMS (docs call acc_treasuries_manage sensitive but AccountingGuard::SENSITIVE omits it and acc_payables_manage, so Employees.php:180 lets non-admins grant them) and A2A-STOCK-ACCESS (Items screens and StockAdjustment ignore the employee's branch scope; only Sales and Receivings enforce it). Both are in WP1. Conflicts resolved: G-62, G-61, G-41, G-65, G-44 were 'done' in one analyst list and 'partial' in another: the done parts are dropped and the genuine residuals kept under their own ids (G-61 pairwise, P6-ASSUMPTION-7, INTERBRANCH-SETTLEMENT, G-41 permit document, G-09 roles scope, S35-D4). 'A7' is used in the docs both for the vouchers-and-cheques phase (alias of G-64) and for the pairwise inter-branch balance in g61-report.md (alias of G-61); G-45 versus INV-15 disagreed on whether aging and turnover layouts were observed: valuation was (repK, 1,720), aging and turnover layouts are treated as unobserved and left to REP-14; SAL-05 was 'partial' in code but 'blocked' for proof, kept as blocked because only S-03 can settle it; matrix text is stale for ACC-28 (15 acc_* permissions exist), CUS-07, FIN-01, ACC-05, PUR-01 (CHK-F). Ordering: WP1-WP19 need no Daftra access and are executable now (correctness, then integrity and auditability, then commercial-flow soundness); WP20-WP29 are the Daftra observation batches and the Daftra-dependent correctness builds (credentials are absent, E47); WP30-WP43 are architecture-fit and parity work; WP44-WP50 are lowest priority (polish, performance, deferred domains, umbrellas). WP44 and WP45 need no Daftra access and can be scheduled in spare capacity while credentials are missing even though they rank last. Owner-gated items: E33 is still not approved (WP15), the cash-up treatment needs an owner decision (A4), the unreceived-purchase state was declined by E27 (WP43), and any production activation, merge to master, deploy or production data change needs separate permission (E47).
