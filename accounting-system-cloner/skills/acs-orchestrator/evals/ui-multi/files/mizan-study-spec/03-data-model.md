# نموذج البيانات

## ملخص
- **49 جدولاً** في `03-schema.sql` (PostgreSQL 15+، يُحمَّل بلا أخطاء — جُرّب على PostgreSQL 16 مع اختبار trigger التوازن)، والـ ERD في `03-erd.mmd`.
- **الاصطلاحات:** snake_case، مفاتيح `bigint identity`، `company_id` في كل جدول (تعدد الشركات مرئي: مبدّل الشركات) مع RLS،
  `branch_id` على المستندات وأسطر القيود (الفرع بُعد تحليلي — مرئي)، المبالغ `numeric(18,4)`، أسعار الصرف `numeric(18,6)`.
- **مصادر الثقة:** "مرئي" = من حقول النماذج وسماتها أو أعمدة القوائم أو الـ API؛ "[مُستنتج]" = افتراض مع سببه.
- **قيود الدراسة:** وضع قراءة فقط ⇒ لا رؤية لما يُنشأ خلف الشاشات (حسابات فرعية، طبقات FIFO، قيود السندات)؛ كلها [مُستنتج] ومربوطة بخلايا محجوبة.

## قرارات التصميم المستخلصة
| القرار | ما يوحي به المرجع | الاقتراح |
|---|---|---|
| طرف موحّد أم جداول منفصلة | شاشات ورموز منفصلة (`C-NNNN`، `S-NNNN`) وحسابات رقابة منفصلة | جدولان `customer` و`supplier` (كالمرجع)؛ يمكن لاحقاً `party` موحّد |
| المستندات | مسارات منفصلة لكل نوع | جدول لكل نوع مستند + أسطره |
| المبالغ بعملتين | العميل/المورد له عملة؛ الأرصدة تُعرض بعملة الطرف؛ إعادة التقييم تعرض "الرصيد بالعملة" و"السعر الدفتري" | `amount` + `amount_base` + `exchange_rate` على المستند وسطر القيد (إلزامي — تجنّب AN-01) |
| الأرصدة | زر "تحديث الأرصدة" في اللوحة يوحي بأرصدة مخزّنة [مُستنتج] | محسوبة دائماً من `journal_line` (+ لقطات للأداء) |
| المخزون | FIFO + جرد مستمر، لا يتغير بعد أول حركة | `stock_movement` + `stock_layer` لكل (صنف، مستودع) |
| الفروع | "لا (بُعد تحليلي)" + حساب جاري لكل فرع | `branch_id` على سطر القيد؛ قيود جاري الفروع للتحويلات بين الفروع فقط |
| تعدد الشركات | مبدّل شركات | `company_id` + Row Level Security |

## الكيانات

### COMPANY — المنشأة
- **الشاشات:** `set-company` · الحقول: الاسم، الرقم الضريبي (15 رقماً)، IBAN، العملة الأساسية (**معطّلة بعد أول قيد** — مرئي)، شهر بداية السنة (يناير/أبريل/يوليو — مرئي).
- العنوان الوطني والسجل التجاري للبائع: **غير موجودة في المرجع**، مضافة لمتطلبات ZATCA [مُستنتج].

### APP_MODULE — الوحدات
- **الشاشات:** `set-apps` · `code, name, category, enabled` — 14 وحدة (مرئي). مجموعات الصلاحيات ترتبط بالوحدة عبر `permission.module_code` [مُستنتج].

### ACCOUNT — الحساب
- **الشاشات:** `gl-coa`، `gl-account-form`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| parent_id | bigint | نعم | — | FK ذاتي | مرئي: "الحساب الأب" (إلزامي في النموذج) |
| code | varchar(12) | لا | مقترح من الأب | فريد لكل شركة؛ أرقام فقط حتى 8 للحسابات اليدوية | مرئي: `maxlength=8`، `pattern=[0-9]+`؛ الفرعية التلقائية بلاحقة `-NNN` |
| name_ar / name_en | varchar(100) | لا / نعم | — | — | مرئي |
| type | enum(asset, liability, equity, income, expense) | لا | — | — | مرئي |
| subtype | enum(general, cash, bank, receivable, payable, inventory) | لا | general | — | مرئي |
| nature | enum(debit, credit) | لا | debit | — | مرئي |
| is_group | boolean | لا | false | لا يقبل قيوداً | مرئي (نص "لا يقبل قيوداً") |
| requires_cost_center / frozen | boolean | لا | false | — | مرئي |
| role | text | نعم | — | قيم نظامية | مرئي في عمود "الدور" |
| party_type | enum | نعم | — | للحسابات الفرعية التلقائية | [مُستنتج] |
- **الترميز:** المستوى 1 رقم واحد، المستوى 2 رقمان، الحركة 4 أرقام، الفرعي التلقائي `XXXX-NNN` (مرئي من القائمة).
- **سلامة:** لا قيد على حساب تجميعي ولا مجمّد [مُستنتج]؛ لا حذف لحساب له حركات [مُستنتج].

