# تقرير WP5: تقرير الضرائب من الدفاتر (REP-07، أساس الاستحقاق)

> الحالة (2026-10-28): **نُفِّذ على الفرع `wp/WP5`** (نسخة العمل `/home/user/wt/WP5`، من الإيداع `e231d3f`) بالتفويض التنفيذي الشامل (E47): تقرير **الضرائب** يُقرأ من **القيود المرحَّلة** لحسابي ضريبة المخرجات وضريبة المدخلات لا من جداول المبيعات، لفترة وفروع بقواعد E45، بخمسة أقسام كما في دفترة (فواتير البيع، مردودات المبيعات، فواتير الشراء، مردودات المشتريات، المصروفات) ثم المحصَّلة والمدفوعة والصافي (مستحق أو مسترد)، وأساس كل قسم ونسبته من **بنود المستند الذي رُحِّل قيده**، وجدول يربط كل حساب ضريبة بالتقرير ويسمّي ما فيه من غير المستندات. **أساس الاستحقاق وحده؛ المرشح النقدي معروض معطَّلًا بملاحظة، وطلبه يُرفض بخطأ مسمّى لا يُتجاهل.** **لا ترحيل في قاعدة البيانات، ولا وحدة ولا صلاحية جديدة** (يُفتح بصلاحية `accounting_reports` القائمة)، **ولا قيد ولا رصيد ولا مستند يتغير** (قراءة فقط). **لا كتابة في دفترة، ولا دمج في `master`، ولا نشر، ولا طلب سحب.** لم أُجرِ فحص المتصفح ولا المجموعة الكاملة (القسم 5). ولم أُعدِّل `decisions.md` ولا المصفوفة ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر: النص المقترح لها في القسم 8 للمُدمِج.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **المكتبة** (`TaxReport` ومعها `TaxDocuments` و`TaxReportResult` و`TaxSectionFigures` و`TaxRateRow` و`TaxAccountFigures` و`TaxDocument` والتعدادان `TaxSection` و`TaxBasis`) | تقرأ سطور القيود **المرحَّلة والمعكوسة** (لا المسودة ولا المعاينة) على الحسابين اللذين يوجّههما `tax.output` و`tax.input` (`215001` و`215002` في الدليل الافتراضي، **من التوجيه لا من رمزين ثابتين**)، لفروع النطاق وفترته، وتجمع لكل قيد ما فعله بالحساب **دائن − مدين** (فتكون الضريبة المستحقة موجبة في الحسابين). القيد المعكوس يُحسب **مع عكسه** (فالمستند الملغى يساوي صفرًا) وينتسب إلى **مستنده الأصلي** (`reverses_journal_id`) |
| **الأقسام الخمسة وإشاراتها** | بترتيب دفترة (`RA-TX-01`): فواتير البيع (+)، مردودات المبيعات والإشعارات الدائنة (−)، فواتير الشراء (−)، مردودات المشتريات والإشعارات المدينة (+)، المصروفات (−). **الإشارة أثر المستند في الضريبة المستحقة**، فمجموع الأقسام = **صافي المستحق** = المحصَّلة (المبيعات − مردوداتها) − المدفوعة (الشراء + المصروفات − مردودات الشراء). المرصود في دفترة: 331.50 على 2,210 وإشعار دائن −33 على −220 فالصافي 298.5 (مختبَر، القسم 2) |
| **الأساس والنسبة من بنود المستند** | **الأساس الخاضع** = مجموع صافي البنود التي عليها ضريبة بعد خصم البند وحصته من خصم إجمالي الفاتورة (WP16 وWP15) ودون الضريبة المضمَّنة في السعر، **بالقواعد نفسها التي يقرأ بها الترحيل البنود** (`SaleAmounts` و`ReceivingAmounts`)، ومن بنود المستند الذي رُحِّل قيده (ربط بالمصدر)، **لا من جداول المبيعات القديمة وحدها**: مبيعة لم يُرحَّل قيدها لا أثر لها. والضريبة نفسها من **القيد** لا من البنود. وللمصروف (بلا بنود) الأساس = قيده − ضريبته. والصفوف **بحسب النسبة** (`15%`...) من بنود المبيعات والمشتريات؛ والمصروف لا يحمل نسبة فلا صفوف له |
| **المستندات بخصم على الإجمالي** | تحت كل قسم من الأقسام الأربعة التي لها بنود صفّ «منها ما عليه خصم على إجمالي المستند» بأساسه وضريبته وعدده (حصص WP16 للمبيعات وWP15 للمشتريات)، ولا يظهر إلا إن وُجد مستند كهذا |
| **الربط بالدفتر** | جدول «ما تحتفظ به حسابات الضريبة»: لكل حساب **حركته في الدفاتر** (للفروع والأيام نفسها) و**ما في التقرير منها** و**قيود أخرى** (يدوية أو افتتاحية أو تسوية للضريبة أو مستند محذوف نهائيًا) بعددها ووسم «متطابق/مختلف». **الصافي لا يشمل ما ليس ضريبة مستند** (دفع الضريبة للهيئة ليس ضريبة مستند)، فإن وُجد قيد كهذا قيل صراحةً إن التقرير لا يتطابق مع الحساب ولم يُدسّ في الأرقام. اختبار يربط الأقسام بميزان المراجعة للفروع والأيام نفسها |
| **مستند لا يتفق مع قيده** | إن لم تعد بنود المستند تقول ما يقوله قيده (مثلًا عُدِّلت بعد الترحيل خارج التطبيق) **بقيت ضريبته في القسم** (من القيد، فلا ينفصل التقرير عن الحساب) **وتُرك أساسه** وعُدَّ وقيل في الشاشة (`unmatchedDocuments`)، ولم يُخمَّن |
| **الفروع (E45)** | `ReportScope` نفسه: ما يطلبه الموظف يُقصّ إلى فروعه؛ «الشركة» تتطلب كل الفروع؛ من يقرأ بعض الفروع يرى تحذيرًا بأن الإجمالي **جزئي**؛ من يقرأ فرعًا واحدًا لا يرى مختارًا ولا وسمًا؛ ومن لا فرع له يرى «لا شيء لجمعه». الروابط (كشف الحساب وCSV) تحمل النطاق ولا توسّعه |
| **الفترة** | من/إلى بالتاريخين شاملين (قيد في اليوم الأول أو الأخير يدخل)، وبلا «من» تبدأ من بدء الدفاتر. تاريخ لا يصح أو فترة تنتهي قبل أن تبدأ تُعرض برسالة وتُحسب الفترة الافتراضية (كقوائم الدخل والميزانية) |
| **قبل بدء الدفاتر** | الشاشة تقول دائمًا متى بدأت الدفاتر وأن المستندات المؤرَّخة قبل ذلك **لم تُرحَّل فلا تدخل التقرير**، وإن امتدت الفترة إلى ما قبل البدء حذّرت بإطار تنبيه، **وتعدّ المبيعات والمشتريات والمصروفات التي عليها ضريبة وتقع في الفترة قبل يوم البدء** (بنطاق الفروع، والمصروف بلا فرع للفرع الافتراضي) وتقول إنها تظهر في تقارير الضرائب التشغيلية |
| **الأساس النقدي** | المرشح «الأساس» في الشاشة: «الاستحقاق: المستندات الصادرة» محدَّد، و«نقدي: المدفوعة بالكامل» و«نقدي: المدفوعة جزئيًا» **معطَّلان** مع ملاحظة تشرح (يحتاج قراءة لدفترة: `WP20`). طلب `?basis=cash` أو `partial` أو أي قيمة لا يعرفها التقرير **يُرفض بالخطأ المسمّى `tax_basis_unavailable`** (رسالة بالعربية والإنجليزية) فلا تظهر أرقام ولا رابط تصدير، وطلب ملف CSV يرجع **422** بالرسالة نفسها. والمكتبة نفسها ترفض `TaxBasis::Cash` في `build()` |
| **الشاشة والتصدير** | `/accounting_reports/tax` و`/accounting_reports/tax/csv` تحت الوحدة `accounting_reports` (بلا صلاحية جديدة)، بالمحاسبة المفعَّلة وإلا 404. الطباعة (زر وفئة `print_hide`)، وCSV بعلامة الترميز (أقسام ونسب وإجماليات، وآخر صف يقول إن الإجمالي للشركة أو لفروع بعينها). الفهرس: بطاقة «تقرير الضرائب» في مجموعة المالية من فهرس التقارير (بجانب تقريرَي الضرائب التشغيليين) لمن يملك `accounting_reports` والمحاسبة مفعَّلة، ورابط من ميزان المراجعة وكشف الحساب |
| **المحاسبة معطَّلة (D1)** | الشاشة والملف يجيبان 404، وتختفي البطاقة من الفهرس والبحث (القاعدة العامة للموضوعات `accounting_*`)، ولا يمرّ المسار القديم بأي كود جديد: **لا تغيير في سلوك OSPOS** (تقارير الضرائب القديمة بحالها) |
| **قراءة فقط** | كل القراءات في لقطة واحدة (`RunsInTransaction`) فلا يظهر عدم تطابق كاذب إن عُدّل مستند أثناء القراءة (اختبار بعدّل سطور مستند من اتصال آخر في منتصف القراءة)؛ لا قيد ولا حركة ولا تدقيق، ولا إقفال فترة يمنع القراءة (اختبار فترة مقفلة). اختبار يلتقط كل استعلامات القراءة فلا يجد كتابة |
| **اللغات** | 46 لغة: ملف `Accounting_tax_reports` جديد (الإنجليزية في `en` و`en-GB`، والعربية الفعلية في `ar-EG` و`ar-LB`، وفراغ في الباقي فتسقط إلى الإنجليزية) ومفتاح الخطأ `error_tax_basis_unavailable` في `Accounting`، مرتبة هجائيًا ومحاذاة بأداة الإضافة وبـ`php-cs-fixer` للملفات الجديدة |
| **الترحيل** | **لا ترحيل** (لم يُستعمل أي ملف في النطاق `202703000000xx`). لا جدول ولا عمود ولا وحدة ولا صلاحية |

