# تقرير WP13: تاريخ الفاتورة وتاريخ الاستحقاق وشروط الدفع والأعمار وأعمدة أرصدة العملاء والموردين (G-27 وG-31 وG-53 وCUS-08 وCUS-09 وG-14)

> الحالة (2026-10-30): **نُفِّذ على الفرع `wp/WP13`** بتفويض المالك التنفيذي الشامل (E47) ومن غير دمج في `master` ولا نشر: للفاتورة وللشراء **تاريخ مكتوب مستقل عن لحظة الحفظ** تأخذه قيودهما ومخزونهما، و**شروط دفع بالأيام** وتاريخ استحقاق = التاريخ + الأيام، وشرائح الأعمار **ستٌّ** (اليوم و1-30 و31-60 و61-90 و91-120 وما فوق 120) تُحسب **من تاريخ المستند** مع عمود **متأخر** (مفتوح واستحقاقه قبل اليوم تمامًا)، وشاشتان جديدتان لكل من العملاء والموردين: **أعمار الأستاذ** (بيوم التقرير وعدد أيام الشريحة) و**تقرير الأرصدة** (الرصيد قبل والمبيعات أو المشتريات والمرتجع والصافي والمدفوعات والتسويات والرصيد)، وكشف الطرف يُقرأ ليوم ماضٍ. **الأرصدة القائمة لا تتحرك:** الأعمدة قابلة للفراغ وكل مستند قائم يبقى تاريخه يوم حفظه واستحقاقه ذلك اليوم نفسه، ولا قيد مرحَّل يُمَسّ. **الربط الثلاثي** (رصيد الطرف = الأعمار = الحساب) يصحّ لكل شريحة ولكل يوم، ويصحّ مجموع أعمدة تقرير الأرصدة مع رصيد الحساب. المحاسبة المعطَّلة لا تحفظ تاريخًا ولا شروطًا ولا تعرض الشاشات (D1).

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الترحيلان** (`20270600000000_InvoiceDatesAndPaymentTerms` و`20270600000001_InvoiceDatePermissions`) | الأول يضيف إلى `sales` و`receivings` ثلاثة أعمدة **تقبل الفراغ بلا قيمة افتراضية**: `invoice_date` (DATE) و`payment_terms_days` (SMALLINT UNSIGNED) و`due_date` (DATE)، **ولا يقرأ ولا يكتب ولا يعيد حساب أي صف**، ويُعاد تشغيله بأمان (يتحقق من وجود كل عمود)، و`down()` **يرفض** إن وُجد مستند فيه تاريخ مكتوب (لأن قيده مؤرَّخ به) فيُستعاد من النسخة الاحتياطية. والثاني يسجّل صلاحيتين `sales_invoice_date` (وحدة المبيعات) و`receivings_invoice_date` (وحدة المشتريات) **ولا يمنحهما لأحد** ويُعاد تشغيله بأمان |
| **`InvoiceTiming`** (قواعد التاريخ والشروط والاستحقاق) | التاريخ المكتوب يوم حقيقي (`Y-m-d` دقيق، فـ`2026-02-30` مرفوض) **لا يتجاوز اليوم ولا يسبق بدء الدفاتر**؛ الشروط عدد صحيح من الأيام (أرقام فقط، خمس خانات على الأكثر، حتى 3650) و**صفر أو فراغ = بلا شروط**؛ الاستحقاق = تاريخ الفاتورة + الأيام، **وبلا شروط = تاريخ الفاتورة** (استنتاج D1)؛ و**المتأخر = متبقٍّ واستحقاقه قبل اليوم تمامًا** (استحقاق اليوم ليس متأخرًا: مرصود في D1) |
| **الفاتورة (المبيعات)** | السجل يأخذ **التاريخ** (لمن يحمل `sales_invoice_date` وحده) و**شروط الدفع** (لكل من يبيع) ويُظهر تاريخ الاستحقاق الناتج؛ **قيود الفاتورة والتكلفة والمخزون تأخذ يوم التاريخ المكتوب** (وإلا يوم الحفظ)، **والمسودة بتاريخ مكتوب تُصدَر بذلك التاريخ** (T-07)، **والمسودة تحفظ الشروط ويُحسب استحقاقها عند الإصدار**. التاريخ في **فترة مقفلة** يُرفض ببيان السبب ولا يُحفظ شيء (يمنعه الترحيل نفسه)، وكذلك **يوم بعد اليوم أو قبل بدء الدفاتر** أو من موظف فقد الصلاحية بعد كتابته. **غير الفاتورة** (بيع نقطة البيع والمرتجع وعرض السعر) **لا تحمل تاريخًا مكتوبًا ولا شروطًا**. الكتابة في سجل التدقيق: `invoice_date_typed` **تُكتب عند كتابة اليوم**: عند حفظ المسودة لا عند إصدارها، **باسم من كتبه** لا باسم من يُصدرها، فلا تتكرر إن حُفظت المسودة بيومها نفسه ولا إن أُصدرت به، ويكتبها المُصدِر وحده إن غيّر اليوم عند الإصدار (من كتبه ومتى حُفظ) و`payment_terms_changed` (ما كانت وما صارت) |
| **الشراء (المشتريات)** | الشيء نفسه لشراء البضاعة: `receivings_invoice_date` للتاريخ، والشروط لكل مشترٍ، **وقيود الشراء والمخزون تأخذ اليوم المكتوب**، والمرتجع لا يحمل تاريخًا ولا شروطًا؛ **والدفعة التي تُؤدَّى عند حفظ الشراء تُؤرَّخ يوم الحفظ** لأنه يوم خروج المال (لا اليوم المكتوب، كدفعات المبيعات)، فلا ينقص يوم مضى في نقدية الخزينة بتاريخ فاتورة قديمة |
| **الرصيد الافتتاحي للمورد** (`SupplierAdvances::openingBalance`) | **لا يُؤرَّخ بعد اليوم** (يُرفض بـ«التاريخ غير صالح» ويحدّ حقل التاريخ في النموذج بأقصاه اليوم): ما لم يحلّ يومه يدخل رصيد المورد والمفتوح ولا يدخل شريحة من شرائح عمره فينكسر الربط الثلاثي (الأعمار لا تحسب مستندًا بعد يوم التقرير). افتراض في القسم 4 |
| **بعد الإصدار** | **التاريخ المكتوب لا يتغير بعد الحفظ** ولو لم يكن القيد قائمًا (القاعدة القائمة أن عميل الفاتورة المرحَّلة وتاريخها مقفلان وتُصحَّح بإشعار؛ تُرفض المحاولة برسالة `SaleLocked`/`PurchaseLocked` ولا يُمَس القيد)، **ولا يُعكس القيد ولا يُعاد ترحيله لأن لا تعديل للتاريخ أصلًا**. **شروط الدفع وحدها تتغير بعد الإصدار** (لفاتورة صادرة أو شراء بضاعة) وتُحرِّك تاريخ الاستحقاق وحده: **لا قيد يتغير ولا قيد جديد**، ويصحّ ذلك ولو كان المستند في فترة مقفلة (لا قيد يتحرك) ويُدوَّن في سجل التدقيق |
| **نماذج التعديل والطباعة** | نموذج تعديل الفاتورة والشراء يعرض التاريخ والاستحقاق ويتيح تعديل الشروط، والفاتورة المطبوعة (`document_helper`) والإيصال يعرضان التاريخ **والاستحقاق عند وجود شروط وحدها** |
| **الأعمار بستّ شرائح** (`AgingBuckets` و`CustomerPosition::aging` و`SupplierPosition::aging`) | `today` (العمر 0) و`1-30` و`31-60` و`61-90` و`91-120` و`120+` (بدل `0-30` و`31-60` و`61-90` و`91+`)، **العمر = يوم التقرير − تاريخ المستند** (لا الاستحقاق: مرصود `RSC-AGE-03`)، **ومستند بعد يوم التقرير لا يُحسب**؛ أطوال الشرائح تتبع «عدد الأيام» (30 افتراضيًا، من 1 إلى 365) في أعمار الأستاذ. حدود 30/31 و60/61 و90/91 و120/121 **مستنتجة من العناوين** (لم تُجرَّب في دفترة) |
| **المتأخر** (`InvoiceFigures::isOverdue` و`PurchaseFigures` و`overdue()` في الموقفين) | علم المستند ومبلغ الطرف المتأخر وعدد أيام التأخر؛ **يوم واحد لكل التقارير** هو يوم إعداد التطبيق (`RSC-AGE-07`) |
| **موقف الطرف ليوم ماضٍ** (`CustomerLedger::position($id, $asOf)` و`SupplierLedger`) | **ما أُرِّخ وسُدِّد حتى ذلك اليوم وحده**: المستندات بتاريخها والدفعات والإشعارات بتاريخ قيدها وتطبيقات الرصيد بين يوم إنشائها ويوم فكّها؛ **ويُحسب القيد المعكوس لاحقًا يوم كان قائمًا**؛ فمجموع الشرائح مطروحًا منه الرصيد الدائن = رصيد حساب ذلك اليوم (يخالف دفترة التي توزّع بالمتبقي الحالي وتُلقي الفرق في «120+»: `RSC-AGE-05`، فرق مقصود) |
| **أعمار الأستاذ** (`AgedLedger` و`AgedLedgerRow` و`AgedLedgerTable`، شاشة `/accounting_receivables/aged_ledger` و`/accounting_payables/aged_ledger`) | **من الحساب نفسه لا من المستندات**: كل سطر مدين فيه (للعميل) أو دائن (للمورد) مستند، **والدائن يُطبَّق على أقدم مدين أولًا** وما بقي من كل مدين يُعمَّر بيوم قيده (`RSC-AGE-06`)، **والقيود حتى يوم التقرير وحده** (الأصلي المعكوس لاحقًا يُحسب والعكس لا يُحسب قبل يومه)، وعدد أيام الشريحة (1 إلى 365) وعناوين الشرائح تتبعه (`1 - 30`، `31 - 60`... `120+` أو `1 - 10`، `11 - 20`... `40+`)، ثم **الإجمالي والرصيد الدائن غير المطبَّق والرصيد**، **وصف الإجماليات**. يُعرض الطرف الذي عليه مستحق أو له رصيد دائن وحده، والمسدَّد كاملًا يُترك |
| **تقرير الأرصدة** (`CustomerBalanceReport` و`SupplierBalanceReport` و`BalanceRow` و`BalanceTable`، شاشة `/accounting_receivables/balances` و`/accounting_payables/balances`) | الأعمدة **الرصيد قبل | إجمالي المبيعات (المشتريات) | إجمالي المرتجع | الصافي | إجمالي المدفوعات | تسويات | الرصيد** بترتيب دفترة (`CUS-08`/`RP-SB-01`): الرصيد = قبل + صافي − مدفوعات + تسويات؛ **الرصيد قبل = رصيد الحساب نهاية اليوم السابق للفترة**، **والإشعار الدائن (المدين للمورد) في «تسويات» بالسالب** (`RSC-BAL-02`)، **والدفعات بلا تطبيقات الرصيد** (لا قيد لها)، والقيد العاكس يُحسب مع ما يعكسه فيزول المستند الملغى كله، **وما لا يفسّره عمود (قيد يدوي) يُعرض عمودًا منفصلًا «قيود أخرى» لا يظهر إلا عند وجوده** فتبقى الأعمدة مجموعها الرصيد، وإجماليات الأعمدة (هي إجماليات الحسابات) في صف الإجماليات، ومن لا حركة له ولا رصيد يُخفى إلا بطلب. المورد: الدفعة المقدَّمة «مدفوعات» والرصيد الافتتاحي المستحق «مشتريات» |
| **الشاشات القائمة** (`AccountingReceivables` و`AccountingPayables`) | القائمة: أعمدة **المتأخر** والشرائح الست؛ **كشف الطرف**: الشرائح الست ومبلغ المتأخر **وتاريخ استحقاق كل مستند وشارة «متأخرة N يومًا»** وحقل **«حتى تاريخ»** (`?to=`) يقرؤه ليوم ماضٍ برسالة سبب إن لم يكن يومًا؛ روابط إلى أعمار الأستاذ وتقرير الأرصدة من القائمة. **الشاشات تتكلم باسم الشركة** كلها (من قُيّد بفروع يجد 404) وتحت وحدة الطرف (`accounting_receivables` أو `accounting_payables`) وتتطلب المحاسبة مفعّلة (404 وإلا)، **وكل اسم مخرَج مُهرَّب (`esc`)** |
| **ما يطلبه الطلب** (`PartyReportQuery`) | يقرأ اليوم وطول الشريحة والفترة وعلم «إظهار الفارغ»: **ما ليس يومًا حقيقيًا يُرفض برسالة ويُقرأ التقرير لليوم**، وفترة تنتهي قبل أن تبدأ تُرفض، وطول الشريحة يُقصّ بين يوم واحد و365، **ولا يُقبل إلا نص (لا مصفوفة)** |
| **الفاحص والمطابقة** | `tests/Support/Parity/T-31.json` (الاستحقاق والمتأخر والشرائح) و`T-35.json` (أعمدة أرصدة العملاء) و`T-07.json` (مسودة بتاريخ مكتوب تُصدَر بتاريخها): حُدِّثت **عمدًا** من «غير منفَّذ/مختلف» إلى «مطابق» بدليلها (القسم 2) |
| **اللغة** | `Sales` و`Receivings` و`Accounting` (رسائل أخطاء الحارسَين وأسماء إجراءي التدقيق) و`Accounting_receivables` و`Accounting_payables` (الشرائح والمتأخر والاستحقاق وأعمار الأستاذ وتقرير الأرصدة): الإنجليزية والعربية الحقيقية (`ar-EG` و`ar-LB`) والباقي فارغ فيرجع للإنجليزية. حُذف المفتاحان القديمان `age_0_30` و`age_91_plus` (لا يستعملهما شيء) |
| **ما لم يتغير** | القيود المرحَّلة لأي مستند قائم؛ منطق الترحيل نفسه (يأخذ تاريخ المستند من `SaleDocument::date`)؛ الأعداد القديمة للأعمار **في مجموعها** (المفتوح لا يتغير، وتتوزع على شرائح أدق: `0-30` صارت «اليوم» و«1-30»، و`91+` صارت «91-120» و«120+») |

