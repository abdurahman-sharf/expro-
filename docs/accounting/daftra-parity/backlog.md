# جرد الأعمال المتبقية وحزم التنفيذ (E47)

> التاريخ: 2026-10-26. **مصدره:** جرد آلي بخمسة محلّلين للقراءة فقط (الخطة والفجوات والمصفوفة والقرارات والتقارير) ثم مُركِّب أعاد التحقق من كل بند بالكود (`grep` و`read`) وأسقط ما هو منفَّذ فعلًا. **الترتيب:** (1) الصحة المحاسبية والمالية، (2) سلامة البيانات وقابلية التدقيق، (3) سلامة سير العمل التجاري، (4) التوافق مع البنية، (5) المطابقة المثبتة مع دفترة، (6) الاستخدام والصيانة والأداء. **الحزم WP1 إلى WP19 لا تحتاج رصدًا في دفترة**؛ WP20 إلى WP29 رصد في دفترة وبناء يعتمد عليه؛ WP30 إلى WP43 ملاءمة بنية ومطابقة؛ WP44 إلى WP50 الأدنى أولوية. **قيود باقية (E47):** لا دمج في `master` ولا نشر ولا تغيير بيانات إنتاج، ولا إعادة تسعير تاريخية ولا إعادة كتابة قيود قائمة في قاعدة إنتاجية دون إذن منفصل، ونفسه `E33` (خصم الفاتورة الكاملة للمشتريات) يُبنى على بيانات التطوير وتجارب دفترة فقط.

> **حالة التنفيذ (2026-10-27):** سُلِّمت **الموجة 1 (WP1 وWP3 وWP8 وWP16 وWP19)** ودُمجت في فرع التطوير بتفويض E47 ([E48 إلى E52](../decisions.md))، **ولا دمج في `master` ولا نشر**. الحالة `done (WPx)` في جدول الحزم وقائمة البنود، وشُطبت اعتماداتها المستوفاة (`~~WPx~~`)، وحُمل ما بقي من تقاريرها إلى حزم لاحقة كبنود جديدة (`G-67` إلى `G-81` وما يليها في القسم 3). **ملاحظتان جديدتان من الموجة:** (أ) المصروفات وإقفالات الصندوق القديمة بلا فرع ولا قيد (قائمة «غير المرحّل» في WP3)، والترحيل بأثر رجعي لها يبقى لدى WP12 (`G-79`)؛ (ب) استيراد CSV في `Items::postImportCsvFile` يضع `set_time_limit(240)` فيجب أن ترفعه أي دالة اختبار تستدعيه (`ITEMS-CSV-TIME-LIMIT`). **وأُخذ `T-25` لقيد مصروف WP3**، فتبدأ ملفات التثبيت الجديدة من `T-26` (صُحِّحت عبارة «T-25 onward» في WP20 وWP21 وWP22 وWP31).

> **حالة التنفيذ (2026-10-28):** سُلِّمت **WP4 وWP15 وWP18** ودُمجت في فرع التطوير بتفويض E47 ([E53 إلى E55](../decisions.md))، **ولا دمج في `master` ولا نشر**. الحالة `done (WPx)` في جدول الحزم وقائمة البنود، وشُطبت اعتماداتها المستوفاة (`~~WPx~~`)، وحُمل ما بقي من تقاريرها إلى حزم لاحقة كبنود جديدة (`G-82` إلى `G-93` وما يليها في القسم 3). **`Y-DEC` بقي `partial`:** أُعيدت صياغة سياسة نهاية السنة على الدليل الفعلي (`32` و`214`) لكن قرارات المالك Y1 إلى Y9 مفتوحة. **E33 نُفِّذ على بيانات التطوير بتفويض E47** (WP15) فلم يعد «غير معتمد» كما في الحالة السابقة، **ولا نشر في الإنتاج قبل إذن مستقل من المالك**. **تصحيح في الجرد:** «شجرة حتى المستوى السادس» في وصف WP4 خاطئة (أقصى مستوى في قائمة دفترة 5، تقرير WP4). **ملاحظتان جديدتان:** (أ) من WP15: عاملا اختبارات التزامن (`tests/Support/RaceWorker.php`) في شجرة عمل `vendor` فيها رابط رمزي يحمّلان `app/` من المستودع الرئيسي (`RACEWORKER-WORKTREE-AUTOLOAD`)؛ (ب) من WP18: تشغيل `tests/helpers/migration_helperTest.php` في العملية نفسها قبل اختبارات تستعمل قاعدة البيانات يُفشلها بجداول مفقودة (`MIGRATION-HELPERTEST-ORDER`).

> **حالة الرصد (2026-10-28):** دُمجت أدلة OBS-A (قراءة فقط، ست مناطق) وD1 وD2 وB (كتابة محكومة في الحساب التجريبي) في المصفوفة والفجوات والقرارات (E57 إلى E67) وهنا: **رُصدت متطلبات الرصد في WP13 وWP17 وWP23 (عدا كسر التعادل) وWP26 وWP30 وWP31 وWP32 وWP33 وWP37 وWP40، وجزئيًا في WP22 وWP28 وWP29**، وأضيفت أربعون فجوة `G-94` إلى `G-133` وأربعة بنود (`E57-CREDIT-ORDER` و`E58-LINE-EDIT-AVERAGE` و`PARITY-FIXTURES-OBS` و`W001-W004-CLEANUP`) وتغيّرت حالة 28 بندًا، وقواعد الرصد في [synthesis.md](observations/obs-a/synthesis.md). **الدفعة C (إعدادات عامة) تعمل الآن والدفعة E (فترات مقفلة وسنة مالية) في الطابور**؛ ما بقي غير مرصود وملخّص في [فهرس الرصد](observations/README.md)، وملفات التثبيت المقترحة `T-26` فما فوق في [fixture-candidates.md](fixture-candidates.md).

> **حالة التنفيذ (2026-10-28، WP7):** سُلِّمت **WP7** (التسوية بين الفروع والأرصدة الثنائية والدفع عبر الفروع) ودُمجت في فرع التطوير بتفويض E47 ([E56](../decisions.md))، **ولا دمج في `master` ولا نشر**. الحالة `done (WP7)` في جدول الحزم وقائمة البنود (`G-61` و`INTERBRANCH-SETTLEMENT` و`P6-ASSUMPTION-7`)، **ولا حزمة تعتمد على WP7 فلا اعتماد يُشطب**، وحُمل ما بقي من تقريرها إلى بنود جديدة (`G-134` إلى `G-139` و`WP7-PRE-DEPLOY-QUERY` و`WP7-BROWSER-PASS` و`WP7-LOCK-STRESS` و`WP7-PAIR-SUMMARY-TABLE` و`WP7-BALANCES-OVER-TIME` و`WP7-INACTIVE-BRANCH-BALANCE`) وإلى **حزمة جديدة WP51** تجمع ما ينتظر قرار المالك من امتدادات التسوية (التسوية الجزئية، وزوج لا يتفق طرفاه، وسجل لتحويلات المستقبل، وموقف الفرع المعطَّل). **الترحيلان** `20270200000000` و`20270200000001` إضافيان، والصلاحية الحساسة `acc_interbranch_settle` بلا منح.

## 1. الحزم