## 2. الأدلة

- **مطابقة المرصود (`RA-TX-01` و`RA-TX-02` و`RA-TX-03`):** الاختبار `TaxReportTest::testBuild_ReproducesTheFiguresObservedInTheReferenceSystem` يدخل **المدخلات التي رُصدت** (فاتورة بيع صافيها 2,210 بضريبة 331.50، وإشعار دائن 220 بضريبة 33) عبر نموذج البيع الحقيقي فيُنتج: فواتير البيع 2,210 / 331.50، والمردودات −220 / −33، **والصافي 298.5 وهو رصيد الحساب `215001`** (`parity-plan.md` المعيار 3). **هذا مطابقة مدخلات مُدخَلة عندنا لأرقام دفترة المسجَّلة**، لا مقارنة بمخرجات أُنتجت الآن من دفترة، **ولم أُنشئ ملف تثبيت `T-xx`** (المعرّفات يعيّنها المُدمِج؛ القسم 8)، فلا أدّعي «مطابق ومختبر» بميزان المصفوفة الصارم. **ما لم يُعاد:** أرقام الفترة 27/09 إلى 27/10 (9,222.00 / 1,383.30 …) لأن بياناتها غير عندنا؛ ما أُثبت منها **القاعدة**: الإشارات وربط الضريبة بالحسابين وأخذ الأساس من المستندات بفرق حتى هللة واحدة عن حساب المبيعات (`testBuild_TakesTheBaseFromTheLinesAndAllowsACentAgainstTheSalesAccount`: أساس 10.10 مقابل 10.105 في `411`).
- **الإيداعات على `wp/WP5` (كلها مدفوعة إلى الفرع نفسه، ولا فرع غيره):** `2ad53d1` المكتبة، `b2b86b2` الشاشة والملف والفهرس، `6acc178` اختبارات الأصناف الصغيرة، `03edc28` تشديد الاختبارات وقائمة الحراس، `5bb6bae` الحراس الأربعة غير المكشوفة، وإيداع هذا التقرير.
- **اختبارات جديدة (119 اختبارًا في 7 ملفات، ملف لكل صنف):** `TaxReportTest` (42: المكتبة)، `TaxDocumentsTest` (22: حساب البنود)، `TaxReportResultTest` (11)، `TaxBasisTest` (4)، `TaxAccountFiguresTest` (4)، `TaxSectionTest` (3)، `AccountingTaxReportsControllerTest` (33: الشاشة والملف). وملف مساند `tests/Support/TaxReportFixtureTrait.php`. **وفي ملف قائم** `tests/helpers/report_helperTest.php` اختباران جديدان للفهرس وتعديل اختبار قائم (عدد روابط وحدة `accounting_reports` صار 2 لا 1 وتأكيد غياب رابط الضرائب مع تعطيل المحاسبة).
- **ما تغطيه:** البيع والإشعار الدائن والشراء بضريبة المدخلات والمصروف بضريبة (وتعديله ومال عاد)، والبيع المعكوس (وعكسه في فترة أخرى)، والمسودة والمعاينة والمعلَّق والعرض (لا يدخلون)، وحدود الفترة (اليوم الأول والأخير ويوم قبلهما وبعدهما)، وفروع النطاق (فرع وفرعان والشركة ولا فرع)، وشركة أخرى، والنسب (بند بنسبتين، ومشتريات بنسب)، وخصم البند والخصم على الإجمالي بنسبة ومبلغ (مبيعات ومشتريات)، والضريبة المضمَّنة في السعر، والبند بلا ضريبة، ومستند لا يتفق مع قيده ومستند محذوف نهائيًا، وقيد يدوي (مع عكسه)، وحسابا الضريبة لحساب واحد وحساب موجَّه غير الافتراضي وتوجيه غائب، ولقطة واحدة، وقراءة فقط، وفترة مقفلة، **والمستندات القديمة** (قبل البدء: كل مرشح من مرشحات العدّ، والفترة، والفروع، ومصروف بلا فرع، وبلا تاريخ بدء)، والمحاسبة المعطَّلة، والصلاحية، وروابط الشاشة، وCSV (الأقسام والنسب والخصم والإجماليات والتقييد بالفروع وعدم توسيع نطاق).
- **ربط الدفاتر:** `testBuild_AddsUpToTheSameAsTheTrialBalanceOfTheSameBranchesAndDays` يقارن حركة `215001` و`215002` في التقرير بصفوف **ميزان المراجعة** للنطاق والأيام نفسها، ويجعل `المحصَّلة = حركة 215001` و`المدفوعة = −حركة 215002` و`الصافي = مجموعهما`؛ و`testBuild_AddsUpTheBranchesOfTheScopeAndNoOthers` يربط فرعًا ثانيًا بحسابه.
- **تعطيل الحراس:** عطّلتُ **142 حارسًا** (كل إذن بالوحدة وقصّ بالفروع ومرشح حالة وفترة وشركة وفحص مستند وفرق بين التفعيل والتعطيل وإفصاح عن نص وعدّ) **كلًّا على حدة** بأداة `tools/dev/guard_mutations.py` (القائمة في `wp5-guards.json`): يُطبَّق التعطيل وحده وتُشغَّل ملفات الاختبار المسمّاة له حتى أول فشل ثم يُعاد الملف. **كُشف 142 من 142، ولا حارس غير مكشوف، ولا نص لم يوجد (INVALID)، على الحالة النهائية.** **تاريخ القياس:** الجولة الأولى (137 حارسًا) كشفت 133 وتركت أربعة (`T47` و`T48` و`T54` و`V06`): ثلاثة منها حدّان لعدّ المستندات القديمة في المشتريات والمصروفات لم يختبرهما غير المبيعات، والرابع تحذير الفروع الجزئية كان تأكيده يمرّ بإطار تنبيه آخر في الصفحة نفسها؛ فقُوّيت اختباراتها (القسم 2) وأُضيف خمسة حراس (`T56` و`T57` و`T58` و`D19` و`D20`) لخروج مبكر اتضح أنه **حارس صحة** لا تحسين، ثم **أُعيد قياس القائمة كلها (142) على الحالة النهائية** فكُشفت كلها. وبقيت شجرة العمل نظيفة بعد القياس (`git status`).
- **التنسيق:** شُغّل `php-cs-fixer` (إعداد `.php-cs-fixer.no-header.php`) على كل ملفات `app/` الجديدة (متحكم وعرض و9 ملفات مكتبة) وعلى ملفات اللغة الجديدة (46)، وعلى `Routes.php` و`report_helper.php` و`AccountingError.php` لم أُعد تنسيقهما (تعديل بأسطر قليلة). **لم أشغّله على ملفات الاختبار** (يعيد تسمية دوال الاختبار بحذف الشرطة السفلية فيخالف `test{Method}_{Behavior}`، كما في تقرير WP18).
- **الجولة الأخيرة (ملفات شُغّلت كل واحد وحده على `ospos_test_wp5`، لا المجموعة الكاملة):** **20 ملفًا كلها ناجحة، 2572 اختبارًا، ولا فاشل ولا متخطّى:** `Libraries/Accounting/TaxBasisTest.php` (4)، `Libraries/Accounting/TaxSectionTest.php` (3)، `Libraries/Accounting/TaxAccountFiguresTest.php` (4)، `Libraries/Accounting/TaxReportResultTest.php` (11)، `Libraries/Accounting/TaxDocumentsTest.php` (22)، `Libraries/Accounting/TaxReportTest.php` (42)، `Controllers/AccountingTaxReportsControllerTest.php` (33)، `helpers/report_helperTest.php` (12)، `LanguageFilesTest.php` (1872)، `Libraries/Accounting/AccountingMessageTest.php` (191)، `Controllers/AccountingReportsControllerTest.php` (51)، `Controllers/AccountingInventoryReportsControllerTest.php` (43)، `Controllers/AccountingStatementsControllerTest.php` (67)، `helpers/NavigationHelperTest.php` (16)، `Libraries/Accounting/TrialBalanceTest.php` (37)، `Controllers/ReportsControllerTest.php` (31)، `Controllers/HomeTest.php` (26)، `Libraries/Accounting/IncomeStatementTest.php` (26)، `Libraries/Accounting/BalanceSheetTest.php` (31)، `Libraries/Accounting/InventoryValuationTest.php` (50).

