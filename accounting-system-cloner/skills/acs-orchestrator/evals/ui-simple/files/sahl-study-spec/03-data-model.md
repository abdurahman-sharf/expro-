# نموذج البيانات

## ملخص
- **29 جدولاً** في `03-schema.sql` (نُفّذ بنجاح على PostgreSQL 16 مع اختبار triggers التوازن والفترة المقفلة والحساب التجميعي).
- الاصطلاحات: snake_case، مفاتيح `bigint identity`، `company_id` في كل جدول (متعدد الشركات + RLS)، المبالغ `numeric(18,4)`.
- **مصادر الاستنتاج:** سمات حقول النماذج (`required`/`maxlength`/`pattern`/`step`)، أسماء الحقول في النماذج (`customer_id`،
  `lines[n][item_id]`…)، بنية `GET /api/customers`، الجداول والقوائم، وقيدان قائمان. كل ما لم يُرَ مباشرة موسوم [مُستنتج].
- **وضع القراءة:** لم يُحفظ أي نموذج، فالقيود الخادمية (التفرّد، الرسائل) كلها [مُستنتج].

## قرارات تصميم
| القرار | ما يوحي به النظام المرجعي | المقترح |
|---|---|---|
| عملاء وموردون: جداول منفصلة أم party موحّد | منفصلة: ترميز مستقل (`C-NNNN`/`S-NNNN`)، نماذج مختلفة، حساب فرعي لكل طرف تحت دور مختلف | جدولان منفصلان `customer` و`supplier` (أبسط وأقرب للمرجع)؛ يمكن لاحقاً إضافة `party` إن طُلب طرف بدورين [مُستنتج] |
| المستندات: جدول لكل نوع | مسارات ونماذج مستقلة لكل نوع | جدول لكل نوع + جدول أسطره |
| الأرصدة: محسوبة أم مخزّنة | زر "تحديث الأرصدة" وأرصدة لا تطابق القيود (AN-03) ⇒ المرجع يخزّن أرصدة | **محسوبة دائماً من `journal_line` المرحّلة**؛ لقطات أداء اختيارية تُبنى من القيود فقط |
| المخزون | تكلفة واحدة لكل صنف، كمية إجمالية بلا مستودعات | `stock_movement` + `item.avg_cost` (متوسط متحرك [مُستنتج]، Q-30)؛ لا مستودعات في المرحلة الأولى |
| العملات | العملة الأساسية SAR فقط في الإعدادات؛ رصيد مورد بعملة أجنبية؛ حساب فروق عملة | أعمدة العملتين في `journal_line` من البداية؛ عملة على `supplier` و`purchase_invoice` [مُستنتج] |
| تعدد الشركات | شركة واحدة ظاهرة، لا مبدّل شركات | `company_id` + RLS |

## الكيانات

### COMPANY — المنشأة
- **الشاشات المصدر:** `set-company`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| name | varchar(200) | لا | — | — | مرئي |
| vat_number | varchar(15) | نعم | — | `^3[0-9]{13}3$` | [مُستنتج] المرجع بلا تحقق هنا |
| iban | varchar(34) | نعم | — | — | مرئي |
| base_currency | char(3) | لا | SAR | لا تُغيَّر بعد أول قيد | مرئي (نص الشاشة) |
| fiscal_year_start_month | smallint | لا | 1 | ∈ {1,4,7} | مرئي |
| prices_include_tax_default | boolean | لا | false | — | مرئي (`tax-list`) |
| cr_number، عنوان البائع الوطني | — | نعم | — | — | [مُستنتج] متطلب فوترة إلكترونية غير موجود في المرجع |

