# تقرير WP34: رسوم رأس الفاتورة (التسوية والشحن) والإشعار المدين للمبيعات وقالب وصف القيد

> الحالة (2026-11-01): **نُفِّذ على الفرع `wp/WP34`** (مأخوذ من `60dd925`) بالتفويض التنفيذي الشامل (E47): **(1)** تسوية (عنوان ومبلغ بإشارة) وشحن (مبلغ وضريبة وخيار عنوان) على **الفاتورة وعرض السعر والإشعار الدائن والفاتورة المرتجعة**، تدخل إجمالي السجل والإيصالات والمطبوعات وتُرحَّل **عبر صفوف التوجيه** إلى حسابين جديدين، **(2)** **الإشعار المدين للمبيعات** مستندًا يزيد ما يدين به العميل بلا فاتورة: مدين العميل ودائن الإيراد (أو حساب يُختار) وضريبة المخرجات، بترقيم خماسي وفرع وقفل فترات وعكس لا حذف، ويدخل **بند مفتوح** في ترتيب تطبيق الرصيد (E57) والأعمار وتقرير أرصدة العملاء ويصمد الربط الثلاثي، **(3)** **قالب وصف القيد** لمبيعات ومشتريات بأربعة متغيرات، افتراضه فارغ فتبقى كل الأوصاف كما هي. **لا دفع إلى `master` ولا دمج ولا نشر ولا كتابة في دفترة ولا مساس بقيد قائم أو فاتورة صادرة.** كل ما يُقال هنا إنه يطابق دفترة مقيَّد بالدليل المذكور بجانبه، وما لم يُرصد وُسم **افتراضًا** (القسم 4). الفرع مدفوع إلى GitHub فرعَ إنقاذ بإذن المالك.
>
> **مرحلة الإصلاح بعد مراجعتين مستقلتين (2026-11-01، القسم 8):** تحقّقتُ من 20 ادعاءً فكانت كلها صحيحة وأصلحتُها باختبار يفشل بدون الإصلاح: **فاتورة بضاعتها صفر وفيها شحن لم تكن تُرحَّل**، وإجمالي السجل ومستحق البيع المحفوظ لم يكونا يضمّان الرسوم، والرسوم التي تُعطي مالًا بلا صلاحية، وضريبة الشحن والإشعار المدين خارج تقرير الضريبة، وكتلة «الشحن إلى» الفارغة بعد الإتمام، وترتيب الإيصالين، وتسمية الصلاحية، وثغرات اختبار (إعدادات وقفل ومواضع حفظ وأوصاف).

## 1. ما نُفِّذ

### 1.1 رسوم رأس الفاتورة (SAL-08 وSAL-09، OBS-A: FRM-R04 وFRM-R06 وFRM-R07)

| الجزء | ما يفعله |
|---|---|
| **الأعمدة** (ترحيل `20271900000000_InvoiceHeaderCharges`) | سبعة أعمدة **تقبل الفراغ ولا قيمة افتراضية** في `sales`: `adjustment_label` و`adjustment_amount` و`shipping_amount` و`shipping_options` و`shipping_tax_option` و`shipping_tax_percent` و`shipping_tax_amount`. يفحص وجود كل عمود قبل إضافته فيُعاد تشغيله بأمان، ولا يقرأ ولا يكتب شيئًا في بيع قائم (اختبار: صف بيع محفوظ يبقى بايتًا بايتًا). |
| **التوجيه** | المفتاح القائم `sales.adjustment` (كان «إلغاء الحسابات» ويشير إلى الخصم المسموح به ويرفضه `assertUnusedRows`) صار **يُطبَّق** (تلقائي بحساب `4204` «تسويات المبيعات») ومفتاح جديد `sales.shipping` (`4208` «إيرادات الشحن»)، كلاهما حساب إيراد. بذرة **الدليل الجديد** (`DefaultChart`) تفتح الحسابين وصفّيهما. ويفتحهما الترحيل في **الدليل القائم** كما فعل ترحيل الشيكات: أول رمز حرّ من أربعة، والصف القديم للتسوية يُنقل من الخصم المسموح به **فقط حين لم يغيّره أحد** (إلغاء + حساب الخصم)، وصف الشحن يُضاف أو يُملأ إن كان فارغًا. لا يقرأ أي كود جديد `accounting_routing` مباشرة (حارس `RoutingResolverTest` أخضر). |
| **القاعدة** (`HeaderCharges` و`ResolvedCharges`) | **التسوية** تُضاف **ما دام الإجمالي الجاري أكبر من صفر** وتقبل السالب (FRM-R07/R04)؛ **الشحن** مبلغ غير سالب؛ **ضريبة الشحن** تتبع وضع الضريبة: فوق المبلغ إن كانت الأسعار بلا ضريبة (المبلغ × النسبة)، وداخله إن كانت شاملة (المبلغ − المبلغ ÷ (1 + النسبة)) ولا تُضاف (FRM-R06: 100 بـ15% = 15.00 فوقه أو 13.0435 داخله)، **وتُحفظ بأربع منازل بنصف لأعلى لا بالهللة** (E14)، والإجمالي يُقرَّب في القيد. **يُرفض** نص غير رقم وشحن سالب ونسبة خارج 0–100 وخيار عنوان مجهول وعنوان تسوية فوق 100 حرف وتسوية تُنزل الإجمالي دون صفر (**قيد إضافي منا، لم يُرصد في دفترة**). |
| **السجل** (`Sale_lib` و`Sales::postSetHeaderCharges`) | نموذج «التسوية» (عنوان + مبلغ) و«الشحن» (مبلغ + خيار العنوان + «احتساب ضريبة X% على الشحن») يظهر **فقط** حين يُفعَّل الإعداد المعني **وفي أوضاع الفاتورة وعرض السعر والمرتجع** (لا في نقطة البيع ولا أمر العمل). صفوف الشحن وضريبته والتسوية تظهر في المجاميع قبل «الإجمالي»؛ `get_totals` يضيفها (بإشارة المستند: المرتجع سالب) ولا يمس «المجموع الفرعي». تغيير الرسوم **يفرّغ الدفعات** (تغيّر المستحق) وتغيير الوضع **ينساها** وعدم صلاحيتها يُرجع ما كان. حقول الإعداد المطفأ تُهمل في المتحكّم. |
| **الحفظ** (`Sale::save_value`) | معامل جديد أخير `headerCharges` (لا يكسر مناديًا). **داخل معاملة الحفظ وبعد حفظ البنود والضرائب** يُحسب الإجمالي الجاري من المحفوظ (`SaleLoader` + `SaleAmounts::totals`) ويُتحقق: النوع (فاتورة أو عرض أو مرتجع)، **والإعداد مفعّل في الخادم لا في الشاشة وحدها** (`HeaderChargesOff`)، **وأن ضريبة الشحن بنسبة الإعداد وبوضع الشركة**، وأن التسوية لا تُنزل الإجمالي، ثم **تُخزَّن القيم الفعلية** (التسوية صفر إن كان الإجمالي صفرًا). حفظ بيع بلا رسوم **يفرّغ الأعمدة**. تدقيق `header_charges_given` عند اكتمال بيع بالمحاسبة المفعّلة. المسودة المعلّقة تحتفظ بالرسوم وتُصدَر بها (`DraftIssue` و`DraftInvoice` وبصمة المسودة تشملها). |
| **القيد** (`SaleLoader` و`SaleAmounts::figures` و`SalePostingBuilder`) | يقرأ الترحيل **المخزَّن لا ما في السجل**. الباني: مدين العميل = الإجمالي المقرَّب؛ دائن الإيراد (البضاعة) **هو الرصيد الموازن** فيقع تقريب الهللة عليه ويبقى الشحن والتسوية **كما كُتبا**؛ دائن ضريبة المخرجات = ضريبة البنود + ضريبة الشحن (بلا تقريب)؛ دائن `sales.shipping` = الشحن بلا ضريبته الداخلة؛ والتسوية الموجبة دائنة والسالبة مدينة في `sales.adjustment`. **المرتجع (إشعار دائن أو فاتورة مرتجعة) مرآة**. الصف الملغى لمفتاح الشحن أو التسوية **يوقف الترحيل باسم** (`RoutingRowCancelled`) ولا يُرحَّل شيء، ومن لا رسوم عليه لا يحتاج الصفّين. `assertSettled` وكشف المستندات يعدّان الرسوم. |
| **العكس والتعديل** | الفاتورة الصادرة مقفلة (E21) فلا تُلغى ولا تُعدَّل، وتصحيحها بإشعار دائن (مرآة). **العكس اليدوي للقيد يعكس السطور المخزَّنة** ولو أُطفئ الإعداد وأُلغي توجيه الشحن بعدها (اختبار). |
| **الإيصالات والمطبوعات** | الإيصال الافتراضي والمختصر والحراري والبريد والفاتورة والفاتورة الضريبية وعرض السعر وPDF وصفحة المسودة: صفوف الشحن وضريبته (حين تكون فوق المبلغ) والتسوية بعنوانها (`headerChargeRows` في `document_helper.php`)، وكتلة «الشحن إلى» بخيار العنوان (آلي: عنوان العميل لمستند فيه شحن، لا تظهر / عنوان العميل / العنوان الثاني). |
| **الإعدادات** (`AccountingSettings::update`، شاشة إعدادات المحاسبة) | `accounting_sales_adjustment` و`accounting_sales_shipping` (**مطفأان افتراضيًا فلا يتغير شيء**) و`accounting_shipping_tax_percent` (0 = بلا ضريبة)، **بتدقيق «قبل وبعد»** وصلاحية `acc_settings_manage`. تعمل مع **المحاسبة معطَّلة** (تُحفظ الرسوم بلا قيود ولا تدقيق). |