## 3. الحراس

كل حارس أضفتُه في هذه الحزمة، عُطِّل **وحده** بـ`tools/dev/guard_mutations.py` (القائمة في [`wp5-guards.json`](wp5-guards.json)) وشُغّلت اختبارات الصنف الخاص به كاملةً حتى أول فشل. مفتاح الأرقام: `T` المكتبة، `R` النتيجة، `A` حساب الضريبة، `B` الأساس، `D` المستندات، `C` المتحكم، `V` العرض، `H` الفهرس والمسارات.

| الحارس | ما يحميه | النتيجة |
|---|---|---|
| `T01` | the cash basis is refused when the report is built | كُشف |
| `T02` | a draft and a preview journal are not in the books | كُشف |
| `T03` | a journal that was reversed still counts, with its reversal | كُشف |
| `T04` | the branches of the scope only | كُشف |
| `T05` | the journals of this company only | كُشف |
| `T06` | the accounts of tax only | كُشف |
| `T07` | the last day of the period counts and the days after do not | كُشف |
| `T08` | the first day of the period counts and the days before do not | كُشف |
| `T09` | what is owed is credit minus debit | كُشف |
| `T10` | a reversal goes with the type of the document it reverses | كُشف |
| `T11` | a reversal goes with the number of the document it reverses | كُشف |
| `T12` | an expense is sorted by its source | كُشف |
| `T13` | a purchase is sorted through its document | كُشف |
| `T14` | what is not tax of a document is counted apart | كُشف |
| `T15` | the tax of an expense is in what the report counts | كُشف |
| `T16` | an expense that nets to nothing is not a document | كُشف |
| `T17` | the tax of a document that is gone is not tax of a document | كُشف |
| `T18` | the tax of a document is in what the report counts | كُشف |
| `T19` | the base of an expense is its journal less its tax | كُشف |
| `T20` | the base of an expense has the sign of its tax | كُشف |
| `T21` | a document that was reversed counts for nothing | كُشف |
| `T22` | a document whose lines disagree with its journal is told apart | كُشف |
| `T23` | the documents that disagree are counted | كُشف |
| `T24` | the base of a document has the sign of its journal | كُشف |
| `T25` | the base of a rate has the sign of its journal | كُشف |
| `T26` | the tax of a rate has the sign of its journal | كُشف |
| `T27` | the documents of a rate are counted | كُشف |
| `T28` | the documents with a discount on the whole are told apart | كُشف |
| `T29` | the documents with a discount on the whole are counted | كُشف |
| `T30` | the base of the documents with a discount has the sign of its journal | كُشف |
| `T31` | the highest rate comes first | كُشف |
| `T32` | the accounts are in the order of their codes | كُشف |
| `T33` | only the routing of this company is read | كُشف |
| `T34` | a kind of tax with no routed account is told | كُشف |
| `T35` | the books have a start date or none | كُشف |
| `T36` | no document is before the books when there is no start | كُشف |
| `T37` | the documents before the books end the day before they began | كُشف |
| `T38` | a sale before the books counts only when completed | كُشف |
| `T39` | a sale before the books counts only when it is a sale, an invoice or a return | كُشف |
| `T40` | a sale before the books counts in the branches of the scope | كُشف |
| `T41` | a sale before the books counts only when it carries tax | كُشف |
| `T42` | a sale before the books counts from the first day | كُشف |
| `T43` | a sale before the books counts to the last day | كُشف |
| `T44` | a purchase before the books counts only when it brings goods in or sends them back | كُشف |
| `T45` | a purchase before the books counts only when it carries tax | كُشف |
| `T46` | a purchase before the books counts in the branches of the scope | كُشف |
| `T47` | a purchase before the books counts from the first day | كُشف |
| `T48` | a purchase before the books counts to the last day | كُشف |
| `T49` | an expense before the books counts only when it was not deleted | كُشف |
| `T50` | an expense before the books counts only when it carries tax | كُشف |
| `T51` | an expense before the books counts to the last day | كُشف |
| `T52` | an expense before the books counts in the branches of the scope | كُشف |
| `T53` | an expense with no branch belongs to the default branch only | كُشف |
| `T54` | an expense before the books counts from the first day | كُشف |
| `T55` | the report is read in one snapshot | كُشف |
| `T56` | a scope of no branch is not queried | كُشف |
| `T57` | tax with no routed account is not queried | كُشف |
| `T58` | the documents before the books are not counted for a scope of no branch | كُشف |
| `R01` | the tax collected is the tax of the sales parts only | كُشف |
| `R02` | the tax paid is not negative | كُشف |
| `R03` | the base of the tax paid is not negative | كُشف |
| `R04` | what is payable is the tax collected less the tax paid | كُشف |
| `R05` | the report does not tie while a kind of tax has no account | كُشف |
| `R06` | the report does not tie while an account does not | كُشف |
| `R07` | a period reaches before the books only when they have a start | كُشف |
| `R08` | a period that starts on the first day of the books does not reach before them | كُشف |
| `R09` | a part the report does not have is refused | كُشف |
| `R10` | the base and the tax are summed apart | كُشف |
| `A01` | what the accounts hold beyond the report is the book less the report | كُشف |
| `A02` | an account ties only when no journal of another kind reached it | كُشف |
| `A03` | an account ties only when its balance is the report's | كُشف |
| `B01` | a basis that does not exist is refused | كُشف |
| `B02` | blanks around a basis are ignored | كُشف |
| `B03` | no basis is the accrual basis | كُشف |
| `D01` | only sales, invoices and returns have tax figures | كُشف |
| `D02` | only the sales named are read | كُشف |
| `D03` | only purchases that bring goods in or send them back have tax figures | كُشف |
| `D04` | only the purchases named are read | كُشف |
| `D05` | a sale that is a return is in the returns | كُشف |
| `D06` | a purchase that sends goods back is in the returns | كُشف |
| `D07` | only the lines that carry tax are counted | كُشف |
| `D08` | the discount of a line comes off the base | كُشف |
| `D09` | the share of the discount on the whole comes off the base | كُشف |
| `D10` | the tax inside the price comes off the base | كُشف |
| `D11` | the tax of a return is counted without its sign | كُشف |
| `D12` | tax inside the price is told from tax added to it | كُشف |
| `D13` | a rate is a number without trailing zeros | كُشف |
| `D14` | a sale with a share of the discount on the whole is marked | كُشف |
| `D15` | a purchase with a share of the discount on the whole is marked | كُشف |
| `D16` | every chunk of sales is read | كُشف |
| `D17` | every chunk of purchases is read | كُشف |
| `D18` | the decimals of the discount are the ones given | كُشف |
| `D19` | sales that have no tax figures are not queried | كُشف |
| `D20` | purchases that have no tax figures are not queried | كُشف |
| `C01` | the screen is under the module of the financial reports | كُشف |
| `C02` | the screen needs accounting to be on | كُشف |
| `C03` | the screen adds up the branches the employee works in | كُشف |
| `C04` | the file adds up the branches the employee works in | كُشف |
| `C05` | the screen refuses a basis that is not the accrual basis | كُشف |
| `C06` | the file refuses a basis that is not the accrual basis | كُشف |
| `C07` | the file is refused with a status of refusal | كُشف |
| `C08` | a period that is not one falls back to the default period | كُشف |
| `C09` | a refusal is shown in words | كُشف |
| `C10` | the last day is today when none is given | كُشف |
| `C11` | the link to the statement of an account carries the first day | كُشف |
| `C12` | the link to the statement of an account carries the branches asked | كُشف |
| `C13` | the link to the file carries the branches asked | كُشف |
| `C14` | the link to the file carries the days asked | كُشف |
| `C15` | the file says whether its total is the company's | كُشف |
| `C16` | the file has a row for the documents with a discount on the whole | كُشف |
| `C17` | the file says whether the net is payable or refundable | كُشف |
| `C18` | the file has a row for each rate | كُشف |
| `C19` | the file starts with the mark of its encoding | كُشف |
| `C20` | the file is sent as CSV | كُشف |
| `V01` | the cash basis is shown disabled | كُشف |
| `V02` | the basis of partly paid documents is shown disabled | كُشف |
| `V03` | the accrual basis is the one chosen | كُشف |
| `V04` | the name of an account is escaped | كُشف |
| `V05` | the code of an account is escaped | كُشف |
| `V06` | who works in some branches is warned that the total is partial | كُشف |
| `V07` | a scope of no branch is said | كُشف |
| `V08` | the documents before the books are counted on the screen | كُشف |
| `V09` | a period that reaches before the books is warned | كُشف |
| `V10` | the day the books began is said | كُشف |
| `V11` | a missing account of tax collected is said | كُشف |
| `V12` | a missing account of tax paid is said | كُشف |
| `V13` | the documents that disagree with their journals are said | كُشف |
| `V14` | a report that does not tie says so | كُشف |
| `V15` | an account that does not tie is marked | كُشف |
| `V16` | the net says whether it is payable or refundable | كُشف |
| `V17` | the documents with a discount on the whole have their row | كُشف |
| `V18` | the branches are offered only to who reads more than one | كُشف |
| `V19` | the file is offered only with a report | كُشف |
| `V20` | the table of the accounts is shown only with accounts | كُشف |
| `V21` | nothing of the report is drawn without one | كُشف |
| `V22` | the period is shown only with a report | كُشف |
| `V23` | a refusal is shown | كُشف |
| `V24` | whose figures the report holds is said | كُشف |
| `V25` | each rate has its row | كُشف |
| `H01` | the tax report is offered to the module of the financial reports | كُشف |
| `H02` | the route of the screen | كُشف |
| `H03` | the route of the file | كُشف |