## 2. الأدلة

- **القاعدة المرصودة (D1/OBS-A) وما قابلها هنا:** الاستحقاق = التاريخ + الأيام، ومتأخر عندما الاستحقاق < اليوم (لا ≤) وبلا شروط يساوي التاريخ (`T-31`: 26/10/2026 متأخرة و27/10 و24/11 غير متأخرتين، وهي تجري في `CustomerLedgerTest::testIsOverdue_FollowsTheRuleOfTheReferenceSystemForDueDatesOfThreeKinds`)؛ الأعمار بتاريخ الفاتورة لا الاستحقاق (`RSC-AGE-03`)؛ ست شرائح (`RSC-AGE-01`)؛ مسودة بتاريخ مكتوب تُصدَر بقيد بذلك التاريخ (`T-07`)؛ أعمدة أرصدة العملاء وتناسق **2,541.50 / 1,563.50 / −253 / 725** (`CustomerBalanceReportTest::testBuild_SplitsTheBalanceInTheColumnsOfTheReferenceSystemAndTheyReconcile` و`T-35`).
- **اختبارات الكود الجديدة (ملفات جديدة):** `AgingBucketsTest` (8 دوال ببياناتها)، `AgedLedgerTest` (14: تطبيق الدائن على أقدم مدين، وأطوال الشرائح وحدّاها، **وكل طول للشريحة يعطي رصيد الحساب نفسه**، ويوم التقرير مع معكوس لاحقًا، والمورد والعميل متفرقان)، `AgedLedgerTableTest` (4)، `PartyReportQueryTest` (12 دالة ببياناتها)، `BalanceTableTest` (5)، `CustomerBalanceReportTest` (12)، `SupplierBalanceReportTest` (13)، `InvoiceTimingTest` (12 ببياناتها).
- **اختبارات أُضيفت إلى ملفات قائمة (لكل صنف ملفّه):** `SalePostingTest` (29: التاريخ المكتوب والإذن والفترة المقفلة وبدء الدفاتر والمسودة والتدقيق والشروط وتعديلها بلا مساس بالقيد ورفض تغيير التاريخ)، `ReceivingPostingTest` (21 للشراء)، `CustomerLedgerTest` (17) و`SupplierLedgerTest` (12) (الشرائح عند حدودها، والعمر من يوم المستند لا الاستحقاق، ومستند بعد اليوم، وموقف ليوم ماضٍ يطابق حساب ذلك اليوم، والمعكوس لاحقًا، والتطبيقات بين يوم إنشائها ويوم فكّها، والمستند بلا تاريخ مكتوب يبقى تاريخه يوم حفظه واستحقاقه ذلك اليوم **فالأعمار القائمة لا تتحرك**، والربط الثلاثي لأي يوم مع الشروط والمدفوعات والإشعارات)، `Sale_libTest` (5: نقل تاريخ المسودة وشروطها إلى السجل ومسحهما بعد البيع فلا يرثهما البيع التالي) و`Receiving_libTest` (3) و`SalesControllerTest` (23) و`ReceivingsControllerTest` (23) (السجل والتاريخ لمن يحمل الإذن والشروط للجميع والإتمام بالتاريخ والاستحقاق المطبوع، والمسودة، ونموذج التعديل، وتعديل الشروط بلا مساس بالقيد)، **`AccountingReceivablesControllerTest` (24) و`AccountingPayablesControllerTest` (23): العمود المتأخر والشرائح الست واستحقاق اليوم غير متأخر وكشف الطرف بتاريخ الاستحقاق وشارة التأخر وقراءته ليوم ماضٍ ويوم غير صالح وأعمار الأستاذ (الإجماليات وطول الشريحة وما بعد اليوم وحدّ الطول) وتقرير الأرصدة (الأعمدة والفترة والفارغ و«قيود أخرى») والإذن والتهريب وعدم الظهور لمن قُيّد بفرع**.
- **ترحيلان:** `InvoiceDatesAndPaymentTermsMigrationTest` (7: الأعمدة تقبل الفراغ، وإعادة التشغيل، وإكمال ما توقف في منتصفه، **ومستند قائم يبقى كما كان بلا تاريخ ولا شروط**، والرفض ما دام مستند يحمل تاريخًا) و`InvoiceDatePermissionsMigrationTest` (4: لا منح لأحد).
- **مراجعة مستقلة بعد التنفيذ (2026-10-30، تسعة ادعاءات، كل واحد تُتبَّع في الكود قبل قبوله):** (1) **صحيح فأُصلح:** دفعة الشراء عند الحفظ كانت تُؤرَّخ بتاريخ الفاتورة المكتوب فيتحرك يوم نقدية مضى، فصارت تُؤرَّخ يوم الحفظ كدفعات المبيعات (`ReceivingPostingBuilder::payment`، اختبار `testSaveValue_DatesThePaymentMadeAtSaveOnTheDayTheMoneyWentWhateverDayIsTypedOnThePurchase`، حارس G129)؛ **وبقي شراء بلا مورد يُدفع فورًا** (قيد واحد، القسم 5). (2) **صحيح فأُصلح عند أصله:** رصيد افتتاحي لمورد مؤرَّخ بعد اليوم كان يدخل الرصيد والمفتوح ولا يدخل شريحة، فصار يُرفض (`SupplierAdvances::openingBalance`، حارس G130)؛ **ولم أُعِد عدّ المستند الآتي في الشريحة الأولى** لأن القرار أن مستندًا بعد يوم التقرير لا يُحسب (اختبارا `testAging_Leaves...` قائمان عليه). (3) و(5) **صحيحان وهما ادعاء واحد فأُصلحا:** `invoice_date_typed` كان يُكتب عند الإصدار باسم المُصدِر فلا يظهر من كتب اليوم على مسودة؛ صار يُكتب عند كتابة اليوم (حفظ المسودة) باسم كاتبه، ولا يتكرر عند الإصدار بيوم المسودة (ثلاثة اختبارات، الحراس G131 إلى G134؛ والشراء لا مسودة له فلا يتغير). (4) **صحيح فأُصلح:** اختبار الترحيل كان يقارن `smallint(5) unsigned` وMySQL 8.0.19 فما بعدها لا يُرجع عرض العدد؛ يُنزع العرض قبل المقارنة (شُغّل هنا على MariaDB 10.11 فقط، فلم يُجرَّب على MySQL 8 فعليًا). (6) **صحيح فأُصلح:** أربعة معالجات تحوّل `getPost` إلى نص فتنفجر بمصفوفة (`name[]=x`)؛ صارت المصفوفة تُرفض بالرسالة وتبقى القيمة السابقة (أربعة اختبارات، الحراس G135 إلى G138). (7) **صحيح فأُصلح:** اختبارا «يُرفض بيان السبب» كانا يجدان التاريخ في حقل الإدخال لا الرسالة؛ صارا يتحققان من نص التنبيه الكامل (`posting_period_closed`). (8) **صحيح فأُصلح:** سُمّيت الدوال العشر الجديدة بـ`camelCase` (`getInvoiceDate` و`setInvoiceDate` و`clearInvoiceDate` و`getPaymentTerms` و`setPaymentTerms` و`clearPaymentTerms` في `Sale_lib`، ونظائرها الأربع (الجلب والكتابة دون المسح) في `Receiving_lib`)، وأُعيدت تسمية متغيرات `Receiving::update` و`updateLocked` و`save_value` و`saveValueLocked` (المعدَّلة) إلى `camelCase`؛ **لم أعدّل** وسيط `$sale_id` في `Sale_lib::copy_entire_sale` لأن ما أضفتُه فيها سطور تقرأه فقط. (9) **صحيح فأُصلح:** أربعة عشر استخدامًا لأسماء كاملة في الاختبارات (`\Config\Services` و`\App\...`) صارت `use`.
- **الملفات التي شُغّلت وكل ما فيها ناجح:** **46 ملفًا، 4035 اختبارًا و17118 توكيدًا، كلها ناجحة** (شُغّل كل ملف وحده على قاعدة `ospos_test_wp13`، آخر تشغيل 2026-10-30 بعد آخر تعديل في الفرع): `InvoiceTimingTest` (23)، `AgingBucketsTest` (27)، `AgedLedgerTableTest` (4)، `AgedLedgerTest` (14)، `BalanceTableTest` (5)، `PartyReportQueryTest` (27)، `CustomerBalanceReportTest` (12)، `SupplierBalanceReportTest` (13)، `CustomerLedgerTest` (37)، `SupplierLedgerTest` (32)، `SalePostingTest` (225)، `ReceivingPostingTest` (229)، `Sale_libTest` (42)، `Receiving_libTest` (24)، `InvoiceDatesAndPaymentTermsMigrationTest` (7)، `InvoiceDatePermissionsMigrationTest` (4)، `AccountingReceivablesControllerTest` (40)، `AccountingPayablesControllerTest` (53)، `SalesControllerTest` (189)، `ReceivingsControllerTest` (158)، `SaleLoaderTest` (3)، `ReceivingLoaderTest` (3)، `SalePostingBuilderTest` (66)، `ReceivingPostingBuilderTest` (41)، `CreditSettlementTest` (22)، `SupplierSettlementTest` (22)، `SupplierAdvancesTest` (39)، `CustomerLockTest` (10)، `SupplierLockTest` (17)، `PeriodLocksTest` (8)، `DocumentJournalsTest` (24)، `LegacyPurchaseDiscountTest` (19)، `CrossBranchPaymentsTest` (32)، `SaleTest` (48)، `ReceivingTest` (28)، `DocumentHelperTest` (14)، `NavigationHelperTest` (16)، `ParityFixtureTest` (394)، `ParityNotImplementedTest` (15)، `LanguageFilesTest` (1826)، `SalesReceivablesMigrationTest` (6)، `PurchasesAccountingMigrationTest` (10)، `SalesAccountingMigrationTest` (5)، `AccountingMessageTest` (193)، `AccountingGuardTest` (4)، `PeriodGuardTest` (5).
- **لم تُشغَّل المجموعة الكاملة** (نحو ساعة، والمعالجات مشتركة مع وكلاء آخرين): يشغّلها المُدمِج بعد الدمج.