### 1.2 الإشعار المدين للمبيعات (SAL-20؛ **تصميم متحفظ، افتراض**)

| الجزء | ما يفعله |
|---|---|
| **الترحيل** `20271900000001_SalesDebitNotes` | جدول `sales_debit_notes` (فرع وعميل وتاريخ ومبلغ ونسبة وضريبة وإجمالي وحساب اختياري وملاحظة وحالة `posted`/`reversed` وسبب العكس) بقيود `CHECK`، **وعمود `debit_note_id` في `customer_credit_applications`** وقاعدة «هدف واحد بالضبط من ثلاثة» (فاتورة أو رصيد افتتاحي أو إشعار مدين) **بالاسم نفسه `_target`**؛ ووحدة `accounting_debit_notes` وصلاحية `acc_debit_notes_manage` **بلا منح لأحد**. يُعاد تشغيله بأمان و`down()` متين. مصنَّف `HEAVY` في `MigrationChecks`. |
| **الخدمة** `SalesDebitNotes` | `create`: صلاحية الإدارة ← **الإعداد والمحاسبة مفعّلان** ← مبلغ (حتى أربع منازل، أكبر من صفر) ونسبة ضريبة (0–100) وتاريخ حتى اليوم وملاحظة ≤ 500 ← عميل موجود ← فرع يعمل فيه الموظف ← حساب مسموح (إيراد أو مصروف مفتوح بلا طرف) ← **قفل العميل ومعاملة**: صف + رقم (ترقيم WP8 نوع `sales_debit_note` **بخمس منازل**، وبلا تسلسل يُعرض رقم المعرّف بخمس منازل) + **قيد واحد**: مدين حساب العميل الفرعي بالإجمالي، دائن `sales.revenue` (أو الحساب المختار) بالباقي، دائن `tax.output` بالضريبة (بلا تقريب) والإجمالي مقرَّب لأقرب هللة **كالفاتورة (E14)** ← `CreditSettlement::settle` ← تدقيق. **قفل الفترة** بالترحيل نفسه فتُرفض فترة مقفلة ولا يبقى شيء. `reverse`: صلاحية + سبب (1–255) + فرع الإشعار + عكس القيد بتاريخه (أو **بتاريخ اليوم إن أُقفلت فترته**) + إعادة توزيع الرصيد الذي أُنفق عليه + تدقيق، **ولا حذف ولا تعديل**، ولا يشترط الإعداد (لتبقى الإشعارات قابلة للإلغاء ولو أُطفئ). |
| **الدفتر والأعمار** | `CustomerLedger::position` يضيف **الإشعارات القائمة بنداً مفتوحًا بتاريخه** (مفتاح في مجال خاص بعد الأرصدة الافتتاحية `SalesDebitNotes::keyOf`)، فتتأثر الأعمار (`aging`) والمتبقي وحالة الدفع، **ويصمد الربط الثلاثي** (رصيد حساب العميل = مجموع البنود المفتوحة = الأعمار) بعد الإنشاء والعكس والدفع (`assertTies`). `AgedLedger` يقرأ الحساب مباشرة فيشمله بتاريخ قيده. |
| **ترتيب E57** | الرصيد الدائن يُنفَق على أقدم بند مفتوح **بتاريخه ثم المعرّف** والإشعار بند كأي بند: إشعار أقدم من الفاتورة يأخذ الرصيد قبلها، وفاتورة أقدم تأخذه قبل إشعار أحدث، **وعند تعادل اليوم يسبق الإشعارُ الفاتورةَ** (مفتاحه سالب كالأرصدة الافتتاحية: **افتراض**)، **ولا تُعاد كتابة تطبيق قائم**. عكس الإشعار يحرّر ما أُنفق عليه فيُطبَّق على غيره. |
| **التقارير** | `CustomerBalanceReport` يعدّ قيد الإشعار تحت **المبيعات** (وعكسه يُحسب كما يعكس: يتحقق باختبار)، والرصيد قبل يحمل إشعار فترة سابقة. صفحة العميل وقائمة التطبيقات (وصفحة تعديل البيع) تسمّي الإشعار برقمه. القيد مصدره `sales_debit_note` محجوز لمستنده (`DOCUMENT_SOURCES`): **شاشة القيود ترفض عكسه** (`DocumentJournalLocked`). |
| **الشاشات** `AccountingDebitNotes` | قائمة (بحث بالرقم أو العميل، حالة، من/إلى، ترقيم صفحات، نطاق فروع الموظف مع تنبيه) ونموذج («إشعار مدين جديد» بمنتقي عملاء) ونموذج الإلغاء بسبب. **كل تغيير POST** وتُرفض المسارات بـGET. تظهر بطاقة «جديد» لمن يملك الصلاحية **والإعداد مفعّل**، وزر «إلغاء» لمن يملكها. |

### 1.3 قالب وصف القيد (SAL-34)

| الجزء | ما يفعله |
|---|---|
| **الإعداد** | `accounting_journal_description_sales` و`..._purchases` (فارغ = **الأوصاف الحالية بايتًا بايتًا**)، بتدقيق «قبل وبعد» وصلاحية الإعدادات. **التحقق** (`JournalDescriptionTemplate::isValid`): ≤ 200 حرف، **المتغيرات `{number}` و`{party}` و`{type}` و`{date}` وحدها** (قوس غير مغلق أو غير معروف يُرفض)، **بلا وسوم `<>` ولا أحرف تحكم**. قالب مخزَّن غير صالح يُعامل كفارغ. |
| **التطبيق** (`JournalDescriptions` وخطافان صغيران) | **على القيد الرئيسي للمستند عند ترحيله فقط**: قيد الفاتورة/المرتجع (`SalePosting::postInvoice`) وقيد المشتريات (`ReceivingPosting::postPurchase` بلا التحويل بين الفروع). **لا يمس قيدًا قائمًا** (لا إعادة كتابة) **ولا قيد التكلفة ولا الدفعات** (وصفها باقٍ). القيم: رقم المستند (الفاتورة: رقم تسلسلها أو المعرّف، الإشعار الدائن والفاتورة المرتجعة بتسلسلها، الشراء والإشعار المدين بتسلسلهما)، اسم العميل أو المورد (الشركة أو الشخص)، نوع المستند بلغة المستخدم لحظة الترحيل، التاريخ. **القيم تُنظَّف** (إزالة وسوم وأحرف تحكم ومسافات زائدة، **ولا يُوسَّع متغير كُتب داخل قيمة**) والنتيجة تُقطع عند 255. علامة «مرتجع بلا فاتورة» تبقى. |

## 2. الأدلة

**الاختبارات** (كل ملف يخص صنفًا واحدًا، وأسماؤها `test{الطريقة}_{السلوك}`):