بنود قرأتُها حارسةً وأبقيتُها بلا تعطيل لأنها **تحسين لا يغيّر النتيجة**: الخروج المبكر حين تسبق الفترة يوم البدء في `legacyDocuments` (الشرطان `>=` و`<` معًا لا يُرجعان صفًا)، و`array_unique` في `TaxDocuments` (تكرار الرقم في `IN` لا يغيّر شيئًا). **وعلى خلافها** الخروج المبكر لنطاق بلا فرع أو بلا حساب موجَّه وللمستندات التي لا أرقام ضريبة لها هو **حارس صحة** لا تحسين: `whereIn` على مصفوفة فارغة يُنتج `IN ()` استعلامًا غير صالح في هذا الإطار، وقيس تعطيله (`T56` و`T57` و`T58` و`D19` و`D20`). **والفحوص التي ورثتُها ولم أضفها** (404 عند تعطيل المحاسبة والإذن بالوحدة في `AccountingController` و`Secure_Controller`، وقصّ النطاق بفروع الموظف في `ReportScope`/`ScopedReportRequest`) تغطّيها اختبارات الصنف الجديد حارسةً عبر الحراس `C01` إلى `C04`.

**لا قفل ولا معاملة كتابة هنا:** التقرير قراءة فقط داخل معاملة واحدة (لقطة، حارس `T55`). لا سجل تدقيق لأنه لا يغيّر شيئًا (كميزان المراجعة). والإقفال (`PeriodLocks`) والمفتاح الفريد وعكس القيد وإعادة الترحيل **لا تنطبق على قراءة** (اختبار الفترة المقفلة يثبت أن القراءة لا تتأثر والاختبار «لا يكتب شيئًا» يثبت ألّا كتابة).