### BRANCH / WAREHOUSE
- **الشاشات:** `gl-branches`، `inv-warehouses`، `inv-transfer-form`
- BRANCH: `code` (3 أحرف، يدخل `{BRANCH}` في الترقيم)، `name`، `is_head_office`، `separate_ledger` (المرصود: لا)، `current_account_id` (حساب جاري لكل فرع)، `default_warehouse_id`.
- WAREHOUSE: `branch_id` (مستودع لكل فرع في الديمو)، `allow_negative` (مرئي لكل مستودع)، `is_transit` [مُستنتج من نص "بضاعة في الطريق"].

### CURRENCY / COMPANY_CURRENCY / EXCHANGE_RATE
- **الشاشات:** `gl-currencies`، `gl-fx-rate-form`
- السعر بـ 6 خانات (مرئي)، تاريخ السعر، المصدر (يدوي — مرئي؛ "آلي" [مُستنتج]). عملة أساسية واحدة لكل شركة.
- **قاعدة اختيار السعر للمستند:** غير متحققة (Q-02) — الافتراض الآمن: سعر تاريخ المستند (آخر سعر ≤ التاريخ) مع إمكانية التعديل بصلاحية.

### FISCAL_YEAR / FISCAL_PERIOD
- **الشاشات:** `set-company`، `set-periods` · فترات شهرية بحالة مفتوحة/مقفلة؛ الإقفال **صلب على كل المستندات** (نص مرئي).

### ACCOUNT_ROUTING — توجيه الحسابات
- **الشاشات:** `set-account-routing` · `(tab, operation, mode, account_id)`؛ الأوضاع المرصودة: `auto`، `auto_subaccount_per_party`، `auto_create_on_first_use`، `none`.

### TAX
- **الشاشات:** `tax-list` · `rate`، `category` (S/Z/E؛ O مضافة للمواصفة)، حسابا المخرجات والمدخلات (اختياريان — المعفاة بلا حسابات)، `exemption_code` (`VATEX-SA-…`).

### DOCUMENT_SEQUENCE (+ COUNTER)
- **الشاشات:** `set-numbering` · `prefix_template` بمتغيرات `{BRANCH}` و`{YYYY}`، `digits`=5، `reset_policy`=سنوياً ولكل فرع، `assign_on` (الفاتورة عند الاعتماد؛ السند والقيد عند الحفظ). العدّاد لكل (تسلسل، فرع، سنة).

### CUSTOMER
- **الشاشات:** `cust-form`، `cust-list`، `cust-detail`، الـ API
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| code | varchar(20) | لا | تلقائي | فريد؛ نمط `C-NNNN` | مرئي (للقراءة فقط "تلقائي") |
| name | varchar(150) | لا | — | — | مرئي |
| kind | enum(registered, individual) | لا | registered | يتحكم في ظهور الرقم الضريبي | مرئي (حقل شرطي) |
| vat_number | varchar(15) | نعم | — | `^3[0-9]{13}3$` | مرئي (`pattern`) |
| cr_number | varchar(10) | نعم | — | — | مرئي |
| building_no، street، district، city، postal_code، additional_no | — | نعم | — | 4 أرقام / 5 أرقام | مرئي (العنوان الوطني) |
| currency_code | char(3) | لا | SAR | FK | مرئي |
| credit_limit | numeric | نعم | — | ≥ 0 | مرئي |
| payment_terms_days | int | لا | 30 | ≥ 0 | مرئي |
| account_id | bigint | نعم | — | الحساب الفرعي التلقائي | [مُستنتج] من التوجيه والحسابات `1201-NNN` |
| status | enum(active, suspended) | لا | active | — | مرئي (فلتر القائمة) |
| id_type، is_government | — | — | — | — | [مُستنتج] لمتطلبات ZATCA؛ غير موجودة في المرجع |
| created_by | bigint | — | — | — | [مُستنتج] لنطاق الصلاحيات "الخاص به" (غير متحقق) |