## 3. الحراس

كل حارس عُطِّل وحده بـ`tools/dev/guard_mutations.py` وقائمته في [wp13-guards.json](wp13-guards.json) (كشفه = فشل اختبار من الملفات المسمّاة، والمعادل الذي لا يغيّر سلوكًا حُذف من القائمة وسُمّي هنا).

**معادلة حُذفت من القائمة (لا يتغير بها سلوك، ومعها السبب):** (أ) `ORDER BY doc_date` في استعلام مستندات الطرف في `CustomerLedger` و`SupplierLedger`: يُعاد الترتيب في PHP بـ`usort` بالتاريخ ثم الرقم قبل التسليم؛ (ب) شرط «فاتورة» في `Sales::postSuspend` وشرط وضع «استلام» في `Receivings::postComplete` قبل تمرير التاريخ والشروط: النموذج نفسه يرفض ما ليس فاتورة أو استلامًا (`timingOf`)، **فأُزيل الشرطان المكرَّران من المتحكمين** ويبقى حارس النموذج ولحارسه G61 وG62 وG72 وG73؛ (ج) حذف منح الصلاحيتين في `down()` من ترحيل الصلاحيات: يحذفها `ON DELETE CASCADE` على `grants` عند حذف الصلاحية. **وخمسة حراس لم يكشفها اختبار في التشغيل الأول فقُوّيت لها الاختبارات:** G27 (طول الشريحة يُقصّ عند الحد الأعلى: فاتورة أقدم من 365 يومًا) وG38 (سجل مؤرَّخ بعد اليوم ولو كان قيده أقدم) وG44 (ما عُكس قبل اليوم لا يُحسب بعده) وG59 وG60 (يوم مكتوب على مسودة أو على شراء لا قيد قائمًا له يُرفض تغييره أيضًا).