## 4. ما افترضتُه

1. **الأساس من بنود المستندات لا من حساب المبيعات `411`** (توصية الرصد `RA-TX-03`: 9,222.00 في تقرير دفترة مقابل 9,222.01 في `411`، والفرق أن الإيراد عندنا الباقي بعد تقريب إجمالي المدين (E14))؛ **الفرق حتى هللة واحدة لكل مستند مقبول** وتقوله الشاشة. **بند قرار للمالك** (القسم 6) لأن الجرد سمّاه «قرارًا مطلوبًا».
2. **الأساس الخاضع = البنود التي عليها ضريبة فقط.** بنود بلا ضريبة (معفاة) في مستند ضرائبه على غيرها لا تدخل أساسًا. وهذا يترك **قسم «غير الخاضع» (`RA-TX-05`) غير منفَّذ** لأن مستندًا لا ضريبة فيه لا سطر له في حسابي الضريبة (القسم 5).
3. **إشارات الأقسام** كما رُصدت في دفترة (`RA-TX-01`): المبيعات موجبة ومردوداتها وفواتير الشراء والمصروفات سالبة ومردودات المشتريات موجبة، والإجمالي الكلي = **مجموع الأقسام**. **أقسام «Sales Debit Note» و«Purchases Credit Note» لا مقابل لها عندنا** (لا مستند بهذا الاسم): مردودات المشتريات عندنا تجمع المرتجع والإشعار المدين كما رُصد في دفترة.
4. **الحسابان من التوجيه اليوم** (`tax.output` و`tax.input`)، فقيد رُحِّل على حساب كان موجَّهًا ثم غُيِّر توجيهه **لا يظهر** (لا إعادة كتابة لقيد قائم). **بند للمالك** (القسم 6): هل يُمنع تغيير حساب ضريبة عليه قيود؟ (`TaxInput` غير قابل للتحرير في شاشة التوجيه اليوم، و`TaxOutput` قابل.)
5. **ما في حسابي الضريبة من غير مستند** (قيد يدوي أو افتتاحي أو تسوية أو مستند حُذف نهائيًا من الجداول) **لا يدخل في الصافي** بل يُعرض بجانبه ويُوسم «مختلف»؛ **لم يُلاحَظ في دفترة** ما يفعله تقرير الضرائب بقيد يدوي على الحسابين (افتراض).
6. **الفترة الافتراضية من بدء الدفاتر إلى اليوم** (دفترة: من شهر إلى اليوم) لتتطابق أرقام الشاشة مع رصيد الحساب افتراضيًا ولتتسق مع ميزان المراجعة وقائمة الدخل. **بند للمالك.**
7. **القيد المعكوس يُحسب بتاريخه هو في قسم مستنده الأصلي** (فعكس مستند في فترة تالية يظهر سالبًا فيها وموجبًا في فترة المستند؛ والفترتان معًا صفر). وعمود «المستندات» يعدّ كل مستند صافي أثره على الضريبة غير صفر مرة واحدة (فالمستند المعكوس لا يُعدّ).
8. **بند بأكثر من ضريبة يُحسب في صف كل نسبة من نسبه** (والأساس مرة واحدة في القسم)، فقد يزيد مجموع الصفوف على القسم؛ والشاشة تقول ذلك. وتُجمع الضرائب بنسبتها لا باسمها (ضريبتان باسمين مختلفين ونسبة واحدة صف واحد).
9. **المصروف بلا نسبة ولا بنود:** أساسه المبلغ مطروحًا منه الضريبة (من القيد، فيصحّ لتعديلاته وعكوسه)؛ ومال عاد من مصروف يظهر **موجبًا** في قسم المصروفات خفضًا للضريبة المدفوعة.
10. **المستند المحفوظ والمحاسبة مطفأة بعد تاريخ البدء** لا قيد له فلا يدخل ولا يُعدّ (عدّ «قبل البدء» بالتاريخ وحده). حدّ معروف (القسم 5).
11. **مصروف بلا فرع (التاريخي) للفرع الافتراضي** في عدّ المستندات القديمة (قاعدة WP3).
12. **الصلاحية بوحدة `accounting_reports`** كميزان المراجعة وقائمة الدخل والميزانية وتقرير المخزون (والمحتوى للفروع التي يعمل فيها الموظف)، **بلا صلاحية جديدة**: الاختيار المحافظ ألا أستحدث صلاحية لا يملكها أحد فيُحجب التقرير عمّن يرى ميزان المراجعة. (بند للمالك في القسم 6.)
13. **الطباعة:** تخطيط الشاشة نفسه بإخفاء المرشحات والأزرار؛ بلا ترويسة طباعة (بند P8 المفتوح).
14. **CSV بلا حماية معادلات الجداول:** كل خلاياه أرقام أو ترجمات من ملفات اللغة، لا نص مستخدم فيه (على خلاف ملف المخزون الذي فيه أسماء أصناف)، فلا حاجة إلى الحماية.