### SUPPLIER
- **الشاشات:** `sup-form`، `sup-list` · النموذج: الاسم، الرقم الضريبي، مدة السداد؛ القائمة تعرض IBAN والعملة والرصيد ⇒ الحقلان موجودان في الكيان رغم غيابهما عن النموذج (Q-44). `account_id` فرعي تلقائي تحت `suppliers`.

### ITEM / UNIT_OF_MEASURE / INVENTORY_SETTINGS
- **الشاشات:** `item-form`، `items-list`، `inv-settings`
- ITEM: `code` (≤20)، `type` (stock/service)، `uom_id` (وحدة أساسية فقط — **لا وحدات بديلة ولا معاملات تحويل** في المرجع)، `barcode`، `sale_price`، `purchase_price`، `tax_id`، `revenue_account_id`، `cogs_account_id` (تجاوز التوجيه لكل صنف)، `reorder_level`. الرصيد الافتتاحي (كمية + تكلفة الوحدة) يُدخل في نموذج الصنف ⇒ حركة `opening` + طبقة FIFO + قيد [مُستنتج].
- INVENTORY_SETTINGS: `valuation` (FIFO مختار؛ متوسط مرجح متحرك بديل؛ **مقفل بعد أول حركة**)، `system` (مستمر)، `allow_negative` (لا)، `variance_account_id`.

### STOCK_MOVEMENT / STOCK_LAYER — FIFO
- كل وارد (رصيد افتتاحي، فاتورة شراء، استلام تحويل) يُنشئ طبقة `(item, warehouse, date, qty, unit_cost)`؛ كل صادر (بيع، إرسال تحويل) يستهلك الطبقات الأقدم أولاً **في المستودع نفسه** [مُستنتج — Q-14]، وتكلفته = Σ(الكمية المستهلكة × تكلفة الطبقة).
- التحويل يحمل تكلفة الطبقات المستهلكة إلى المستودع المستلم كطبقة جديدة **بتاريخ الطبقة الأصلي أو تاريخ الاستلام** [مُستنتج — Q-14].

### STOCK_TRANSFER (+ LINES)
- **الشاشات:** `inv-transfer-form` · `from_warehouse_id`، `to_warehouse_id`، `transit_warehouse_id`، `status` (مسودة/مُرسَل/مُستلَم [مُستنتج من النص وزر "إرسال التحويل")]، أسطر بكمية ≥ 0.001 وتكلفة FIFO محسوبة.

### SALES_INVOICE (+ LINES)
- **الشاشات:** `sales-invoice-form`، `sales-invoice-list`، `sales-invoice-view`
- رأس: `branch_id` (من فرع الترويسة [مُستنتج])، `number` (عند الاعتماد)، `customer_id`، `invoice_date`، `reference`، `prices_include_tax`، `doc_discount` (مبلغ)، `salesman_id`، `currency_code`/`exchange_rate` (**غير ظاهرين في النموذج** — [مُستنتج] من عملة العميل)، الإجماليات بالعملتين، `status` (مسودة/معتمدة…)، `payment_status` (غير مدفوعة/مدفوعة جزئياً/مدفوعة)، `delivery_status` (مُسلَّم/غير مُسلَّم) — ثلاث حالات مستقلة (مرئي).
- أسطر: `item_id` (NULL = بند نصي حر، والوصف إلزامي حينها — مرئي)، `qty` (3 خانات)، `unit_price` (خانتان)، `tax_rate` (15%/0%)، `discount_alloc` [مُستنتج]، `unit_cost` (FIFO).
- حقول ZATCA (`uuid`، `icv`، `pih`، `invoice_hash`، `qr_payload`، `zatca_status`، `zatca_messages`، `cleared_at`، `reported_at`، `invoice_type_code`، `invoice_subtype`، `egs_unit_id`): **غير ظاهرة في واجهة المرجع** (الطباعة 404) لكنها لازمة للمرحلة الثانية المفعّلة في `set-zatca`.
- قيود سلامة تمنع تناقضات المرجع: المسودة لا تكون "مدفوعة جزئياً" ولا "مُسلَّمة"؛ "مدفوعة جزئياً" ⇒ 0 < المدفوع < الإجمالي.