**النتيجة النهائية (2026-10-30، على الفرع بعد آخر تعديل في الاختبارات):** `{"DETECTED": 147, "UNDETECTED": 0, "INVALID": 0}`: 147 حارسًا (137 قبل المراجعة المستقلة و10 أُضيفت لها، وعُدِّلت نصوص خمسة منها لتتبع الأسماء الجديدة) كشف كلًّا منها اختبار، ولا حارس بلا كشف ولا تعطيل غير صالح.

أسماء الحراس بالإنجليزية كما في الملف `wp13-guards.json`؛ وكل صف عُطِّل فيه النص المذكور وحده وشُغّلت الملفات المسمّاة (وبعضها بمرشّح لدوال الحزمة).

| الحارس | الملف الذي يحرسه | الاختبار الذي يكشفه | النتيجة |
|---|---|---|---|
| G01 a typed day must be a real day (no roll-over of february 30th) | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G02 a typed day is not later than today | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G03 a typed day is not before the books start | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G04 terms are digits only | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G05 terms are at most five characters long | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G06 terms stay within the limit | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G07 zero days of terms is no terms | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G08 due today is not overdue (strictly before today) | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G09 only what is owed can be overdue | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G10 no terms means due on the invoice date | `InvoiceTiming.php` | `InvoiceTimingTest` | كُشف |
| G11 an invoice of the day is in the first bucket | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G12 everything older than four periods is in the last bucket | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G13 a bucket ends on its last day (30 is in 1-30, 31 in 31-60) | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G14 a period of nothing counts as a day | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G15 a document dated after the day of the report is left out | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G16 the age of a document of the future is negative | `AgingBuckets.php` | `AgingBucketsTest` | كُشف |
| G17 the length of the buckets is at most four digits | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G18 the length of the buckets stays between one day and the limit | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G19 an aged ledger takes no start day | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G20 the day of a statement is given only when typed and valid | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G21 what is not a day is refused | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G22 only a 1 shows what has nothing to show | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G23 only text is read from the request | `PartyReportQuery.php` | `PartyReportQueryTest` | كُشف |
| G24 the total of a bucket adds that bucket of every row | `AgedLedgerTable.php` | `AgedLedgerTableTest` | كُشف |
| G25 the balance is what is owed less the credit not applied | `AgedLedgerTable.php` | `AgedLedgerTableTest` | كُشف |
| G26 the credit total adds the credit of every row | `AgedLedgerTable.php` | `AgedLedgerTableTest` | كُشف |
| G27 the length of the buckets is kept within one day and the limit | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G28 a supplier is owed what the account credits | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G29 journals after the day of the report are not counted | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G30 a journal that was reversed still counts on a day before its reversal | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G31 customers and suppliers are kept apart | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G32 one party can be asked for | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G33 the credit is applied to the oldest debit first | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G34 credit that came in is applied | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G35 credit is applied up to what the debit holds and no more | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G36 a party settled to nothing is left out and one holding credit is kept | `AgedLedger.php` | `AgedLedgerTest` | كُشف |
| G37 customer: a document is dated by the typed day | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G38 customer: a document dated after the day of the position is left out | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G39 customer: the due date is the one stored, the document day when none | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G40 customer: the balance of a day counts the journals up to that day | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G42 customer: an application made after the day is not counted | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G43 customer: an application released by the day is not counted | `CustomerLedger.php` | `CustomerLedgerTest` | كُشف |
| G37 supplier: a document is dated by the typed day | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G38 supplier: a document dated after the day of the position is left out | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G39 supplier: the due date is the one stored, the document day when none | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G40 supplier: the balance of a day counts the journals up to that day | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G42 supplier: an application made after the day is not counted | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G43 supplier: an application released by the day is not counted | `SupplierLedger.php` | `SupplierLedgerTest` | كُشف |
| G44 a journal reversed after the day still counts on that day | `StandingJournals.php` | `CustomerLedgerTest`، `SupplierLedgerTest` | كُشف |
| G45 customer: the overdue amount is what is overdue | `CustomerPosition.php` | `CustomerLedgerTest` | كُشف |
| G46 supplier: the overdue amount is what is overdue | `SupplierPosition.php` | `SupplierLedgerTest` | كُشف |
| G47 invoice: only what is open can be overdue | `InvoiceFigures.php` | `CustomerLedgerTest` | كُشف |
| G48 purchase: only what is open can be overdue | `PurchaseFigures.php` | `SupplierLedgerTest` | كُشف |
| G49 invoice: days overdue are nothing for what is not overdue | `InvoiceFigures.php` | `CustomerLedgerTest` | كُشف |
| G50 purchase: days overdue are nothing for what is not overdue | `PurchaseFigures.php` | `SupplierLedgerTest` | كُشف |
| G53 sale: the journals take the typed day | `SaleLoader.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G54 purchase: the journals take the typed day | `ReceivingLoader.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G55 sale: the typed day never changes after the sale is saved | `SalePosting.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G56 purchase: the typed day never changes after the purchase is saved | `ReceivingPosting.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G57 sale: the moment it was saved is compared with the day typed on the edit, not the day it is dated | `SalePosting.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G58 purchase: the moment it was saved is compared with the day typed on the edit, not the day it is dated | `ReceivingPosting.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G59 sale: the typed day is checked even when the journal is not posted | `SalePosting.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G60 purchase: the typed day is checked even when the journal is not posted | `ReceivingPosting.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G61 sale: accounting off stores no typed day or terms | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G62 sale: only an invoice carries a typed day | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G63 sale: only who holds the permission types the day | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G64 sale: a draft is issued at the day it carries by whoever issues it, at no other | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G65 sale: a draft has terms and no due date until it is issued | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G66 sale: the typed day of an invoice is audited when it is typed | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G67 sale: the terms of what is not an issued invoice are not changed | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G68 sale: terms that stay the same write nothing | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G69 sale: a change of terms is audited with what it was and what it became | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G70 sale: the due date and the typed day handed to an edit are not stored | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G71 sale: terms that are not days leave no failure in the posting log | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G72 purchase: accounting off or a return stores no typed day or terms | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G73 purchase: a return carries no typed day | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G74 purchase: only who holds the permission types the day | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G75 purchase: the typed day of a purchase is audited | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G76 purchase: the terms of what is not a purchase of goods are not changed | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G77 purchase: terms that stay the same write nothing | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G78 purchase: a change of terms is audited with what it was and what it became | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G79 purchase: the due date and the typed day handed to an edit are not stored | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G80 purchase: terms change only while accounting is on | `Receiving.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G81 sales register: the day is typed only by who holds the permission | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G82 sales register: the day is not typed while accounting is off | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G83 sales register: terms are not typed while accounting is off | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G84 sales register: the permission to type the day is the one of the invoice date | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G86 purchases register: the day is typed only by who holds the permission | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |
| G87 purchases register: the day is not typed while accounting is off | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |
| G88 purchases register: terms are not typed while accounting is off | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |
| G89 purchases register: the permission to type the day is the one of the purchase date | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |
| G91 customers: the aged ledger speaks for the whole company only | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G92 customers: the balance report speaks for the whole company only | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G93 customers: the statement can be read on a day of the past | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G94 customers: the aged ledger takes the length of the buckets asked for | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G95 customers: the balance report shows who has nothing to show only when asked | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G96 customers: the statement of a party links to the day read | `AccountingReceivables.php` | `AccountingReceivablesControllerTest` | كُشف |
| G91 suppliers: the aged ledger speaks for the whole company only | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G92 suppliers: the balance report speaks for the whole company only | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G93 suppliers: the statement can be read on a day of the past | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G94 suppliers: the aged ledger takes the length of the buckets asked for | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G95 suppliers: the balance report shows who has nothing to show only when asked | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G96 suppliers: the statement of a party links to the day read | `AccountingPayables.php` | `AccountingPayablesControllerTest` | كُشف |
| G97 customers: the name in the aged ledger is escaped | `aged_ledger.php` | `AccountingReceivablesControllerTest`، `AccountingPayablesControllerTest` | كُشف |
| G98 customers: the name in the balance report is escaped | `balances.php` | `AccountingReceivablesControllerTest`، `AccountingPayablesControllerTest` | كُشف |
| G99 the overdue badge shows only what is overdue (customers) | `customer.php` | `AccountingReceivablesControllerTest` | كُشف |
| G100 the overdue badge shows only what is overdue (suppliers) | `supplier.php` | `AccountingPayablesControllerTest` | كُشف |
| G101 customers: the balance before is the account at the end of the day before the period | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G102 customers: a journal after the last day is not counted | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G103 customers: a reversal counts as what it reverses | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G104 customers: the other parties are kept apart | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G105 customers: payments are shown as what was paid | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G106 customers: a party with nothing to show is hidden on request | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G107 customers: what no column explains is kept apart as other | `CustomerBalanceReport.php` | `CustomerBalanceReportTest` | كُشف |
| G101 suppliers: the balance before is the account at the end of the day before the period | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G102 suppliers: a journal after the last day is not counted | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G103 suppliers: a reversal counts as what it reverses | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G104 suppliers: the other parties are kept apart | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G105 suppliers: payments are shown as what was paid | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G106 suppliers: a party with nothing to show is hidden on request | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G107 suppliers: what no column explains is kept apart as other | `SupplierBalanceReport.php` | `SupplierBalanceReportTest` | كُشف |
| G108 balance row: the net is what was sold less what came back | `BalanceRow.php` | `BalanceTableTest` | كُشف |
| G109 balance row: the columns must explain the balance | `BalanceRow.php` | `BalanceTableTest` | كُشف |
| G110 balance table: the net total is the net of the rows | `BalanceTable.php` | `BalanceTableTest` | كُشف |
| G111 migration: a column that exists is not added again | `20270600000000_InvoiceDatesAndPaymentTerms.php` | `InvoiceDatesAndPaymentTermsMigrationTest` | كُشف |
| G112 migration: it cannot be taken back once a document carries a typed day | `20270600000000_InvoiceDatesAndPaymentTerms.php` | `InvoiceDatesAndPaymentTermsMigrationTest` | كُشف |
| G113 migration: a purchase that carries a typed day also stops it being taken back | `20270600000000_InvoiceDatesAndPaymentTerms.php` | `InvoiceDatesAndPaymentTermsMigrationTest` | كُشف |
| G114 migration: the columns may be empty | `20270600000000_InvoiceDatesAndPaymentTerms.php` | `InvoiceDatesAndPaymentTermsMigrationTest` | كُشف |
| G115 permissions migration: a registered permission is not registered again | `20270600000001_InvoiceDatePermissions.php` | `InvoiceDatePermissionsMigrationTest` | كُشف |
| G117 customers: the aged ledger has its route | `Routes.php` | `AccountingReceivablesControllerTest` | كُشف |
| G118 suppliers: the balance report has its route | `Routes.php` | `AccountingPayablesControllerTest` | كُشف |
| G123 sales register: a draft taken up brings its typed day back | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G124 sales register: a draft taken up brings its terms back | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G125 sales register: a finished sale leaves no typed day for the next one | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G126 sales register: a finished sale leaves no terms for the next one | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G127 purchases register: a finished purchase leaves no typed day for the next one | `Receiving_lib.php` | `Receiving_libTest` | كُشف |
| G128 purchases register: a finished purchase leaves no terms for the next one | `Receiving_lib.php` | `Receiving_libTest` | كُشف |
| G129 purchase: the payment made at save is dated the day the money went, not the day typed on the invoice | `ReceivingPostingBuilder.php` | `ReceivingPostingTest` (بمرشّح) | كُشف |
| G130 supplier: an opening balance is not dated after today | `SupplierAdvances.php` | `SupplierAdvancesTest` (بمرشّح) | كُشف |
| G131 sale: the day typed on a draft is audited when the draft is saved | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G132 sale: issuing a draft at the day it carries is not typing the day again | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G133 sale: the day typed on a draft is compared with the day the draft already carries | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G134 sale: a day changed at issue is audited for the employee who changed it | `Sale.php` | `SalePostingTest` (بمرشّح) | كُشف |
| G135 sales register: a list sent as the day is refused | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G136 sales register: a list sent as the terms is refused | `Sales.php` | `SalesControllerTest` (بمرشّح) | كُشف |
| G137 purchases register: a list sent as the day is refused | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |
| G138 purchases register: a list sent as the terms is refused | `Receivings.php` | `ReceivingsControllerTest` (بمرشّح) | كُشف |

## 4. ما افترضتُه

كل بند افتراض لا دليل عليه في دفترة، **وللمالك نقضه** (ويقابله قرار تنفيذي تحت E47):

1. **التاريخ المكتوب لا يتجاوز اليوم ولا يسبق بدء الدفاتر.** لم يُرصد في دفترة قبول تاريخ مستقبلي للفاتورة؛ الرفض هو المتحفظ لأن القيد المؤرَّخ بالمستقبل يغيّر ميزانًا وتقارير يوم لم يأتِ.
2. **التاريخ المكتوب مقفل بعد الحفظ** (لا عكس وإعادة ترحيل لأن لا تعديل أصلًا): هذه قاعدة عندنا قائمة (عميل الفاتورة المرحَّلة وتاريخها مقفلان) ولا نفتح فيها بابًا. **دفترة تعدّل التاريخ في موضعه (E62)** ونحن لا (فرق مقصود). ومن أخطأ في تاريخ يصحّح بإشعار دائن وفاتورة جديدة.
3. **الصلاحيتان `sales_invoice_date` و`receivings_invoice_date` بلا منح**: ما دامتا لا تُمنحان يُؤرَّخ كل مستند يوم حفظه كما كان، فالنشر لا يغيّر سلوك أحد. **المسودة** المكتوب عليها تاريخ ممن يملك الإذن **تُصدَر بذلك التاريخ ولو لم يكن المُصدِر يملكه** (لا بتاريخ آخر).
4. **الشروط يكتبها كل من يبيع أو يشتري** بلا صلاحية خاصة (الصلاحية للتاريخ لأنه يحرّك القيد، والشروط لا تحرّك شيئًا)، ويدوَّن تغييرها في سجل التدقيق.
5. **تاريخ الاستحقاق يُحسب عند الإصدار** من تاريخ المستند (المكتوب وإلا يوم الحفظ) ويُخزَّن؛ **ومسودة بشروط بلا تاريخ استحقاق** إلى أن تُصدَر؛ وتغيير الشروط لاحقًا يعيد حسابه من تاريخ المستند نفسه.
6. **بلا شروط يستحق المستند يوم تاريخه فيُعدّ «متأخرًا» بعد انقضاء ذلك اليوم** (استنتاج `RSC-DUE-01`: #000005 بلا استحقاق دخلت «متأخر»).
7. **الأعمار تُحسب بتاريخ المستند ولو كان الاستحقاق ماضيًا** (`OO-RSC-03` لم يُرصد بعدُ: رُصد استحقاق مستقبلي واحد). **وأعمار الموردين بتاريخ الشراء** كذلك (مرصود في أعمار حساب الموردين `RP-SA-01` بتاريخ المستند، لكن أثر الاستحقاق فيها لم يُقرأ).
8. **أطوال الشرائح من 1 إلى 365** (حدّ دفترة الأعلى لم يُقرأ)، وما خارجها يُقصّ بصمت ويُعرض الطول الفعلي في الحقل.
9. **عمود «المرتجع» صفر الآن**: لا نوع «فاتورة مرتجعة» عندنا (SAL-19/WP9)، فكل مرتجع إشعار دائن في «تسويات» كما يعرض دفترة الإشعار؛ يتحرك العمود حين يُبنى النوع (والمكتبة جاهزة له بصيغته). **المرتجع النقدي للمورد** كذلك يظهر تسوية.
10. **الأرصدة وأعمار الأستاذ تتكلم باسم الشركة** كالشاشات القائمة (رصيد الطرف يُنفَق على مستندات أي فرع)، فمن قُيّد بفروع لا يجدها (404). **تقرير الأرصدة ببعض الفروع** قرار مفتوح (القسم 6).
11. **أعمار الأستاذ تعرض الطرف ذا الرصيد الدائن** (مستحق صفر ودائن غير مطبَّق) في عمود منفصل، وأعمار دفترة لا تعرض إلا موجب الرصيد (`RP-SA-02`)؛ **الإجمالي هنا** مفتوح − دائن = رصيد الحساب.
12. **«إظهار من لا حركة له ولا رصيد»** يعرض كل من له قيد في الحساب ولو صفرت أعمدته؛ **ولا «إخفاء الرصيد الصفري» لمن له حركة** (`OO-RSC-19` لم يُحسم).
13. **كشف الطرف بتاريخ ماضٍ ولا تتبعه القائمة:** القائمة (`customersWithBalance`) تسرد من رصيده الحالي غير صفر، فتبقى «الآن»؛ ومن أراد يومًا ماضيًا لكل الأطراف فأعمار الأستاذ.
15. **الدفعة عند حفظ الشراء تُؤرَّخ يوم الحفظ لا اليوم المكتوب على الفاتورة** (يوم خروج المال كما في دفعات المبيعات): لم يُرصد في دفترة تاريخ الدفعة المُدخَلة مع فاتورة شراء بتاريخ ماضٍ (هل يتبع تاريخ الفاتورة أم تاريخ إدخالها)؛ المتحفظ ألا يتحرك يوم نقدية مضى. **ولا يشمل هذا شراءً بلا مورد يُدفع عند الحفظ**: قيده واحد (المخزون والخزينة) فيأخذ اليوم المكتوب كله (القسم 5).
16. **الرصيد الافتتاحي للمورد لا يُؤرَّخ بعد اليوم** (يشمل ما عليه وما له): رصيد لم يحلّ يومه لا يدخل أي شريحة من الأعمار فيكسر الربط الثلاثي؛ والمتحفظ رفضه كما يُرفض تاريخ الفاتورة المستقبلي. **يُستثنى من لا يُسجَّل رصيده إلا بعد أن يبدأ دفتره في يوم لاحق** (تُدخل الأرصدة يوم البدء نفسه أو بعده).
17. **اليوم المكتوب على مسودة يُسجَّل في التدقيق عند حفظ المسودة باسم كاتبه**، فتبقى مسودة حُذفت بلا فاتورة بسجلها؛ ومن يُصدرها بيومها لا يظهر فيه، ولا يظهر فيه إلا من غيّر اليوم عند الإصدار.
14. **تاريخ الاستحقاق لا يُخزَّن لمستند قائم قبل النشر** (لا إعادة حساب): فاستحقاقه يوم تاريخه بالقراءة لا بالتخزين، **ولا يظهر تاريخ استحقاق على فاتورة مطبوعة بلا شروط** (دفترة تعرضه بحسب ما رُصد).

## 5. ما بقي

- **قائمة الفواتير القديمة** (`Sales::getManage`): تبويبان «مستحقة الدفع» و«متأخر» (`RSC-DUE-01`) ومرشّح «متأخر» في تقارير الإيرادات: لم تُبنَ (تمس بحث OSPOS القديم وتجاوز نطاق الشاشات المحاسبية)؛ المتأخر يُرى الآن في شاشتي الذمم وكشف الطرف.
- **إجماليات القائمة المرقَّمة** (`/accounting_receivables`): تُصفَّح صفحة صفحة ولا تحمل صف إجماليات (مكلف لكل طرف)؛ **الإجماليات في أعمار الأستاذ وتقرير الأرصدة** وكلاهما لكل الأطراف.
- **مرشّحات دفترة الزائدة:** الطرف المتعدد والتصنيف والموظف والحالة اليدوية والعملة، والملخص (ثلاثة أعمدة) و«إظهار التفاصيل» و«إخفاء الأعمدة» (`RSC-BAL-04`)، وتصدير CSV: لم تُبنَ (لا أثر محاسبيًا).
- **بطاقات في فهرس التقارير** (`reportCatalog`) لشاشتي الأعمار والأرصدة: لم تُضف (الروابط من شاشتي الذمم)؛ إضافة صغيرة مستقلة تغيّر اختبارات الفهرس.
- **عمود «المرتجع»** ينتظر نوع الفاتورة المرتجعة (WP9/SAL-19).
- **تاريخ الاستحقاق على الدفعات والسندات** (الإشعارات والمصروفات) وشرائح الأعمار بعملات متعددة: لاحقًا (WP28).
- **شراء بلا مورد يُدفع عند الحفظ بتاريخ ماضٍ:** قيده واحد (مخزون مدين وخزينة دائنة) فيأخذ اليوم المكتوب كله، ويتحرك فيه يوم نقدية الخزينة؛ فصله إلى قيدين يغيّر ترحيل ما قبل WP13 فلم يُفعل (ينتظر قرار المالك: هل يُرفض التاريخ المكتوب لشراء بلا مورد يُدفع فورًا؟).
- **رصيد افتتاحي لمورد مؤرَّخ بعد اليوم دخل قبل هذا التعديل:** لا يُعدَّل ولا يُرفض الآن؛ يبقى في رصيد الطرف والمفتوح خارج الشرائح حتى يحلّ يومه (يكشفه فحص ما بعد النشر في القسم 7).
- **تقرير الأرصدة وأعمار الأستاذ لقارئ ببعض الفروع** (قواعد E45): ينتظر قرار المالك.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرارات المالك (`ownerDecisionsNeeded`):** (1) **من يمنح `sales_invoice_date` و`receivings_invoice_date`** (محاسب أو مدير؟ لا أحد الآن)، (2) **هل يُقبل تاريخ مستقبلي للفاتورة** (نرفضه)، (3) **هل تحتاج الشروط صلاحية خاصة أم يكتبها كل بائع**، (4) **هل يرى قارئ ببعض الفروع الأرصدة والأعمار لفروعه** وكيف يُعامَل رصيد الطرف المشترك بين الفروع، (5) **هل تعرض أعمار الأستاذ الطرف الدائن** أم الموجب وحده كدفترة، (6) **هل يُرفض التاريخ المكتوب على شراء بلا مورد يُدفع فورًا** (قيده الواحد يحرّك يوم نقدية مضى)، (7) **هل يُسمح برصيد افتتاحي مؤرَّخ في المستقبل** لمن يبدأ دفتره في يوم لاحق (نرفضه الآن).

**رصد دفترة المطلوب (`daftraObservationsNeeded`):** (1) **`OO-RSC-03`**: أثر تاريخ استحقاق ماضٍ في شريحة الفاتورة (فاتورة D−40 باستحقاق D−10) وفي أعمار الموردين، (2) هل تقبل دفترة **تاريخ فاتورة مستقبليًا**، (3) **ترتيب شرائح أعمار الموردين بالفواتير** (`aged_creditors`) وتخطيطها، (4) **`OO-RSC-19`**: إخفاء الرصيد الصفري لطرف ذي حركة، (5) أثر **فاتورة المرتجع** في أعمدة أرصدة العملاء بعد WP9. (6) **تاريخ الدفعة المُدخَلة مع فاتورة شراء بتاريخ ماضٍ**: هل تتبع تاريخ الفاتورة أم يوم إدخالها (أُخذ الثاني).

## 7. قبل النشر

- **ترحيلان إضافيان يعملان تلقائيًا** عند أول دخول: أعمدة تقبل الفراغ في `sales` و`receivings` (تعديل جدولين كبيرين فيُنفَّذ وقت هدوء مع نسخة احتياطية) وصلاحيتان بلا منح. **لا يلمسان صفًّا ولا قيدًا.** لا تراجع بـ`down()` بعد أن يحمل مستند تاريخًا مكتوبًا (يرفض): استعادة النسخة الاحتياطية.
- **راجع بعد النشر:** (أ) أعمار العملاء والموردين **مجموعها** كما قبل النشر لكل طرف (يختلف التوزيع على الشرائح لا المجموع: «0-30» صارت «اليوم» و«1-30»، و«91+» صارت «91-120» و«120+»)، (ب) كل طرف «يطابق الحساب» في القائمة، (ج) **تقرير الأرصدة يساوي ميزان المراجعة** لحساب العملاء (12041) والموردين (2211) لليوم نفسه، (د) لا قيد تغيّر لمستند قائم، (هـ) لا رصيد افتتاحي لمورد مؤرَّخ بعد اليوم (استعلام: `SELECT receiving_id, supplier_id, receiving_time FROM ospos_receivings WHERE receiving_type = 'opening' AND receiving_time > NOW()` و`SELECT advance_id, supplier_id, advance_date FROM ospos_supplier_advances WHERE kind = 'opening' AND advance_date > CURDATE()`): ما وُجد منه يبقى في رصيد الطرف خارج الشرائح حتى يحلّ يومه ويُراجَع مع المحاسب.
- **تفعيل التاريخ المكتوب** (قرار المالك 1): امنح `sales_invoice_date` للمحاسب المخوّل من «الموظفون ← الصلاحيات»، وجرّب فاتورة بتاريخ أمس: يُقيَّد بذلك اليوم ويظهر في أعمار ذلك اليوم، ثم تاريخًا في فترة مقفلة (يُرفض برسالة)، ثم تغيير الشروط بعد الإصدار (لا قيد جديد، ويُدوَّن في سجل التدقيق).
- **قوائم محفوظة في المتصفح:** أعمدة قائمة الذمم صارت `age_today` و`age_1_30`... فتظهر الأعمدة الجديدة بالتخطيط الافتراضي لمن حفظ ظهور الأعمدة.
- المحاسبة المعطَّلة: كل شيء كما كان (لا أعمدة تُملأ ولا شاشات).

## 8. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار بعد E67):

**decisions.md** (صف جديد، رقمه E-التالي):

> | E-؟؟ | **تاريخ الفاتورة وتاريخ الاستحقاق وشروط الدفع والأعمار بست شرائح وأرصدة الأطراف (WP13: `G-27` و`G-31` و`G-53` و`CUS-08` و`CUS-09` و`G-14`)** (2026-10-30، [التقرير](daftra-parity/wp13-report.md)): **قرار تنفيذي أُخذ تحت E47 على المرصود في D1 وOBS-A، وللمالك حق نقضه.** **(1)** للفاتورة وشراء البضاعة **تاريخ مكتوب** (`invoice_date`) مستقل عن لحظة الحفظ **تأخذه قيودهما ومخزونهما** و**مسودة بتاريخ مكتوب تُصدَر بذلك التاريخ**، يكتبه من يحمل `sales_invoice_date` أو `receivings_invoice_date` (**صلاحيتان بلا منح**)، **ولا يتجاوز اليوم ولا يسبق بدء الدفاتر، والتاريخ في فترة مقفلة مرفوض بالترحيل نفسه**، **ويُقفل بعد الحفظ** (لا عكس وإعادة ترحيل لأن لا تعديل: يخالف دفترة التي تعدّله في موضعه، E62) ويُدوَّن في سجل التدقيق باسم من كتبه (عند حفظ المسودة لا عند إصدارها)، **والدفعة التي تُؤدَّى عند حفظ الشراء تُؤرَّخ يوم الحفظ لا اليوم المكتوب** (كدفعات المبيعات)، **والرصيد الافتتاحي للمورد لا يُؤرَّخ بعد اليوم**. **(2)** **شروط دفع بالأيام** (`payment_terms_days`) واستحقاق (`due_date`) = التاريخ + الأيام، **وبلا شروط = التاريخ**؛ **تتغير الشروط بعد الإصدار فيتحرك الاستحقاق وحده ولا يُمَسّ قيد**، ويُدوَّن التغيير. **(3)** **متأخر = متبقٍّ واستحقاقه قبل اليوم تمامًا** (استحقاق اليوم ليس متأخرًا؛ D1)، بيوم إعداد التطبيق في كل التقارير. **(4)** **الأعمار بست شرائح** (اليوم و1-30 و31-60 و61-90 و91-120 و120+، بدل أربع) **من تاريخ المستند لا الاستحقاق** (`RSC-AGE-03`) **ولا يُحتسب مستند بعد يوم التقرير**، وكشف الطرف وأعمار الأستاذ يُقرآن **ليوم ماضٍ بمجموع شرائح مطروحًا منه الدائن = رصيد ذلك اليوم** (فرق مقصود عن دفترة التي تُلقي الفرق في «120+»، `RSC-AGE-05`)؛ **وأعمار الأستاذ تطبّق الدائن على أقدم مدين أولًا** بعدد أيام للشريحة (`RSC-AGE-06`). **(5)** **تقرير أرصدة العملاء والموردين** بأعمدة دفترة (قبل ومبيعات (مشتريات) ومرتجع وصافٍ ومدفوعات وتسويات ورصيد)، **الرصيد قبل = رصيد الحساب نهاية اليوم السابق للفترة**، **والإشعار الدائن (المدين) تسوية بالسالب** و**عمود المرتجع صفر حتى يُبنى نوع الفاتورة المرتجعة** (WP9)، **وما لا يفسّره عمود يظهر «قيود أخرى»**، والمجموع = رصيد الحساب. **افتراضات للمراجعة:** القسم 4 من التقرير (14 افتراضًا، أهمها: رفض التاريخ المستقبلي، وكتابة الشروط بلا صلاحية، والأعمار بتاريخ المستند ولو ماضي الاستحقاق، وتكلّم التقارير باسم الشركة). **الأدلة:** اختبارات `InvoiceTimingTest` و`AgingBucketsTest` و`AgedLedgerTest` و`CustomerBalanceReportTest` و`SupplierBalanceReportTest` و`CustomerLedgerTest` و`SupplierLedgerTest` و`SalePostingTest` و`ReceivingPostingTest` و`SalesControllerTest` و`ReceivingsControllerTest` و`AccountingReceivablesControllerTest` و`AccountingPayablesControllerTest` والترحيلان، **والمطابقات `T-07` و`T-31` و`T-35`** (4035 اختبارًا ناجحًا في 46 ملفًا، و147 حارسًا عُطِّل كلٌّ منها وحده فكشفه اختبار: [wp13-guards.json](daftra-parity/wp13-guards.json)). ترحيلان `20270600000000` و`20270600000001` إضافيان (أعمدة تقبل الفراغ وصلاحيتان بلا منح) ولا يمسّان صفًّا ولا قيدًا، ولا دمج في `master` ولا نشر | يؤكد E15 وE57 ويحدّد WP14 وWP33 وWP36 |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `SAL-11`: عمود «ما عندنا»: «تاريخ فاتورة مكتوب بإذن يؤرّخ القيود ومسودة تُصدَر بتاريخها، وشروط دفع بالأيام واستحقاق = التاريخ + الأيام، يتغيّر بعد الإصدار بلا مساس بالقيد، ومتأخر = متبقٍّ واستحقاقه قبل اليوم (WP13)». التصنيف: «مطابق» للتاريخ والشروط والاستحقاق والمتأخر في شاشات الذمم، و**يبقى «مختلف»** لأن (أ) التاريخ مقفل بعد الحفظ بينما تعدّله دفترة، (ب) تبويبا «مستحقة الدفع» و«متأخر» في قائمة الفواتير لم يُبنيا. الأدلة: `InvoiceTimingTest` و`SalePostingTest` و`SalesControllerTest` و`T-07` و`T-31`.
- الصفان `CUS-08` و`CUS-09`: «تقرير الأرصدة بأعمدة دفترة وأعمار الأستاذ بعدد أيام للشريحة وأعمار الفواتير بست شرائح (WP13)»، ويبقيان «مختلف ويحتاج تعديلًا» لغياب المرشّحات الزائدة والملخص والتصدير وعمود المرتجع (WP9). الأدلة: `CustomerBalanceReportTest` و`AgedLedgerTest` و`AccountingReceivablesControllerTest` و`T-35`.
- الصف `PUR-06`/`RP-SB-01`/`RP-SA-01` (أرصدة الموردين وأعمارهم): «تقرير أرصدة الموردين وأعمار الأستاذ (WP13)» ويبقى «مختلف» لما سبق.
- فقرة تحديث بعد الجدول: «**تحديث بعد WP13 (2026-10-30):** نُفِّذ تاريخ الفاتورة وشروط الدفع والاستحقاق وأعمار الست شرائح وتقريرا الأرصدة والأعمار بالأستاذ للعملاء والموردين ([تقرير WP13](wp13-report.md)). لا تتغير الأعداد إلا إن قرر المُدمِج نقل `SAL-11` إلى «مطابق»، وهو **لا يُنقَل** لأن تعديل التاريخ في الموضع وتبويبات قائمة الفواتير ما زالت مختلفة.»

**تقرير الفجوات** (`gap-report.md`): تُستبدل نصوص `G-27` و`G-31` و`G-53` بـ: «**نُفِّذت (2026-10-30، WP13):** التاريخ المكتوب (مقفل بعد الحفظ، وللمسودة تاريخها عند الإصدار) وشروط الدفع والاستحقاق والمتأخر، والأعمار بست شرائح من تاريخ المستند للعملاء والموردين، وتقرير الأرصدة بأعمدة دفترة وأعمار الأستاذ ([التقرير](wp13-report.md)). **ما بقي:** تبويبا «مستحقة» و«متأخر» في قائمة الفواتير، وعمود المرتجع (WP9)، ومرشّحات التقريرين وتصديرهما، وأثر الاستحقاق الماضي في الشريحة (`OO-RSC-03`).» ويُضاف إلى `G-14` أن حالتي «مستحقة» و«متأخرة» صارتا للمستند في شاشتي الذمم لا في قائمة الفواتير.

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **تاريخ الفاتورة وشروط الدفع والأعمار بست شرائح (WP13):** ترحيلان إضافيان يعملان تلقائيًا عند أول دخول: `20270600000000_InvoiceDatesAndPaymentTerms` (ثلاثة أعمدة تقبل الفراغ في `sales` و`receivings`، فيُنفَّذ وقت هدوء مع نسخة احتياطية، ولا يلمس صفًّا ولا قيدًا) و`20270600000001_InvoiceDatePermissions` (صلاحيتان `sales_invoice_date` و`receivings_invoice_date` **بلا منح لأحد**). `down()` يرفض بعد أن يحمل مستند تاريخًا مكتوبًا: استعادة النسخة الاحتياطية. **قبل التفعيل:** سجّل مجموع أعمار العملاء والموردين لكل طرف، **وبعد النشر** قارنه (يتغير التوزيع على الشرائح لا المجموع)، وتحقق أن كل طرف «يطابق الحساب» وأن «تقرير الأرصدة» يساوي ميزان المراجعة وأنه لا رصيد افتتاحي لمورد مؤرَّخ بعد اليوم. **ثم يقرر المالك من يمنح الصلاحيتين**؛ بعد المنح جرّب فاتورة بتاريخ أمس (قيدها بذلك اليوم)، وتاريخًا في فترة مقفلة (يُرفض)، وتغيير الشروط بعد الإصدار (لا قيد جديد). الشاشات الجديدة: `/accounting_receivables/aged_ledger` و`/balances` و`/accounting_payables/aged_ledger` و`/balances` (للشركة كلها، وتتطلب المحاسبة مفعّلة). المحاسبة المعطَّلة: لا تاريخ ولا شروط ولا شاشات.

**backlog.md:** صف WP13 «نُفِّذ (2026-10-30)» والبنود `G-27` و`G-31` و`G-53` و`CUS-08` و`CUS-09` «partial» (تبويبا قائمة الفواتير وعمود المرتجع والمرشّحات)، و`G-14` «partial» (المستحقة والمتأخرة في الذمم لا في القائمة)؛ ويُضاف بند «WP13-INVOICE-LIST-DUE-TABS» لتبويبي القائمة، وبند «WP13-REPORT-FILTERS» لمرشحات التقريرين وتصديرهما، وبند «WP13-AGING-PAST-DUE» ينتظر رصد `OO-RSC-03`.