## 5. ما بقي

- **قسم «غير الخاضع للضريبة»** (`RA-TX-05`: خيار «إظهار البنود غير الخاضعة للضريبة» بالأقسام السبعة نفسها): يتطلب قراءة قيود المبيعات والمشتريات والمصروفات نفسها لا سطور الضريبة، لأن المستند بلا ضريبة لا يمسّ حسابها. **بند جديد `WP5-NON-TAXED`.**
- **التقرير المفصل** (`RA-TX-06`: صف لكل بند من كل مستند برقمه وطرفه ورقمه الضريبي وتاريخه، وتجميع «فاتورة»): لم يُنفَّذ؛ الأقسام والنسب فقط. **بند جديد `WP5-DETAIL`.**
- **الأساس النقدي** («مدفوعة بالكامل» و«مدفوعة جزئيًا»، `RA-TX-04`): معطَّل عمدًا؛ يحتاج `WP20` وقرارًا في جانب الشراء (ثابت في الأنواع الثلاثة في دفترة).
- **الإقرار الضريبي** (`REP-20`، `RA-TX-07`: أربعة جداول بعمود التعديل) **وتسوية الإقرار** (`RA-TX-08`، قيد التسوية غير مرصود): `G-98`، لم يبدأ.
- **مستندات محفوظة والمحاسبة مطفأة بعد يوم البدء** (لا قيد لها): لا تدخل التقرير ولا تُعدّ؛ ويعالجها الترحيل الرجعي (A6) وهو غير منفَّذ. **بند جديد `WP5-UNPOSTED-AFTER-START`** (عدّها بـ`NOT EXISTS` على القيود).
- **العملة** (الجميع / SAR): عملة أساسية واحدة (`G-138`).
- **PDF وExcel وترويسة الطباعة:** CSV وحده (بند P8 المفتوح `E46` البند 4).
- **فحص المتصفح** (محور الوصول، فيض الأفقي، الهاتف، العربية) لم يُجرَ: لا قاعدة `ospos_ui` لهذا الوكيل. الشاشة تستعمل فئات الواجهة نفسها لميزان المراجعة وتقرير المخزون، فاحتمال المخالفات منخفض لكنه **غير مثبت**. **بند `WP5-BROWSER-PASS`.**
- **لم تُشغَّل المجموعة الكاملة** (نحو ساعة؛ تشاركها وكلاء آخرون): شُغّلت الملفات الجديدة والمتغيرة وملفات الاختبار ذات الصلة (القسم 2)، ويشغّلها المُدمِج.
- **توحيد كتابة CSV:** تقرير المخزون وهذا التقرير يكتبان CSV كلٌّ بدالته الخاصة (دالة صغيرة)؛ توحيدهما في صنف مساعد مؤجَّل لأنه يعدّل ملفًا قائمًا بلا حاجة هنا.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرار المالك**
1. **أساس المبيعات:** من بنود المستندات كما في دفترة (الافتراض، فرق حتى هللة عن `411`) أم من `411`؟
2. **الفترة الافتراضية:** من بدء الدفاتر (الافتراض) أم من أول الشهر كما في دفترة؟
3. **قيد على حسابي الضريبة من غير مستند** (تسوية الضريبة للهيئة مثلًا): يبقى بجانب التقرير لا في الصافي (الافتراض)، أم يُحتسب «مدفوعًا للهيئة» في الإقرار حين يُبنى (`G-98`)؟
4. **تغيير توجيه حساب الضريبة** وعليه قيود: يُسمح كما اليوم (والتقرير يقرأ الحساب الموجَّه اليوم) أم يُمنع؟
5. **صلاحية مستقلة** لتقرير الضرائب أم `accounting_reports` (الافتراض)؟
6. **عدّ المستندات غير المرحَّلة بعد يوم البدء** (محفوظة والمحاسبة مطفأة): يُنفَّذ العدّ (`WP5-UNPOSTED-AFTER-START`) أم يُنتظر الترحيل الرجعي؟