### ACCOUNT — الحساب (شجري)
- **الشاشات المصدر:** `gl-coa`، `gl-account-form`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| parent_id | bigint | نعم | — | FK → account | مرئي: "الحساب الأب" إلزامي في النموذج |
| code | varchar(12) | لا | مقترح من الأب | فريد لكل شركة؛ أرقام (maxlength 8) + لاحقة `-NNN` للفرعي التلقائي | مرئي |
| name_ar / name_en | varchar(100) | لا / نعم | — | — | مرئي |
| type | enum(asset, liability, equity, income, expense) | لا | asset | — | مرئي |
| subtype | enum(general, cash, bank, receivable, payable, inventory) | لا | general | يحدد ظهوره في السندات [مُستنتج] | مرئي |
| nature | enum(debit, credit) | لا | debit | — | مرئي |
| is_group | boolean | لا | false | التجميعي لا يقبل قيوداً | مرئي |
| requires_cost_center | boolean | لا | false | — | مرئي |
| frozen | boolean | لا | false | يمنع الترحيل [مُستنتج] | مرئي |
| role | enum (انظر `04-business-rules.md#أدوار-الحسابات-وتوجيهها`) | نعم | — | فريد لكل شركة [مُستنتج] | مرئي (عمود الدور) |
| party_type | enum(customer, supplier) | نعم | — | للحساب الفرعي التلقائي | مرئي (`1201-001`…) |
| level | smallint | لا | — | 1، 2، 4، 5(فرعي) | مرئي |
- **الفهارس:** (company_id, code) فريد؛ (company_id, role) فريد جزئي؛ (parent_id).
- **قواعد سلامة:** لا يُحذف حساب له حركات [مُستنتج]؛ لا قيد على تجميعي/مجمّد (trigger).

### ACCOUNT_ROUTING — توجيه الحسابات
- **الشاشات المصدر:** `set-account-routing`
| الحقل | النوع | القيد | المصدر |
|---|---|---|---|
| operation | varchar | 10 عمليات مرصودة (3 تبويبات) | مرئي |
| mode | enum(auto, auto_subaccount_per_party, auto_create_on_first_use, none) | — | مرئي |
| account_id | bigint | إلزامي إلا مع none/auto_create | مرئي |

### FISCAL_PERIOD — الفترة المالية
- **الشاشات المصدر:** `set-periods` · فترات شهرية، `status ∈ {open, closed}`؛ الإقفال صلب لكل المستندات (نص الشاشة). `closed_by/closed_at` [مُستنتج].

### DOCUMENT_SEQUENCE — الترقيم
- **الشاشات المصدر:** `set-numbering` · `prefix_pattern` (`INV-{YYYY}-`)، `digits=5`، `reset_policy=yearly`، `assign_on ∈ {save, approve}`.
  الفاتورة تُرقَّم **عند الاعتماد** (المسودة بلا رقم)، السند والقيد **عند الحفظ**. الحجز داخل المعاملة نفسها (`SELECT … FOR UPDATE`) لمنع الفجوات [مُستنتج].

### TAX — الضريبة
- **الشاشات المصدر:** `tax-list` · rate، category ∈ {S, Z, E} (+O للمنفّذ)، exemption_code/text (VATEX)، output/input account.
  `CHECK`: غير S ⇒ سبب إعفاء إلزامي [مُستنتج من متطلبات ZATCA].

### CUSTOMER — العميل
- **الشاشات المصدر:** `cust-form`، `cust-list`، `cust-detail`، `GET /api/customers`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| code | varchar(20) | لا | تلقائي `C-NNNN` | فريد | مرئي |
| name | varchar(150) | لا | — | — | مرئي maxlength=150 |
| kind | enum(registered, individual) | لا | registered | يتحكم في ظهور الرقم الضريبي | مرئي |
| vat_number | varchar(15) | شرطي | — | `^3[0-9]{13}3$`؛ إلزامي للمسجّل [مُستنتج] | مرئي pattern |
| cr_number | varchar(10) | نعم | — | — | مرئي |
| building_no / postal_code / additional_no | varchar(4/5/4) | نعم | — | `[0-9]{4}` / `[0-9]{5}` / — | مرئي |
| street / district / city | varchar | نعم | — | — | مرئي |
| phone / email | varchar | نعم | — | — | مرئي في القائمة والـ API (لا حقول في النموذج — Q-27) |
| credit_limit | numeric(18,4) | نعم | — | ≥ 0 | مرئي |
| payment_terms | integer | لا | 30 | ≥ 0 | مرئي |
| status | enum(active, suspended) | لا | active | — | مرئي (فلتر) |
| account_id | bigint | لا | — | FK → الحساب الفرعي التلقائي | مرئي (حقل حساب الذمم + الدليل) |
| id_type، is_government | — | نعم | — | — | [مُستنتج] متطلب سعودي غير موجود في المرجع |