- `tests/Libraries/Accounting/SalePostingTest.php`: **35** اختبارًا جديدًا
- `tests/Libraries/Accounting/SalesDebitNotesTest.php`: **35** اختبارًا جديدًا
- `tests/Controllers/AccountingDebitNotesControllerTest.php`: **18** اختبارًا جديدًا
- `tests/Libraries/HeaderChargesTest.php`: **14** اختبارًا جديدًا
- `tests/Controllers/SalesControllerTest.php`: **13** اختبارًا جديدًا
- `tests/Libraries/Accounting/SalePostingBuilderTest.php`: **13** اختبارًا جديدًا
- `tests/Libraries/Sale_libTest.php`: **12** اختبارًا جديدًا
- `tests/Database/InvoiceHeaderChargesMigrationTest.php`: **9** اختبارًا جديدًا
- `tests/Libraries/Accounting/JournalDescriptionTemplateTest.php`: **8** اختبارًا جديدًا
- `tests/Libraries/Accounting/AccountingSettingsTest.php`: **7** اختبارًا جديدًا
- `tests/Database/SalesDebitNotesMigrationTest.php`: **5** اختبارًا جديدًا
- `tests/Libraries/Accounting/ReceivingPostingTest.php`: **3** اختبارًا جديدًا
- `tests/Libraries/Accounting/RoutingKeyTest.php`: **3** اختبارًا جديدًا
- `tests/Libraries/ResolvedChargesTest.php`: **3** اختبارًا جديدًا
- `tests/Libraries/Accounting/CustomerBalanceReportTest.php`: **2** اختبارًا جديدًا
- `tests/Libraries/Accounting/DefaultChartTest.php`: **2** اختبارًا جديدًا
- `tests/Libraries/Numbering/DocumentTypeTest.php`: **2** اختبارًا جديدًا
- `tests/Libraries/Accounting/AgedLedgerTest.php`: **1** اختبارًا جديدًا
- `tests/Libraries/Accounting/DraftInvoiceTest.php`: **1** اختبارًا جديدًا
- **المجموع: 186 اختبارًا جديدًا في 19 ملفًا**، وعُدِّل بعض القائم (القسم 2 أدناه).

**الاختبارات المرتبطة التي شُغِّلت (متسلسلة على `ospos_test_wp34`، لا المجموعة الكاملة بحسب التعليمات)** وكلها ناجحة بلا فشل ولا خطأ ولا تحذير:

**التشغيل الأخير بعد كل التعديلات والتبسيطات: 5136 اختبارًا و23095 تأكيدًا، كلها ناجحة** (ملف واحد متسلسل على `ospos_test_wp34`): `HeaderChargesTest` و`ResolvedChargesTest` و`Sale_libTest` و`Sale_libPaymentTest` و`Tax_libTest` و`SalePostingBuilderTest` و`SalePostingTest` و`SaleLoaderTest` و`DraftInvoiceTest` و`CustomerLedgerTest` و`CreditSettlementTest` و`CustomerBalanceReportTest` و`AgedLedgerTest` و`SalesDebitNotesTest` و`JournalDescriptionTemplateTest` و`AccountingSettingsTest` و`RoutingKeyTest` و`RoutingServiceTest` و`RoutingResolverTest` (حارس قراءة التوجيه المباشرة) و`DefaultChartTest` و`ReceivingPostingTest` و`JournalServiceTest` و`CustomerOpeningTest` و`CustomerAdvancesTest` و`CustomerDocumentsTest` وكل `Libraries/Accounting/Readiness` وكل `Libraries/Numbering` و`SalesControllerTest` و`AccountingDebitNotesControllerTest` و`AccountingSetupControllerTest` و`AccountingReceivablesControllerTest` و`AccountingRoutingControllerTest` وكل `tests/Database` و`Models/SaleTest` و`LanguageFilesTest`. وقبلها أخفقت اختبارات سُجِّلت وعولجت: ثلاثة في `DraftInvoiceTest` (مفاتيح `figures` الأربعة الجديدة) وثلاثة في `CustomerOpeningsMigrationTest` وفشلان في `DocumentNumberingTest` (الخانات الخمس) وسبعة في الجولة الأولى من `SalePostingTest` (إعدادات بقيت من اختبار سابق، وإلغاء الفاتورة الصادرة مقفل أصلًا) وأربعة في `RoutingServiceTest` و`DefaultChartTest` (الصف والحسابان الجديدان).

- **اختبارات ذهبية للوصف:** `SalePostingTest::testSaveValue_LeavesTheDescriptionsTheApplicationWritesWhenThereIsNoTemplate` و`ReceivingPostingTest::testSaveValue_LeavesTheDescriptionOfAPurchaseAsItWasWhenThereIsNoTemplate`، **ولم يُعدَّل أي توقع في `T-06` إلى `T-19`** (كلها خضراء).
- **اختبارات الرسوم الفارغة:** `SalePostingBuilderTest::testInvoice_JournalsTheSameWhenTheChargesAreEmptyAsWhenThereAreNone` و`SalePostingTest::testSaveValue_LeavesTheColumnsOfTheChargesEmptyOnASaleWithoutAnyAndJournalsItAsBefore`.
- **ما عُدِّل من اختبارات قائمة (والسبب):** `RoutingKeyTest` (صف التسوية صار يُطبَّق وله نوع افتراضي «تلقائي» وحساب مقبول ثان) و`SalePostingBuilderTest` (اختبار رفض التسوية «التلقائية» صار رفض صف مبيعات المنتج)، `RoutingServiceTest` و`DefaultChartTest` (صف توجيه وحسابان جديدان)، `DocumentNumberingTest` (الخانات الافتراضية للإشعار المدين خمس)، `DraftInvoiceTest` (أربعة مفاتيح جديدة في `figures`)، `CustomerOpeningsMigrationTest` (يعيد الترحيل الأحدث إلى الوراء قبل أن يعيد الأقدم لأن الأحدث يتكئ عليه)، و`Sale_libTest` (طريقة جديدة في المحاكاة)، والمساعد المشترك `SaleAccountingFixtureTrait` (معامل أخير للرسوم، وتفريغ جدول الإشعارات في `stopSelling`).
- **لم يُجرَ** (ولا يُدّعى): تجربة متصفح على الواجهة الحقيقية، **والمجموعة الكاملة للمشروع**، ومراجعة مستقلة خارج هذا التقرير، وأي مقارنة مع دفترة بالكتابة (لم يُكتب في دفترة شيء).

## 3. أدلة تعطيل الحارس

عطّلتُ **كل حارس أضفتُه على حدة** بـ`tools/dev/guard_mutations.py` وقائمة التعطيلات في `docs/accounting/daftra-parity/wp34-guards.json`، وشغّلتُ ملفات الاختبار المعنيّة فقط (وللملفات الكبيرة `--filter` باختبارات WP34).

**النتيجة على الشيفرة النهائية: 110 من 110 حارسًا كُشف تعطيله (فشلت اختبارات كلٍّ منها).** في التشغيل الأول من 115 تعطيلًا: 103 مكشوفة و12 غير مكشوفة، فعالجتُها هكذا: **خمسة تعطيلات مكافئة** (لا يتغير بها سلوك ظاهر) حُذفت من القائمة بعد تبسيط الشيفرة أو لأنها تحسين لا حارس: `G11` (ضريبة الشحن لا تكون سالبة فلا تغيّر شرط «الإجمالي > 0» فبُسِّط السطر) و`G42` (مسح الرسوم قبل استرجاعها تكرار لأن الاسترجاع نفسه يمسح الفارغ فحُذف السطر) و`G69` (الخروج المبكر لقالب فارغ يوفّر استعلامين ولا يغيّر النتيجة لأن عرض قالب فارغ لا يعطي نصًا) و`D29` (ترتيب استعلام الإشعارات يعيد فرزه `position()` بعده) و`D41` (مرشح الحالة محروس مرتين، في المتحكّم والخدمة)؛ **وسبعة قوّيتُ لها الاختبار حتى كُشفت** (إعادة التشغيل: 7 من 7 مكشوفة): `G62` و`G65` (حالات أحرف التحكم وتوسيع المتغير داخل القيمة) و`D12` (سبب ناقص لإشعار ملغى فعلًا، فيسبق فحص السبب فحص الحالة) و`D14` (إشعار مُعلَّم ملغى وقيده قائم) و`D16` (رصيد قائم قبل الإشعار ينفَق عليه فور إنشائه) و`D31` (تقلّص الرصيد يُبقي هدف ما تبقّى من التطبيق) و`M04` (تطبيق بهدفين حقيقيين يرفضه قيد «واحد من ثلاثة» لا مفتاح خارجي).