**رصد دفترة (قراءة فقط عند توفر بيانات الدخول)**
1. **الأساس النقدي:** سلوك «مدفوعة بالكامل» و«مدفوعة جزئيًا» (`RA-TX-04`) لجانب الشراء والمصروفات ومردودات المشتريات، وهل يقسّم الدفعة الجزئية على الفاتورة (`WP20`، `R-02`).
2. **قيد تسوية الإقرار** (`RA-TX-08` و`OO-03`): ما يرحّله `settle_tax_declaration` (غير مرصود).
3. **ما يفعله تقرير الضرائب بقيد يدوي على `215001` أو `215002`:** هل يدخل في أي قسم؟
4. **قسم «Non Taxed»** (`RA-TX-05`): قاعدة تصنيف المستند ذي البنود المختلطة (بنود خاضعة وأخرى لا).

## 7. قبل النشر

**لا ترحيل في هذه الحزمة**: لا جدول ولا عمود ولا وحدة ولا صلاحية جديدة، فلا شيء يُنفَّذ عند أول دخول ولا يتغير قيد ولا رصيد. تظهر الشاشة (`/accounting_reports/tax`) لمن يملك الوحدة `accounting_reports` أصلًا والمحاسبة مفعَّلة، وتظهر بطاقتها في فهرس التقارير وروابطها في ميزان المراجعة وكشف الحساب. **قبل الاعتماد على التقرير في قاعدة حقيقية:** (1) افتحه لفترة كاملة وقارن «ما تحتفظ به حسابات الضريبة»: يجب أن يتطابق الحسابان أو أن يُفسَّر الفرق بقيود يدوية أو افتتاحية أو تسوية؛ (2) تأكد أن `tax.output` و`tax.input` موجَّهان إلى الحسابين المقصودين في شاشة التوجيه (يقول التقرير إن حسابًا غير موجَّه)؛ (3) تذكّر أن **ما قبل تاريخ بدء الدفاتر** لا يظهر (وتعدّه الشاشة)، وأن تقارير الضرائب التشغيلية القديمة تبقى كما هي لما قبله. التراجع بحذف الملفات الجديدة وسطرَي المسار وأسطر الربط الثلاثة (البطاقة في `report_helper.php` ورابطَي الشاشتين) ومفتاح اللغة (لا بيانات تتغير).

## 8. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار بعد E67 ومعرّفات `T-xx`):

**decisions.md** (صف جديد، رقمه E-التالي):

> | E-؟؟ | **تقرير الضرائب من الدفاتر (WP5: `REP-07` بأساس الاستحقاق، جزء من `G-99`)** (2026-10-28، [تقرير WP5](daftra-parity/wp5-report.md)): **قرار تنفيذي أُخذ تحت E47 على اقتراح مؤلف الحزمة، وللمالك حق نقضه.** تقرير `/accounting_reports/tax` (وملف CSV) تحت وحدة `accounting_reports` القائمة (بلا وحدة ولا ترحيل ولا صلاحية جديدة) **يقرأ القيود المرحَّلة والمعكوسة لحسابي `tax.output` و`tax.input` (`215001` و`215002`) لا جداول المبيعات**، فيطابق ميزان المراجعة للفروع والأيام نفسها، بخمسة أقسام بإشارات دفترة (فواتير البيع +، مردودات المبيعات −، فواتير الشراء −، مردودات المشتريات +، المصروفات −) والصافي = المحصَّلة − المدفوعة. **الأساس والنسبة من بنود المستند الذي رُحِّل قيده** (بعد خصم البند وحصته من خصم الإجمالي ودون الضريبة المضمَّنة، وللمصروف قيده − ضريبته) **لا من `411`** (فرق حتى هللة عن حساب المبيعات مقبول ومعروض)، **والضريبة من القيد**. **ما في الحسابين من غير مستند (يدوي أو افتتاحي أو تسوية) لا يدخل الصافي ويُعرض بجانبه بوسم «مختلف»**، والمستند الذي لا تتفق بنوده مع قيده يبقى بضريبته ويُترك أساسه ويُعدّ. نطاق الفروع (E45) ووسم «جزئي»، **والمستندات المؤرَّخة قبل بدء الدفاتر لا تدخل وتقول الشاشة ذلك وتعدّها**. **الأساس النقدي معطَّل بملاحظة وطلبه يُرفض بخطأ مسمّى `tax_basis_unavailable` ولا يُتجاهل** (يحتاج `WP20`). **افتراضات للمراجعة:** الفترة الافتراضية من بدء الدفاتر، والصلاحية بـ`accounting_reports`، والحسابان من التوجيه اليوم. **لا قسم «غير الخاضع» ولا تقرير مفصّل ولا إقرار (`G-98`) ولا ترحيل ولا كتابة في دفترة ولا دمج في `master` ولا نشر.** **أسئلة مفتوحة للمالك:** القسم 6 من التقرير. |