### SUPPLIER — المورد
- **الشاشات المصدر:** `supp-form`، `supp-list` · name (150)، vat_number (pattern)، payment_terms (30)، code `S-NNNN`، iban، currency [مُستنتج من القائمة]، account_id (فرعي تحت `suppliers`).

### ITEM — الصنف · UNIT_OF_MEASURE — وحدة القياس
- **الشاشات المصدر:** `item-form`، `item-list` · code (20، يدوي)، name، type ∈ {stock, service}، uom (وحدة واحدة — لا وحدات بديلة)،
  barcode، sale_price، purchase_price، tax_id، revenue_account_id، cogs_account_id، reorder_level؛ opening_qty/opening_cost
  تُترجم إلى `stock_movement(source_type='opening')` + قيد افتتاحي. avg_cost/qty_on_hand لقطات [مُستنتج].

### JOURNAL_ENTRY / JOURNAL_LINE — القيد وأسطره
- **الشاشات المصدر:** `gl-journal-list`، `gl-journal-form`، `gl-journal-view`
- رأس: number، entry_date، reference، memo (إلزامي)، source_type/source_id (المصدر النصي في القائمة)، status ∈ {draft, posted}
  (مرئي ضمنياً: "تضمين القيود غير المرحّلة" في الميزان + زرا حفظ/ترحيل)، is_recurring (مرئي)، reversal_of_id [مُستنتج].
- سطر: account_id، debit/credit (أحدهما)، **currency_code، exchange_rate، debit_base، credit_base** (مقترح — المرجع عملة أساسية
  في القيد المرصود)، cost_center_id، memo.
- **فاتورة البيع تولّد قيدين منفصلين** (`sales_invoice` و`sales_invoice_cost`) — مرئي.
- **التوازن:** constraint trigger مؤجّل + فحص عند الترحيل؛ **الفترة المقفلة والحساب التجميعي/المجمّد:** trigger (مُختبر).

### SALES_INVOICE / SALES_INVOICE_LINE — فاتورة المبيعات
- **الشاشات المصدر:** `sales-invoice-form`، `sales-invoice-list`، `sales-invoice-view`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| number | varchar(30) | نعم (مسودة) | — | فريد؛ إلزامي إن لم تكن مسودة | مرئي |
| customer_id | bigint | لا | — | FK | مرئي required |
| invoice_date | date | لا | اليوم | فترة مفتوحة عند الإصدار | مرئي required |
| reference | varchar(200) | نعم | — | — | مرئي |
| prices_include_tax | boolean | لا | من `company.prices_include_tax_default` (false) | — | مرئي |
| doc_discount | numeric(18,4) | لا | 0 | ≥ 0 | مرئي (خيارات متقدمة) |
| salesman_id | bigint | نعم | — | FK | مرئي (عنصر مخصص) |
| subtotal / tax_total / total / paid_amount | numeric(18,4) | لا | 0 | paid ≤ total | مرئي في العرض |
| status | enum(draft, issued, partially_paid, paid, cancelled) | لا | draft | اتساق مع paid_amount (يمنع AN-02) | مرئي (مسودة/معتمدة/مدفوعة جزئياً) + [مُستنتج] للباقي |
| delivery_status | enum(undelivered, delivered) | لا | undelivered | — | مرئي (مُسلَّم + إجراء إلغاء التسليم) |
| حقول ZATCA: invoice_type_code، invoice_subtype، uuid، icv، pih، invoice_hash، qr_payload، zatca_status، issued_at | — | نعم | — | — | [مُستنتج] غير موجودة في المرجع |
- السطر: item_id (NULL = بند نصي حر)، description (إلزامية للحر)، qty (3 خانات)، unit_price، tax_id + لقطة tax_rate،
  discount_alloc، net_amount، tax_amount، line_total، unit_cost (لقطة للتكلفة).