| الرمز | الحارس | الملف المعطَّل | الاختبار | النتيجة |
|---|---|---|---|---|
| G01 | header charges: adjustment and shipping must be numbers | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G02 | header charges: shipping cannot be negative | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G03 | header charges: tax rate of shipping within 0 to 100 | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G04 | header charges: only the known shipping address choices | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G05 | header charges: label no longer than 100 characters | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G06 | header charges: the adjustment cannot take the total below zero | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G07 | header charges: the adjustment counts only while the total before it is above zero | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G08 | header charges: tax inside the shipping is taken out of it | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G09 | header charges: a shipping tax option of none or a rate of zero taxes nothing | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G10 | header charges: text that is not a number is refused and not dropped as empty | `Libraries/HeaderCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G12 | resolved charges: shipping net takes the included tax out | `Libraries/ResolvedCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G13 | resolved charges: a tax inside the amount is not added to the total | `Libraries/ResolvedCharges.php` | `Libraries/HeaderChargesTest.php`, `Libraries/ResolvedChargesTest.php` | **مكشوف** |
| G14 | routing: shipping and adjustment rows apply automatic routing | `Libraries/Accounting/RoutingKey.php` | `Libraries/Accounting/RoutingKeyTest.php`, `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G15 | routing: shipping row accepts revenue accounts only | `Libraries/Accounting/RoutingKey.php` | `Libraries/Accounting/RoutingKeyTest.php` | **مكشوف** |
| G16 | default chart: new charts open the adjustment and shipping accounts | `Libraries/Accounting/DefaultChart.php` | `Libraries/Accounting/DefaultChartTest.php`, `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G17 | builder: shipping posts to its own routed account | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G18 | builder: an adjustment posts to its routed account | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G19 | builder: a negative adjustment debits the adjustment account | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G20 | builder: a credit note mirrors the charges | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G21 | builder: a sale without a customer must settle the charges as well | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G22 | builder: the product sales row stays refused | `Libraries/Accounting/SalePostingBuilder.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G23 | amounts: the rounding of the cent falls on the goods and the shipping stays exact | `Libraries/Accounting/SaleAmounts.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G24 | amounts: the tax of the shipping joins the tax of the invoice | `Libraries/Accounting/SaleAmounts.php` | `Libraries/Accounting/SalePostingBuilderTest.php` | **مكشوف** |
| G25 | loader: posting reads the charges stored on the sale | `Libraries/Accounting/SaleLoader.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G26 | sale model: only an invoice, a quote or a credit note carries charges | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G27 | sale model: a charge whose setting is off is refused | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G28 | sale model: charges that cannot be added are refused with their own words | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G29 | sale model: the tax on shipping follows the rate and the mode of the company | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G30 | sale model: the rate typed must be the rate of the setting | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G31 | sale model: what is stored is the amount that counts | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G32 | sale model: a sale saved without charges has its columns emptied | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G33 | sale model: the audit says who gave the charges | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G34 | sale model: no audit while accounting is off | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G35 | sale model: a draft is issued with its stored charges | `Models/Sale.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G36 | draft: a change of its charges changes its fingerprint | `Libraries/Accounting/DraftInvoice.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G37 | draft: the total of the draft counts the charges | `Libraries/Accounting/DraftInvoice.php` | `Libraries/Accounting/DraftInvoiceTest.php` | **مكشوف** |
| G38 | register: the total carries the charges | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G39 | register: a return counts the charges in the direction of the credit | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G40 | register: an empty cart adds no charges | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G41 | register: a loaded sale gives back its charges | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G43 | register: clearing the sale forgets the charges | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G44 | register: charges that empty are forgotten, not stored | `Libraries/Sale_lib.php` | `Libraries/Sale_libTest.php` | **مكشوف** |
| G45 | controller: charges only on an invoice, a quote or a credit note | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G46 | controller: a charge whose setting is off is ignored | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G47 | controller: tax on shipping only at the rate of the setting | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G48 | controller: charges that cannot be added keep the ones that were | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G49 | controller: the payments are emptied because the amount due changed | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G50 | controller: a change of mode forgets the charges | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G51 | controller: the register offers the form only where charges are allowed | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G52 | controller: the sale is saved with the charges of the register | `Controllers/Sales.php` | `Controllers/SalesControllerTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G53 | settings: the tax on shipping must be a percentage | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G54 | settings: a template that is not valid is refused | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G55 | settings: a stored template that is not valid is not used | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G56 | settings: the setting of the debit note is off until turned on | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G57 | settings: adjustment is off until turned on | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G58 | settings: shipping is off until turned on | `Libraries/Accounting/AccountingSettings.php` | `Libraries/Accounting/AccountingSettingsTest.php` | **مكشوف** |
| G59 | template: at most 200 characters, no markup and no control characters | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G60 | template: only the four placeholders | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G61 | template: values are cleaned of markup | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G62 | template: values are cleaned of control characters | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G63 | template: an invalid template renders nothing | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G64 | template: the result is cut at the length of a line description | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G65 | template: a value typed with a placeholder is not expanded again | `Libraries/Accounting/JournalDescriptionTemplate.php` | `Libraries/Accounting/JournalDescriptionTemplateTest.php` | **مكشوف** |
| G66 | descriptions: the mark of a return without a sale stays | `Libraries/Accounting/JournalDescriptions.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G67 | sale posting: the invoice journal takes the template of sales | `Libraries/Accounting/SalePosting.php` | `Libraries/Accounting/SalePostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| G68 | receiving posting: the journal of a purchase takes the template of purchases | `Libraries/Accounting/ReceivingPosting.php` | `Libraries/Accounting/ReceivingPostingTest.php` (`--filter` باختبارات WP34) | **مكشوف** |
| D01 | debit note: creating needs the permission | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D02 | debit note: taking back needs the permission | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D03 | debit note: the setting and accounting must be on | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D04 | debit note: the customer must exist | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D05 | debit note: the branch must be one the employee works in | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D06 | debit note: taking back needs the branch of the note | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D07 | debit note: the amount must be more than zero with four decimals | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D08 | debit note: the tax is a percentage from 0 to 100 | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D09 | debit note: dated up to today | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D10 | debit note: the date must be a real date | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D11 | debit note: the note is at most 500 characters | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D12 | debit note: taking back needs a reason | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D13 | debit note: the account must be one a note may credit | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D14 | debit note: a note taken back cannot be taken back again | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D15 | debit note: the reversal is dated today when the day is in a closed period | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D16 | debit note: credit is spent on the note once it stands | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D17 | debit note: credit spent on a note taken back is given back | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D18 | debit note: the tax goes to the output tax account | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D19 | debit note: the note is numbered by the sequence of its own type | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D20 | debit note: who raised it is audited | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D21 | debit note: who took it back is audited | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D22 | debit note: the list shows the notes of the branches of the employee only | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D23 | debit note: the list filters by status | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D24 | debit note: the revenue is what is left of the total after the tax | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D25 | debit note: its own key range past the openings | `Libraries/Accounting/SalesDebitNotes.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D26 | debit note: the journal is the one the journal screen cannot reverse | `Libraries/Accounting/JournalService.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D27 | ledger: the debit notes that stand are open items of the customer | `Libraries/Accounting/CustomerLedger.php` | `Libraries/Accounting/SalesDebitNotesTest.php`, `Libraries/Accounting/CustomerBalanceReportTest.php` | **مكشوف** |
| D28 | ledger: an application on a debit note is read as such | `Libraries/Accounting/CustomerLedger.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D30 | settlement: credit spent on a note is written as an application of the note | `Libraries/Accounting/CreditSettlement.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D31 | settlement: releasing part of an application keeps its note | `Libraries/Accounting/CreditSettlement.php` | `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D32 | balance report: a debit note is counted as sales | `Libraries/Accounting/CustomerBalanceReport.php` | `Libraries/Accounting/CustomerBalanceReportTest.php` | **مكشوف** |
| D33 | numbering: five digits for the sales debit note | `Libraries/Numbering/DocumentType.php` | `Libraries/Numbering/DocumentTypeTest.php`, `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D34 | numbering: the sales debit note keeps its own table | `Libraries/Numbering/DocumentType.php` | `Libraries/Numbering/DocumentTypeTest.php`, `Libraries/Accounting/SalesDebitNotesTest.php` | **مكشوف** |
| D35 | screen: the form is for those who may manage debit notes | `Controllers/AccountingDebitNotes.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| D36 | screen: the customers search is for those who may manage debit notes | `Controllers/AccountingDebitNotes.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| D37 | screen: the reverse form is for the branches of the employee | `Controllers/AccountingDebitNotes.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| D38 | screen: taking back is offered to those who may manage notes | `Views/accounting_debit_notes/manage.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| D39 | screen: a new note is offered only while the setting is on | `Views/accounting_debit_notes/manage.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| D40 | screen: the list shows the notes of the branches of the employee only | `Controllers/AccountingDebitNotes.php` | `Controllers/AccountingDebitNotesControllerTest.php` | **مكشوف** |
| M01 | migration: the adjustment row moves only when nobody changed it | `Database/Migrations/20271900000000_InvoiceHeaderCharges.php` | `Database/InvoiceHeaderChargesMigrationTest.php` | **مكشوف** |
| M02 | migration: a free code is taken when the first is taken | `Database/Migrations/20271900000000_InvoiceHeaderCharges.php` | `Database/InvoiceHeaderChargesMigrationTest.php` | **مكشوف** |
| M03 | migration: the shipping row that has an account is kept | `Database/Migrations/20271900000000_InvoiceHeaderCharges.php` | `Database/InvoiceHeaderChargesMigrationTest.php` | **مكشوف** |
| M04 | migration: an application points at exactly one of three targets | `Database/Migrations/20271900000001_SalesDebitNotes.php` | `Database/SalesDebitNotesMigrationTest.php` | **مكشوف** |
| M05 | migration: the permission is registered and never granted | `Database/Migrations/20271900000001_SalesDebitNotes.php` | `Database/SalesDebitNotesMigrationTest.php` | **مكشوف** |

## 4. ما افترضتُه

1. **قيد التسوية والشحن لم يُرصد في دفترة** (OBS-A رصد الحقول وحساب الإجمالي فقط؛ FRM-R04/R06/R07): التسوية الموجبة دائنة والسالبة مدينة في حساب إيراد واحد من صف «التسوية»، والشحن دائن إيراد مستقل، وضريبة الشحن مع ضريبة المخرجات، **كلها افتراض**. الفرق المرصود: حقل ضريبة الشحن لا يظهر في نموذج البيع بحساب دفترة (غالبًا يحجبه إعداد لم يُقرأ)؛ **نتبنى نسبة واحدة في الإعدادات** تُطبَّق بوضع الضريبة، لا اختيار ضريبة لكل مستند.
2. **التسوية السالبة لا تُنزل الإجمالي دون صفر:** قيد منا لم يُرصد في دفترة التي تضيفها ما دام الإجمالي الجاري موجبًا ولو أنزلته.
3. **المرتجع (إشعار دائن وفاتورة مرتجعة) له رسومه التي تُكتب فيه** ولا يرث رسوم أصله ولا يُحدّ بها (لا حدّ مالي لمرتجع بل حدّ كميات: `ReturnLimit`). النموذجان متماثلان في الرسوم كما رُصد التماثل بين الفاتورة والإشعار الدائن (SAL-08/SAL-09)؛ **الفاتورة المرتجعة لا رصد لها** فتتبع الإشعار. التسوية والشحن **مرآة** القيد.
4. **أنواع المستند:** الفاتورة وعرض السعر والمرتجع تحمل الرسوم؛ **نقطة البيع وأمر العمل لا** (رُصدت في الفاتورة والعرض والإشعار الدائن). عرض السعر يحفظها ولا يرحّل.
5. **التقارير التشغيلية** (`Summary_*` وإجماليات قائمة المبيعات) **لا تضم الرسوم** (الأسطر وحدها)؛ **تقارير الدفاتر** (الميزان وكشف العميل والأعمار وأرصدة العملاء وضريبة المخرجات من الدفاتر) تضمها لأنها تقرأ القيود. لم أعدّل SQL التقارير التشغيلية لأن تعريف «صافي المبيعات» فيها قرار (القسم 6).
6. **الإشعار المدين (كل ما يخصه افتراض: رُصد إعداده فقط):** القيد مدين العميل / دائن الإيراد المُوجَّه أو حساب يُختار (إيراد أو مصروف) + ضريبة مخرجات تُضاف فوق المبلغ؛ بلا خزينة؛ بلا تعديل (يُلغى ويُنشأ غيره)؛ بند مفتوح بتاريخه؛ **يسبق الفاتورة عند تعادل التاريخ**؛ يُحسب «مبيعات» في تقرير الأرصدة؛ **صلاحية واحدة للإدارة** بدل الخيارات الست (إضافة وتعديل وحذف وعرض بنطاق الكل والخاص: **نطاق «الخاص به» غير مبني**، يقابله نطاق الفروع)، والعرض لمن يملك الوحدة؛ الإعداد `accounting_debit_note` (دفترة: `enable_debit_note`) مطفأ افتراضيًا؛ ترقيم خماسي (دفترة: نوع 34 بخمس منازل، التالي 00001): **بلا تسلسل مفعّل يُعرض معرّف الصف بخمس منازل**.
7. **قالب الوصف:** يطبَّق على **القيد الرئيسي** وحده (الفاتورة والشراء) لا التكلفة ولا الدفعات؛ متغيراته الأربعة (دفترة: إعداد للمبيعات وآخر للمشتريات، **لم تُرصد متغيراته ولا القيود التي يمسّها**)؛ نص «النوع» بلغة المستخدم لحظة الترحيل.
8. **مفتاح الإعدادات** أسماؤها عندنا `accounting_*` لا أسماء دفترة (`enable_sales_adjustment` ...) ومحلّها شاشة إعدادات المحاسبة، وتعمل بلا تفعيل المحاسبة.

## 5. ما بقي

- **تقارير المبيعات التشغيلية** لا تضم الرسوم (القسم 4، البند 5).
- **نطاق «الخاص بي»** للإشعار المدين والصلاحيات الست التفصيلية.
- **تعديل الإشعار المدين** (اليوم إلغاء وإنشاء) و**طباعته** (لا مطبوع ولا PDF له) و**ربطه بفاتورة أصل** (دفترة تفتح نموذجه من فاتورة) و**تصنيفه في تقرير مبيعات العملاء** (مرشح النوع 16).
- **قالب الوصف للقيود الأخرى** (التكلفة والدفعات والمصروفات والإيصالات) ومتغيرات إضافية.
- **طلب ضريبة الشحن بضريبة محددة** لكل مستند (اليوم نسبة الإعداد).
- **رسوم الشحن والتسوية في المشتريات** (دفترة: حقل شحن بضريبة في الشراء، والتسوية مطفأة): لم تُبنَ (`purchases.adjustment` ما زال غير مطبَّق).
- **حدّ رسوم الإشعار الدائن برسوم أصله** وصلاحية مستقلة للرسوم التي تُعطي مالًا (اليوم `sales_invoice_discount`): قراران للمالك (القسم 6).
- **قوائم المبيعات والتقارير التشغيلية** ما زالت لا تضم الرسوم (الافتراض 5 في القسم 4)، وإن صار مستحق نموذج تعديل البيع وإجمالي السجل يضمانها.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرارات المالك:** (1) اعتماد حسابَي `4204` و`4208` وأسمائهما وأنهما إيراد، ونقل صف التسوية القائم عن الخصم المسموح به. (2) ضريبة الشحن بنسبة واحدة في الإعدادات أم ضريبة تُختار في المستند. (3) هل يرث الإشعار الدائن رسوم أصله ويُحدّ بها. (4) صلاحيات الإشعار المدين (نطاق الكل/الخاص) وهل يُعدَّل. (5) هل يشمل قالب الوصف التكلفة والدفعات. (6) هل تضم تقارير المبيعات التشغيلية الشحن والتسوية في «المبيعات». (7) **هل تُمنح الرسوم التي تُعطي مالًا (تسوية سالبة على فاتورة، وشحن أو تسوية موجبة على إشعار دائن) صلاحية مستقلة بدل `sales_invoice_discount`، وهل يُحدّ إشعار الدائن بما حمله أصله من رسوم.** (8) أساس الإشعار المدين في تقرير الضريبة: المبلغ المكتوب (اليوم) أم الإيراد المقرَّب.

**ملاحظات دفترة المطلوبة:** `OBS-D-WP34-JOURNALS`: قيد فاتورة بتسوية موجبة وسالبة وشحن بضريبة (الحسابان والجانبان والتقريب)، وقيد إشعار دائن بهما. `OO-FRM-04`: تفعيل «اشعار مدين» وفتح نموذجه من فاتورة وحفظ إشعار وقراءة قيده ورصيد العميل وأعماره ثم الإعادة إلى المطفأ. `OBS-D-TEMPLATE`: قالب الوصف في المبيعات والمشتريات: المتغيرات وأي القيود يمسّ. شرط ضريبة الشحن في نموذج البيع.

## 7. قبل النشر

- ترحيلان إضافيان: `20271900000000_InvoiceHeaderCharges` (سبعة أعمدة تقبل الفراغ في `sales`، وفتح حسابين وصفّين في الدليل القائم، **وصف التسوية يُنقل من الخصم المسموح به إن لم يغيّره أحد**) و`20271900000001_SalesDebitNotes` (جدول + عمود + **استبدال قاعدة الهدف** في `customer_credit_applications` وهي تعيد بناء الجدول). صنّفتهما في `MigrationChecks`: خذ نسخة احتياطية وشغّلهما في ساعة هادئة (`php spark accounting:readiness`).
- **لا يتغير شيء حتى تُفعَّل الإعدادات**: التسوية والشحن والإشعار المدين مطفأة، والقالب فارغ.
- الصلاحية `acc_debit_notes_manage` والوحدة `accounting_debit_notes` مسجّلتان **بلا منح**.
- افحص شاشة التوجيه بعد الترحيل: صفّا «تسوية المبيعات» و«شحن المبيعات» بحسابيهما.
- **مرحلة الإصلاح بلا ترحيل جديد.** تتغير بعد النشر أرقام **تقرير الضريبة** للفواتير والإشعارات الدائنة التي لها شحن خاضع للضريبة (تدخل أساس الجزء ونسبته وتزول رسالة «مستندات غير مطابقة»)، وللإشعارات المدينة (تدخل جزء فواتير المبيعات). و**الإيصالان الافتراضي والمختصر** يطبعان صفوف الرسوم تحت كتلة الضرائب. والصلاحية `sales_invoice_discount` صارت تُطلب لتسوية سالبة على فاتورة وللشحن والتسوية الموجبة على إشعار دائن: راجع من يملكها قبل تفعيل الرسوم. مفتاح اللغة `Sales.shipping_options_second_address` يحلّ محلّ اسم أطول (لم يُنشر بعد) ومفتاح `Accounting_debit_notes.debit_notes_manage` جديد في كل اللغات.
- مفاتيح لغة جديدة في `Sales` و`Accounting` و`Accounting_routing` و`Accounting_settings` و`Accounting_receivables` و`Module` و`Numbering` وملف جديد `Accounting_debit_notes` في كل اللغات (الإنجليزية في en وen-GB، **عربية فعلية** في ar-EG وar-LB، وفارغة في الباقي فتعود إلى الإنجليزية).

## 8. مرحلة الإصلاح بعد المراجعة المستقلة

راجع المراجِعان المستقلان العمل فعادا بـ**20 ادعاءً**. تتبّعتُ كل ادعاء في الشيفرة بنفسي ولم آخذه على علاته: **الادعاءات العشرون كلها صحيحة** (واحد منها، الرابع، أصلحتُ نصفه وتركتُ نصفه قرارًا للمالك لأنه تصميم لا خطأ). لم أرفض أيًّا منها. لكل إصلاح اختبار يفشل بدونه (جرّبتُ ذلك بإرجاع الإصلاح مؤقتًا في الحالات الأربع الأخطر: الصفر مع الشحن، وترتيب «الشحن إلى»، والإيصالات، وتقرير الضريبة) وبعضها له حارس جديد في `wp34-guards.json` (القسم 8.1).

| # | الادعاء | الحكم | ما أصلحتُه | الاختبار |
|---|---|---|---|---|
| 1 | فاتورة بضاعتها صفر وفيها شحن تفشل (`LineWithoutAmount`) | **صحيح** | إيراد البضاعة هو الرصيد الموازن فيصير صفرًا مع شحن، وقد يصير سالبًا بجزء من الهللة. `SaleAmounts::figures` تحمل الشحن تقريب الهللة حين صافي البضاعة صفر، و`SalePostingBuilder` لا يُنشئ سطر إيراد بلا مبلغ (ويبقى سطر الخصم المسموح به حين يُوجَّه) | `SalePostingBuilderTest` (8) و`SalePostingTest` (3: فاتورة وإشعار دائن وضريبة شحن تقرَّب هبوطًا) |
| 2 | `get_total()` و`get_amount_due()` لا يضمّان الرسوم فتُحجَم بطاقة الهدايا والنقاط والتقريب النقدي على إجمالٍ خاطئ | **صحيح** | `Sale_lib::get_total()` تضيف الرسوم باتجاه المستند قبل التقريب النقدي، بدالة `chargesSign` المشتركة مع `get_totals()` | `Sale_libTest` (5) |
| 3 | `Sale::get_info` يحسب `amount_due` بلا الرسوم فتُقترح دفعة ناقصة ويُسجَّل `cash_refund` وهمي | **صحيح** (مع المحاسبة المعطلة أيضًا) | `Sale::withHeaderCharges` تضيف التسوية والشحن وضريبة الشحن «فوق المبلغ» باتجاه المستند وتقرّب المجموع **مرة واحدة** كالقيد؛ **بيع بلا رسوم يعطي الإجمالي القديم بايتًا بايتًا** | `SaleTest` (5 + 2 لـ`getHeaderCharges`) |
| 4 | لا صلاحية للرسوم: التسوية السالبة تتجاوز `sales_invoice_discount`، والإشعار الدائن يقبل رسومًا موجبة بلا حد | **صحيح، وأصلحتُ النصف الأول والصلاحية للثاني** | `HeaderCharges::givesAway()`: **تسوية سالبة على فاتورة أو عرض سعر (خصم على المستند) وشحن أو تسوية موجبة على إشعار دائن (تزيد ما يُرد للعميل)** تحتاج `sales_invoice_discount`، وتُفحص في أربعة مواضع: عند كتابتها في السجل وعند الإتمام وعند التعليق وعند إصدار المسودة (`Sales::mayGiveHeaderCharges`). **لم أحدّ رسوم الإشعار الدائن برسوم أصله** (قرار المالك، القسم 6) | `SalesControllerTest` (14) و`HeaderChargesTest` (3) |
| 5 | ضريبة الشحن في الفاتورة والإشعار الدائن لا تُطابَق في تقرير الضريبة فتسقط القاعدة والنسب ويظهر تحذير «مستندات غير مطابقة» | **صحيح** | `TaxDocuments::sales` تضم أساس الشحن (دونه الضريبة إن كانت داخله) وضريبته بنسبتها في المستند، فيطابق التقرير قيده | `TaxDocumentsTest` (5) و`TaxReportTest` (3) |
| 6 | ضريبة المخرجات للإشعار المدين تظهر «قيدًا آخر» لا مستند مبيعات | **صحيح** | `TaxDocuments::debitNotes` و`TaxReport` يوجّهان قيد الإشعار المدين إلى جزء **فواتير المبيعات** بأساسه (مبلغه) ونسبته، ويُعكس بعكسه، ويُحسب «محذوفًا» إن زال صفه | `TaxReportTest` (4) |
| 7 | مبلغ إشعار مدين دون نصف هللة يرمي استثناء قاعدة بيانات (500) | **صحيح** | `figures()` ترفض إجمالًا لا يبلغ أصغر وحدة نقدية أو يفوق ما يتسع له `DECIMAL(15,4)` برسالة `DebitNoteAmountInvalid` واضحة (عُدِّل نصها في الإنجليزية والعربية: «مع ضريبته أصغر وحدة نقدية على الأقل وأقل من مئة مليار») | `SalesDebitNotesTest` (5) و`AccountingDebitNotesControllerTest` (1) |
| 8 | كتلة «الشحن إلى» فارغة دائمًا بعد الإتمام لأن `shipTo()` تُستدعى قبل `header_charges` | **صحيح** | نُقل الاستدعاء إلى ما بعد ضبط `header_charges` في `postComplete` (الموضع الآخر كان سليمًا) | `SalesControllerTest` (5) |
| 9 | صلاحية `acc_debit_notes_manage` بلا تسمية فتظهر «Debit Notes Manage» في كل اللغات | **صحيح** | مفتاح `Accounting_debit_notes.debit_notes_manage` في 46 لغة (إنجليزية وعربية فعلية والباقي فارغ) | `LanguageFilesTest` (1) |
| 10 | صفحة المسودة تعرض صف ضريبة الشحن «الداخلة» كصف إضافي لا يدخل الإجمالي | **صحيح** | `DraftInvoice::figures` تُعيد `shippingTaxIncluded` والصفحة تمرّره إلى `headerChargeRows` | `DraftInvoiceTest` (2) و`SalesControllerTest` (2) |
| 11 | في الإيصالين الافتراضي والمختصر صفوف الرسوم فوق «المجموع الفرعي» الذي لا يضمها | **صحيح** | نُقلت الكتلة تحت كتلة الضرائب وقبل الإجمالي في `receipt_default.php` و`receipt_short.php` كبقية الإيصالات والمستند | `SalesControllerTest` (4 مع صفوف المزوّد) |
| 12 | لا اختبار لحفظ الإعدادات الستة وعرض حقولها | **صحيح** | اختبارات حفظ وعرض لكل مفتاح تشغيل وللنسبة وللقالبين وللرفض بلا صلاحية | `AccountingSetupControllerTest` (7 اختبارات، منها مزوّدان بثلاثة صفوف) |
| 13 | موضع واحد من مواضع `save_value` الخمسة مُختبر عبر المتحكم | **صحيح** | اختبارات لعرض السعر والإشعار الدائن والفاتورة المرتجعة والفاتورة المعلّقة والإشعار الدائن المعلّق وأمر العمل (يُرفض ولا تُسقط الرسوم) | `SalesControllerTest` (6) |
| 14 | قفل العميل في الإشعار المدين غير مُختبر | **صحيح** | اختبارات تلتقط `GET_LOCK` عند الإنشاء والعكس وأن الرفض المبكر لا يأخذ قفلًا | `SalesDebitNotesTest` (3) |
| 15 | اختبار «صف التوجيه الملغى» لا يبلغ الفرع الذي يسميه | **صحيح** | استُبدل بثلاثة اختبارات: `sales.revenue` ملغى يعطي `RoutingRowCancelled` باسم المفتاح، وحساب ضريبة مفقود يعطي `RoutingAccountUnusable`، وإشعار بحساب مختار يُرحَّل رغم إلغاء الصف | `SalesDebitNotesTest` |
| 16 | اختبار وصف العكس يقرأ القيد الأصلي لا العكس | **صحيح** | أُعيدت كتابته: يقرأ القيد العكسي (بسببه لا بالقالب) ويتأكد أن الأصلي يحتفظ بنصّه | `SalePostingTest` (1 بدل 1) |
| 17 | لا اختبارات للدالة المساعدة ولـ`JournalDescriptions` ولا لقراءة `getHeaderCharges` | **صحيح** | ملف جديد `JournalDescriptionsTest` (15، يشمل مرتجع المشتريات واحتياط اسم المورد) و6 لـ`headerChargeRows` و2 لـ`getHeaderCharges` | الملفات المذكورة |
| 18 | قاعدة «العميل مطلوب» في نموذج الإشعار المدين ميتة والإكمال التلقائي بلا `focus` | **صحيح** | القاعدة على حقل الاسم المرئي، ومعالجا `focus` و`change` كنموذج الشيكات | `AccountingDebitNotesControllerTest` (1) |
| 19 | مفتاح بطول 40 حرفًا أعاد محاذاة 46 ملف `Sales.php` (16 ألف سطر مسّتها المحاذاة) فتتعارض الدمجات | **صحيح** | سُمِّي المفتاح `shipping_options_second_address` (31 حرفًا، ≤ 33 أطول مفتاح قائم) وأُعيد توليد الملفات من نسخة `60dd925`: **الفرق أسطر مضافة فقط (18 لكل ملف، 828 إضافة و0 حذف)** | `git diff 60dd925 --numstat` |
| 20 | اختبار تنبيه النطاق لا يتحقق من التنبيه ولا من ترقيم الصفحات | **صحيح** | أُضيف التحقق من التنبيه وغيابه لمن يرى كل الفروع، واختبار ترقيم بـ26 إشعارًا (التالي والسابق) | `AccountingDebitNotesControllerTest` (2) |

### 8.1 حرّاس مرحلة الإصلاح (وقد أُضيفت إلى `wp34-guards.json`)

أُضيف **73 تعطيلًا** (`H01` إلى `H72`، ويتكرر `H26` للإيصالين) وأُصلح نص ثلاثة قديمة تحرّكت شيفرتها (`G23` و`G37` و`G39`). الجدول يلخّص الحارس والاختبار وكلها **مكشوفة** (نتيجة التشغيل الأخير في الملحق أدناه).

NEW_GUARDS_TABLE

### 8.2 ما افترضتُه في هذه المرحلة

1. **حامل التقريب حين لا بضاعة:** إيراد البضاعة يحمل تقريب الهللة (E14) ويبقى الشحن والتسوية كما كُتبا؛ ومتى كان صافي البضاعة صفرًا **حمل الشحن التقريب** وصار الإيراد صفرًا بلا سطر. فرق أقل من نصف هللة بين الشحن المكتوب وسطر قيده (لا يظهر إلا مع ضريبة شحن بأربع منازل أو مبلغ بأكثر من منزلتين). افتراض لم يُرصد في دفترة.
2. **صلاحية الرسوم التي تُعطي مالًا هي `sales_invoice_discount`** (التسوية السالبة خصم على المستند، والشحن والتسوية الموجبة على إشعار دائن تزيد ما يُرد): اختيار محافظ لا رصد له في دفترة؛ لا صلاحية جديدة (القسم 6).
3. **ضريبة الشحن في تقرير الضريبة:** أساسها الشحن دون ضريبته ونسبتها نسبة الإعداد لحظة الحفظ، وتجتمع مع صفّ نسبة الأسطر إن تساوت النسبتان.
4. **أساس الإشعار المدين في تقرير الضريبة:** المبلغ المكتوب قبل الضريبة (لا الإيراد المقرَّب)؛ يصير الإشعار «مستند مبيعات» في جزء الفواتير لأنه يزيد الضريبة المستحقة كالفاتورة. افتراض.
5. **حدّ الإشعار المدين:** إجمالي بعد التقريب لا يقل عن 0.01 ولا يفوق 99,999,999,999.9999 (حدّ `DECIMAL(15,4)`).
6. **قوائم المبيعات والتقارير التشغيلية ما زالت لا تضم الرسوم** (الافتراض 5 في القسم 4 باقٍ)؛ لكن **مبلغ المستحق في نموذج تعديل البيع وإجمالي السجل صار يضمّها**.

## 9. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار بعد E92):

**decisions.md** (ثلاثة صفوف، أرقامها E-التالية):

> | E-؟؟ | **رسوم رأس الفاتورة: التسوية والشحن** (WP34: `SAL-08` و`SAL-09`، 2026-11-01، [التقرير](daftra-parity/wp34-report.md)): **قرار تنفيذي أُخذ تحت E47 على ما رُصد من الحقول وقواعد الإجمالي (FRM-R04 وFRM-R06 وFRM-R07) وبقيد لم يُرصد، وللمالك حق نقضه.** تحمل الفاتورة وعرض السعر والإشعار الدائن والفاتورة المرتجعة **تسوية** (عنوان + مبلغ بإشارة، تُضاف ما دام الإجمالي الجاري > 0) و**شحنًا** (مبلغ + ضريبة بوضع الضريبة: فوق المبلغ أو داخله، بأربع منازل بلا تقريب الهللة E14 + خيار عنوان)، **بأعمدة تقبل الفراغ في `sales`** وإعدادَين مطفأين افتراضيًا بتدقيق (`accounting_sales_adjustment` و`accounting_sales_shipping`) ونسبة ضريبة شحن واحدة في الإعدادات. **تُرحَّل عبر صفّي توجيه**: `sales.adjustment` (صار يُطبَّق، حساب `4204`، موجبة دائنة وسالبة مدينة) و`sales.shipping` (جديد، `4208`)، بذرة الدليل الجديد وترحيل يفتحهما في القائم (ويُنقل صف التسوية عن الخصم المسموح به إن لم يغيّره أحد). **الإيراد هو الرصيد الموازن فيقع التقريب عليه ويبقى الشحن والتسوية كما كُتبا**؛ المرتجع مرآة؛ **الفاتورة الصادرة لا تتغير** (E21) وتصحيحها بإشعار دائن؛ **تسوية تُنزل الإجمالي دون صفر مرفوضة** (قيد منا). لا تُحدّ رسوم المرتجع بأصله ولا يرثها. **التقارير التشغيلية لا تضم الرسوم** (تقارير الدفاتر تضمها). يطبّق E14 وE15 وE21 ولا يغيّر D1 (المحاسبة معطَّلة: تُحفظ الرسوم بلا قيد) |
>
> | E-؟؟ | **الإشعار المدين للمبيعات** (WP34: `SAL-20`، 2026-11-01): **قرار تنفيذي أُخذ تحت E47 على إعداد وصلاحيات ونوع ترقيم رُصدت وبنموذج وقيد لم يُرصدا (افتراض)، وللمالك حق نقضه.** مستند يزيد ما يدين به العميل **بلا فاتورة**: مدين حساب العميل بالإجمالي، دائن `sales.revenue` أو حساب إيراد/مصروف يُختار بالباقي، ودائن `tax.output` بالضريبة (تُضاف فوق المبلغ، **الإجمالي مقرَّب والضريبة كما حُسبت والإيراد الباقي كالفاتورة E14**)، بلا خزينة، بجدول `sales_debit_notes` ونوع ترقيم `sales_debit_note` **بخمس منازل**، وفرع وقفل فترة بالترحيل، **لا يُعدَّل ولا يُحذف: يُعكس قيده بسبب** (بتاريخه أو اليوم إن أُقفلت فترته) ويحرَّر ما أُنفق عليه (E15). **بند مفتوح بتاريخه** في ترتيب E57 (**يسبق الفاتورة عند تعادل اليوم**: افتراض، ولا تُعاد كتابة تطبيقات قائمة) وفي الأعمار وتقرير أرصدة العملاء (تحت «المبيعات»)، و`customer_credit_applications` يقبل هدفًا ثالثًا (`debit_note_id`، القاعدة «هدف واحد من ثلاثة»)، والربط الثلاثي محفوظ. **خلف الإعداد `accounting_debit_note` (مطفأ) وصلاحية جديدة `acc_debit_notes_manage` بلا منح** ووحدة `accounting_debit_notes`؛ لا نطاق «الخاص به» (نطاق الفروع وحده) ولا تعديل ولا مطبوع. القيد محجوز لمستنده (لا يُعكس من شاشة القيود). يطبّق E14 وE15 وE57 |
>
> | E-؟؟ | **قالب وصف القيد الآلي** (WP34: `SAL-34`، 2026-11-01): **قرار تنفيذي أُخذ تحت E47، وللمالك حق نقضه؛ متغيرات دفترة والقيود التي يمسّها لم تُرصد.** إعدادان (`accounting_journal_description_sales` و`..._purchases`) بأربعة متغيرات `{number}` و`{party}` و`{type}` و`{date}`، **فارغان افتراضيًا فتبقى الأوصاف كما هي بايتًا بايتًا** (ذهبيات T-06 إلى T-19 لم تتغير)، **يُطبَّقان على القيد الرئيسي للمستند عند ترحيله وحده** (الفاتورة والمشتريات، لا التكلفة ولا الدفعات ولا التحويل بين الفروع) **ولا يمسّان قيدًا قائمًا**؛ يُتحقق عند الحفظ (≤ 200 حرف، المتغيرات الأربعة وحدها، بلا وسوم ولا أحرف تحكم) وتُنظَّف القيم عند الاستعمال ولا يُوسَّع متغير داخل قيمة. يطبّق E15 |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `SAL-08`: «ما عندنا»: «تسوية بعنوان ومبلغ بإشارة على الفاتورة وعرض السعر والإشعار الدائن والفاتورة المرتجعة، تُضاف ما دام الإجمالي > 0، تُرحَّل عبر صف `sales.adjustment` (حساب `4204`)، خلف إعداد مطفأ (WP34). القيد افتراض». الحالة `partial` («لا يُقارَن قيد دفترة»).
- الصف `SAL-09`: «شحن بمبلغ وضريبة (فوق المبلغ أو داخله) وخيار عنوان، يُرحَّل عبر `sales.shipping` (`4208`) وخيار العنوان يتحكم بكتلة «الشحن إلى» (WP34). نسبة ضريبة واحدة في الإعدادات». الحالة `partial`.
- الصف `SAL-20`: «إشعار مدين للمبيعات (WP34): مستند بقيد مدين العميل/دائن الإيراد + الضريبة، ترقيم 5 منازل، بند مفتوح في الأعمار وE57 وتقرير الأرصدة، خلف الإعداد والصلاحية الجديدة. النموذج والقيد افتراض». الحالة `partial`.
- الصف `SAL-34`: «قالب بأربعة متغيرات للمبيعات وللمشتريات على القيد الرئيسي (WP34)». الحالة `partial` (متغيرات دفترة لم تُرصد).
- فقرة تحديث بعد الجدول: «**تحديث بعد WP34 (2026-11-01):** نُفِّذت `SAL-08` و`SAL-09` و`SAL-20` و`SAL-34` جزئيًا ([تقرير WP34](wp34-report.md))؛ تُراجَع الأعداد عند الدمج».

**تقرير الفجوات** (`gap-report.md`): بنود جديدة: `G-WP34-REPORTS` (تقارير المبيعات التشغيلية لا تضم الرسوم)، `G-WP34-DEBIT-NOTE-SCOPE` (نطاق «الخاص به» والصلاحيات الست وتعديل الإشعار المدين وطباعته)، `G-WP34-SHIPPING-TAX` (ضريبة محددة لكل مستند)، `G-WP34-TEMPLATE-SCOPE` (قالب الوصف للقيود الأخرى)، `G-WP34-PURCHASE-CHARGES` (شحن المشتريات وتسويتها).

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **رسوم الرأس والإشعار المدين وقالب الوصف (WP34):** ترحيلان إضافيان `20271900000000_InvoiceHeaderCharges` (سبعة أعمدة تقبل الفراغ في `sales` وفتح حسابَي `4204`/`4208` وصفّي التوجيه `sales.adjustment`/`sales.shipping` في الدليل القائم، وصف التسوية يُنقل من الخصم المسموح به إن لم يغيّره أحد) و`20271900000001_SalesDebitNotes` (جدول وعمود `customer_credit_applications.debit_note_id` واستبدال قاعدة الهدف الواحد؛ **يعيد بناء الجدول فخذ نسخة احتياطية** وشغّله في ساعة هادئة). كل شيء مطفأ افتراضيًا: `accounting_sales_adjustment` و`accounting_sales_shipping` و`accounting_debit_note` و`accounting_shipping_tax_percent` (0) والقالبان فارغان، فلا تتغير فاتورة ولا قيد ولا وصف قبل التفعيل. الوحدة `accounting_debit_notes` والصلاحية `acc_debit_notes_manage` مسجّلتان بلا منح. راجع شاشة التوجيه بعد الترحيل. **ملاحظة:** المحاسبة المعطَّلة تحفظ الرسوم بلا قيود.

**backlog.md:** صف WP34 «نُفِّذ (2026-11-01)» والبنود `SAL-08` و`SAL-09` و`SAL-20` و`SAL-34` `partial`، وبنود جديدة من الفجوات أعلاه، وتُضاف الملاحظات `OBS-D-WP34-JOURNALS` و`OO-FRM-04` (قائمة) و`OBS-D-TEMPLATE`.