### RECEIPT / RECEIPT_ALLOCATION
- **الشاشات:** `sales-receipt-form` · سند لفاتورة واحدة في المرجع (حقل `invoice_id`)؛ المقترح جدول تخصيص لدعم عدة فواتير والدفعة المقدمة. `fx_diff_base` لفرق العملة المحقق. `exchange_rate` غير ظاهر في المرجع (Q-03).

### SALES_CREDIT_NOTE — [مُستنتج، غير موجود في المرجع]
- لم يُعثر على شاشة إشعار دائن/مرتجع (Q-21)، لكنه إلزامي لتصحيح فاتورة معتمدة ولـ ZATCA (نوع 381، مرجع الفاتورة الأصلية + سبب).

### PURCHASE_INVOICE (+ LINES)
- **الشاشات:** `purch-invoice-form` · `supplier_invoice_no` إلزامي (فريد لكل مورد [مُستنتج])، أسطر بتكلفة وضريبة؛ لا مسودة ولا عملة ولا مستودع ظاهر.

### JOURNAL_ENTRY / JOURNAL_LINE
- **الشاشات:** `gl-journal-form`، `gl-journal-list`، `gl-journal-view`
- رأس: `number` (`{BRANCH}-JE-{YYYY}-NNNNN` عند الحفظ)، `entry_date`، `reference`، `memo` (إلزامي)، `source_type/source_id` (المصدر يُعرض نصاً — مرئي)، `status` (مسودة/مرحّل)، `is_recurring` (خانة "متكرر شهرياً").
- سطر: `account_id`، `cost_center_id`، `memo`، `debit/credit` + **`currency_code`، `exchange_rate`، `debit_base/credit_base`** (غير ظاهرة في المرجع — مضافة لتجنّب AN-01)، `branch_id` (بُعد).
- **فاتورة بيع مخزنية = قيدان مستقلان** (إيراد، تكلفة) — مرئي.

### FX_REVALUATION (+ LINES)
- **الشاشات:** `gl-fx-revaluation` · أسطر المعاينة: الحساب، الرصيد بالعملة، السعر الدفتري، سعر التقييم، الفرق (مرئي). قيد + قيد عكسي أول الفترة التالية (نص مرئي).

### EGS_UNIT / ZATCA_SUBMISSION
- **الشاشات:** `set-zatca` · وحدة لكل فرع (`name`، `serial_no`، `invoice_types`، حالة الشهادة، تاريخ الانتهاء) — مرئي. طابور الإرسال بإعادة محاولة كل 15 دقيقة (نص مرئي). **لا تُخزَّن مفاتيح خاصة في هذا الجدول** (خزنة أسرار).

### APP_USER / ROLE / PERMISSION / ROLE_PERMISSION / USER_ROLE / USER_BRANCH / APPROVAL_RULE / DOCUMENT_APPROVAL
- **الشاشات:** `set-roles`، `set-approvals` · الإجراءات المرئية: عرض/إضافة/تعديل (مع "مسودة فقط")/حذف/اعتماد/طباعة + صلاحيات خاصة (رؤية التكلفة، تعديل سعر البيع، تجاوز حد الائتمان). `user_branch` من نص "المستخدم مقيّد بفروعه المسموحة". حدود الاعتماد بمراحل (`step_no`).
- `scope` (الكل/الخاص به/الفرع): [مُستنتج] — لم يظهر نطاق في المصفوفة.

### AUDIT_LOG / SAVED_REPORT / IMPORT_JOB
- AUDIT_LOG: الوقت، المستخدم، الإجراء، السجل (مرئي)؛ قبل/بعد [مُستنتج، موصى به].
- SAVED_REPORT: المصدر (الفواتير/القيود) + تعريف JSON (مرئي جزئياً).
- IMPORT_JOB: الكيان (العملاء/الأصناف/أرصدة افتتاحية) (مرئي).

## الفهارس المتوقعة [مُستنتج من الفلاتر]
`journal_entry(company_id, entry_date)`، `journal_line(account_id)`، `journal_entry(source_type, source_id)`، `stock_layer(item_id, warehouse_id, layer_date) WHERE qty_remaining > 0`، `customer(company_id, code)`، `sales_invoice(company_id, number)`.

## تحقق الاتساق
ميزان المراجعة = Σ `journal_line.debit_base − credit_base` لكل حساب حيث `entry.status='posted'` (+ غير المرحّل اختيارياً) — قابل للاشتقاق.
قيمة المخزون = Σ `stock_layer.qty_remaining × unit_cost` = رصيد دور `stores` (جرد مستمر). أعمار الديون من `sales_invoice.total − paid_amount` حسب `due_date`.