- **قواعد سلامة:** لا حذف لفاتورة غير مسودة (trigger — يصحح AN-04).

### RECEIPT — سند القبض
- **الشاشات المصدر:** `sales-receipt-form` · number `RCV-…` عند الحفظ، invoice_id (إلزامي، فاتورة صادرة)، receipt_date، amount ≥ 0.01،
  account_id (treasury/bank)، journal_entry_id. تخصيص على عدة فواتير/دفعات مقدمة: غير موجود في المرجع.

### PURCHASE_INVOICE / PURCHASE_INVOICE_LINE — فاتورة المشتريات
- **الشاشات المصدر:** `pur-invoice-form` · supplier_id، supplier_invoice_no (إلزامي؛ فريد لكل مورد [مُستنتج])، invoice_date، أسطر
  (item_id/description/qty/unit_cost/tax). لا مسودة. currency/exchange_rate [مُستنتج].

### STOCK_MOVEMENT — حركة المخزون [مُستنتج]
لا شاشة حركات في المرجع؛ يُستنتج من كمية الصنف وتقرير "حركة المخزون" وقيد التكلفة.

### CREDIT_NOTE — الإشعار الدائن [مُستنتج — غير موجود في المرجع]
مطلوب لتصحيح الفواتير الصادرة بدل حذفها (ZATCA: نوع 381 يرجع للفاتورة الأصلية بسبب).

### الصلاحيات والمستخدمون
`app_user`، `role`، `role_permission(screen, action, scope)`، `role_special_permission`، `user_role` — المصدر `set-roles`.
**النطاق (`scope`: all/own) غير ظاهر في المرجع** — إن طُلب "الخاص به" يلزم `created_by` (موجود في جداول المستندات) + سياسة RLS [مُستنتج].

### APP_MODULE، AUDIT_LOG، SAVED_REPORT، SALESMAN، COST_CENTER
- `app_module` من `apps-manager` (14 وحدة، enabled).
- `audit_log` من `audit-log` (الوقت، المستخدم، الإجراء، السجل) + before/after [مُستنتج].
- `saved_report` من `rpt-builder` (source ∈ {invoices, journal}).
- `salesman` من الحقل المخصص في الفاتورة، `cost_center` من سطر القيد (مستوى واحد مرصود).

## الترقيم لكل كيان
| الكيان | النمط | التوليد | إعادة الضبط | المصدر |
|---|---|---|---|---|
| فاتورة مبيعات | `INV-{YYYY}-NNNNN` | عند الاعتماد | سنوياً | مرئي |
| سند قبض | `RCV-{YYYY}-NNNNN` | عند الحفظ | سنوياً | مرئي |
| قيد يومية | `JE-{YYYY}-NNNNN` | عند الحفظ | سنوياً | مرئي |
| عميل | `C-NNNN` | عند الحفظ | لا | مرئي في القائمة [التوليد مُستنتج] |
| مورد | `S-NNNN` | عند الحفظ | لا | مرئي في القائمة |
| حساب فرعي لطرف | `<رمز الأب>-NNN` | عند إنشاء الطرف | لا | مرئي في الدليل |
| صنف | يدوي | — | — | مرئي (حقل إلزامي) |
| فاتورة مشتريات | غير معروف | — | — | Q-35 |

## تحقق الاتساق
ميزان المراجعة وقائمة الدخل وكشف حساب العميل كلها قابلة للاشتقاق من `journal_line` + `journal_entry.status='posted'` +
`account.type/role` (المعادلات في `06-reports.md`). أرصدة العملاء = رصيد الحساب الفرعي لكل عميل.