**conformance-matrix.md** (تعديل الصف `REP-07`، **الحالة تبقى «مختلف ويحتاج تعديلًا» والإجمالي 223 بلا تغيير** لأن الأقسام غير المنفَّذة تُبقيه مختلفًا):

> خانة «الأول برو» الجديدة: **تقرير الضرائب من الدفاتر `/accounting_reports/tax` (WP5):** يقرأ قيود `215001` و`215002` المرحَّلة لا جداول المبيعات، بخمسة أقسام بإشارات دفترة (فواتير البيع، مردودات المبيعات، فواتير الشراء، مردودات المشتريات، المصروفات) وصافي مستحق أو مسترد، وأساس ونسبة من بنود المستند (فرق حتى هللة عن `411`، `RA-TX-03`)، ومرشح الفترة والفروع (E45)، **وأساس الاستحقاق وحده: «المدفوعة بالكامل/جزئيًا» معطَّلان وطلبهما يُرفض**. **غير منفَّذ:** قسم «Non Taxed» والتقرير المفصل وتجميع «فاتورة» (`RA-TX-05`/`06`)، والأساس النقدي (`WP20`)، والإقرار وتسويته (`REP-20`). تبقى التقارير التشغيلية القديمة (`Reports::summary_taxes`، `summary_sales_taxes`) كما هي. **مطابقة مختبرة جزئيًا:** 331.50 على 2,210 و−33 على −220 والصافي 298.5 تُنتَج من المدخلات نفسها (`TaxReportTest`)؛ **لا ملف تثبيت `T-xx` بعد** (يُنشأ بمعرّف يعيّنه المُدمِج إن أراده المالك).
>
> ويُضاف صف جديد في جدول القرائن إن لزم: معيار الربط 3 في `parity-plan.md` (رصيد `215001` = ضريبة المبيعات − ضريبة المردودات في تقرير الضرائب) **صار اختبارًا دائمًا** (`TaxReportTest::testBuild_ReproducesTheFiguresObservedInTheReferenceSystem` و`testBuild_AddsUpToTheSameAsTheTrialBalanceOfTheSameBranchesAndDays`).

**gap-report.md** (تحديث):

> `G-17` (البند «تقرير الضرائب (`REP-07`)» من المتبقي للتنفيذ): **نُفِّذ أساس الاستحقاق في WP5 (2026-10-28)**، ويبقى من `G-17`: الربح والخسارة (`REP-05`) والتدفقات (`REP-06` و`SET-11`) والإقرار والتسوية (`REP-20`) والحركات المالية (`REP-19`) وتقارير القيود والدليل (`REP-08`) وتصدير التقارير (`REP-22`). **`G-99`** (تقرير الضرائب من الدفتر بأقسامه السبعة): **جزئي**: خمسة من السبعة، ولا «Debit Note» للمبيعات ولا «Credit Note» للمشتريات (لا مستند)، والأساس من المستندات (قرار المالك مطلوب)؛ المتبقي: قسم «غير الخاضع» والتقرير المفصل. **`G-98`** (الإقرار بجداوله الأربعة وقيود تسوية الإقرار): **مفتوح** كما هو.

**backlog.md** (حالات):

> `REP-07` تبقى `partial` (الاستحقاق منفَّذ)، و`G-99` تصير `partial`، و`G-98` تبقى `open`. تُضاف بنود جديدة بحزمة `WP5` (أو حزمة لاحقة يحددها المُدمِج): `WP5-NON-TAXED` (قسم «غير الخاضع»، متوسط)، `WP5-DETAIL` (التقرير المفصل وتجميع «فاتورة»، متوسط)، `WP5-UNPOSTED-AFTER-START` (عدّ المستندات المحفوظة والمحاسبة مطفأة بعد يوم البدء، منخفض)، `WP5-BROWSER-PASS` (فحص المتصفح، `WP45`، منخفض). وتُعلَّم `WP5` «شريحة الاستحقاق منفَّذة» ويبقى ما يخصّ المرشح النقدي والإقرار في `WP20` و`G-98`. **لا حزمة تعتمد على `WP5` فلا اعتماد يُشطب.**

**docs/accounting/README.md** (سطر في الفهرس): تقرير WP5: تقرير الضرائب من الدفاتر [`wp5-report.md`](daftra-parity/wp5-report.md) (2026-10-28، E-؟؟).

**docs/ui-redesign/deploy-checklist.md** (بند مقترح):

- [ ] **تقرير الضرائب من الدفاتر (WP5):** **بلا ترحيل ولا جدول ولا عمود ولا صلاحية ولا وحدة**، فلا يتغير قيد ولا رصيد ولا مستند. بعد النشر: (1) تظهر الشاشة `/accounting_reports/tax` لمن يملك وحدة `accounting_reports` والمحاسبة مفعَّلة، وبطاقتها في فهرس التقارير (مجموعة المالية) ورابطها في ميزان المراجعة وكشف الحساب. (2) **افتح التقرير لفترة كاملة** وتأكد أن جدول «ما تحتفظ به حسابات الضريبة» يقول «متطابق» أو أن الفرق مفسَّر بقيود يدوية/افتتاحية/تسوية؛ وأن `tax.output` و`tax.input` موجَّهان في شاشة التوجيه (وإلا قالت الشاشة إن حسابًا غير موجَّه). (3) **المستندات قبل يوم بدء الدفاتر لا تظهر** (تعدّها الشاشة)، وتقارير الضرائب التشغيلية القديمة (`reports_taxes`) تبقى لما قبله. (4) **الأساس النقدي غير متاح** (يُرفض طلبه). التراجع بحذف الملفات الجديدة وإزالة سطرَي المسار وأسطر الربط الثلاثة ومفتاح اللغة؛ لا بيانات تتغير.