| الحزمة | الاسم | الحجم | تعتمد على | رصد دفترة | البنود |
|---|---|:-:|---|---|---|
| **WP1** — done (merged 2026-10-27) | Close control gaps: sensitive grants, branch scope on stock screens and legacy reports, destination-location grant, lock topology guard | M | — | none | P6-SENSITIVE-PERMS, A2A-STOCK-ACCESS, G-48, G41-A5, P5-LOCK-POOLING |
| **WP2** | Pre-deployment data checks on owner databases (owner-run) plus one read-only readiness command | M | ~~WP1~~ (done) | none (needs owner database access, not Daftra) | E38-SCAN, E40-BANK-DETAILS, E41-STAMP, OWNER-GRANTS, E26-ACTIVATION, E54-PRE-DEPLOY-BACKUP, WP7-PRE-DEPLOY-QUERY |
| **WP3** — done (merged 2026-10-27) | Expenses and cash-up posted to the ledger (categories to accounts, tax split, treasury, branch) | L | ~~WP1~~ (done) | none for expenses (S-25 observed); revenue vouchers (FIN-03) and recurrence (FIN-06) are separate packages; cash-up has no Daftra counterpart and n… **[2026-10-28: D2 observed the expense (amount stored with the tax, E61; account chosen per document; no due option; edit in place; delete erases; draft; several accounts): see G-77 closed, G-80 and G-118.]** | FIN-05, FIN-02, FIN-04, A4 |
| **WP4** — done (merged 2026-10-27; Y-DEC decisions stay with the owner) | Income statement and balance sheet with dynamic unclosed-years equity (policy re-based first) | L | ~~WP3~~ (done) | none **[2026-10-28: OBS-A read the income statement and balance sheet (signs by section, levels 1 to 5, display options): the scope additions below are small; the reference numbers stay in fixtures built from our own inputs.]** | Y-DEC, REP-03, REP-04, Y8 |
| **WP5** | Ledger-based tax report (accrual first) | M | ~~WP3~~ (done) | cash-basis filter and the return form layout (R-02 in WP20); accrual slice needs none **[2026-10-28: OBS-A read the tax report (seven sections, cash filter, detailed columns) and the return with its four tables; the settlement journal stays unobserved.]** | REP-07, G-98, G-99 |
| **WP6** | Year-end closing entry per branch with preview, approval, reversal and guards | XL | ~~WP4~~ (done) | Y9 (how Daftra closes a year and whether it reopens) as a non-blocking read before the screen; service and ledger work needs none **[2026-10-28: Y9 stays unobserved (batch E is queued); only general-source inference exists (G-129).]** | YE-01, Y5, YE-03, G-82, YEAR-END-STALE-ACCOUNT-REFS, G-129 |
| **WP7** — done (merged 2026-10-28) | Inter-branch settlement, pairwise balances and cross-branch payments | L | ~~WP1~~ (done) | none **[2026-10-28: internal design with no Daftra equivalent; the Branches app is disabled in the trial account and its screens were never seen, so no parity claim is made (G-139)]** | G-61, INTERBRANCH-SETTLEMENT, P6-ASSUMPTION-7 |
| **WP8** — done (merged 2026-10-27, safe half; rest is WP8-S33) | Per-document numbering service | M | — | base sequence none; editing the next number and duplicate manual numbers need S-33 (WP21) **[2026-10-28: OBS-A read 26 numbering types and the prefix behaviour; the off state of the shared journal counter is observed (ACC-19, G-122); S-33 write cases remain for batch C.]** | G-08, CUS-02 |
| **WP9** | Customer documents: payments list, credit notes list, refund invoice, journal order | L | ~~WP8~~ (service done) | none **[2026-10-28: OBS-A read the customer-payment list columns and the credit-note form; D1 read the credit-note behaviour (E57, E60, E63).]** | G-34, SAL-19, G-28, SAL-29, G-120 |
| **WP10** | Supplier documents: payments list, debit notes list, entered payment date, paid-by | M | ~~WP8~~ (service done) | none **[2026-10-28: OBS-A read the supplier payments report, number formats, refund and debit-note forms; D2 read the supplier payment and the overpay button.]** | G-51 |
| **WP11** | Opening-data tools: customer opening balance, opening-stock cost correction option, negative-line decision, known limits (dev-only forms) | L | ~~WP1~~ (done) | CUS-03 customer journal is a provisional assumption until a customer-side equivalent of S-35 T-10/T-11 is observed (WP22); the other items need none **[2026-10-28: OBS-A read the opening-balance fields (supplier add form only, no setting); D2 read the supplier opening-balance journal (matches E35).]** | G-57, E26-NEG-LINES, E26-LIMITS, CUS-03 |
| **WP12** | Retroactive posting (backfill) with preview batches | XL | WP11, ~~WP8~~ (service done) | none (OSPOS-specific, D3) | A6, G-79 |
| **WP13** | Invoice date, due date, payment terms, aging and balance-report columns | L | ~~WP8~~ (service done) | none for dates and supplier aging; the customer bucket layout and by-ledger variant (CUS-09) are inferred from the supplier side and confirmed by R… **[2026-10-28: **Observation requirement met (2026-10-28):** D1 observed the due and overdue rule and OBS-A the customer aging and balance layouts; the supplier aging basis stays unobserved.]** | G-27, G-31, G-53, CUS-08, CUS-09, G-14, G-106, G-112 |
| **WP14** | Draft and quote lifecycle | M | ~~WP8~~ (service done), WP13 | none **[2026-10-28: OBS-A read the eight estimate statuses and the estimate forms.]** | SAL-13, SAL-21, WP8-WORK-ORDERS |
| **WP15** — done (merged 2026-10-27) | Discount on the whole purchase invoice (E33 / G-59) | M | — | none (T-16 evidence) **[2026-10-28: No change: OBS-A read the purchase discount tab; do not copy the page's one-cent correction loop.]** | G-59 |
| **WP16** — done (merged 2026-10-27) | Invoice-level discount on sales (percent or fixed) | L | — | none **[2026-10-28: D1 observed the allocation (proportional to each line net, unrounded in Daftra, E59) and the return without proration (E60); the page's tax and rounding behaviour is recorded in the rules.]** | SAL-07 |
| **WP17** | Purchase flow completion: edit saved receiving lines, item purchase taxes, supplier registry field | M | ~~WP1~~ (done) | none (the average-cost effect of editing or deleting is the unobserved G-43 assumption, kept as 'average unchanged') **[2026-10-28: **No observation needed (2026-10-28):** D2 observed edit and delete of a saved line (E58); item purchase taxes are read in the forms.]** | G-37, G-38, PUR-10, G-117, G-133, E58-LINE-EDIT-AVERAGE |
| **WP18** — done (merged 2026-10-27) | Inventory valuation report from valued movements | M | ~~WP1~~ (done) | none for valuation; aging and turnover layouts are covered by WP33 **[2026-10-28: OBS-A read the stock report layouts; the valuation reference is the valued movements (E55), Daftra shows three values (G-45 update).]** | G-45 |
| **WP19** — done (merged 2026-10-27) | Automatic-journal metadata edit and manual-journal parity | M | — | none (S-14 observed); line tax and currency need WP38 and WP28 **[2026-10-28: OBS-A read the manual-journal form rules and the audit events of a journal edit.]** | ACC-16, ACC-18 |
| **WP20** | Daftra observation batch 1: read-only reports, forms, prints, role matrix, year-end | M | — | This package is the observation: filtered ledger, journal movements, inventory movement, cost centres, cash flow, cash and accrual profit and loss,… **[2026-10-28: **Largely consumed by OBS-A (2026-10-28):** R-02 to R-05 read except the print header content of the statements, the cost-centre and asset report columns and Y9 (batch E). Safe-read rule added below.]** | R-02, R-03, R-04, R-05, Y9, G-72, G-86, G-89 |
| **WP21** | Daftra write-observation batch A: closed periods and messages, settings scenarios, numbering toggle, inclusive tax | L | WP20 | This package is the observation (writes on opes.daftra.com only, each setting reverted afterwards) **[2026-10-28: **Batch C (global settings) is running and batch E (closed periods and year-end) is queued (2026-10-28)**; ACC-19 off state observed; S-32 and S-33 keep their open cases.]** | E12-PKG-C, E12-PKG-D, ACC-19, SAL-05, WP8-S33, G-71, G-76, G-122, W001-W004-CLEANUP, G-139 |
| **WP22** | Daftra write-observation batch B: unobserved assumptions of the sales, purchase, stock and treasury flows | L | WP20 | This package is the observation: F-04c settle button, F-08 stock permit, F-09 stocktake, F-10 credit note without invoice, F-11 credit note on unde… **[2026-10-28: **Observation requirement largely met (2026-10-28):** D1, D2 and B observed F-04c, F-08, F-09, F-10, F-11, F-12, F-13, G-26, G-35, G-43, G-49, G-66, S35-T06 and S35-D6; open: the tie-break, S35-D4, PUR-08, SAL-37, G-23, G-55 and the items listed below.]** | G-49, G-20, G-55, G-35, G-23, G-26, G-43, G-19, G-66, S35-T06, S35-D4, S35-RESIDUE, D1D4-ACCOUNT-NUMBER-RULE, P3-EVIDENCE-GAPS, PUR-08, SAL-37, G-67, G-68, G-69, G-77, G-87, G-116 |
| **WP23** | Credit flows and return exceptions: advance payments, cash refunds, return override, undelivered credit note | L | WP22, WP9 | F-12 (two open invoices and a covering balance, enable_advance_payment), F-10 (credit note without invoice), F-11 (credit note on undelivered invoi… **[2026-10-28: **Observation requirement met except the tie-break (2026-10-28):** D1 observed F-10, F-11, F-12 and F-13 (E57, E63); the cash-refund path and the advance payment need batch C.]** | G-32, G-21, G-30, P5-CUSTOMER-CASH-REFUND, G-52, E57-CREDIT-ORDER |
| **WP24** | Routing types per-item and main-account-per-entity | L | WP21 | S-28 (item-level journal accounts and product sales account) and S-29 (preview of types 2 and 4) in WP21 | G-02 |
| **WP25** | Per-tax inclusive option and default invoice tax mode | M | WP21 | S-03 and the tax-mode settings (WP21) **[2026-10-28: OBS-A read the per-tax inclusive formulas in the page scripts; the account has one non-included tax, so the inclusive behaviour needs a settings-changing observation (batch C).]** | SAL-04, SAL-33 |
| **WP26** | Cheques, notes receivable and payable, receipt vouchers and revenue, payment method independent of treasury | XL | WP20, WP21, WP22, ~~WP8~~ (service done), ~~WP3~~ (done) | Enable the cheques module in the trial account and run S-27; read the revenue and receipt-voucher forms (R-03); S-35 D6 (method independent of trea… **[2026-10-28: **Observation requirement met (2026-10-28):** B observed the cheque cycle (E64) and the payment method independent of the treasury (E65), D2 the revenue voucher (E62 on edit in place). **Correction:** the constant 'journals via 1206/212' needs leaf accounts under those two group accounts, as Daftra's cheque module posts to leaf accounts it creates (E64, G-131).]** | G-64, FIN-03, ACC-17, S35-D6, G-78, G-118, G-131, G-137 |
| **WP27** | Stock documents: numbered stock permits, stocktake document, stock requests | L | ~~WP8~~ (service done), WP21, WP22 | F-08, F-09 and S-26 (WP21, WP22) **[2026-10-28: D2 observed the stock permits and the stocktake and their journals; the settings that enable the transfer permit and stock requests need batch C.]** | G-41, G-42, INV-12, G-92 |
| **WP28** | Multi-currency: document currency and rate, FX difference entries, treasury currency, non-two-decimal currencies | XL | WP21, WP22 | S-30 (currency selector hidden in the invoice form, payments at a different rate) and the treasury currency behaviour (WP21) **[2026-10-28: **Partly met (2026-10-28):** B observed currencies, rates, the FX difference journal and decimals (E66); foreign-currency expense, income and credit note and the rate-update setting stay unobserved.]** | G-12, G-25, G-63, G-132, G-138 |
| **WP29** | Banking: bank reconciliation and bank directory | L | WP26 | Daftra's reconciliation (if any) and bank search list behaviour were never observed (R-03) **[2026-10-28: **Partly met (2026-10-28):** B observed the bank-statement import wizard and the reconciliation screen; 'finish and close' and a voucher from a match were not run.]** | E47-BANKREC, D1D4-BANK-LIST, G-111 |
| **WP30** | Roles and own-versus-all record scope | XL | ~~WP1~~ (done), ~~WP20~~ (OBS-A read it), WP21 | R-04 role matrix and S-32 restricted-employee behaviour and denial messages (WP20, WP21) **[2026-10-28: **Observation requirement met except denial messages (2026-10-28):** OBS-A read the role matrix, its dependency relation and the own-versus-all scope; S-32 needs a second user (batch C).]** | G-09, INV-09, G-90, G-123 |
| **WP31** | Report parity: Daftra filters and layouts, third trial-balance type, statement print header, cash-basis profit and loss, cash flow | L | ~~WP20~~ (OBS-A read it), ~~WP4~~ (done) | R-02 (filtered runs, cash flow, cash and accrual P&L), R-05 (print layouts), the 'review account' trial balance screen **[2026-10-28: **Observation requirement met except the print header content (2026-10-28):** OBS-A read the trial balance in three layouts, the ledger, profit and loss (both bases), cash flow, the tax report and return.]** | REP-01, P8-TB3, REP-02, P8-PRINT, REP-05, REP-06, CHK-B, G-83, G-84, G-94, G-95, G-96, G-97, G-100, G-101, G-128 |
| **WP32** | Operational report families: sales, items, payments, profit, net position | L | ~~WP20~~ (OBS-A read it), ~~WP1~~ (done) | R-02 column and filter reads (WP20) **[2026-10-28: **Observation requirement met (2026-10-28):** OBS-A read 14 revenue views, 20 item views, 9 payment views, two profit reports and the net-position reports.]** | REP-09, REP-10, REP-11, REP-12, REP-16, G-70, G-104, G-105, G-108 |
| **WP33** | Report families: purchases and suppliers, inventory aging and turnover, expenses and income splits, journal, chart and cost-centre reports, activity log | L | ~~WP20~~ (OBS-A read it), ~~WP18~~ (done), ~~WP3~~ (done), WP13 | R-02 (WP20) **[2026-10-28: **Observation requirement met except the cost-centre and receipt-voucher columns (2026-10-28):** OBS-A read the purchase, supplier, inventory, expense and activity-log reports and the journal and chart reports.]** | REP-13, REP-14, REP-15, REP-08, REP-17, G-81, G-88, G-109, G-110, G-113, G-114 |
| **WP34** | Invoice header charges, sales debit note, journal description template | L | WP20, WP9, WP21 | R-03 forms and write observations of adjustment, shipping, debit note journals **[2026-10-28: OBS-A read the header charges (adjustment, shipping, deposit); the sales debit note needs its setting enabled (batch C).]** | SAL-08, SAL-09, SAL-20, SAL-34 |
| **WP35** | Order-to-cash documents: sales orders, recurring invoices, recurring expenses | L | WP20, ~~WP8~~ (service done), ~~WP3~~ (done), WP21 | R-03 add forms and a controlled S-34 generation read **[2026-10-28: OBS-A read the recurring invoice and expense forms; sales orders need their setting enabled (batch C).]** | SAL-22, SAL-23, FIN-06 |
| **WP36** | Pricing and credit controls: price lists, minimum price, maximum discount, excluded categories, credit limit, preview and profit tab | L | WP20, WP13, WP21 | R-03 and the setting effects (messages, draft and return behaviour) **[2026-10-28: OBS-A read the price-list form and the minimum-price check; credit limit and maximum discount need their settings enabled (batch C).]** | SAL-24, SAL-25, SAL-26, SAL-27, CUS-04, INV-02, SAL-32 |
| **WP37** | Product and customer master data | L | ~~WP20~~ (OBS-A read it) | meaning of 'suspended' versus 'inactive', category and brand screen contents, barcode merge setting (R-03) **[2026-10-28: **Observation requirement met (2026-10-28):** OBS-A read the client, supplier, product, category, brand and unit-template forms.]** | INV-01, INV-03, INV-08, INV-16, INV-17, CUS-01, CUS-05, G-93, G-107, G-115, G-119, G-121 |
| **WP38** | Cost-centre distribution, journal settings, shipping-company accounts | L | WP20, ~~WP19~~ (done), WP24 | R-02/R-03 reads of the distribution forms and settings **[2026-10-28: OBS-A read the cost-centre distribution form; no cost centre exists in the account, so the report columns are unobserved.]** | ACC-20, ACC-21, ACC-05, G-73, G-74, G-80, G-85 |
| **WP39** | Purchase cycle documents: purchase request, quotation request, quotation, purchase order | XL | WP20, ~~WP8~~ (service done), WP21 | R-03 forms and statuses **[2026-10-28: The four purchase-cycle documents stay unobservable while the app is disabled (batch C).]** | PUR-07 |
| **WP40** | Templates, print outputs and extra fields | L | ~~WP20~~ (OBS-A read it) | R-05 print outputs and R-03 **[2026-10-28: **Observation requirement met (2026-10-28):** OBS-A read the print outputs, the template designers and the extra-field builders.]** | SET-06, SAL-31, SAL-35, G-125, G-126, G-127 |
| **WP41** | Integration settings: payment gateways and methods, secondary language, auto-send rules, files and API keys | XL | WP20 | scope of each setting (R-03) **[2026-10-28: OBS-A read the role options and the apps manager; the secrets screens need an owner decision on their permission.]** | SET-04, SET-07, SET-08, SET-09, G-130 |
| **WP42** | Multiple units, bundles and batch, serial and expiry tracking | XL | WP20, WP27, WP21 | R-03 and write observations of bundle cost, batch picking and unit conversion **[2026-10-28: OBS-A read the unit-template form and the negative-stock options; bundle, batch and conversion behaviour need batch C then D.]** | INV-04, INV-05, INV-06 |
| **WP43** | Purchase received-state and draft (decision-gated) | M | WP17, WP10 | none **[2026-10-28: No change: the received-state defaults are recorded, E27 stands.]** | G-36 |
| **WP44** | Report export, reports-hub link, phone layout and review polish | M | ~~WP4~~ (done) | none **[2026-10-28: OBS-A defined the export (server-side CSV, Excel, PDF) and the settings gaps; Daftra export defects are not copied.]** | P8-EXPORT, README-HUB, P8-MOBILE, P8-MINOR, G41-A4, G-91, SCOPED-REQUEST-ADOPTION, G-103, G-124, WP7-BALANCES-OVER-TIME |
| **WP45** | Performance measurements, account picker and residual hardening | M | — | INV-07 blocking settings only (WP22); the rest none | P1-PICKER, PERF-LOAD-UNMEASURED, INV-07, G-56, E32-RETURN-ROUNDING, E22E32-BROWSER, CHK-F, G-75, ITEMS-CSV-TIME-LIMIT, REPORT-TESTS-LEGACY, NAMING-SWEEP, RACEWORKER-DOUBLE-LOADER, WAVE1-RACE-TESTS, WAVE1-BROWSER-PASS, WP16-TAX-SUMMARY-ROUNDING, WAVE2-BROWSER-PASS, RACEWORKER-WORKTREE-AUTOLOAD, MIGRATION-HELPERTEST-ORDER, G-102, PARITY-FIXTURES-OBS, WP7-BROWSER-PASS, WP7-LOCK-STRESS, WP7-PAIR-SUMMARY-TABLE |
| **WP46** | E-invoicing (ZATCA phase 2) | XL | WP9 | none (official specification) **[2026-10-28: OBS-A read the e-invoicing party master data and the submission report as a screen only.]** | SET-10 |
| **WP47** | Fixed assets and depreciation | XL | ~~WP20~~ (OBS-A read it) | asset form and depreciation journals **[2026-10-28: OBS-A read the asset form, the four depreciation methods and the assets report; the journals of the asset operations are unobserved.]** | FIN-08 |
| **WP48** | AI agent (proposals as drafts, anomaly detection, accounting_ai permission) | XL | WP30 | none | A9 |
| **WP49** | Contact list and customer portal (owner scope decision) | L | WP30 | portal behaviour (R-03) **[2026-10-28: OBS-A found the contact list inside the client form: split CUS-06 from CUS-10.]** | CUS-06, CUS-10 |
| **WP50** | Umbrella rows (no own work; close when the children close) | S | ~~WP4~~ (done), WP5, WP6, WP8 (safe half done; WP8-S33 remains), WP9, WP10, ~~WP15~~ (done), ~~WP16~~ (done), WP17, WP25, WP33, WP34, WP35, WP36, WP40, WP41 | none | G-13, P7, P9, A8, PUR-01 |
| **WP51** | Inter-branch settlement follow-ups (decision-gated): partial settlement, settling an unmatched pair with an adjustment entry, attributing unattributed journals, a record for future transfers, the inactive-branch rule | M | ~~WP7~~ (done) | none (internal design; the Daftra read of the Branches app is G-139 in WP21) | G-134, G-135, G-136, WP7-INACTIVE-BRANCH-BALANCE |

## 2. ثوابت وفحوص كل حزمة

### WP1: Close control gaps: sensitive grants, branch scope on stock screens and legacy reports, destination-location grant, lock topology guard

**الحالة (2026-10-27): done (WP1)** — merged into the development branch under E47 ([E48](../decisions.md), [report](wp1-report.md)): 165 test methods added in 35 files and 101 guards each disabled alone and detected (101 of 101). No migration. Not merged into master, not deployed. **Carried over:** the expenses report stays company-wide only for restricted employees (`G-81` in WP33, owner decision E48 (a)); the two uncollected legacy report test files and three older assertRedirect-only tests (`REPORT-TESTS-LEGACY`, WP45); the named-lock guard detects and refuses but does not fix connection pooling, so `accounting:check_locks` runs on every environment before and after deploy (deploy checklist).

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

**الحالة (2026-10-27): done (WP3)** — merged into the development branch under E47 ([E52](../decisions.md), [report](wp3-report.md)): 276 new tests in 13 files plus 29 methods added to 8 existing files, 234 guards each disabled alone and detected (234 of 234; eleven equivalent mutants named in the report are not counted). Migration `20261120000000_ExpensesAccounting` (nullable columns only). Not merged into master, not deployed. **Carried over:** retroactive posting of legacy expenses and cash-ups stays with WP12 (`G-79`, **new finding**: legacy rows have no branch and are listed as unposted); Daftra behaviour not observed for expenses (`G-77`, WP22); card, cheque and credit cash-up and the screen's default-branch expectation (`G-78`, WP26); recurrence, attachments, multi-account expenses, cost centres, currency (`FIN-06` in WP35, `G-80` in WP38, `G-12` in WP28, `REP-15` in WP33); revenue vouchers and the revenue category screen (`FIN-03` in WP26); no two-process race test on one expense or cash-up (`WAVE1-RACE-TESTS`, WP45). The 13 assumptions are owner questions in E52.

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

**رصد دفترة (2026-10-28):** D2 observed the expense (amount stored with the tax, E61; account chosen per document; no due option; edit in place; delete erases; draft; several accounts): see G-77 closed, G-80 and G-118.
- يُضاف (تقارير الحسابات): قاعدة الربط بتقارير WP3: قيد المصروف ذو الضريبة يساوي مدين 541 بـ200.00 ومدين 215002 بـ30.00 ودائن الخزينة 230.00 (القيد 000074 في دفترة، مصدره expense)، وتقرير المصروفات عند دفترة يعرض المبلغ 200.00 والضرائب 30.00 والإجمالي 230.00 وفلتر الخزينة والتصنيف…
- يُضاف (تقارير المشتريات والمخزون والخزائن): المصروف يحمل خزينة (دفعة من خزينة الفرع) وتصنيفًا وبائعًا نصيًا ومدخل عملية وحسابًا فرعيًا (journal_account) وضريبة، لتقوم عليه تقارير المصروفات المقسمة الثمانية. أرقام الربط للمراجعة: مصروفان 3,000 (إيجار، ضريبة 0.00) و200 (+ ضريبة 30 = 230) من Main…
- قرار مطلوب (النماذج): Daftra's expense and revenue forms pick the ledger account per document through «الحساب الفرعي» (journal_account_id, default «آلي» = the routed account, 541 in the observed S-25 expense) and make it mandatory only when expenses_mandatory / income_mandatory…
- يُضاف (النماذج): Add to the expense screen what the observed form has and WP3 lacks: a required document number from the sequence (default next 000003), the paying treasury (default Main Treasury), the supplier chosen from the list (with an inline new supplier), tax1 as tax…
- اعتماد جديد (الطباعة وإقفال السنة): طباعة سند الصرف تحتاج من المصروف: رقمًا مستقلًا وتاريخًا والمستفيد (قد يكون فارغًا) والغرض («وذلك مقابل») والمبلغ والخزينة؛ فيُدرج المستفيد والغرض في نموذج المصروف عند WP3 حتى لا يُعاد فتحه.
- **قواعد مرصودة** (2؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-EX-02`، `FRM-R43`

### WP4: Income statement and balance sheet with dynamic unclosed-years equity (policy re-based first)

**الحالة (2026-10-28): done (WP4)** — merged into the development branch under E47 ([E53](../decisions.md), [report](wp4-report.md)): 165 new test methods (159 in five new files, 6 in three existing ones) and 97 guards each disabled alone and detected (97 of 97), two independent reviews answered (the first claim, that the reversal of a closing entry was not left out of the income statement, was real and fixed). No migration and no new permission (the existing `accounting_reports` module, granted to nobody automatically). Not merged into master, not deployed; **no browser pass, no accessibility pass and no full suite were run by the author**. The year-end policy was re-based on the real chart (32 and 214) and stays an unadopted proposal: **Y-DEC is `partial`, decisions Y1-Y9 are the owner's.** **Carried over:** export, print header, reports-hub link and phone layout (`P8-EXPORT`, `P8-PRINT`, `README-HUB`, `P8-MOBILE`; WP44 and WP31); monthly and cash profit and loss and cash flow (`REP-05`, `REP-06`, WP31); the year-end entry, its ledger label and the trial-balance before/after option (`YE-01`, `YE-03`, WP6) and the definition of the closing entry by source or type (`G-82`, WP6); two-period comparison and the current/fixed assets split (`G-84`, WP31); the cost-centre filter (`G-85`, WP38); the owner and Daftra questions (`G-83` in WP31, `G-86` in WP20); the stale `3102`/`3103` in three historical design documents (`YEAR-END-STALE-ACCOUNT-REFS`, WP6); the missing browser pass (`WAVE2-BROWSER-PASS`, WP45). Owner questions are in E53.

**لماذا هنا:** Core financial statements derive from the ledger and need WP3 so revenue and expense are complete; no Daftra access needed (reference numbers 1,990 = 2,210 - 220 are recorded; the tree reaches level 5, not 6: the sixth level of the first reading was wrong, WP4 report RA-BS-06). Y-DEC first because the policy still uses accounts 3102/3103 that no longer exist (retained earnings is 32, opening difference 214).

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

**رصد دفترة (2026-10-28):** OBS-A read the income statement and balance sheet (signs by section, levels 1 to 5, display options): the scope additions below are small; the reference numbers stay in fixtures built from our own inputs.
- يُضاف (تقارير الحسابات): قائمة الدخل والميزانية صارتا مرصودتين بأعمدتهما وأرقامهما، فلم يعد WP4 بلا ملاحظة دفترة. تُضاف إلى النطاق: (1) إشارة العرض بحسب القسم لا اتجاه الحساب (الإيرادات دائن − مدين والمصروفات مدين − دائن والأصول مدين − دائن والخصوم وحقوق الملكية دائن − مدين…
- قرار مطلوب (تقارير الحسابات): تسجيل فرق مقصود عن دفترة: الميزانية عندنا مركز مالي حتى «إلى» ولا تحتسب «من» (دفترة تحتسبه فتعرض حركة نافذة)، مع اختبار يثبت أن النتيجة بلا «من» وبـ«من» = بدء الدفاتر واحدة. ويُحسم هل يعرض نموذجنا حقل «من» أصلًا في الميزانية (توصية: لا). كما يُسجَّل أن البند…
- يُضاف (الطباعة وإقفال السنة): ثبّت في تصميم القوائم: (أ) سطر «أرباح / خسائر (غير مرحلة)» المحسوب وصيغة التوازن (الأصول 96,991.84 = الخصوم 268.30 + حقوق الملكية 96,723.54 حيث 100,000 + 0.00 − 3,276.46) مرجعًا حسابيًا ثانيًا غير حالة A/B (1,990 = 2,210 − 220) لأن حالة الحساب تغيّرت؛ (ب)…
- **قواعد مرصودة** (15؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RA-IS-01` إلى `RA-IS-05`، `RA-BS-01` إلى `RA-BS-06`، `PY-R17`، `PY-R18`، `PY-R19`، `PY-R21`

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

**رصد دفترة (2026-10-28):** OBS-A read the tax report (seven sections, cash filter, detailed columns) and the return with its four tables; the settlement journal stays unobserved.
- يُضاف (تقارير الحسابات): WP5 لم يعد مقيَّدًا بشريحة الاستحقاق وحدها: رُصد المرشح النقدي (ثلاث نوعيات دخل) وأقسام التقرير السبعة وقسم «Non Taxed» وأعمدة المفصل وتجميع «فاتورة» والإقرار بجداوله الأربعة. تُضاف: الإقرار الضريبي (REP-20) بمعادلاته، وعرض مردودات المشتريات (مرتجع مشتريات…
- قرار مطلوب (تقارير الحسابات): قراران: (1) أساس المبيعات في التقرير: من المستندات كما في دفترة (9,222.00) أم من 411 (9,222.01، فرق 0.01 ناتج من أن الإيراد هو الباقي بعد تقريب إجمالي المدين حسب E14)؛ التوصية أن يُحسب الأساس من مجموع صافي بنود المستندات والضريبة من 215001 و215002 بقبول فرق…
- **قواعد مرصودة** (8؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RA-TX-01` إلى `RA-TX-08`

### WP6: Year-end closing entry per branch with preview, approval, reversal and guards

**مدخلات من WP4 (2026-10-28):** the income statement and the balance sheet already leave out and take in `year_end` journals (E53). `YearEndEntries::leaveOut()` recognises a closing entry by source `year_end` or by type `year_end` as a stopgap (no such entry is created yet and `JournalEntryType` has no `year_end` case) and follows `reverses_journal_id`, so the reversal of a closing entry, the reversal of that reversal and its replacement are left out too; this package picks one definition and changes the filter in one place (`G-82`). Still to build here: the entry itself, the trial-balance before/after option, the ledger label and the two-period comparison exclusion. The year-end policy was re-based on 32 and 214 and stays unadopted until the owner decides Y-DEC; three historical design documents still name `3103` (`YEAR-END-STALE-ACCOUNT-REFS`).

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

**رصد دفترة (2026-10-28):** Y9 stays unobserved (batch E is queued); only general-source inference exists (G-129).
- قرار مطلوب (الطباعة وإقفال السنة): قبل شاشة الإقفال يلزم قرار المالك المسجَّل (Y-DEC) على: (1) آلي (دفترة بحسب التوثيق) أم اختياري بمعاينة واعتماد (Y1 المقترح)؛ (2) قيد افتتاح للسنة التالية (دفترة بحسب التوثيق) أم لا افتتاح (سياستنا §2 و§3.1)؛ (3) إعادة الفتح بإلغاء الأحدث (دفترة) أم بعكس قيد…
- اعتماد جديد (الطباعة وإقفال السنة): Y9 قُرئ بالقراءة ولم يحسم قيد الإقفال: لا سنة معرَّفة في الحساب. منطق الدفتر في WP6 لا ينتظر دفترة (كما ورد في نطاق الحزمة)، أما شاشة الإقفال والمعاينة فتنتظر اختياريًا (كتابة تُنفَّذ أخيرًا في الحساب التجريبي) أو قرار المالك بالاكتفاء بالتوثيق العام.
- **قواعد مرصودة** (6؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `PY-R22` إلى `PY-R27`

### WP7: Inter-branch settlement, pairwise balances and cross-branch payments

**الحالة (2026-10-28): done (WP7)** — merged into the development branch under E47 ([E56](../decisions.md), [report](wp7-report.md)): 270 test methods added in 22 files (counted in the diff of `wp/WP7`; one existing test was renamed on purpose), 195 guards each disabled alone and detected (195 of 195, from the report; the disabling tool is outside the repository), two independent reviews answered (16 distinct points, all real and fixed, two partly refused). Migrations `20270200000000_InterBranchSettlements` (three tables and the sensitive permission `acc_interbranch_settle`, granted to nobody) and `20270200000001_CrossBranchPayments` (a nullable `treasury_branch_id` with an index and a foreign key on `sales_payments`, `receivings_payments` and `expenses`); both additive and safe to run again, and neither touches a journal, a payment or a balance. Not merged into master, not deployed; **no browser pass and no full suite were run by the author.** The same merge brought the permanent parity fixtures `T-26` to `T-64`. **Carried over:** partial settlement (`G-134`), an unmatched pair with the attribution of unattributed journals (`G-135`) and a record for future transfers (`G-136`), with the inactive-branch rule (`WP7-INACTIVE-BRANCH-BALANCE`), all owner decisions, in WP51; customer balance without a sale, supplier advances and cash-up into another branch's treasury (`G-137`, WP26); one currency (`G-138`, WP28); the Daftra read of the Branches app (`G-139`, WP21); balances over time and export (`WP7-BALANCES-OVER-TIME`, WP44); the browser pass, the many-session load test and the summary table of pairs (`WP7-BROWSER-PASS`, `WP7-LOCK-STRESS`, `WP7-PAIR-SUMMARY-TABLE`, WP45); the query to run on each owner database before the first real settlement (`WP7-PRE-DEPLOY-QUERY`, WP2). Owner questions are in E56. **Corrections to the report:** the error code when the two accounts have no routing is `inter_branch_accounts_missing`; the sensitive permissions are nine and the `acc_*` permissions seventeen after the merge; the two matrix rows (`FIN-17`, `FIN-18`) are 'not yet verifiable', not 'not comparable with Daftra'.

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

**الحالة (2026-10-27): done (WP8, safe half)** — merged into the development branch under E47 ([E49](../decisions.md), [report](wp8-report.md)): `DocumentNumberingTest` 72 tests, `DocumentSequencesMigrationTest` 12, 77 guards each disabled alone and detected (77 of 77). Migration `20261210000000_DocumentSequences` (two empty tables; every sequence off by default). Not merged into master, not deployed. **Carried over:** lowering the next number, duplicate manual numbers, prefix variables, annual reset, sequence per branch and the other S-33 questions (`WP8-S33`, observation in WP21, then the build on `DocumentNumbering::configure` and `assign`); the payments, credit-note and debit-note list screens (`G-34` in WP9, `G-51` in WP10); work-order numbers (`WP8-WORK-ORDERS`, WP14); a separate renaming pass (`NAMING-SWEEP`); a simultaneous-configure test (`WAVE1-RACE-TESTS`). Owner questions are in E49.

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

**رصد دفترة (2026-10-28):** OBS-A read 26 numbering types and the prefix behaviour; the off state of the shared journal counter is observed (ACC-19, G-122); S-33 write cases remain for batch C.
- يُضاف (تقارير المشتريات والمخزون والخزائن): صيغ الأرقام المرصودة للمستندات المشتراة: فاتورة شراء 000001 (ست خانات)، مرتجع مشتريات 000001، اشعار مدين المشتريات 00001 (خمس خانات)، دفعة المورد 000021 و000027 (ست خانات، بمعرّف داخلي غيرها)، Supplier Payment Refund 000001 و000002، قيد 000119 (ست خانات)…
- يُضاف (النماذج): The forms show how numbers behave at entry: every add form pre-fills the next number (observed values and zero-padding widths: 6 digits for client 000005, supplier 000008, product 000010, invoice 000028, estimate 000002, purchase invoice 000025, purchase…
- ينفتح بالرصد (الأدوار): رُصد كل ما كان WP8 يفترضه عن أسلوب دفترة عدا الكتابات: 26 نوعًا بتسلسل مستقل، حقول موحدة، منازل 6 (و5 للأنواع 34 و35 و36)، رقم تالٍ قابل للتحرير في صفحة الترقيم، «غير مكرر» للمنتجات وحدها، بادئة بـ11 متغيرًا وثلاث سياسات، سبع صيغ. فيصير ممكنًا بلا انتظار…
- يُضاف (الأدوار): أنواع لم يشملها WP8 ولها مقابل في التطبيق اليوم أو قريبًا: المورد (8، يقابل `suppliers.account_number`)، الموظف (49، ولا كود للموظف اليوم، SET-19)، المنتج (18 بـ«غير مكرر» مفعّل، يقابل `items.item_number` وفحص التكرار القائم)، والفاتورة المرتجعة (3) إن بُنيت…
- قرار مطلوب (الأدوار): (أ) القيود الآلية: دفترة ترقّمها من تسلسل النوع 9 المشترك مع اليدوية (000135) بينما هي عندنا بلا رقم (`A-{id}`) واليدوي تسلسل مستقل؛ وثابت WP8 «تسلسل القيود يبقى بلا مساس» يتعارض مع ترقيمها، فيلزم قرار صريح (لا يمس E16). (ب) المنازل الافتراضية: صفر في WP8…
- **قواعد مرصودة** (11؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-NO-01`، `FRM-R36`، `RP-STF-01`، `RP-NUM-01` إلى `RP-NUM-08`

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

**رصد دفترة (2026-10-28):** OBS-A read the customer-payment list columns and the credit-note form; D1 read the credit-note behaviour (E57, E60, E63).
- يُضاف (تقارير المبيعات والعملاء): تُضاف إلى قائمة المدفوعات (G-34): أعمدة المعرف والتاريخ وكود العميل واسمه والنوع ورقم المستند ووسيلة الدفع والخزينة والمبلغ والموظف و«تم التحصيل بواسطة» و«أضيفت بواسطة» ورقم معرّف المرجع (CHQ وTRF)؛ ودفعات تطبيق الرصيد لا تظهر في القائمة (RSC-CRD-02)؛ وقائمة…
- قرار مطلوب (تقارير المبيعات والعملاء): أُكِّد ترتيب التطبيق بكتابة مضبوطة (D1، E57): لا أفضلية للفاتورة الأصل ويبقى كسر التعادل غير مرصود؛ تغيير فرع «الأصل أولًا» في `CreditSettlement::apply` هو البند `E57-CREDIT-ORDER` ويعيد تصنيف اختبار الأصل في `CreditSettlementTest`.
- يُضاف (النماذج): The credit-note add form (add_creditnote, type 5, found in p-creditnote-add.probe.json) is the invoice form without payment, deposit, due terms and delivery-confirmation blocks, labelled «رقم إشعار دائن» (default 000007) and «تاريخ إشعار دائن», and it keeps…
- يُضاف (الطباعة وإقفال السنة): إيصال لكل دفعة عميل من قائمة المدفوعات بحقول: عنوان، رقم، تاريخ، كتلة الشركة، من، المبلغ، وسيلة الدفع، رقم الفاتورة، المستلم باسمه الكامل، الخزينة، التوقيع؛ قالب A4 وآخر حراري يُختاران لحظة الطباعة؛ وطباعة الإشعار الدائن بملف تعريفه (WP40).
- **قواعد مرصودة** (5؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-PAY-03`، `RSC-PAY-05`، `RSC-CRD-02`، `FRM-R09`، `PY-R06`

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

**رصد دفترة (2026-10-28):** OBS-A read the supplier payments report, number formats, refund and debit-note forms; D2 read the supplier payment and the overpay button.
- يُضاف (تقارير المشتريات والمخزون والخزائن): (1) تقرير ومستند المدفوعات REP-28 بالأعمدة المرصودة (المعرف / رقم الفاتورة / التاريخ / منشئ الفاتورة / الطريقة / رقم معرف / الإجمالي)؛ (2) ترقيم الدفعة مستقل عن المعرّف الداخلي (عملية الدفع 000027 لفاتورة 000024 بمعرف داخلي 29)، وللرد المستقل ترقيمه (#000001…
- يُضاف (النماذج): Purchase forms: the refund (type 6) and debit note (type 14) share the purchase-invoice form class with different labels and defaults (refund number 000003, debit note 00003; «مستلم» unchecked on both; refund payment checkbox «تم رد المبلغ الإجمالي للبنود…
- يُضاف (الطباعة وإقفال السنة): إيصال دفعة المورد (رقم وتاريخ وكتلة الشركة وإلى وطريقة الدفع والمبلغ ورقم فاتورة الشراء والمستلم والتوقيع)، وكشف المورد بصف رصيد بداية المدة ونهايتها وتصديره CSV بعدد حقول ثابت، وزر إرسال الكشف بالبريد (لم يُضغط في دفترة فسلوكه غير مرصود).
- **قواعد مرصودة** (4؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-PY-01`، `RP-PY-02`، `FRM-R31`، `PY-R07`

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

**رصد دفترة (2026-10-28):** OBS-A read the opening-balance fields (supplier add form only, no setting); D2 read the supplier opening-balance journal (matches E35).
- قرار مطلوب (النماذج): Customer opening balance is switched by a client setting (starting_balance, off) and its field exists only in the raw React schema («الرصيد الافتتاحي»); the supplier opening balance and its date («تاريخ رصيد الافتتاحي», default today) are on the supplier add…
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R54`

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

**رصد دفترة (2026-10-28):** **Observation requirement met (2026-10-28):** D1 observed the due and overdue rule and OBS-A the customer aging and balance layouts; the supplier aging basis stays unobserved.
- ينفتح بالرصد (تقارير المبيعات والعملاء): ما كان WP13 يعدّه «مستنتجًا من جهة الموردين ويؤكده R-02» (مخطط شرائح العملاء والنسخة بالأستاذ، CUS-09) صار مرصودًا: ست شرائح `اليوم / 1-30 / 31-60 / 61-90 / 91-120 / 120+` بالفواتير، وتقرير بالأستاذ بعدد أيام قابل للتغيير (`period_days`) وتطبيق الدائن على…
- يُسحب (تقارير المبيعات والعملاء): يُحذف من نطاق WP13 كل ما يجعل تاريخ الاستحقاق مدخلًا لشرائح الأعمار: عند دفترة تُحسب الشرائح من تاريخ الفاتورة (الفاتورة #000004 باستحقاق 21/11/2026 في «1 - 30» بتاريخ 27/10)، فاختبار «ties with due dates» في WP13 يُعاد كتابته: الشرائح من تاريخ الفاتورة…
- يُضاف (تقارير المبيعات والعملاء): (1) تاريخ تقرير «إلى» يُعرض ويُمرَّر (والمكتبة تقبله اليوم والشاشة لا تعرضه) و«من» بلا أثر، (2) ست شرائح بدل أربع بإظهار «اليوم»، (3) تقرير ثانٍ بالأستاذ بـ`period_days` وعناوين تتبع الفترة، (4) أعمدة الفترة في أرصدة العملاء بترتيب الأعمدة نفسه وتصنيف…
- قرار مطلوب (تقارير المبيعات والعملاء): قراران قبل التنفيذ: (1) تاريخ التقرير: دفترة تجمع الأرصدة كما كانت في ذلك التاريخ لكنها توزّع الشرائح بالمتبقي الحالي وتلقي الفرق في «120+»؛ التصميم المقترح عندنا ترشيح الفواتير والدفعات والتطبيقات بتاريخها ≤ تاريخ التقرير فيتساوى مجموع الشرائح مع رصيد الحساب…
- يُضاف (تقارير المشتريات والمخزون والخزائن): (1) `aging(asOf, periodDays)` بخانة «اليوم» (عمر 0) وست خانات وطول الخانة = الفترة، والعمر = asOf − تاريخ المستند (لا تاريخ استحقاق: أعمار دفترة للموردين لا تتوقف على بند الاستحقاق فيصح تقديمها على بقية WP13)، والمستندات والدفعات بعد asOf لا تُحتسب؛ (2) تقرير…
- يُضاف (النماذج): Observed rules: invoice «تاريخ الفاتورة» and «تاريخ الإصدار» are both required, «شروط الدفع» (due_after) is a number of days >= 0 and the page computes due date = invoice date + due_after days (invoices only; estimates call the same field «صالح لمدة»; the…
- **قواعد مرصودة** (21؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-BAL-01` إلى `RSC-BAL-04`، `RSC-AGE-01` إلى `RSC-AGE-07`، `RSC-DUE-01`، `RP-PU-06`، `RP-SB-01` إلى `RP-SB-05`، `RP-SA-01`، `RP-SA-02`، `FRM-R10`

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

**رصد دفترة (2026-10-28):** OBS-A read the eight estimate statuses and the estimate forms.
- يُضاف (النماذج): Estimate statuses are eight, not three: 0 «مفتوح», 1 «تم الإرسال», 2 «تمت مشاهدته», 3 «تم الرد», 4 «تمت الموافقة», 5 «حولت لفاتورة», 6 «تم الطلب», -1 «مسودة»; the status is not a form field (set by list and view actions); the estimate add form has a draft…
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R11` إلى `FRM-R13`

### WP15: Discount on the whole purchase invoice (E33 / G-59)

**الحالة (2026-10-28): done (WP15)** — merged into the development branch under E47 ([E54](../decisions.md), [report](wp15-report.md)): E33 implemented on development data. Migration `20261220000000_PurchaseInvoiceDiscount` (three nullable columns, no row touched; `down()` refuses once a receiving carries the discount). A golden file of 22 purchase scenarios recorded on the code before E33 is reproduced letter for letter by the new code, `T-16` has all four cases exact (810 / 121.50 / 931.50 and the 73.67 average), 49 guards each disabled alone and detected (49 of 49: 36, then 13 after the review), two independent reviews answered (four findings, all real, all fixed). The report counts 89 test methods added (one removed) plus 18 after the review; a count of added `public function test` lines in the merge diff gives 121 added and one removed, a difference that was not settled. Not merged into master, not deployed; **no full suite was run by the author, and two concurrency tests of `ReceivingPostingTest` failed in the author's environment for an environmental reason (the race workers loaded `app/` from the main repository), so the integrator's full suite must pass them.** **Carried over:** Daftra observation of the discount on several lines, as a fixed amount and on a return (`G-87`, WP22); editing saved receiving lines needs the shares re-allocated in the reversal (`G-37`, WP17); a discount column in the detailed receivings report (`G-88`, WP33); a backup before the migration and a separate owner permission before production (`E54-PRE-DEPLOY-BACKUP`, WP2); the worktree autoload of the race workers (`RACEWORKER-WORKTREE-AUTOLOAD`, WP45); the missing browser pass (`WAVE2-BROWSER-PASS`, WP45). The six assumptions are owner questions in E54.

**لماذا هنا:** Daftra applies an invoice-level discount after line discounts that enters stock cost and the tax base with no discount line (810 / 121.50 / 931.50). Observed, so no Daftra access is needed, but it is a HISTORICAL-RISK change: the recent fixed-discount incident (E32) showed that reinterpreting a stored column silently changes old documents.

**ثوابت لا تُكسر:**
- Additive nullable header column defaulting to 0; ReceivingAmounts output is byte-identical to today for discount 0 (golden test over T-11 to T-19)
- Applied after the line discount; enters stock cost, weighted average and tax base; no discount journal line
- Returns take the proportional share; historical receivings are never recalculated or re-posted
- LegacyFixedPurchaseDiscount markers keep their meaning; closed periods and branch scope unchanged
- Dev data only until the owner permits production (E33 was 'still not approved' when this package was defined; it was implemented on development data under E47, see the status line)

**اختبارات الإثبات:**
- ReceivingAmountsTest/ReceivingPostingTest: T-16 'discount-on-the-whole-purchase' flips to exact (810, 121.50, 931.50)
- Weighted average and return share with the header discount; reversal restores
- Receiving_libTest totals and receivings report agree with journals
- Existing receipts: totals and journals identical before and after the migration

**رصد دفترة (2026-10-28):** No change: OBS-A read the purchase discount tab; do not copy the page's one-cent correction loop.
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R32`

### WP16: Invoice-level discount on sales (percent or fixed)

**الحالة (2026-10-27): done (WP16)** — merged into the development branch under E47 ([E50](../decisions.md), [report](wp16-report.md)): two new `invoice_discount` cases in `T-04` (fixed 20 on 355 exact; 10% on 355 one halala, accepted by E19 and not counted as a match), 63 guards each disabled alone and detected (63 of 63), two independent reviews answered. Migrations `20261240000000_SalesInvoiceDiscount` and `20261240000001_SalesInvoiceDiscountPermission` (nullable columns, permission granted to nobody). Not merged into master, not deployed; **no browser pass and no full suite were run**. **Carried over:** Daftra observations on allocation across tax rates, the credit note on a discounted invoice, rounding of the discount amount and the quote discount (`G-67`, `G-68`, `G-69` in WP22); the purchase-side discount stays `G-59` (WP15) and a dedicated invoice-discount report is `G-70` (WP32); the aggregate Summary_taxes rounding gap (`WP16-TAX-SUMMARY-ROUNDING`) and the missing browser pass (`WAVE1-BROWSER-PASS`) in WP45. Owner questions are in E50.

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

**رصد دفترة (2026-10-28):** D1 observed the allocation (proportional to each line net, unrounded in Daftra, E59) and the return without proration (E60); the page's tax and rounding behaviour is recorded in the rules.
- يُضاف (تقارير المبيعات والعملاء): يُضاف إلى خصم الفاتورة (SAL-07): توزيع الخصم على السطور في تقارير البنود والربح بالتناسب مع صافي كل سطر بعد خصمه (مرصود بدقة السنت على فاتورتين: 13.50 و22.00 من 35.50، و7.61 و12.39 من 20.00)، وعمود «الخصومات» في «مبيعات العملاء» = خصم الفاتورة وحده. اختبار…
- يُضاف (النماذج): Observed computation to align WP16 (merged) with: mode select percent/amount; percent input numeric <= 100 (no minimum); amount converted to a percent over the sum of line nets after line discounts, then applied to each line net unrounded; taxes on the…
- قرار مطلوب (النماذج): Two divergences between WP16 and what the Daftra page does: (1) WP16 rounds each line share to currency decimals and puts the remainder on the last line, while the page keeps shares unrounded (server rounding at save is unobserved: OBS-D); (2) the page does…
- **قواعد مرصودة** (4؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-DSC-01`، `FRM-R01`، `FRM-R02`، `FRM-R05`

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

**رصد دفترة (2026-10-28):** **No observation needed (2026-10-28):** D2 observed edit and delete of a saved line (E58); item purchase taxes are read in the forms.
- قرار مطلوب (تقارير المشتريات والمخزون والخزائن): سطر فاتورة شراء بلا صنف: فاتورة الخليج 000001 بند «مستلزمات مكتبية وتشغيلية» (5 وحدات، 5,000) يجمعه تقرير مشتريات المنتجات تحت «UNKNOWN». الاستلام عندنا من أصناف قائمة. قرار: بند نصي أو صنف خدمة غير مخزني، وحسابه. كذلك عمودا «الرقم الضريبي» و«رقم تعريف…
- يُضاف (النماذج): Item purchase taxes are observed: product form fields «ضريبة الشراء 1» and «ضريبة الشراء 2» appear after «متقدم» with options «مطابق لضريبه المبيعات 1/2» (default), «القيمة المضافة» and «بدون ضريبة» (-1); documents default the line tax by…
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R29`

### WP18: Inventory valuation report from valued movements

**الحالة (2026-10-28): done (WP18)** — merged into the development branch under E47 ([E55](../decisions.md), [report](wp18-report.md)): `InventoryValuationTest` (50 test methods) and `AccountingInventoryReportsControllerTest` (43) plus additions to `ReportsControllerTest`, `report_helperTest` and `HomeTest`, 71 guards each disabled alone and detected (71 of 71), two independent reviews answered (ten claims, section 8 of the report). No migration, no new module and no new permission (the existing `accounting_reports` module, granted to nobody automatically). Not merged into master, not deployed; **no browser pass, no accessibility pass and no full suite were run by the author.** **Carried over:** inventory aging and turnover and the rest of `REP-14` (WP33); Daftra's valuation report columns, layout and filters (`G-89`, WP20); four owner decisions: restrict by stock-location grants (`G-90`, WP30), keep the old-basis column (`G-91`, WP44), a log of historical shelf quantities (`G-92`, WP27), forbid moving a location that has posted movements to another branch (`G-93`, WP37); PDF and Excel export and the print header (`P8-EXPORT`, `P8-PRINT`); the `ScopedReportRequest` trait is used by this screen only (`SCOPED-REQUEST-ADOPTION`, WP44); the missing browser pass (`WAVE2-BROWSER-PASS`, WP45); the test-order finding (`MIGRATION-HELPERTEST-ORDER`, WP45). The report's proposal kept `G-45`/`INV-15` as 'partial'; here `G-45` is `done (WP18)` and the matrix row `INV-15` stays 'differs', with the remainder split into the items above.

**لماذا هنا:** The existing inventory report multiplies cost by quantity (1,320) while the ledger-based value is 1,314; the ledger-based value exists only in the library and tests. Daftra's valuation report ties to account 1203 (1,720) and is observed.

**ثوابت لا تُكسر:**
- Value comes from valued stock entries (StockLedger), never cost price times quantity
- Per location the total equals the 1203xxx sub-account (StockLedger::reconcile)
- Read-only; branch scope; units with no valued movement are flagged unposted, not silently valued

**اختبارات الإثبات:**
- StockLedgerTest tie plus InventoryValuationReportTest (new): 1,314 versus 1,320 case
- Opening-stock locations and partially posted locations show flagged units
- ReportsControllerTest permission and branch scope

**رصد دفترة (2026-10-28):** OBS-A read the stock report layouts; the valuation reference is the valued movements (E55), Daftra shows three values (G-45 update).
- يُضاف (تقارير المشتريات والمخزون والخزائن): ثلاث شرائح على الحركات المقيَّمة: (1) جعل `reports/inventory_summary` يقرأ قيمة الحركات لا تكلفة الصنف × الكمية، وإظهار الموقع والقيمة ومطابقة 1203xxx والوحدات «بلا ترحيل»؛ (2) «ميزان المنتجات» REP-35: قبل/وارد/منصرف/صافٍ (كمية ومبلغ) بمدى تاريخ ومستودع…
- قرار مطلوب (تقارير المشتريات والمخزون والخزائن): مرجع المطابقة: عند دفترة ثلاث قيم (4,971.53 الأستاذ، 4,771.53 مجموع قيم الحركات، 4,634.39 المتوسط × الكمية) فلا يوجد «رقم دفترة» واحد يُطابَق. التوصية: تعتمد القيمة من الحركات المقيَّمة المساوية لحساب الموقع (ما نصّت عليه ثوابت WP18)، ولا يُقلَّد فرق 200.00…
- ينفتح بالرصد (تقارير المشتريات والمخزون والخزائن): تخطيط الأعمار والدوران (REP-36 وREP-37) رُصد بأعمدته وصيغه، فيمكن ضمه إلى WP18 أو إبقاؤه في WP33 دون انتظار رصد؛ وتخطيط ميزان المنتجات والقيمة التقديرية ورصيد المخازن رُصد كذلك. وتبقى قاعدة الكمية الباقية (FIFO أم متوسط) في أعمار المخزون غير مرصودة.
- **قواعد مرصودة** (9؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-IV-04`، `RP-IV-05`، `RP-IV-06`، `RP-IV-07`، `RP-IV-09`، `RP-IV-10`، `RP-IV-11`، `RP-IV-14`، `RP-IV-16`

### WP19: Automatic-journal metadata edit and manual-journal parity

**الحالة (2026-10-27): done (WP19)** — merged into the development branch under E47 ([E51](../decisions.md), [report](wp19-report.md)): 175 new test methods, 120 guards each disabled alone and detected (120 of 120: 94, then 26 after the independent review), 13 review findings all confirmed and fixed. Migrations `20261250000000_JournalAttachments` and `20261250000001_JournalDetailsPermission` (permission granted to nobody; `writable/accounting_attachments` must be writable and backed up). Not merged into master, not deployed; **no browser, accessibility or real upload and download pass** (the report's section 3 also says no independent review was run, which contradicts its section 7; section 7 is the later one). **Carried over:** line tax and currency of the manual journal (`G-73`, WP38 and WP28), journal import (`G-74`, WP38), description-only edit in a closed period (`G-71`, WP21), Daftra attachment limits (`G-72`, WP20), required line description default (`G-76`, WP21), preview, virus scan and legacy Office formats (`G-75`, WP45), the missing browser pass (`WAVE1-BROWSER-PASS`, WP45). Owner questions are in E51.

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

**رصد دفترة (2026-10-28):** OBS-A read the manual-journal form rules and the audit events of a journal edit.
- يُضاف (النماذج): Manual-journal parity from the form: required valid date; at least two lines; debit and credit non-empty (empty becomes 0) and non-negative; entering a debit zeroes the same line's credit and vice versa; totals balanced within 0.0001; line description marked…
- قرار مطلوب (النماذج): Daftra shares one counter between automatic and manual journals while the separate-manual-numbering setting is off (observed state) and lets the manual number be typed; Expro keeps a separate manual series and an automatic number. Decide whether to add the…
- يُضاف (الأدوار): حدث تدقيق لكل تعديل بيانات قيد: حدث للقيد وحدث لكل سطر معدَّل بأسماء الحقول المتغيرة (دفترة: «قام بتعديل قيد يومية #… كود القيد: <قديم> <جديد> وصف المستخدم: …» و«قام بتعديل سطر قيد يومية … وصف الحركة: …»). فحدّ WP19 الأدنى: تعديل الوصف ومركز التكلفة مع حدث…
- يُضاف (الطباعة وإقفال السنة): إجراء «نسخ قيد» يفتح نموذج قيد يدوي مملوءًا من قيد قائم (`journals/add/{id}`) إلى جانب «قيد عكسي» (مرصود سابقًا). يُنفَّذ عندنا نموذج مسودة جديدة بلا ربط بالأصل ما لم يقرر المالك غيره (ACC-40).
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R37`، `FRM-R38`، `RP-ACT-05`

### WP20: Daftra observation batch 1: read-only reports, forms, prints, role matrix, year-end

**لماذا هنا:** Read-only evidence that unlocks the report parity and settings packages. Requires the owner's credentials through a 600 file in /dev/shm and the REA tool, neither available now, so it is scheduled after everything that can proceed without them.

**ثوابت لا تُكسر:**
- GET only, no saves, no deletes; trial account opes.daftra.com only
- Credentials only from a 600 file in /dev/shm shredded afterwards and never written to the repository
- Evidence ids and numbers only, no copied Daftra text, code or assets (E11)

**اختبارات الإثبات:**
- No product code: deliverable is evidence entries, matrix updates and fixtures T-26 onward (T-25 is the WP3 expense journal)
- ParityFixtureTest registers every new evidence id; conformance-matrix counts updated

**رصد دفترة (2026-10-28):** **Largely consumed by OBS-A (2026-10-28):** R-02 to R-05 read except the print header content of the statements, the cost-centre and asset report columns and Y9 (batch E). Safe-read rule added below.
- يُسحب (تقارير الحسابات): غطّت دفعة rep-accounting جزء R-02 الخاص بالحسابات العامة: الميزان بأنواعه الثلاثة والأستاذ بمرشحاته والدخل والميزانية والربح والخسارة بنوعيه والتدفقات والضرائب والإقرار (دون ضغط التسوية) وحركات القيود وقيودها اليومية ودليل الحسابات والمصروفات والحركات…
- يُسحب (تقارير المشتريات والمخزون والخزائن): تُسحب من WP20 (R-02) قراءات: تقارير المشتريات والموردين والمخزون والمصروفات وسندات القبض والخزائن وسجل النشاطات والموظفين (أُنجزت 27/10/2026 مع ملاحظاتها وأدلتها). يبقى في WP20 ما لم تقرأه هذه المنطقة: الأستاذ المفلتر وحركات القيود ومراكز التكلفة والتدفقات…
- يُسحب (النماذج): R-03 is largely consumed: the forms of invoice, estimate, credit note, recurring invoice, purchase invoice/refund/debit note, stock permits, stocktake, price list, client, supplier, product, unit template, categories, brand, expense, revenue voucher,…
- يُسحب (الأدوار): R-04 رُصد في OBS-A فلا يلزم WP20 منه إلا ما لم يُقرأ: رسائل المنع والصفحات المحظورة (الحقل فارغ) ونتيجة «نسخ الدور» (`roles/add/{id}` بـGET، قراءة آمنة غالبًا).
- اعتماد جديد (الأدوار): قاعدة قراءة آمنة تُضاف إلى تعليمات كل دفعة قراءة (R-03 وR-05): لا GET لأي رابط يحوي `local_entities/custom_data/.../create` ولا `local_entities/create` (ينشئ سجل نموذج مخصص). والسجلات الأربعة الباقية (W-001 إلى W-004) تُنظَّف في دفعة التنظيف بعد معرفة صفحة…
- يُسحب (الطباعة وإقفال السنة): R-05 (المطبوعات) أُنجز بالقراءة: 79 حقيقة (69 مرصودة و10 استنتاجات) في observations/obs-a/prints-yearend.md. Y9 قُرئ ما أمكن: الحساب بلا سنة مالية فلا إغلاق يُرصد بالقراءة، فيبقى مفتوحًا وينتقل كتابةً إلى WP21. المتبقي من WP20 في هذه المنطقة: قراءات صغيرة بلا…
- قاعدة قراءة آمنة تُضاف إلى كل دفعة قراءة: لا `GET` لرابط يحوي `local_entities/custom_data/.../create` ولا `local_entities/create` (ينشئ نموذجًا مخصصًا فارغًا؛ [السجل](daftra-write-log.md) W-001 إلى W-004).

### WP21: Daftra write-observation batch A: closed periods and messages, settings scenarios, numbering toggle, inclusive tax

**لماذا هنا:** Package C (S-15, S-17) and D (S-03, S-26, S-28, S-29, S-32, S-33, S-16, S-30) are authorized for the trial account only by E47 and unlock inclusive tax, routing types 2 and 4, stock permits, roles, numbering and multi-currency. Credentials are absent now.

**ثوابت لا تُكسر:**
- Trial account only; revert every changed setting; the closed-period scenario temporarily blocks posting in the whole account, so run it last and confirm the reopen
- No deletion of the account, no ownership or login changes, no real customer notification (E47)

**اختبارات الإثبات:**
- No product code first: fixtures T-26 onward (T-25 is the WP3 expense journal), then tests for our behaviour (PeriodGuardTest message mapping, inclusive-tax fixture run against Tax_lib::get_included_tax for SAL-05)
- Classification flips (exact versus differs) only through ParityFixtureTest-backed fixtures

**رصد دفترة (2026-10-28):** **Batch C (global settings) is running and batch E (closed periods and year-end) is queued (2026-10-28)**; ACC-19 off state observed; S-32 and S-33 keep their open cases.
- اعتماد جديد (النماذج): To open the gated forms WP21 must toggle global settings in the trial account and revert them (record original values in daftra-write-log): sales order (enable_sales_order off), sales debit note (enable_debit_note off), purchase credit note…
- يُسحب (الأدوار): ACC-19: حالة تعطيل الترقيم المستقل للقيود اليدوية رُصدت (تسلسل مشترك 000135) فلا يبقى في WP21 إلا حالة التفعيل (حفظ مفتاح النوع 86 ثم إعادته). وجزء قراءة مصفوفة الدور من اعتماد WP21 على WP20 (R-04) انتهى.
- يُضاف (الأدوار): S-32 بما هو مطلوب فعلًا: إنشاء دور اختبار فارغ وموظف اختبار (يُعطَّل بعد القراءة)، والدخول به وقراءة القائمة الجانبية لكل خيار (1001 و34 و36 و99 و100 و16 و17 و20 و21)، وتجربة 4 بلا 3 و27 بلا 85، ورسائل المنع والصفحات المحظورة، وأثر التعطيل وحذف دور مرتبط…
- يُضاف (الطباعة وإقفال السنة): أضف سيناريو سنة مالية يُشغَّل في آخر الدفعات: (أ) إنشاء السنة الأولى 01/01/2026 12 شهرًا وقراءة قائمة السنوات ومرشح السنة في الدخل والميزانية بلا إقفال؛ (ب) إنشاء سنة ثانية وإغلاق الأولى وقراءة القيود الثلاثة بأرقامها وحالة «مغلق» وإجمالي الربح/الخسارة وقائمة…
- اعتماد جديد (الطباعة وإقفال السنة): قيدان على التنفيذ: (1) باقة Free تنتهي 04/11/2026 ومستهلكها 35 من 50 للفواتير وعروض الأسعار (15 مستندًا متبقيًا ولا يُعرف أتُحتسب الإشعارات والمرتجعات والمحذوفات) و4 من 15 للعملاء و1 من 2 للمستودعات والفروع 0 من 0؛ فيُرتَّب العمل بحيث تُنجَز الكتابات قبل ذلك…
- بند التنظيف الختامي: حذف النماذج المخصصة الفارغة W-001 إلى W-004 (`W001-W004-CLEANUP`).

### WP22: Daftra write-observation batch B: unobserved assumptions of the sales, purchase, stock and treasury flows

**لماذا هنا:** Each item is an implemented assumption that is not counted as a match until Daftra is watched: the settle button, credit application order, unpaid-partially-returned, no-customer sale, sales return cap, average after receipt deletion, half-cent ties, supplier standalone payment and opening journals (S-35 T-10/T-11), treasury delete rules. It also executes the F second slice (F-04c, F-08 to F-13) that gates WP23 and WP27. Credentials are absent now.

**ثوابت لا تُكسر:**
- Trial account only; S35-RESIDUE cleanup touches only documents created by S-35 (invoice #000027, the disabled 'S35 treasury', S35 supplier, item and purchase)
- No product code changes until a fixture proves a mismatch; deliberate differences (E25, E29, E34) stay
- Stop conditions and reversibility recorded before each write

**اختبارات الإثبات:**
- Fixtures T-26 onward (T-25 is the WP3 expense journal) and ParityFixtureTest registration; existing T-09/T-10/T-13/T-17 classifications change only with evidence
- After G-66 observation: SupplierAdvancesTest journal expectations updated or confirmed

**رصد دفترة (2026-10-28):** **Observation requirement largely met (2026-10-28):** D1, D2 and B observed F-04c, F-08, F-09, F-10, F-11, F-12, F-13, G-26, G-35, G-43, G-49, G-66, S35-T06 and S35-D6; open: the tie-break, S35-D4, PUR-08, SAL-37, G-23, G-55 and the items listed below.
- يُضاف (تقارير المبيعات والعملاء): يُضاف إلى F-12 (فاتورتان مفتوحتان ورصيد يغطي إحداهما) حالة تكسر التعادل: فاتورتان بالتاريخ نفسه ورقمان مختلفان، وفاتورة أقدم بتاريخها وأعلى رقمًا، وإشعار دائن على الأحدث ليُحسم: التاريخ ثم الرقم، وأن فاتورة الأصل بلا أولوية. ويُضاف إلى بنود WP22 اختبار أثر…
- يُضاف (تقارير المشتريات والمخزون والخزائن): يُضاف إلى رصد الافتراضات (كتابة محدودة في الحساب التجريبي فقط): قاعدة «متأخر» لفواتير الشراء، تاريخ حركة المخزون، المرتجع المسدَّد جزئيًا، حالتا صافي الوضع «مرتجع» و«مدفوع بالزيادة»، حدّ تنبيه المخزون، تكلفة مرتجع الشراء بعد مخزون سالب، الرصيد الافتتاحي…
- يُضاف (النماذج): Add to the write observations: the journal and stock effect of an inbound/outbound stock permit including its default unit price 0 and its «الحساب الفرعي» (F-08), the stocktake count sheet and its journal (F-09), the journal of a paid deposit (SAL-42), the…
- اعتماد جديد (الطباعة وإقفال السنة): سيناريوهات الدفعة B تنشئ مستندات تُحتسب على حصة الباقة (15 متبقية من 50) وتنتهي الباقة 04/11/2026؛ فيُجمَّع ما أمكن في مستندات قليلة ويُفضَّل تعديل مستندات قائمة. وقاعدة حارس القراءة: لا يُفتح رابط GET ينشئ سجلًا (سجل الكتابة W-001 إلى W-004).

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

**رصد دفترة (2026-10-28):** **Observation requirement met except the tie-break (2026-10-28):** D1 observed F-10, F-11, F-12 and F-13 (E57, E63); the cash-refund path and the advance payment need batch C.
- قرار مطلوب (تقارير المبيعات والعملاء): أُكِّد ترتيب التطبيق بكتابة مضبوطة (D1، E57): لا أفضلية للفاتورة الأصل ويبقى كسر التعادل غير مرصود؛ تغيير فرع «الأصل أولًا» في `CreditSettlement::apply` هو البند `E57-CREDIT-ORDER` ويعيد تصنيف اختبار الأصل في `CreditSettlementTest`.
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-CRD-01`

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

**رصد دفترة (2026-10-28):** OBS-A read the per-tax inclusive formulas in the page scripts; the account has one non-included tax, so the inclusive behaviour needs a settings-changing observation (batch C).
- ينفتح بالرصد (النماذج): The per-tax inclusive formulas are now readable from the page scripts (taxes page column «متضمنة» غير متضمن=0 / متضمن=1; line: net = x - (sum of included % / (sum of included % + 100)) * x; tax1 and tax2 each on the same net; non-included tax added to the…
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R03`

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

**رصد دفترة (2026-10-28):** **Observation requirement met (2026-10-28):** B observed the cheque cycle (E64) and the payment method independent of the treasury (E65), D2 the revenue voucher (E62 on edit in place). **Correction:** the constant 'journals via 1206/212' needs leaf accounts under those two group accounts, as Daftra's cheque module posts to leaf accounts it creates (E64, G-131).
- يُضاف (تقارير الحسابات): مفردات القيد لدورة الشيكات رُصدت أسماء ومفاتيح: تسعة مصادر (ثلاثة مدفوعة: إستلام وتحصيل ورفض، وستة مستلمة: إستلام وإيداع وتحصيل وتحصيل وديعة ورفض ورفض وديعة) ومصدر `income` لسندات القبض. وتظهر وسيلة الدفع Cheque في الحركات المالية داخلة بمجرد تسجيل الدفعة…
- يُضاف (تقارير المشتريات والمخزون والخزائن): تقارير سندات القبض ثماني صيغ (تصنيف، بائع، مدخل، عميل، يومي، أسبوعي، شهري، سنوي) بنموذج مرشح المصروفات نفسه بلا «التصنيف»، فتُبنى بمحرك واحد مع المصروفات عند WP3/WP33. أعمدتها لا قيودها غير مرصودة (لا سند قبض في الحساب).
- يُضاف (النماذج): Receipt-voucher form is observed: the expense form without the supplier block, number «رقم الإيراد *» (default 000001), amount, currency link, category, treasury, date, recurrence, note, attachments, sub-account (default «آلي»), multiple accounts, cost…
- يُضاف (الطباعة وإقفال السنة): سند القبض يُطبع بنمط سند الصرف نفسه بثلاثة قوالب (A4 ونموذج 2 وحراري 80mm) بحسب المراقب، ونصوصه لم تُنقل فتُكتب بأسمائنا وتُرصد قبل الاعتماد؛ وأنواع قيود الشيكات التسعة تغطيها منطقتا forms وrep-accounting.
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-EX-04`، `FRM-R41`، `FRM-R42`

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

**رصد دفترة (2026-10-28):** D2 observed the stock permits and the stocktake and their journals; the settings that enable the transfer permit and stock requests need batch C.
- يُضاف (النماذج): Stock permit and stocktake forms are observed. Permit: types 1 «إذن إضافة مخزن» (supplier) and 2 «إذن صرف مخزن» (client), numbering 12 and 13 (15 for the transfer permit, form not opened); fields date (default now), sub-account, number (required, next…
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R33` إلى `FRM-R35`

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

**رصد دفترة (2026-10-28):** **Partly met (2026-10-28):** B observed currencies, rates, the FX difference journal and decimals (E66); foreign-currency expense, income and credit note and the rate-update setting stay unobserved.
- يُضاف (تقارير الحسابات): مرشح العملة في التقارير: الميزان والأستاذ `currency=-1` (الجميع إلى SAR)، والمصروفات «الجميع إلي (SAR) / كل على حده / SAR»، والضرائب والإقرار «الجميع إلي (SAR) / SAR»، والحركات المالية قائمة بعملة واحدة؛ وترويسة التقارير «العملة: الجميع (إلى SAR)». لا فروق…
- يُضاف (تقارير المشتريات والمخزون والخزائن): كل تقارير المشتريات والمخزون والمصروفات المرصودة تحمل مرشح «العملة» بثلاثة خيارات: «الجميع إلي (SAR)» و«كل على حده» (Sperated) وSAR؛ ولم يُرصد أثره لأن كل مستندات الحساب بالريال. يلزم رصد عملة أجنبية قبل تصميم أعمدة التقارير.
- يُضاف (النماذج): Observed currency facts: document currency select (161 options, hidden at load with «تعديل العملة» link, hidden manual rate), the update_journal_entries_currency_rates setting shows different states on two pages, treasury-transfer currency and exchange-rate…
- **قواعد مرصودة** (2؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R56`، `RP-SET-03`

### WP29: Banking: bank reconciliation and bank directory

**لماذا هنا:** Bank balances tie to the ledger but nothing matches statement lines; the bank name is free text. E47 prohibits paid external integrations without separate permission, so this stays manual or static.

**ثوابت لا تُكسر:**
- Reconciliation never edits a journal; matched lines are flagged
- Statement import is checksummed and idempotent
- No paid external service without separate permission (E47)

**اختبارات الإثبات:**
- BankReconciliationTest: match, unmatch, idempotent import
- Treasury scope and branch scope; legacy bank accounts missing details are flagged

**رصد دفترة (2026-10-28):** **Partly met (2026-10-28):** B observed the bank-statement import wizard and the reconciliation screen; 'finish and close' and a voucher from a match were not run.
- يُضاف (تقارير المشتريات والمخزون والخزائن): لتبويب «كشف حساب البنك» استيراد CSV/Excel بمحددات (فاصلة، فاصلة منقوطة، Tab) وخيار «استيراد اول صف» وأعمدة التاريخ والوصف وإيداع وسحب والرقم المرجعي، للحساب البنكي فقط. المطابقة نفسها لم تُرصد (لا سطور). لا يُبنى «رفع كشوف حساب بالذكاء الاصطناعي» لأنه خدمة…
- قرار مطلوب (تقارير المشتريات والمخزون والخزائن): WP29 كما كُتب يقول إن مطابقة دفترة (إن وُجدت) لم تُرصد. بقي كذلك: لا بد من رصد بكتابة (استيراد ملف نموذجي في حساب S35 البنكي ثم قراءة التبويب) قبل تصميم المطابقة. قرار: هل يُنفَّذ الاستيراد اليدوي وحده الآن مع تأجيل المطابقة.
- يُضاف (النماذج): Bank account form: name, bank name chosen from a searchable directory (hidden portal_bank_id, portal_bank_provider_id, portal_provider_internal_bank_id; example Riyad Bank id 11), account number as a numeric required input (example 3500000001), currency,…
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-TR-05`، `FRM-R44`، `FRM-R45`

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

**رصد دفترة (2026-10-28):** **Observation requirement met except denial messages (2026-10-28):** OBS-A read the role matrix, its dependency relation and the own-versus-all scope; S-32 needs a second user (batch C).
- ينفتح بالرصد (الأدوار): R-04 مرصود: الكتالوج (119/122) والعلاقة (107 حافة) والنطاق (33 خيارًا ومعنيان) والدوران الجاهزان، ولا نطاق فروع أو مستودع أو خزينة في الدور. يمكن البدء بكيان الدور وجدول ربط الدور بالصلاحيات (بدل نسخ منح الموظف) وكتالوج صلاحياتنا مع جدول مقابلة (صلاحيات الأول…
- قرار مطلوب (الأدوار): (أ) فرض الاستتباع: هل يكون الدور المحفوظ مغلقًا دائمًا أم يُخزَّن كما أُرسل كما يبدو في دفترة (الدور Staff يحمل 4 و414 بلا 3)؟ التوصية: نخزّن كما أُرسل ونفرض كل فحص على المنحة الصريحة، ونُغلق الأدوار المزروعة. (ب) معنى «الخاص به»: نموذجان منفصلان…
- يُضاف (الأدوار): دورة حياة الموظف (حالة نشط/غير نشط بدل الحذف الماسح لكل المنح، كود تلقائي، «السماح بالدخول»، آخر تسجيل دخول)، وجدول المستودع الافتراضي لكل موظف (موظف فريد ← موقع مخزون) في موضع شاشة «المستودعات الأفتراضية للموظفين»، وقاعدة تسجيل صلاحيات كل مستند جديد (زوج…
- **قواعد مرصودة** (13؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-ST-01`، `RP-ROL-01` إلى `RP-ROL-12`

### WP31: Report parity: Daftra filters and layouts, third trial-balance type, statement print header, cash-basis profit and loss, cash flow

**لماذا هنا:** Trial balance and ledger shipped but cannot be called matching until identical inputs are compared; cash-basis recognition, the cash-flow layout, the third trial-balance type and the print header are unobserved. Needs the read-only evidence of WP20.

**ثوابت لا تُكسر:**
- Read-only; branch rules of E45; totals tie to the ledger
- Honest naming: the third shape keeps its own name until it matches Daftra's
- Parity fixtures record Daftra numbers only (122,478 = 122,478)

**اختبارات الإثبات:**
- Fixtures T-26 onward (T-25 is the WP3 expense journal) for trial balance and ledger on the A/B scenario documents
- TrialBalanceTest and AccountLedgerTest ties; print header and export controller tests
- a8-design.md written before UI code (phase checklist)

**رصد دفترة (2026-10-28):** **Observation requirement met except the print header content (2026-10-28):** OBS-A read the trial balance in three layouts, the ledger, profit and loss (both bases), cash flow, the tax report and return.
- ينفتح بالرصد (تقارير الحسابات): ينفتح WP31 بالكامل تقريبًا: الصيغة الثالثة للميزان (انظر RA-TB-01 إلى RA-TB-04) والربح والخسارة بنوعيه وقاعدة النقدي والتدفقات بمعادلاتها وإعدادها والأستاذ بخيارات عرضه ومرشح الموظف وترتيب السطور. المتبقي بلا رصد: ترويسة الطباعة ومحتوى التصدير (R-05). وتُضاف…
- اعتماد جديد (تقارير الحسابات): ملفات تثبيت التقارير تبدأ من `T-26` (`T-25` للمصروف في WP3 المدموج)؛ ومعرّفات `pw-` لا تطابق نمط `ev_` في `ParityFixtureTest` فيُوسَّع النمط أو يُكتب `id: null` بملاحظة؛ واعتماد WP31 على WP4 وWP3 مستوفى.
- قرار مطلوب (تقارير الحسابات): ثلاثة قرارات: (1) الصيغة الثالثة: التوصية تنفيذ `groups_only` (قبل وبعد إجماليان) باسم «حساب مراجعة» بعد اختبار تماثل، وإبقاء صيغتنا الصافية «افتتاح وحركة وإقفال» كما هي (E46 بند 3 يأمر بالتنفيذ بما يطابق) وعدم تنفيذ سلوكَي الرابط الافتراضي و`balances_only`…
- يُضاف (تقارير المبيعات والعملاء): تُضاف قراءة كشف حساب العميل (CUS-12) إلى جانب كشف الأستاذ: صفحة العميل بملخص «مختصر الحساب» وحركاته، وPDF A4 عمودية بصفحة واحدة، وإعدادات الكشف (أعمدة الحركات والمقدمة والتذييل ومتغيراتها). R-05 صار مقروءًا جزئيًا لكشف العميل وحده (عناصر ملف PDF: اسم الشركة…
- ينفتح بالرصد (الطباعة وإقفال السنة): R-05 مقروء: ترويسات المطبوعات (عنوان وفترة واسم النشاط وسطر نطاق)، وخيارات التصدير CSV وExcel وPDF بإعداد الاتجاه وحجم الخط، وتخطيطا كشف العميل والمورد وصفا رصيد بداية المدة ونهايتها، وكشف الخزينة كتقرير أستاذ. فتصير P8-PRINT وترويسة الكشوف قابلة للتصميم بما…
- يُضاف (الطباعة وإقفال السنة): أضف إلى WP31: كشف العميل والمورد (جدول حركات برصيد جارٍ وخلاصة ومقدمة وتذييل قابلان للضبط وأعمدة اختيارية)، وPDF للأستاذ والميزان بترويسة موحَّدة، ونصوص كشف الخزينة؛ وكلها تقرأ الدفاتر بنطاق الفروع ولا تكتب.
- **قواعد مرصودة** (31؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RA-TB-01` إلى `RA-TB-06`، `RA-GL-01` إلى `RA-GL-07`، `RA-PA-01`، `RA-PA-02`، `RA-PC-01` إلى `RA-PC-03`، `RA-CF-01` إلى `RA-CF-05`، `RSC-STM-01` إلى `RSC-STM-03`، `RP-FM-01`، `PY-R12`، `PY-R13`، `PY-R15`، `PY-R20`

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

**رصد دفترة (2026-10-28):** **Observation requirement met (2026-10-28):** OBS-A read 14 revenue views, 20 item views, 9 payment views, two profit reports and the net-position reports.
- ينفتح بالرصد (تقارير المبيعات والعملاء): اعتماد WP32 على R-02 (WP20) للصفوف REP-09 وREP-10 وREP-11 وREP-12 وREP-16 انتهى: الأعمدة والمرشحات والعناوين وقواعد التوزيع والأرقام مقروءة في rep-sales-clients (14 عرضًا للإيرادات و20 للبنود و9 للمدفوعات وتقريرا ربح وتقريرا صافي الوضع والمطابقة). تبقى WP20…
- يُضاف (تقارير المبيعات والعملاء): يُضاف إلى نطاق WP32 ما لم يكن فيه: تقرير «مبيعات العملاء» (REP-24)، وتقرير «مدفوعات العملاء» بتجميع العميل والخزينة والوسيلة (REP-25)، وتقرير الدفعات المقدمة، وتقرير «أرباح مبيعات الأصناف» بأعمدة النسب (RSC-PRF-04)، وهيكل الصفحة (REP-27: الملخص/التفاصيل…
- قرار مطلوب (تقارير المبيعات والعملاء): ثلاثة قرارات تسبق البناء: (1) قاعدة واحدة لتوزيع «مدفوعة/غير مدفوعة/مرتجع»: توصيتي (أ) لا (ب) ولا (ج)؛ (2) تعريف الربح: الحركات المقيَّمة بمرتجع بتكلفة بيعه الأصلي ليتفق مع 511 فتخالف نتيجتنا تقرير الحركات عند دفترة بـ5.70 في بياناتها وتوافق تقرير أرباح…
- اعتماد جديد (تقارير المبيعات والعملاء): اعتمادات جديدة: (أ) WP9 لعمودي «تم التحصيل بواسطة» و«رقم معرف» ولتقرير الدفعات المقدمة ولنوع الفاتورة المرتجعة (التي تُصنَّف «مرتجعًا» لا «تسويات» في أرصدة العملاء)، (ب) WP13 لمرشح «متأخر» وتاريخ التقرير، (ج) WP16 لتوزيع خصم الفاتورة على السطور في تقريري…
- ينفتح بالرصد (تقارير المشتريات والمخزون والخزائن): REP-16 (في نطاق WP32): جانب المشتريات (Purchase Invoice Net Position) صار مرصودًا بأعماله وصيغه (الصافى = الإجمالي + المرتجعات، والحالة من المدفوع مقابل الإجمالي الكلي) فلا يلزم رصد R-02 له؛ يبقى جانب المبيعات في مخرج rep-sales-clients.
- **قواعد مرصودة** (29؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-REV-01` إلى `RSC-REV-06`، `RSC-ITM-01` إلى `RSC-ITM-05`، `RSC-CLS-01` إلى `RSC-CLS-03`، `RSC-PAY-01`، `RSC-PAY-02`، `RSC-PAY-04`، `RSC-PRF-01` إلى `RSC-PRF-04`، `RSC-NET-01` إلى `RSC-NET-04`، `RSC-UI-01`، `RSC-UI-03`، `RP-NP-01`، `RP-NP-02`

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

**رصد دفترة (2026-10-28):** **Observation requirement met except the cost-centre and receipt-voucher columns (2026-10-28):** OBS-A read the purchase, supplier, inventory, expense and activity-log reports and the journal and chart reports.
- ينفتح بالرصد (تقارير الحسابات): انفتح ما يخص REP-08 وREP-15 (جانب المصروفات): أعمدة «حركات القيود» وخيارات تجميعها (رقم قيد اليومية / يوم / شهر / سنة / الحساب الفرعي) ومرشح مصدرها الثلاثة والثلاثين، وأعمدة «القيود اليومية»، وتقرير «دليل الحسابات العامة» بمرشحاته، وتقرير المصروفات بأعمدته…
- اعتماد جديد (تقارير المبيعات والعملاء): تقارير الموردين في REP-13 (أعمار الموردين بست خانات، وأعمار الأستاذ `supplier_aged_ledger`، وأرصدتهم بأعمدة الفترة) تعيد استعمال محرك الأعمار والأرصدة المبني في WP13 بتاريخ تقرير وفترة قابلة للتغيير بدل تنفيذ ثانٍ. قواعد RSC-AGE-04 وRSC-AGE-05 وRSC-AGE-06…
- ينفتح بالرصد (تقارير المشتريات والمخزون والخزائن): حُسم R-02 لعائلات المشتريات والموردين والمخزون (الأعمار والدوران وغيرها) والمصروفات وسجل النشاطات بقراءة الأعمدة والمرشحات والأرقام (27/10/2026، 298 حالة صفحة). لا يزال في WP33 ما لم يُقرأ هنا: تقارير القيود ودليل الحسابات ومراكز التكلفة (تتبع مخرج…
- يُضاف (تقارير المشتريات والمخزون والخزائن): يُضاف للنطاق: REP-28 مدفوعات الموردين، REP-29 مشتريات المنتجات، REP-30 إلى REP-37 تقارير المخزون، FIN-13 كشف الخزينة بالمرشحات والترقيم، وصيغ المشتريات الزمنية (يومي/أسبوعي/شهري/سنوي) بإظهار الفترات الفارغة بأصفار، وتفصيل بالمدخل، وتقرير مشتريات الموردين…
- اعتماد جديد (تقارير المشتريات والمخزون والخزائن): كل تقرير جديد يطبّق نطاق الفرع (G-48 في WP1: `reports_receivings` وغيره لا يقيّد بالفرع اليوم)؛ وتقارير المشتريات الزمنية والمتأخرة تحتاج تاريخ الاستحقاق والتاريخ المُدخَل (WP13)؛ وتقارير المصروفات المقسمة تحتاج عمود الخزينة وحساب التصنيف (WP3)؛ وتقارير…
- قرار مطلوب (تقارير المشتريات والمخزون والخزائن): قرارات: (1) تعريف واحد لصافي المشتريات (14,384) في كل التقارير ولا يُنسخ 14,729 ولا 15,971 (RP-PU-04)؛ (2) نطاق سجل النشاطات: أحداث الأعمال (41 نوعًا) أم التدقيق المحاسبي مع ترقيم حقيقي بدل حد الـ30؛ (3) تعريف الدوران القياسي (النسبة = تكلفة المبيعات ÷ متوسط…
- يُضاف (الأدوار): REP-17 صار محدّدًا: أنواع الأحداث ومرشحاتها وتركيب الـ672 (دخول 57.7%) ونصوصها، والمطلوب قرار نطاق (سجل دخول بـIP وطباعة وتنزيل وتعديل المستندات التجارية بجانب أحداثنا التحكمية) وتصحيح التعارض مع الرصد المجاور (الترقيم بـ`p` و43 مقابل 41 إجراءً) بقراءة واحدة.
- **قواعد مرصودة** (37؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RA-JR-01`، `RA-JR-02`، `RA-CH-01`، `RA-FM-01`، `RA-EX-01`، `RP-PU-01`، `RP-PU-02`، `RP-PU-03`، `RP-PU-04`، `RP-PU-05`، `RP-PU-07`، `RP-PU-08`، `RP-PP-01`، `RP-PP-02`، `RP-PY-03`، `RP-SD-01`، `RP-SS-01`، `RP-SS-02`، `RP-IV-01`، `RP-IV-02`، `RP-IV-03`، `RP-IV-08`، `RP-IV-12`، `RP-IV-13`، `RP-IV-15`، `RP-EX-01`، `RP-EX-03`، `RP-TR-02`، `RP-TR-04`، `RP-AL-01` إلى `RP-AL-03`، `RP-ACT-01`، `RP-ACT-02`، `RP-ACT-03`، `RP-ACT-04`، `RP-ACT-06`

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

**رصد دفترة (2026-10-28):** OBS-A read the header charges (adjustment, shipping, deposit); the sales debit note needs its setting enabled (batch C).
- يُضاف (النماذج): Header charges observed: adjustment (label text + signed number, added only while the running total > 0, gated by enable_sales_adjustment which is on, present on invoice and credit note, absent on purchases where the setting is off), shipping (options «آلي» /…
- اعتماد جديد (النماذج): The sales debit note (SAL-20) has only its setting (enable_debit_note off, next 00001, numbering heading «الاشعارات المدينة للمبيعات») observed; its form and journal need the setting enabled in the trial account and reverted (OBS-C then OBS-D).
- اعتماد جديد (الأدوار): الإشعار المدين للمبيعات يحمل في دفترة ستة خيارات صلاحية (427 إلى 432: إضافة وتعديل وحذف وعرض بنطاق الكل والخاص) ونوع ترقيم 34 بخمس منازل (5 منازل افتراضيًا، التالي 00001)؛ يُسجَّلان وقت بناء المستند.
- **قواعد مرصودة** (4؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R04`، `FRM-R06`، `FRM-R07`، `FRM-R08`

### WP35: Order-to-cash documents: sales orders, recurring invoices, recurring expenses

**لماذا هنا:** No sales order, subscription or expense recurrence exists; Daftra's generation timing and document behaviour were deliberately not observed (S-34 creates future documents).

**ثوابت لا تُكسر:**
- Generated documents follow the normal posting path and numbering
- Generation is idempotent per schedule and date
- No posting for orders until invoiced

**اختبارات الإثبات:**
- Generator command test with repeated runs and a closed period
- Conversion tests; branch scope

**رصد دفترة (2026-10-28):** OBS-A read the recurring invoice and expense forms; sales orders need their setting enabled (batch C).
- يُضاف (النماذج): Recurring invoice (add_subscription) and recurring expense forms are observed: schedule fields, validation (issue-before days must be less than period x 1/7/30/365 for days/weeks/months/years; period and max repeat >= 0; name required), up to 10 reminders…
- اعتماد جديد (النماذج): Sales orders: the add form is not reachable while the module is off (add button redirects to the invoice list; /owner/sales_orders/add renders the plain invoice form); only settings (enable_sales_order, next 00001, exceeding-quantity toggle, offers) and a raw…
- اعتماد جديد (الأدوار): أوامر البيع تحمل ستة خيارات صلاحية (421 إلى 426) وخيارًا لتحويلها إلى فاتورة (433). ونوع ترقيمها 32 (رابط في صفحة إعدادات أوامر البيع بحسب رصد النماذج) غائب عن قائمة الأنواع الـ26 الظاهرة لأن الوحدة معطّلة في الحساب، فعدد أنواع الترقيم الكلي أكثر من 26 ولا…
- **قواعد مرصودة** (5؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R14` إلى `FRM-R18`

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

**رصد دفترة (2026-10-28):** OBS-A read the price-list form and the minimum-price check; credit limit and maximum discount need their settings enabled (batch C).
- يُضاف (تقارير المبيعات والعملاء): إعدادات كشف الحساب في دفترة تحمل متغيرين يحتاجان حقلين عندنا: «الحد الائتماني» و«فترة السماح» (يظهران في مقدمة الكشف المخصصة). فيدخلان قائمة حقول العميل مع CUS-04 ويُعرضان في مقدمة الكشف عند بنائها. لا أثر على قفل الحد الائتماني نفسه.
- يُضاف (النماذج): Price list create form: «الاسم *» and «الحالة *» (نشط default / غير نشط) only; per-list prices live on the product form (placeholder «use default price»); default list and change-on-invoice settings (off). Minimum price: product field «أقل سعر بيع» with a…
- اعتماد جديد (النماذج): Credit limit and credit period need their two settings enabled in the trial account and reverted to observe the blocking message and what counts as outstanding (OBS-C then OBS-D); the same for the maximum-discount setting and the excluded-categories lists.
- **قواعد مرصودة** (2؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R19`، `FRM-R20`

### WP37: Product and customer master data

**لماذا هنا:** Field-level gaps in items, warehouses, categories, barcodes and customers; mostly schema and form work with the field lists already observed.

**ثوابت لا تُكسر:**
- Additive columns only; existing items and customers unchanged
- Warehouse status and primary flag never orphan stock or sub-accounts
- Customer type changes do not alter posted documents

**اختبارات الإثبات:**
- ItemsControllerTest/CustomersControllerTest field tests
- Warehouse model tests; migration up/down tests

**رصد دفترة (2026-10-28):** **Observation requirement met (2026-10-28):** OBS-A read the client, supplier, product, category, brand and unit-template forms.
- يُضاف (تقارير المبيعات والعملاء): حقول العميل التي تظهر في تقارير دفترة: تصنيف العميل (مرشح في كل تقارير هذه المنطقة)، الحالة اليدوية بخمس قيم، الموظف المسند، المنطقة والمدينة ورمز الدولة وتاريخ الإنشاء كمرشحات «دليل العملاء»، نوع العميل (فردي، تجاري)، جهة حكومية. تقرير «دليل العملاء»…
- ينفتح بالرصد (النماذج): Field lists, defaults, required markers and conditional rules of the client, supplier, product (and service), category, brand and unit-template forms are observed (see rules). Still unobserved: the meaning of «موقوف» versus «غير نشط» for products, category…
- **قواعد مرصودة** (7؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R22`، `FRM-R23`، `FRM-R24`، `FRM-R25`، `FRM-R27`، `FRM-R28`، `FRM-R59`

### WP38: Cost-centre distribution, journal settings, shipping-company accounts

**لماذا هنا:** Cost centres exist one per journal line; Daftra distributes by percentage with over-100% and mandatory settings, tags, tax on lines and a cost-centre report. Rounding and messages were never observed.

**ثوابت لا تُكسر:**
- Distribution sums to 100% (or the observed allowance) and never changes debit or credit
- Existing single-centre lines unchanged

**اختبارات الإثبات:**
- CostCenterServiceTest distribution and rounding
- JournalServiceTest line tax and tags storage

**رصد دفترة (2026-10-28):** OBS-A read the cost-centre distribution form; no cost centre exists in the account, so the report columns are unobserved.
- يُضاف (تقارير الحسابات): يُضاف إلى نطاق WP38: تقرير مراكز التكلفة (تجميع حسب مراكز التكلفة / الحسابات الفرعية / يومياً / أسبوعياً / شهري / سنوي، ومركز رئيسي وفرعي، والحساب، والفترة)، ومرشح مركز التكلفة في حركات القيود بالخيار «بدون مراكز تكلفة»، وترويسة القوائم «مراكز التكلفة: كل…
- يُضاف (النماذج): Cost-centre distribution UI: a single chosen centre becomes one row at 100%, choosing an account fetches the account's automatic centres with default percentages, the distribution table needs percentage and amount per row and shows both totals, the row side…
- **قواعد مرصودة** (2؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R39`، `FRM-R40`

### WP39: Purchase cycle documents: purchase request, quotation request, quotation, purchase order

**لماذا هنا:** None of the four documents or their manual statuses exists; only a settings list was seen in Daftra.

**ثوابت لا تُكسر:**
- No journal or stock until a purchase invoice is saved
- Numbering from WP8; conversion is explicit

**اختبارات الإثبات:**
- Document lifecycle and conversion tests; branch scope

**رصد دفترة (2026-10-28):** The four purchase-cycle documents stay unobservable while the app is disabled (batch C).
- اعتماد جديد (النماذج): The four purchase-cycle documents stay unobservable: the app «دورة المشتريات» is disabled, list URLs redirect to the dashboard and add URLs return 404; only four manual-status toggles exist. Note the internal entity purchase_order is the purchase invoice, so…
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R57`

### WP40: Templates, print outputs and extra fields

**لماذا هنا:** Fixed templates exist; Daftra has a template designer, vouchers, labels, copy invoice and custom invoice fields. Print layouts were never read.

**ثوابت لا تُكسر:**
- Printed documents never change posted figures
- Custom fields do not affect journals

**اختبارات الإثبات:**
- Document rendering tests; PdfLib tests
- Custom-field storage tests

**رصد دفترة (2026-10-28):** **Observation requirement met (2026-10-28):** OBS-A read the print outputs, the template designers and the extra-field builders.
- يُضاف (النماذج): Extra-fields builder has 20 field types (listed in rule FRM-R53), builders for products (custom_fields/1), invoices (/2) and React builders per entity (client, supplier, journal, asset, purchase_order, staff); documents also carry a terms-and-conditions…
- ينفتح بالرصد (الطباعة وإقفال السنة): R-05 مقروء: مستند كل نوع بعنوانه وتسمياته وعناصره، والقسائم الثلاث، والإيصالات بأنظمتها، وسند الصرف بالتفقيط، وPDF القيد، ومصممات القوالب (أعداد القوالب والمتغيرات وحقول المصمم). فلم يعد «Print layouts were never read» صحيحًا، ويمكن تقسيم WP40 إلى مرحلتين…
- يُضاف (الطباعة وإقفال السنة): أضف إلى WP40: (1) QR المرحلة الأولى على الفاتورة والإشعار الدائن (باني TLV نقي وطبقة مستند ترفض غياب الرقم الضريبي ولا QR في عرض السعر ولا فاتورة الشراء)؛ (2) ملف تعريف الإشعار الدائن في `documentProfile`؛ (3) طباعة فاتورة الشراء وPDF؛ (4) القسائم الثلاث؛ (5)…
- قرار مطلوب (الطباعة وإقفال السنة): هل نبني مصمم القوالب (اسم، مقاس A4/A3/A5/Other وعرض وارتفاع، أربعة هوامش، محتوى بمتغيرات وكتل متكررة وتذييل، قوالب جاهزة بتعديل ونسخ، اختيار القالب لحظة الطباعة، تصميم أساسي مستقل لكل مستند) أم نكتفي بقوالب ثابتة موسّعة بإعدادات؟ الأول عمل كبير (11 عائلة و242…
- **قواعد مرصودة** (13؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R53`، `FRM-R60`، `PY-R01`، `PY-R02`، `PY-R03`، `PY-R04`، `PY-R05`، `PY-R08`، `PY-R09`، `PY-R10`، `PY-R11`، `PY-R28`، `PY-R29`

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

**رصد دفترة (2026-10-28):** OBS-A read the role options and the apps manager; the secrets screens need an owner decision on their permission.
- قرار مطلوب (الأدوار): لا خيار في مصفوفة الدور لطرق الدفع ولا SMTP ولا API ولا إدارة التطبيقات ولا الترقيم ولا قواعد الإرسال الآلي (استنتاج: للمالك فقط). القرار: تُحجب هذه الشاشات عندنا بصلاحية `config` الموجودة أم بمنحة حساسة جديدة للأسرار؟ التوصية: مفاتيح API وكلمة SMTP حساسة…
- قرار مطلوب (الطباعة وإقفال السنة): هل نحتاج مفهوم تفعيل التطبيقات لكل حساب (36 تطبيقًا في دفترة، 7 مفعّلة)؟ البديل القائم وحدات بصلاحيات الموظف ومفتاح محاسبة D1. التوصية: لا تفعيل لكل حساب الآن، وتسجيل الفرق قرارًا، ويُعاد النظر حين تُبنى وحدة اختيارية مستقلة (الشيكات أو الفروع).
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-SEC-02`

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

**رصد دفترة (2026-10-28):** OBS-A read the unit-template form and the negative-stock options; bundle, batch and conversion behaviour need batch C then D.
- يُضاف (النماذج): Unit template form observed (main unit name, small name <= 25, template name, active, larger-unit rows with name, factor, small name, hidden update_all_costs); the three negative-stock options and the available-versus-on-hand validation select; bundle type 1…
- اعتماد جديد (النماذج): Multi-unit, bundle and tracking behaviour needs the three settings enabled in the trial account and reverted (OBS-C), then write scenarios for bundle cost, batch picking and unit conversion (OBS-D); depends on WP27 as before.
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R50` إلى `FRM-R52`

### WP43: Purchase received-state and draft (decision-gated)

**لماذا هنا:** Daftra books an unreceived purchase to 1210 and moves it to inventory in place; the owner declined it (E27) because it conflicts with E15 (reverse and repost). Do it last and only on the owner's word; behaviour is already evidenced (S-13).

**ثوابت لا تُكسر:**
- In-place Daftra behaviour is emulated by reverse and repost with a new revision, never by updating a posted journal (E15)
- Unreceived purchases move no stock; stock and journal move together on confirm
- Draft re-issue takes a new number

**اختبارات الإثبات:**
- ReceivingPostingTest: unreceive, confirm, draft, reissue
- StockLedger::reconcile through each state; closed periods; legacy receivings default to received

**رصد دفترة (2026-10-28):** No change: the received-state defaults are recorded, E27 stands.
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R30`

### WP44: Report export, reports-hub link, phone layout and review polish

**لماذا هنا:** Usability slice with no Daftra access needed: server-side PDF and Excel export that re-runs the report with branch scope (the client-posted table export would bypass it), a hub entry, stacked cards on phones and the leftover review findings. Lowest priority, schedulable in spare capacity while credentials are missing.

**ثوابت لا تُكسر:**
- Export re-runs the report with the employee's branch scope and the same balanced totals
- No change to report figures

**اختبارات الإثبات:**
- ExportsControllerTest and AccountingReportsControllerTest for the new export
- ui-smoke three sizes without horizontal scroll
- Arabic-locale render test

**رصد دفترة (2026-10-28):** OBS-A defined the export (server-side CSV, Excel, PDF) and the settings gaps; Daftra export defects are not copied.
- يُضاف (تقارير الحسابات): يتحدد التصدير: نمط `<مسار التقرير>.{csv/xlsx/pdf}?<المرشحات نفسها>` يعيد تشغيل التقرير على الخادم، ونافذة إعدادات PDF (اتجاه عمودي/أفقي وحجم خط 10 إلى 50 افتراضي 14) للميزان والأستاذ، و«PDF بدون رسم بياني» للربح والخسارة، ولا تصدير لمراكز التكلفة. التسمية…
- يُضاف (تقارير المبيعات والعملاء): يُحدَّد نطاق التصدير من المرصود: CSV وExcel وPDF وPDF بدون رسم بياني (لتقارير الرسوم) وطباعة، يعيد تشغيل التقرير بنطاق فرع الموظف ويطابق الجدول المعروض بخاتمته. وتُدوَّن عيوب دفترة الأربعة لتُمنع في اختباراتنا: وسم HTML داخل خلايا CSV، وملف `.xls` قديم باسم…
- يُضاف (تقارير المشتريات والمخزون والخزائن): التصدير بالخادم لتقارير المشتريات والمخزون والمصروفات بصيغ CSV (UTF-8 مع BOM) وExcel وPDF مع خيار PDF بلا رسم بياني لتقارير بعينها وزر طباعة، ويعيد تشغيل التقرير بنطاق الفرع نفسه. كشف الخزينة وسجل النشاطات بلا تصدير عند دفترة (فعندنا تصديرهما زيادة اختيارية)…
- يُضاف (الأدوار): لا حزمة تملك الإعدادات العامة، فتُقترح هنا أو في حزمة جديدة: (1) إضافة الرياض والكويت وبغداد وقطر والبحرين وعمّان إلى `get_timezones` (إصلاح صغير فوري)؛ (2) صيغ التاريخ الـ22 بتقويم أم القرى عبر intl؛ (3) أول يوم في الأسبوع وتنسيق القيم السالبة وطريقة عرض…
- يُضاف (الطباعة وإقفال السنة): التصدير من الخادم يشمل CSV وExcel وPDF بالمرشحات نفسها، وإعدادات PDF اتجاه الصفحة (طولي/عرضي) وحجم الخط، وصفوف CSV بعدد حقول الترويسة نفسه (شذوذ كشف المورد)، وعرض التقرير بلا أي كتابة (دفترة ترسل POST تلقائيًا من صفحة التقرير).
- **قواعد مرصودة** (11؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RA-EXPORT-01`، `RSC-UI-02`، `RSC-UI-04`، `RP-XP-01`، `RP-XP-02`، `RP-FMT-01`، `RP-SET-01`، `RP-SET-02`، `RP-SET-04`، `PY-R14`، `PY-R16`

### WP45: Performance measurements, account picker and residual hardening

**لماذا هنا:** Maintenance slice: a search picker before activation on a business with many customers, benchmarks for the sale save with posting and the new reports, the sale-level oversell test, documenting accepted differences and syncing stale docs. No Daftra access needed except the INV-07 blocking settings.

**ثوابت لا تُكسر:**
- No behaviour change; measurements are recorded, not asserted against production
- Documented differences are listed as intentional

**اختبارات الإثبات:**
- Benchmark tests in tools/benchmarks for Sale::save_value with posting, settlement with thousands of open documents, opening-stock plan, trial balance on large journals
- SalePostingTest: sell 20 with 8 on hand saves, stock -12, cost journal 20 x cost
- AccountingJournalsControllerTest picker uses search

**رصد دفترة (2026-10-28):** رصد OBS-A يضيف ما يلي.
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RP-SEC-01`

### WP46: E-invoicing (ZATCA phase 2)

**لماذا هنا:** Only reserved columns exist. Requirements must be fixed from the official specification when the phase starts, not from Daftra.

**ثوابت لا تُكسر:**
- Issued invoices are corrected by notes, never edited (D7)
- Signing keys never stored in the repository

**اختبارات الإثبات:**
- Document-level field tests; signing and submission against the official sandbox

**رصد دفترة (2026-10-28):** OBS-A read the e-invoicing party master data and the submission report as a screen only.
- يُضاف (النماذج): Party master data for e-invoicing is observed: «نوع المعرف» with 11 codes (CRN, MOM, MLS, SAG, 700, TIN, NAT, IQA, GCC, PAS, OTH), «العميل جهة حكومية», tax number and commercial-registration fields with the document labels «الرقم الضريبي» and «سجل تجارى/ رقم…
- اعتماد جديد (الطباعة وإقفال السنة): باني TLV الخاص بـQR المرحلة الأولى في WP40 يُعاد استعماله في المرحلة الثانية بإضافة الوسوم 6 إلى 9 (التجزئة والتوقيع والمفتاح) من المواصفة الرسمية؛ فيُصمَّم صنفًا نقيًا مستقلًا عن المستند. لا يتغير أن متطلبات المرحلة الثانية تؤخذ من المواصفة لا من دفترة.
- **قواعد مرصودة** (3؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `RSC-ZAT-01`، `FRM-R26`، `RP-SET-05`

### WP47: Fixed assets and depreciation

**لماذا هنا:** Only chart accounts exist. Deferred by D8; depreciation journals were never observed.

**ثوابت لا تُكسر:**
- Depreciation journals are balanced, idempotent per period and respect closed periods
- Historical journals untouched

**اختبارات الإثبات:**
- Depreciation run test with reversal and closed period

**رصد دفترة (2026-10-28):** OBS-A read the asset form, the four depreciation methods and the assets report; the journals of the asset operations are unobserved.
- يُضاف (تقارير الحسابات): تقرير الأصول: مرشحاته الستة وخيارات تجميعه الأربعة وحالاته الخمس، وستة مصادر قيد للأصول (أصل، عملية أصل، إهلاك الأصل، شطب الأصول، إعادة تقدير أصل، بيع أصل). الأعمدة غير مرصودة، وسطرا الاستثمار في التدفقات النقدية (النقد المستلم من بيع الأصول والنقد المُنفق…
- يُضاف (النماذج): Asset add form, the four depreciation methods, first-depreciation date and amount formulas, per-unit serial/barcode/custody rules and field visibility per method are observed from the form and its scripts. Unobserved: the journals of purchase, depreciation,…
- **قواعد مرصودة** (4؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R46` إلى `FRM-R49`

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

**رصد دفترة (2026-10-28):** OBS-A found the contact list inside the client form: split CUS-06 from CUS-10.
- قرار مطلوب (النماذج): The contact list is a panel inside the client form (rows with first name, last name, e-mail, phone, mobile) and a button on the supplier form, not only a separate CRM screen; it can be built as a sub-table of the party without the customer portal, so split…
- **قواعد مرصودة** (1؛ النص الكامل وأدلتها في [synthesis.md](observations/obs-a/synthesis.md)): `FRM-R58`

### WP50: Umbrella rows (no own work; close when the children close)

**لماذا هنا:** Tracking rows from the docs that aggregate other items; each is closed by the packages named in dependsOn. A8 uniquely adds the Chart.js presentation of the financial reports and the Assets = Liabilities + Equity acceptance gate.

**ثوابت لا تُكسر:**
- Close only when every child item is closed

**اختبارات الإثبات:**
- A8 equation gate test already covered by WP4

### WP51: Inter-branch settlement follow-ups (decision-gated)

**لماذا هنا:** WP7 settles a whole pair, refuses a pair whose two sides disagree, and leaves seven questions to the owner (E56 (a) to (g)): partial settlement, an adjustment entry for an unmatched pair, attributing unattributed journals, a record for future transfers, a special permission for cross-branch payments, who is granted `acc_interbranch_settle`, and the settlement-day and inactive-branch rules. None is built until the owner answers; each is a small extension of `InterBranchSettlements`, `InterBranchBalances` and `InterBranchLegs`. Internal design with no Daftra equivalent to read (G-139 in WP21).

**ثوابت لا تُكسر:**
- Same as WP7: each journal balanced inside its own branch, 12044 and 2214 net to zero for the company, branch-number lock order, E43 and the treasury scopes on both sides, a closed period in either branch rejects the whole document
- An existing journal is never rewritten to attribute it: an attribution or an adjustment is a new journal with a reason and an audit line, and the pair still agrees afterwards
- A partial settlement leaves the remainder visible as a balance of the pair and refuses a day before a standing settlement of the same pair (the rule of WP7 section 2.7)

**اختبارات الإثبات:**
- InterBranchSettlementsTest: the partial amounts that leave the pair in agreement, a pair that does not agree settled by an adjustment, concurrent opposite settlements
- InterBranchBalancesTest: an attributed journal moves from unattributed to its pair; the company difference stays zero
- A branch deactivated with a balance: the chosen rule (forbid or allow) with both outcomes tested

## 3. البنود (الكل مرة واحدة)

| المعرّف | العنوان | الحزمة | الحالة | دفترة | الخطورة |
|---|---|---|---|:-:|---|
| P6-SENSITIVE-PERMS | Treasury and payables manager permissions are documented as sensitive but are not in the sensitive list | WP1 | done (WP1) | لا | medium |
| A2A-STOCK-ACCESS | Items and stock-adjustment screens ignore the employee's branch scope | WP1 | done (WP1) | لا | medium |
| G-48 | Operational reports are not restricted by the employee's branch (the expenses report stays company-wide only: G-81) | WP1 | done (WP1) | لا | medium |
| G41-A5 | Transfer destination location is not checked against location grants | WP1 | done (WP1) | لا | medium |
| P5-LOCK-POOLING | Customer and supplier locks rely on a named lock held by one database connection | WP1 | done (WP1) | لا | medium |
| E38-SCAN | Scan every target database before LegacyFixedPurchaseDiscount runs | WP2 | blocked-needs-owner-credentials | لا | high |
| E40-BANK-DETAILS | Run accounting:bank_accounts before and after the bank-account migration and complete missing bank details | WP2 | blocked-needs-owner-credentials | لا | medium |
| E41-STAMP | Dry-run then apply accounting:stamp_payments on each database before E41 is deployed | WP2 | blocked-needs-owner-credentials | لا | medium |
| OWNER-GRANTS | New accounting modules and sensitive permissions are registered but granted to nobody (acc_interbranch_settle added by WP7: the administrator grants it by hand) | WP2 | blocked-needs-owner-credentials | لا | medium |
| E26-ACTIVATION | Production activation of accounting and opening-stock posting (owner-ordered) | WP2 | blocked-needs-owner-credentials | لا | high |
| E54-PRE-DEPLOY-BACKUP | Back up each owner database before the additive migration 20261220000000_PurchaseInvoiceDiscount (down() refuses once a receiving carries the discount, so rollback is restore only) and keep the whole-purchase discount off in production until a separate owner permission (E47, E54) | WP2 | blocked-needs-owner-credentials | لا | medium |
| WP7-PRE-DEPLOY-QUERY | Run the unattributed-journals query of the WP7 deploy step on each owner database before the first real settlement (journals on 12044 and 2214 that do not come from a transfer show as unattributed and are never rewritten) and back up before migration 20270200000001 (additive, three large tables) | WP2 | blocked-needs-owner-credentials | لا | medium |
| FIN-05 | Expense categories have no account link and there is no revenue category screen (account link done in WP3; revenue screen waits for FIN-03 in WP26) | WP3 | done (WP3) | لا | medium |
| FIN-02 | Expenses never reach the ledger (posting done in WP3; Daftra extras are G-80, legacy rows are G-79) | WP3 | done (WP3) | لا | high |
| FIN-04 | Mandatory expense and revenue account settings (expense side done in WP3; revenue side waits for FIN-03 in WP26) | WP3 | done (WP3) | لا | low |
| A4 | Cash-up (cash register closing) never posts to the ledger (difference-only design done in WP3, owner may veto; follow-ups are G-78 and G-79) | WP3 | done (WP3) | لا | high |
| Y-DEC | Record decisions Y1-Y9 and re-base year-end-policy.md on the current chart (re-base done in WP4 on accounts 32 and 214 and still an unadopted proposal; decisions Y1-Y9 are the owner's and stay open: E53) | WP4 | partial | لا | low |
| REP-03 | Income statement over the journals (done in WP4; matrix row stays 'differs': Daftra figures rebuilt with synthetic entries, no T-xx fixture, no monthly button, no export) | WP4 | done (WP4) | لا | medium |
| REP-04 | Balance sheet with the dynamic result of unclosed years (done in WP4; matrix row stays 'differs': same reasons, and the first day is ignored on purpose, RA-BS-04) | WP4 | done (WP4) | لا | medium |
| Y8 | Caption 'cumulative balance since the start' on the chart-of-accounts screen | WP4 | done (WP4) | لا | low |
| REP-07 | Tax report and VAT return from the ledger (215001, 215002, returns, cash and accrual filter) | WP5 | partial | نعم | medium |
| G-98 | Tax return (four tables, adjustment column) and return settlement journals; settlement needs a controlled observation of the journal it posts | WP5 | open | نعم | medium |
| G-99 | Tax report from the ledger with the seven Daftra sections, section signs and the optional non-taxed section; sales base from documents or from 411 (owner decision) | WP5 | open | نعم | high |
| YE-01 | Year-end closing entry per branch with preview, approval and reversal | WP6 | open | نعم | high |
| Y5 | Sensitive permission acc_year_end_close and guards (reopen blocked while a closing entry stands) | WP6 | open | لا | high |
| YE-03 | Report treatment of year_end entries (before/after option, ledger tag, comparison and income-statement exclusion; the income-statement exclusion and balance-sheet inclusion are done in WP4 with a stopgap definition, G-82) | WP6 | partial | لا | medium |
| G-82 | The closing entry is recognised by source or by type year_end as a stopgap (YearEndEntries::leaveOut; no such entry exists yet and JournalEntryType has no year_end case): WP6 picks one definition and changes the filter in one place, keeping the reversal-chain following (WP4) | WP6 | open | لا | medium |
| YEAR-END-STALE-ACCOUNT-REFS | Historical design documents still name accounts 3102/3103 (a2b-periods.md lines 52-53, ui/a2-design.md lines 108, 111, 232 and 245, multi-branch.md line 97) after WP4 re-based year-end-policy.md on 32 and 214; align them once the owner decides Y-DEC | WP6 | open | لا | low |
| G-129 | Year-end closing: general sources say Daftra closes by a mandatory next period with up to three journals per branch and reopens by cancelling the newest; our proposed policy differs; owner decisions Y1, Y2, Y6 and two new items before WP6 | WP6 | open | نعم | medium |
| G-61 | Inter-branch: who owes whom between two specific branches (done in WP7 with the counterpart branch recorded on every new journal; transfers before WP7 are read from their documents, G-136; unattributed journals stay unattributed, G-135) | WP7 | done (WP7) | لا | medium |
| INTERBRANCH-SETTLEMENT | Settlement of inter-branch balances (done in WP7: cash or netting, two journals, branch-ordered locks, request key, cancel reverses both; partial settlement G-134, unmatched pair G-135, inactive branch WP7-INACTIVE-BRANCH-BALANCE, one currency G-138) | WP7 | done (WP7) | لا | high |
| P6-ASSUMPTION-7 | Payment recorded in one branch into another branch's treasury (done in WP7 for customer payments on a sale, supplier payments on a receiving and expenses; the other payments are G-137) | WP7 | done (WP7) | لا | high |
| G-08 | Per-document numbering with an editable next number (safe half done in WP8; the rest is WP8-S33) | WP8 | done (WP8) | نعم | low |
| CUS-02 | Automatic customer numbering (sequence and server-side duplicate refusal done in WP8; Daftra duplicate behaviour is part of WP8-S33) | WP8 | done (WP8) | لا | low |
| G-34 | Customer payment and credit-note documents with own numbering, list screens and collected-by | WP9 | partial | لا | low |
| SAL-19 | Refund invoice as a second return document | WP9 | open | لا | low |
| G-28 | Journal order of a paid-on-creation sale: invoice, payment, then cost | WP9 | open | لا | low |
| SAL-29 | Editing an issued document: non-financial fields and payment edits | WP9 | partial | لا | low |
| G-120 | Deposit tab inside the invoice (amount or percentage, treasury, method) and the 'next payment' line; its journal is unobserved | WP9 | open | نعم | medium |
| G-51 | Supplier payment and debit-note documents: list screens, numbering, entered date, paid-by | WP10 | partial | لا | low |
| G-57 | Opening-stock cost correction: explicit re-post at current item cost | WP11 | open | لا | high |
| E26-NEG-LINES | Owner decision on posting negative or excess-movement items as negative opening lines | WP11 | partial | لا | medium |
| E26-LIMITS | Known limits of opening-stock posting | WP11 | partial | لا | medium |
| CUS-03 | Customer opening balance | WP11 | open | نعم | high |
| A6 | Retroactive posting (backfill) with dry-run preview, opening balances and approve or cancel | WP12 | open | لا | high |
| G-79 | Expenses and cash-ups saved before activation carry no branch and no journal (listed as unposted; readiness notices expenses_unposted and cashups_unposted); only retroactive posting can give them a journal (NEW finding of Wave 1, WP3) | WP12 | open | لا | medium |
| G-27 | Invoice date separate from issue time, with permission to edit it | WP13 | open | لا | medium |
| G-31 | Customer due date, payment terms, due and overdue states | WP13 | open | لا | low |
| G-53 | Supplier aging by due date, six buckets and Daftra balance columns | WP13 | open | لا | low |
| CUS-08 | Customer balances report with Daftra period columns | WP13 | partial | لا | low |
| CUS-09 | Debt aging by invoices and by ledger | WP13 | partial | نعم | low |
| G-14 | Document and payment statuses: due, overdue, draft, quote lifecycle | WP13 | partial | لا | low |
| G-106 | As-of date in the aging: filter invoices, payments and applications by date so the buckets add up to the balance of that date | WP13 | open | نعم | medium |
| G-112 | Purchase invoice date is typed and differs from the creation time; ours stores the save time and cannot be changed after posting | WP13 | open | نعم | medium |
| SAL-13 | Draft invoice lifecycle: temporary number, issue link, typed date | WP14 | partial | لا | medium |
| SAL-21 | Quotations: states and conversion to invoice | WP14 | partial | لا | low |
| WP8-WORK-ORDERS | Work-order numbers (work_order_number) are not among the nine numbered document types of WP8 | WP14 | open | لا | low |
| G-59 | Discount on the whole purchase invoice (E33) (done in WP15 on development data under E47; the allocation rule is an assumption, Daftra questions are G-87; production needs separate owner permission) | WP15 | done (WP15) | لا | medium |
| SAL-07 | Invoice-level discount on sales (percent or fixed) and its effect on tax and journal (done in WP16; matrix row stays 'differs': one-halala case and assumed allocation; Daftra questions are G-67 to G-69) | WP16 | done (WP16) | لا | high |
| G-37 | Editing the lines of a saved receiving (since WP15 the shares of a discount on the whole invoice must be re-allocated inside the reversal and repost) | WP17 | open | لا | medium |
| G-38 | Item purchase taxes (two purchase taxes per item) | WP17 | open | لا | medium |
| PUR-10 | Supplier commercial-registry field | WP17 | partial | لا | low |
| G-117 | Purchase invoice line without a product (text line or non-stock service item): Daftra groups it under UNKNOWN; its debit account is unobserved | WP17 | open | نعم | low |
| G-133 | Deleted purchase documents go to a 90-day trash in Daftra; restoring posts a new journal and movement and a document in the trash blocks deleting its item | WP17 | open | نعم | low |
| E58-LINE-EDIT-AVERAGE | Edit and delete of a saved receiving line reverse the valued movement at its recorded value so the average recomputes; posted COGS unchanged (E58) | WP17 | open | نعم | medium |
| G-45 | Inventory value report from valued movements (done in WP18; matrix row INV-15 stays 'differs': Daftra columns and layout not compared, G-89; aging and turnover are REP-14 in WP33) | WP18 | done (WP18) | لا | low |
| ACC-16 | Edit description and cost centre of an automatic journal (done in WP19; the number stays locked, E16) | WP19 | done (WP19) | لا | medium |
| ACC-18 | Manual journal: attachments, required line description, line tax, currency, import (attachments and required line description done in WP19; line tax and currency are G-73, import is G-74) | WP19 | done (WP19) | لا | medium |
| R-02 | Daftra read: filtered reports, cash flow, cash and accrual profit and loss, tax return, directories [OBS-A read the reports of the general accounts, sales, purchases, inventory, treasuries and activity; print layouts and cost-centre columns remain] | WP20 | partial | نعم | low |
| R-03 | Daftra read: add forms (sales order, subscription, treasury transfer, debit note, purchase order, price list, edit forms) [OBS-A read the add forms that are reachable; forms behind disabled apps remain (batch C)] | WP20 | partial | نعم | low |
| R-04 | Daftra read: permission matrix of the two existing roles (edit without saving) [OBS-A read the role matrix (119 options); denial messages and the effect of restriction remain (S-32)] | WP20 | partial | نعم | low |
| R-05 | Daftra read: print and PDF outputs with headers (invoice, receipt, journal, statements) [OBS-A read the print outputs of the printed documents and the statements; PDF header content of the financial statements remains] | WP20 | partial | نعم | low |
| Y9 | Daftra read: year-end closing behaviour (closing entry, retained earnings, reopen after close) | WP20 | blocked-needs-owner-credentials | نعم | low |
| G-72 | Daftra limits, file types and posted-journal attachments are not observed (the 10 files, 5 MB and file types of WP19 are assumptions) [OBS-A: attachment limits of posted journals still unobserved] | WP20 | open | نعم | low |
| G-86 | Daftra reads WP4 left open: default depth of its balance sheet, whether 'accounts with transactions' differs from 'hide zero accounts' for an account whose movement was reversed, how both statements look after a real year-end close (Y9: does the amount move to 32, does the income statement show the closing entry; a write in the trial account), print header and PDF/CSV/Excel content (R-05), effect of the cost-centre filter | WP20 | open | نعم | low |
| G-89 | Daftra inventory valuation report not read: columns, filters (warehouse, category, date) and whether it has an 'as of' date; behaviour with negative stock and with a deleted item that has a balance; export columns and order. Only its total (1,720 equals account 1203) was observed [OBS-A read the stock reports (store summary, product average cost, worth sheet, aging, turnover) but not a report named valuation] | WP20 | partial | نعم | low |
| E12-PKG-C | Daftra package C: closed periods and validation messages (S-15, S-17) | WP21 | blocked-needs-owner-credentials | نعم | low |
| E12-PKG-D | Daftra package D: settings-changing scenarios (S-03, S-26, S-28, S-29, S-32, S-33, S-16, S-30) | WP21 | blocked-needs-owner-credentials | نعم | low |
| ACC-19 | Journal numbering toggle: behaviour when independent numbering is off [OBS-A: with the switch off Daftra shares one counter; the on state is unobserved (batch C)] | WP21 | partial | نعم | low |
| SAL-05 | Tax-inclusive price decomposition parity | WP21 | blocked-needs-owner-credentials | نعم | medium |
| WP8-S33 | Numbering behaviour that needs S-33: lowering the next number, duplicate manual numbers, prefix variables (year, month, branch), annual reset, one sequence per branch, whether invoice, return and credit note share a sequence, numbering a draft or quote at save versus issue, a number for a 'Due' payment, Daftra's default digits per document type; the build then extends DocumentNumbering::configure and assign without changing the table schema except for prefix variables | WP21 | blocked-needs-owner-credentials | نعم | low |
| G-71 | Description-only edit of an automatic journal inside a closed period is refused today (owner decision E51 (a)); Daftra behaviour in closed periods is not observed | WP21 | open | نعم | low |
| G-76 | Required line description is off by default here but mandatory in Daftra (owner decision E51 (e); setting versus constant in Daftra not observed) | WP21 | open | نعم | low |
| G-122 | Shared journal numbering: with the switch off Daftra numbers automatic and manual journals from one counter; ours keeps a separate manual series and gives automatic journals no number | WP21 | open | نعم | low |
| W001-W004-CLEANUP | Delete the four empty custom forms that read-only GET links created in the trial account (W-001 to W-004) in the final cleanup | WP21 | open | نعم | low |
| G-139 | Daftra inter-branch behaviour unobserved: how it shows what branches owe each other (one account per branch or per pair, a who-owes-whom report), whether it has a settlement document (cash and netting) and its journal, whether it takes a payment on one branch's invoice into another branch's treasury, whether an expense can be charged to a branch other than the treasury's; the Branches app is disabled in the trial account and enabling it is a settings change (batch C) | WP21 | open | نعم | medium |
| G-49 | Settle button on a purchase paid in excess (F-04c) [observed in D2: two options (convert the excess to supplier credit, or remove it and return it to the treasury); E29 stands] | WP22 | done (observed D2) | نعم | low |
| G-20 | Customer credit auto-application: oldest-first by date or number not observed [observed in D1: oldest invoice date first, no origin-first (E57); tie-break unobserved; the code change is E57-CREDIT-ORDER] | WP22 | done (observed D1) | نعم | low |
| G-55 | Supplier credit application order: purchase date versus document number | WP22 | partial | نعم | low |
| G-35 | Payment state 'unpaid, partially returned': when Daftra creates it [observed in D1: it arises when the credit goes to an older invoice] | WP22 | done (observed D1) | نعم | low |
| G-23 | Sale without a customer: confirm Daftra behaviour | WP22 | partial | نعم | low |
| G-26 | Sales return quantity cap not observed in Daftra [observed in D1: refused, cumulative cap, matches ReturnLimit] | WP22 | done (observed D1) | نعم | low |
| G-43 | Average cost after deleting a receiving and after a transfer not observed [observed in D2: delete and edit recompute the average (E58); built in WP17] | WP22 | done (observed D2) | نعم | low |
| G-19 | Rounding rule: two exact half-cent cases still differ by one cent (WP16 adds a third: the 10% invoice discount of T-04) | WP22 | partial | نعم | medium |
| G-66 | Standalone supplier payment and supplier opening balance: Daftra journals not yet observed [observed in D2: journals match E35 and E30] | WP22 | done (observed D2) | نعم | medium |
| S35-T06 | S-35 T-06 (b), (c), (d): empty then disable, delete a treasury with movement, delete one without [observed in D2: delete refused by movement not by balance; deactivation at zero allowed] | WP22 | done (observed D2) | نعم | low |
| S35-D4 | Effect of deposit and withdraw scopes with a second restricted employee | WP22 | blocked-needs-owner-credentials | نعم | medium |
| S35-RESIDUE | S-35 leftovers in the Daftra trial account | WP22 | blocked-needs-owner-credentials | لا | low |
| D1D4-ACCOUNT-NUMBER-RULE | Bank account number validation rule is an assumption | WP22 | partial | نعم | low |
| P3-EVIDENCE-GAPS | Incomplete Daftra evidence behind fixtures: S-04, S-08, S-31 amounts, S-21 numbers, journal description text, T-01 purchase eviden | WP22 | partial | نعم | low |
| PUR-08 | Update product prices from purchase (update_product_prices setting) | WP22 | blocked-needs-owner-credentials | نعم | medium |
| SAL-37 | Overpayment banner and settle action on a sales invoice | WP22 | partial | نعم | low |
| G-67 | Distribution of an invoice discount across lines with different tax rates (our proportional rule is an assumption; T-04 has one tax rate) [observed in D1: proportional to each line net, tax on taxed lines only; rounding differs (E59)] | WP22 | done (observed D1) | نعم | low |
| G-68 | Credit note on an invoice with an invoice-level discount: does Daftra return the discount proportionally [D1: the form leaves the discount unprorated (80.50 against 98.22); percent discount and a full return unobserved (E60)] | WP22 | partial | نعم | low |
| G-69 | Rounding of the discount amount itself, and an invoice discount on a quote or work order carried into the invoice [D1: the 3.3% case observed; a discount on a quote unobserved (E59)] | WP22 | partial | نعم | low |
| G-77 | Expense behaviour not observed in Daftra: unpaid expense, edit in place versus reverse and repost, what delete leaves, typed amount before or after tax when the tax is an amount (S-25 observed only the cash case) [observed in D2: no due option, edit in place, delete erases, amount stored with the tax (E61, E62)] | WP22 | done (observed D2) | نعم | medium |
| G-87 | Daftra whole-purchase discount beyond the one observed line (10 x 100): allocation over several lines and its rounding, a fixed amount on the whole invoice (assumed to be an amount on the total, not per unit), a return from a discounted purchase (assumed to take the item's share, not the line's) | WP22 | open | نعم | medium |
| G-116 | Average after negative stock then a purchase: ours derives value over quantity from the movements, Daftra shows the unit cost of the purchase; one small observation | WP22 | open | نعم | low |
| G-32 | Customer advance payment without an invoice | WP23 | open | نعم | high |
| G-21 | Return of stock items without an original sale: manager override [D1 observed F-10; E63 keeps the refusal, the override is built in WP23] | WP23 | partial | نعم | medium |
| G-30 | Credit note on an invoice whose goods were not delivered [D1 observed F-11; E63: accept without stock or cost journal in WP23] | WP23 | partial | نعم | medium |
| P5-CUSTOMER-CASH-REFUND | Cash refund of a customer's credit balance from a treasury | WP23 | open | نعم | medium |
| G-52 | Cash refund of a standing supplier credit | WP23 | open | نعم | medium |
| E57-CREDIT-ORDER | Apply the observed customer-credit order in CreditSettlement::apply (oldest invoice date first, no origin-first, tie by sale id), change the origin-first unit tests and add a T-10 case (E57) | WP23 | open | نعم | medium |
| G-02 | Per-item and main-per-entity routing types do not drive journals | WP24 | partial | نعم | high |
| SAL-04 | Per-tax included or excluded option on the tax table | WP25 | partial | نعم | high |
| SAL-33 | Default invoice tax mode (match product, none, fixed) and mandatory tax 1 | WP25 | partial | نعم | medium |
| G-64 | Cheques: documents, statuses, due dates, notes receivable and payable, two cheque reports [B observed the cheque cycle (E64); building is WP26] | WP26 | partial | نعم | high |
| FIN-03 | Revenue and receipt voucher | WP26 | open | نعم | high |
| ACC-17 | Journal types and sources for the unbuilt documents (expense and cashup sources done in WP3) | WP26 | partial | لا | low |
| S35-D6 | Payment method independent of the chosen treasury (Daftra D6) [observed in B: the method is a label independent of the treasury (E65)] | WP26 | done (observed B) | نعم | medium |
| G-78 | Cash-up follow-ups (WP3 A4): card, cheque and credit are counted but not posted, a bank-treasury difference is outside, and the screen shows expected cash for the employee's default branch only; the owner may veto the difference-only design | WP26 | open | لا | low |
| G-118 | Expense and revenue category does not decide the account in Daftra (the account is chosen on each document, automatic creation per category on first use); our category account is an extension; record or replace | WP26 | open | نعم | medium |
| G-131 | Cheque-cycle chart accounts: leaf accounts under notes receivable (1206) and notes payable (212), plus under-collection and deposit-fee accounts; code 216 is already our gift-card liability | WP26 | open | نعم | medium |
| G-137 | Other payments cannot go into another branch's treasury: customer balance settled without a sale, supplier advance payments and cash-up stay in the branch of their treasury (CrossBranchPayments covers sale, receiving and expense payments only) | WP26 | open | لا | medium |
| G-41 | Numbered stock permit document (add, issue, transfer) [D2 observed the permit documents and their journals (WP27)] | WP27 | partial | نعم | medium |
| G-42 | Stocktake document and its accounts [D2 observed the stocktake and its settlement permits (WP27)] | WP27 | partial | نعم | medium |
| INV-12 | Stock requests | WP27 | open | نعم | medium |
| G-92 | Owner decision (E55 (3)): a log of historical shelf quantities (a new table and a write at every movement) so a past day can be compared with the shelves; today past days show no shelf column because the inventory table records the save time, not the document date | WP27 | open | لا | medium |
| G-12 | Multi-currency: invoice currency, exchange rate and FX difference entries [B observed currencies, rates and FX difference journals (E66)] | WP28 | partial | نعم | high |
| G-25 | Currencies with decimals other than two (tests and behaviour) [B observed decimals: USD 2, KWD 3, JPY 0 (E66)] | WP28 | partial | نعم | medium |
| G-63 | Treasury and bank currency (bank placement under 1202 is done) [B: no treasury or bank in a foreign currency; per-currency balances (E66)] | WP28 | partial | نعم | high |
| G-132 | Foreign currency rules: frozen posted journals, FX difference journal for payments and cheques, per-line conversion and rounding, separate gain and loss accounts (owner choice) | WP28 | open | نعم | high |
| G-138 | No settlement or cross-branch payment in different currencies (one base currency, D4) | WP28 | open | لا | low |
| E47-BANKREC | Bank reconciliation [B observed the import wizard and the reconciliation screen; finish-and-close not run] | WP29 | partial | نعم | medium |
| D1D4-BANK-LIST | Bank name picked from a bank-search list and bank linking | WP29 | open | نعم | low |
| G-111 | Bank statement tab: CSV import with five columns and a three-step wizard, reconciliation matching equal amounts only without any journal; no AI upload | WP29 | open | نعم | low |
| G-09 | Roles and own-versus-all record scope for every document | WP30 | open | نعم | medium |
| INV-09 | Default warehouse per employee and employee profile documents | WP30 | partial | نعم | low |
| G-90 | Owner decision (E55 (1)): restrict the inventory valuation report by stock-location grants like the old inventory report, or keep it by branch like the trial balance (the WP18 default, E45) | WP30 | open | لا | low |
| G-123 | Employee lifecycle: active or inactive status instead of the grant-erasing delete, automatic code, allow sign-in, last sign-in, send credentials | WP30 | open | نعم | medium |
| REP-01 | Trial balance: Daftra filter set, column layout and parity run | WP31 | partial | نعم | low |
| P8-TB3 | Third trial-balance type 'review account' matching Daftra | WP31 | open | نعم | low |
| REP-02 | Account statement: multi-account grouping, creator filter, Daftra print template | WP31 | partial | نعم | low |
| P8-PRINT | Print header and layout for the financial statements (the two WP4 statements and the WP18 valuation have a print button but no company header or fold options) | WP31 | partial | نعم | low |
| REP-05 | Profit and loss, cash and accrual basis, monthly, quarterly, yearly | WP31 | open | نعم | medium |
| REP-06 | Cash flow statement | WP31 | open | نعم | medium |
| CHK-B | Design summary ui/a8-design.md and owner approval before further report UI | WP31 | open | نعم | low |
| G-83 | Owner questions of E53 (b) and (c): default depth of the balance sheet (3 now, an assumption; Daftra's default not observed) and a fiscal-year filter on the income statement (from/to only today); and the definitions of 'accounts with movement' versus 'hide zero accounts' (ours, not an observed distinction) | WP31 | open | نعم | low |
| G-84 | Statements features Daftra has that WP4 did not build: two-period comparison in one statement and the assets split into current and fixed under separate headings (the monthly button is REP-05) | WP31 | open | نعم | low |
| G-94 | Review-account trial balance (before, movement, after groups): our 'opening, movement, closing' format is not it; add the group-only layout after a symmetry test | WP31 | open | نعم | medium |
| G-95 | Trial-balance filters missing: levels 1 to 5, main or sub account, four account-display options, 'balance before', end date required | WP31 | open | نعم | low |
| G-96 | Ledger report: all-accounts mode, two-column running balance, employee column and filter, per-account totals, three display options | WP31 | open | نعم | low |
| G-97 | Cash-flow statement: make the total equal the change of the cash accounts always (Daftra's differs by the unmapped accounts); owner decision | WP31 | open | نعم | medium |
| G-100 | Cash-basis profit and loss from documents (three bases): copy Daftra or build from the ledger; owner decision | WP31 | open | نعم | low |
| G-101 | Financial movements statement across treasuries and banks with opening and closing lines | WP31 | open | نعم | low |
| G-128 | Printed customer and supplier statement: screen and PDF in two layouts, summary box, running balance, opening and closing lines | WP31 | open | نعم | medium |
| REP-09 | Sales reports by customer, entry user and rep with daily, weekly, monthly, yearly variants | WP32 | partial | نعم | low |
| REP-10 | Item sales reports by item, category, brand, entry user, rep and customer | WP32 | partial | نعم | low |
| REP-11 | Payment reports (customer, entry user, method, period) and advance-payments report | WP32 | partial | نعم | low |
| REP-12 | Profit reports (daily, weekly, yearly) from stock movements | WP32 | partial | نعم | low |
| REP-16 | Net invoice position and invoice-versus-returns reconciliation reports | WP32 | open | نعم | low |
| G-70 | A dedicated report or filter for invoice-level discounts (Summary_discounts sums line discounts only) and the line share in prints; the purchase-side discount was G-59, done in WP15 | WP32 | open | لا | low |
| G-104 | Paid, unpaid and returned split in the revenue reports: Daftra has three rules by report type; pick one rule (owner decision) | WP32 | open | نعم | medium |
| G-105 | Profit definition: ledger-consistent profit from valued movements (cost of a return from the original sale) against Daftra's two reports; owner decision | WP32 | open | نعم | high |
| G-108 | Salesperson as a dimension separate from the employee who entered the document: not built before it is observed | WP32 | open | نعم | low |
| REP-13 | Purchase and supplier reports (by supplier, user, product, directory, balances, aging, payments) | WP33 | partial | نعم | low |
| REP-14 | Inventory reports: count sheet, operations summary, movements, warehouse and product balance, aging, turnover (the valuation report from valued movements is done in WP18) | WP33 | partial | نعم | medium |
| REP-15 | Split expense and income reports (eight variants each) | WP33 | partial | نعم | low |
| REP-08 | Journal movements, chart-of-accounts and cost-centre reports | WP33 | partial | نعم | low |
| REP-17 | Account activity log beyond the accounting audit log | WP33 | partial | نعم | low |
| G-81 | Expenses report is refused to an employee of some branches because an expense had no branch; WP3 stamps new expenses with a branch but legacy rows have none: owner decision whether to open it per branch (E48 (a)) | WP33 | open | لا | low |
| G-88 | Detailed receivings report has no column for the whole-purchase discount (line totals and profit already carry the share; WP15) | WP33 | open | لا | low |
| G-109 | Purchase reports give three totals for one period in Daftra (14,384, 14,729, 15,971): one definition in one library for all of ours | WP33 | open | نعم | high |
| G-110 | Treasury statement: date and amount filters, order, pagination with an exact balance before each page, export and print, keeping the below-zero list | WP33 | open | نعم | medium |
| G-113 | Account activity log: Daftra logs sign-in, print, download and every business document (41 action types); ours logs accounting and control events only; scope decision | WP33 | open | نعم | low |
| G-114 | Expense reports split by category, vendor, entry employee, treasury, day, week, month, year and by sub-account | WP33 | open | نعم | medium |
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
| G-93 | Owner decision (E55 (4)): forbid moving a stock location that has posted movements to another branch (BranchService::assignLocations allows it with no check today); the valuation report copes by not comparing the shelves of such a location outside a scope that holds all its branches | WP37 | open | لا | medium |
| G-107 | Customer data that Daftra reports filter by: category, manual status, assigned employee, region, city, country code, creation date, customer type, government entity | WP37 | open | نعم | low |
| G-115 | Optional per-item reorder alert and its comparison (less than or equal): ours is mandatory | WP37 | open | نعم | low |
| G-119 | Customer and supplier fields we lack: type, identifier type, government entity, short address, contact list, default currency, language, categories, billing method, duplicate check | WP37 | open | نعم | medium |
| G-121 | Lighter required-field rules in Daftra (item name only, trade name only): product decision | WP37 | open | نعم | low |
| ACC-20 | Journal settings: tax in journals, cost-centre options, tags, conversion factor | WP38 | open | نعم | low |
| ACC-21 | Cost centres: distribution by percentage, over-100% and mandatory settings, report | WP38 | partial | نعم | medium |
| ACC-05 | Automatic sub-accounts for shipping companies | WP38 | partial | نعم | low |
| G-73 | Line tax and journal currency on the manual journal (split from ACC-18; the currency part belongs to WP28) | WP38 | open | نعم | low |
| G-74 | Import of manual journals from a file (split from ACC-18) | WP38 | open | نعم | low |
| G-80 | Expense features Daftra has and we lack: attachments, several accounts in one expense, cost centres (recurrence is FIN-06 in WP35, currency is G-12 in WP28, split reports are REP-15 in WP33) | WP38 | open | نعم | low |
| G-85 | Cost-centre filter on the income statement and the balance sheet and the 'fiscal year' and 'cost centres' header lines Daftra shows (WP4 has no cost-centre filter) | WP38 | open | نعم | low |
| PUR-07 | Purchase cycle: purchase request, quotation request, quotation, purchase order | WP39 | open | نعم | low |
| SET-06 | Template designer for print, invoice, email and terms | WP40 | partial | نعم | low |
| SAL-31 | Print, send and copy outputs (vouchers, parcel and shipping labels, receiving list, copy invoice) | WP40 | partial | نعم | low |
| SAL-35 | Extra invoice fields, line notes and order sources | WP40 | partial | نعم | low |
| G-125 | QR code on the invoice and the credit note (ZATCA phase 1, five TLV tags): Daftra prints it, ours has none; taking it out of the D7 deferral is an owner decision | WP40 | open | نعم | high |
| G-126 | Missing document prints: credit note, purchase invoice and PDF, the three slips, customer and supplier payment receipts, payment and receipt vouchers, journal PDF and copy | WP40 | open | نعم | medium |
| G-127 | No amount-in-words tool (Arabic): Daftra uses it on the payment voucher and in the variable catalogue; the wording needs owner approval | WP40 | open | نعم | medium |
| SET-04 | Payment methods and online payment gateways | WP41 | partial | نعم | low |
| SET-07 | Secondary language for entities (secondary_language_enabled) | WP41 | open | نعم | low |
| SET-08 | Automatic send rules and alerts | WP41 | open | نعم | low |
| SET-09 | Documents and files library and public API keys | WP41 | open | نعم | low |
| G-130 | App activation (36 apps, 7 enabled) and plan quotas: ours are modules by employee grants; probably a deliberate product difference to record | WP41 | open | نعم | low |
| INV-04 | Multiple units and unit templates | WP42 | open | نعم | high |
| INV-05 | Bundles: pre-assembled or on-the-fly modes | WP42 | partial | نعم | medium |
| INV-06 | Tracking by serial, batch and expiry | WP42 | partial | نعم | high |
| G-36 | Purchase receiving status: not received versus received, and draft purchase | WP43 | open | لا | high |
| P8-EXPORT | Server-side PDF and Excel export of the trial balance and account statement (and, since WP4 and WP18, of the income statement, the balance sheet and the inventory valuation, which has CSV only) | WP44 | open | لا | low |
| README-HUB | Link the accounting reports from the Reports hub (the inventory valuation card is already in the hub since WP18; the two statements are not) | WP44 | open | لا | low |
| P8-MOBILE | Phone layout of the trial balance and account statement tables (and, since WP4 and WP18, of the two statements, a three-column table in table-responsive, and the eight-column inventory valuation table: unchecked) | WP44 | open | لا | low |
| P8-MINOR | Residual minor review findings of P8 slice 1 | WP44 | open | لا | low |
| G41-A4 | Receiving screen does not show each location's branch in the location list | WP44 | partial | لا | low |
| G-91 | Owner decision (E55 (2)): keep the diagnostic column 'at item cost (old basis)' of the inventory valuation once accountants accept 1,314 against 1,320, or drop it | WP44 | open | لا | low |
| SCOPED-REQUEST-ADOPTION | The ScopedReportRequest trait (branch scope read from the request) is used by the inventory valuation controller only; AccountingReports and AccountingStatements keep their own copies of the same reading (WP18 left AccountingReports untouched so it could be adopted later) | WP44 | open | لا | low |
| G-103 | Server-side export (CSV, Excel, PDF) and print layout settings for the general-accounting reports | WP44 | open | نعم | low |
| G-124 | Localisation and general settings: time-zone list without Riyadh, Kuwait, Baghdad, Qatar, Bahrain, Oman; 7 date formats against 22; first weekday; negative-number format; default account names by language; national address and identifier type | WP44 | open | نعم | low |
| WP7-BALANCES-OVER-TIME | Pairwise inter-branch balances over time and an export (the screen shows one date at a time) | WP44 | open | لا | low |
| P1-PICKER | Account pickers load every account (search picker before activating on large businesses) | WP45 | partial | لا | low |
| PERF-LOAD-UNMEASURED | Performance under load never measured | WP45 | open | لا | medium |
| INV-07 | Selling more than available: end-to-end behaviour and blocking options | WP45 | partial | نعم | medium |
| G-56 | Supplier payment methods accepted: residual after observation | WP45 | partial | لا | low |
| E32-RETURN-ROUNDING | Return share of an old per-line fixed discount is rounded to two decimals | WP45 | partial | لا | low |
| E22E32-BROWSER | Browser checks not done for editing a saved document's line discount | WP45 | partial | لا | low |
| CHK-F | Bring README status and counts up to date after P8 and E43-E47 | WP45 | open | لا | low |
| G-75 | Journal attachments: no image preview or compression, no virus scan, old Office formats rejected, and the content check may refuse an xlsx or docx from an unusual generator (WP19) | WP45 | open | لا | low |
| ITEMS-CSV-TIME-LIMIT | Items::postImportCsvFile sets set_time_limit(240) (app/Controllers/Items.php:1182); a test that calls /items/importCsvFile must lift it as ItemsControllerTest::importOneRow does with set_time_limit(0), otherwise the PHPUnit process stops 240 seconds after the first import (NEW finding of Wave 1, WP1); a shared test helper is the safer form | WP45 | open | لا | low |
| REPORT-TESTS-LEGACY | Summary_discounts_test.php and Summary_taxes_test.php (suffix _test.php) are not collected by PHPUnit and fail if run on their own; three older ReportsControllerTest cases (testNonAdminWithoutReportGrantIsDeniedSummaryCustomers and its two siblings) use assertRedirect alone, which a stale Location header may satisfy if the guard they protect is removed after an earlier redirecting test in the same process (WP1 did not measure that for these three). Sources: WP1 and WP16 | WP45 | open | لا | low |
| NAMING-SWEEP | Legacy snake_case local variables in functions that WP8 and WP16 edited (Sale::save, Customers::postSave, Tax_lib::get_taxes, apply_destination_tax, Sales::postComplete) were left as they were to avoid merge conflicts: one separate renaming pass | WP45 | open | لا | low |
| RACEWORKER-DOUBLE-LOADER | tests/Support/RaceWorker.php carries both the class-map block of WP8 and the spl_autoload_register block of WP16 after the merge; WP16's report says WP8's block is enough | WP45 | open | لا | low |
| WAVE1-RACE-TESTS | No two-process race on one expense or cash-up (WP3: row locks are proven by statement order only) and no two simultaneous numbering configure saves of one type (WP8) | WP45 | open | لا | low |
| WAVE1-BROWSER-PASS | No browser or accessibility pass for the WP16 invoice-discount form and the WP19 edit-details and attachments screens (WP19 also ran no real upload and download) | WP45 | open | لا | low |
| WP16-TAX-SUMMARY-ROUNDING | Summary_taxes rounds group totals to currency decimals while the journal keeps line tax unrounded (three decimals or more); the gap across many invoices was not measured (WP16 assumption 10) | WP45 | open | لا | low |
| WAVE2-BROWSER-PASS | No browser or accessibility pass for the WP4 income statement and balance sheet (four paths were added to tools/ui-smoke/screens.json for whoever runs the UI smoke) and the WP18 inventory valuation screen, and none is reported for the WP15 discount card of the receiving register; phone layout unchecked (WP4: three-column table in table-responsive; WP18: eight-column table) | WP45 | open | لا | low |
| RACEWORKER-WORKTREE-AUTOLOAD | In a git worktree whose vendor directory is a symlink to the main repository, the two-process race workers (tests/Support/RaceWorker.php) load app/ from the main repository and fail on a newer schema: two concurrency tests of ReceivingPostingTest failed so in WP15's environment; they must be run on the merged code (WP15 report, section 8) | WP45 | open | لا | low |
| MIGRATION-HELPERTEST-ORDER | Running tests/helpers/migration_helperTest.php in the same process before tests that use the database makes them fail with missing tables (existing before WP18, reproduced without any WP18 file: ReportsControllerTest, then migration_helperTest, then StockLedgerTest failed 18 of 29); the full-suite order (helpers last) avoids it | WP45 | open | لا | low |
| G-102 | Default chart direction of two accounts differs from Daftra: 215002 (debit there) and 1208 (credit there); decide to follow or record as deliberate, then test | WP45 | open | نعم | low |
| PARITY-FIXTURES-OBS | Build the permanent parity fixtures T-26 onward of fixture-candidates.md and widen ParityFixtureTest (id list, evidence pattern for the pw- ids or id null with a note) | WP45 | open | لا | medium |
| WP7-BROWSER-PASS | Browser pass (ui-smoke) of the inter-branch balances screen, the settle and cancel forms, the other-branch treasuries in the sale and receiving forms and the expense branch field; none was run in WP7 | WP45 | open | لا | medium |
| WP7-LOCK-STRESS | Load test of settlements and cross-branch payments with many sessions (the wait and the lock order were proven with two real sessions and by reading the lock queries, not under load) | WP45 | open | لا | low |
| WP7-PAIR-SUMMARY-TABLE | Summary table of pairwise balances updated with every journal, only if the journals on 12044 and 2214 pass hundreds of thousands (the balances are summed in the database and linear: 250 thousand journals take 5 to 6 seconds for the whole screen) | WP45 | open | لا | low |
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
| G-134 | Partial settlement between two branches (a settlement clears the whole pair up to the day chosen; owner decision E56 (a)) | WP51 | open | لا | medium |
| G-135 | A pair whose two sides disagree is refused and manual journals on 12044 and 2214 stay unattributed with no tool to attribute them; settle with an adjustment entry and attribute by an adjustment entry, or keep the refusal (owner decisions E56 (b) and (f)) | WP51 | open | لا | medium |
| G-136 | Future treasury and stock transfers do not write the counterpart-branch record, so their pairs are always read from their documents (owner decision E56 (e)) | WP51 | open | لا | low |
| WP7-INACTIVE-BRANCH-BALANCE | An inactive branch with an inter-branch balance cannot be settled until it is reactivated (the books refuse a new journal in an inactive branch and the whole settlement rolls back); owner decision: forbid deactivating such a branch or allow the settlement (E56 (g)) | WP51 | open | لا | low |

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
- G-61 and G-41 transfer parts (money and stock between branches): TreasuryTransfers.php, migrations 20261102/20261103, InterBranchPosition.php; residuals kept as G-61 (pairwise), P6-ASSUMPTION-7, INTERBRANCH-SETTLEMENT and G-41 (permit document) (superseded 2026-10-28: G-61 (pairwise), P6-ASSUMPTION-7 and INTERBRANCH-SETTLEMENT were done in WP7; the new residuals are G-134 to G-139 and G-41 (permit document))
- CUS-07 (per-customer sub-account and statement): EntityAccountService, CustomerLedger, AccountingReceivables::getCustomer; the matrix cell is stale
- ACC-32 (editable automatic journal number): intentionally not copied (E16); no number-edit method in JournalService.php
- ACC-33 (reverse a journal): JournalService::reverse() links to the original, required reason and date
- PUR-11 (auto-settlement from supplier credit): SupplierSettlement.php; E29, E30, E34 are owner-approved differences; only the settle-button observation remains (G-49)
- R-01 and R-07 (Daftra reads of the chart and of the branches and cheques modules): executed; R-06 (routing option lists): read in rtP2 (ev_8c1f06a3...), the scenario table still says pending
- Matrix rows already 'exact and tested': SAL-01, SAL-02, SAL-03, SAL-06, SAL-28, SAL-30, SAL-41, INV-13, INV-14, INV-18, PUR-02, ACC-01, ACC-02, ACC-03, ACC-04, ACC-06, ACC-07, ACC-08, ACC-10, ACC-11, ACC-13, ACC-15

## 5. بنود خطرة على البيانات التاريخية وصورتها الآمنة

Items risky for historical data, with the safe dev-only form of each. (1) E33 / G-59 (whole-purchase discount) and its sales twin SAL-07: the E32 incident shows that reinterpreting a stored column silently changes old documents (LegacyFixedPurchaseDiscount had to mark them). Safe form: additive nullable header columns defaulting to 0 or null; a golden test that every existing fixture (T-11 to T-19, T-06 to T-10 for sales) produces byte-identical journals when the discount is empty; no recalculation, re-post or backfill of existing receivings or invoices; issued sales stay locked (E21); owner approval of E33 first (superseded on 2026-10-28: E33 was implemented on development data under E47 in WP15 in exactly this safe form, with nullable columns defaulting to null or 0, a golden file of 22 purchase scenarios and no recalculation of any existing receiving; SAL-07 was done the same way in WP16); development data only, production only by separate owner permission. (2) G-57 (explicit re-post of opening stock at current item cost): re-pricing the opening journal changes the base of the weighted average and breaks StockLedger::reconcile for later movements and COGS. Safe form: per-location explicit option, default off, with a reason and a before/after plan; refused when any later movement exists at the location or the period is closed; old revision stays reversed and visible; never a batch or catalogue-wide reprice; development data only, production only by separate owner permission (E47). Also historical-risk and handled the same way: E38-SCAN (the legacy fixed-discount migration rewrites discount_type and its down() changes meaning, so scan first, back up, rollback by restore only); A6 (retroactive posting: preview journals excluded from all balances, dry run only on dev data); CUS-03 and E26-NEG-LINES / E26-LIMITS (opening balances: reverse-and-repost only, triple tie, provisional); YE-01 (closing entries: preview, reversal, never on production without permission); E26-ACTIVATION, E40-BANK-DETAILS, E41-STAMP (owner-run operations on real databases); G-27, G-31, G-08 (new nullable date and number columns whose defaults reproduce current aging and numbering; existing documents are never renumbered or redated); G-37 and G-36 (edit or unreceive of posted receivings must go through reverse-and-repost and must not reprice consumed cost); G-28 (journal-order swap applies to new journals only, historical journal numbers are not rewritten); G-12 and G-63 (base-currency journals unchanged, rate 1). (2) E58 / WP17 (edit or delete of a saved receiving line recomputes the average): safe form: reverse the valued movement at its recorded value and repost under a new revision, never rewrite a posted journal or a posted cost of goods sold; refuse when it would drive a location negative or change a cost already consumed by posted sales; a golden file that receivings never edited stay byte-identical; WP17 must also redistribute the whole-invoice discount (E54) inside the same transaction. (3) E57 / WP23 (credit application order): the change is one branch of CreditSettlement::apply and moves credit between invoices of existing customers on the next settlement run, so it is deployed with the release that carries the T-10 reclassification and a read-only report of the invoices whose applications would move.

## 6. ملاحظات المُركِّب

Method and verification. Source: five analyst lists merged by meaning; all aliases are kept in the item's aliases field and repeated as '[aliases: ...]' at the start of the summary. 168 backlog items are listed, each exactly once, plus the dropped list; 50 packages. Items I re-verified against code (grep/read): G-28 journal order (SalePosting.php:81-86), G-57 (OpeningStock.php:201,207), G-48 (no 'branch' anywhere in Models/Reports, Controllers/Reports.php, report_helper.php), G-45/INV-15 (StockLedger/StockPosition referenced only inside app/Libraries/Accounting), P1-PICKER (AccountService::all() at AccountingJournals.php:40 and AccountingChart.php:91,203,245,270), expense and cash-up posting absent (no Accounting reference in Expense/Expenses/Cashup/Cashups), due_date/payment_term/purchase_tax/credit_limit/price_list/recurring/sales_order/invoice_discount absent, income statement/balance sheet/cash flow/year_end absent, backfill = schema only, cheques = payment-method label only, per-item and main-per-entity routing refused (RoutingType.php:28-32), customer opening balance blocked (OpeningBalanceService CONTROL_KEYS), no refund path in SupplierAdvances, no journal-metadata edit in JournalService, no description template, no tests using totals_decimals, no sale-level oversell test. Two NEW findings not in any analyst list: P6-SENSITIVE-PERMS (docs call acc_treasuries_manage sensitive but AccountingGuard::SENSITIVE omits it and acc_payables_manage, so Employees.php:180 lets non-admins grant them) and A2A-STOCK-ACCESS (Items screens and StockAdjustment ignore the employee's branch scope; only Sales and Receivings enforce it). Both are in WP1. Conflicts resolved: G-62, G-61, G-41, G-65, G-44 were 'done' in one analyst list and 'partial' in another: the done parts are dropped and the genuine residuals kept under their own ids (G-61 pairwise, P6-ASSUMPTION-7, INTERBRANCH-SETTLEMENT, G-41 permit document, G-09 roles scope, S35-D4). 'A7' is used in the docs both for the vouchers-and-cheques phase (alias of G-64) and for the pairwise inter-branch balance in g61-report.md (alias of G-61); G-45 versus INV-15 disagreed on whether aging and turnover layouts were observed: valuation was (repK, 1,720), aging and turnover layouts are treated as unobserved and left to REP-14; SAL-05 was 'partial' in code but 'blocked' for proof, kept as blocked because only S-03 can settle it; matrix text is stale for ACC-28 (15 acc_* permissions exist), CUS-07, FIN-01, ACC-05, PUR-01 (CHK-F). Ordering: WP1-WP19 need no Daftra access and are executable now (correctness, then integrity and auditability, then commercial-flow soundness); WP20-WP29 are the Daftra observation batches and the Daftra-dependent correctness builds (credentials are absent, E47); WP30-WP43 are architecture-fit and parity work; WP44-WP50 are lowest priority (polish, performance, deferred domains, umbrellas). WP44 and WP45 need no Daftra access and can be scheduled in spare capacity while credentials are missing even though they rank last. Owner-gated items: E33 is no longer waiting for approval (implemented on development data under E47 in WP15; production still needs separate owner permission), the cash-up treatment needs an owner decision (A4), the unreceived-purchase state was declined by E27 (WP43), and any production activation, merge to master, deploy or production data change needs separate permission (E47).

**Wave 1 integration notes (2026-10-27).** WP1, WP3, WP8, WP16 and WP19 were merged into the development branch (E48 to E52); the tables above carry their status, the struck dependencies and the items they left behind. Two NEW findings: (a) expenses and cash-ups saved before activation have no branch and no journal and are listed as unposted by WP3, so their retroactive posting remains with WP12 (G-79); (b) the CSV import in the Items controller sets set_time_limit(240) (Items.php:1182), which any test calling it must lift (ITEMS-CSV-TIME-LIMIT; ItemsControllerTest::importOneRow does so with set_time_limit(0)). Conflicts between the reports resolved while integrating: the matrix ids REP-WP1 and LOCK-WP1 proposed by WP1 became REP-18 and ACC-35; the WP16 proposal for a single decision table with four columns was fitted to the three-column table of decisions.md; the sensitive-permission count is eight after the merge (WP1 said seven, WP19 added the eighth); T-25 now belongs to WP3, so later observation packages start at T-26; WP3 dated its proposed decision 2026-10-26 while the merge is 2026-10-27. Backlog items added by Wave 1: G-67 to G-81 and WP8-S33, WP8-WORK-ORDERS, ITEMS-CSV-TIME-LIMIT, REPORT-TESTS-LEGACY, NAMING-SWEEP, RACEWORKER-DOUBLE-LOADER, WAVE1-RACE-TESTS, WAVE1-BROWSER-PASS, WP16-TAX-SUMMARY-ROUNDING (the 168 stated above predates Wave 1 and the table already held 175 rows before it; it holds 199 now, 24 of them added by Wave 1).

**Wave 2 integration notes (2026-10-28).** WP4, WP15 and WP18 were merged into the development branch (E53 to E55); the tables above carry their status, the struck dependencies and the items they left behind. Conflicts between the reports resolved while integrating: WP4's own backlog proposal quoted 136 test methods, 70 guards and 'no independent review' (the figures before its review), while its sections 3 and 7b give 165, 97 and a review by two agents: the later figures are used. WP18's proposed matrix paragraph quoted counts that predate Wave 1 (22, 75, 43 and 9 of 149) and said no count changes, while WP4 moves two rows from not built to differs: the counts were taken from the table (22, 79, 39 and 14 of 154). WP18's proposal kept G-45 and INV-15 'partial' while the package table says done: G-45 is `done (WP18)`, the matrix row INV-15 stays 'differs', and the remainder became G-89 to G-93. WP4 and WP18 left the number of their decision open and WP15 asked to replace the text of E33: E53 is WP4, E54 is WP15 and E55 is WP18, and E33 now points to E54. The reports date their own work 2026-10-27 while the integration date used here and in the decisions is 2026-10-28. A correction no report proposed: the retained-earnings routing key in matrix row ACC-23 was `equity.retained` and is `fiscal.retained_earnings` (read in RoutingKey.php). A count of added `public function test` lines in the merge diffs gives 165 for WP4 (the report's number), 101 for WP18 (50 and 43 in its two new files plus 8 in three existing files) and 121 for WP15 (the report says 89 plus 18, a difference that was not settled). Not integrated on purpose: the Daftra observations of 2026-10-27 under observations/ (obs-a, d1, d2, b) wait for a later pass. Backlog items added by Wave 2: G-82 to G-93, YEAR-END-STALE-ACCOUNT-REFS, E54-PRE-DEPLOY-BACKUP, SCOPED-REQUEST-ADOPTION, WAVE2-BROWSER-PASS, RACEWORKER-WORKTREE-AUTOLOAD and MIGRATION-HELPERTEST-ORDER (the table held 199 rows after Wave 1 and holds 217 now, 18 of them added by Wave 2; each id appears once).

**Wave 3 integration notes (2026-10-28).** The observation evidence of OBS-A (six read-only areas), D1, D2 and B was merged into the matrix, the gap report (section 5K, G-94 to G-133), the decisions (E57 to E67) and this inventory. What changed here: 28 item statuses and titles; 44 new items (40 gaps and four more); the package table gained the 42 items earlier waves had added to the item list without listing them in their package, plus the new ones; WP20 is struck from the dependencies of WP30, WP31, WP32, WP33, WP37, WP40 and WP47 because its R-02 to R-05 reads were done by OBS-A, and WP21 was added to those of WP34, WP35, WP36, WP39 and WP42 (their forms sit behind disabled apps). Conflicts between the analysts resolved while integrating: new matrix ids proposed by different areas collided with each other (REP-40, SET-11, CUS-12 to CUS-14) or with existing rows (REP-18, ACC-35) and were renumbered; proposals that marked REP-03, REP-04, SAL-07 and FIN-04 as not built were refused (WP4, WP16 and WP3 are merged); statements that WP1, WP3, WP4, WP8, WP15, WP16, WP18 or WP19 were not merged were corrected; the batch-B note that the Daftra chart has no 1206 or 212 is wrong (OBS-A read both as group accounts before the cheque module created its own leaf accounts). The analysts' rules, anomalies not to copy and open observations are kept in [synthesis.md](observations/obs-a/synthesis.md). Reports of this wave are drafts the owner can veto under E47.

**Wave 4 integration notes (2026-10-28).** WP7 was merged into the development branch (E56) together with the permanent parity fixtures `T-26` to `T-64`; the tables above carry its status and the items it left behind. What changed here: three item statuses (`G-61`, `INTERBRANCH-SETTLEMENT` and `P6-ASSUMPTION-7` are `done (WP7)`), twelve new items (261 rows became 273: `G-134` to `G-139`, `WP7-PRE-DEPLOY-QUERY`, `WP7-BROWSER-PASS`, `WP7-LOCK-STRESS`, `WP7-PAIR-SUMMARY-TABLE`, `WP7-BALANCES-OVER-TIME` and `WP7-INACTIVE-BRANCH-BALANCE`), and one new package, WP51, to hold the owner-gated extensions of the settlement (it is the only place those items fit; 51 packages now). No package depended on WP7, so no dependency was struck. Conflicts and corrections while integrating: the report asked for the next free decision number (E56 is the number reserved for it); it proposed two matrix rows after `FIN-08` with the status 'not comparable with Daftra', which is not in the legend, so they became `FIN-17` and `FIN-18` at the end of their domain with 'not yet verifiable' (the precedent of `REP-18` and `ACC-35`); the report wrote `interbranch_accounts_missing` while the code says `inter_branch_accounts_missing`; the sensitive permissions are nine and the `acc_*` permissions seventeen after the merge (matrix row `ACC-28`); the report is dated 2026-10-27 while the merge into the development branch is 2026-10-28. The report's 270 added tests were recounted in the diff of `wp/WP7` and match; its 195 guards cannot be re-run from the repository (the disabling tool lives outside it) and are quoted as the report states them. The deploy query of the report was run read-only against the test database and executes. One thing not touched here: `PARITY-FIXTURES-OBS` still reads `open` although the same merge brought `T-26` to `T-64`; its status belongs to the integration of the parity report. Reports of this wave are drafts the owner can veto under E47.
