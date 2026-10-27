# تقرير WP1: إغلاق ثغرات الضبط (الصلاحيات الحساسة، فروع شاشات المخزون والتقارير القديمة، وجهة التحويل، أقفال العملاء والموردين)

> الحالة (2026-10-27): **نُفِّذ على الفرع `wp/WP1`** (نسخة عمل مستقلة من `f5d4927`) بالتفويض التنفيذي الشامل (E47). خمسة بنود ضبط في الكود تحقّقتُ من كلٍّ منها بقراءة الشيفرة قبل تعديلها، ثم أثبتُّ كل حارس باختبار يفشل حين يُعطَّل (القسم 2). **لا شيكات، ولا `E33` ولا `G-57`، ولا كتابة في دفترة، ولا ترحيل جديد في قاعدة البيانات (لا ملف في النطاق `202611100000xx`)، ولا دمج في `master`، ولا نشر.** لا قيد ولا رصيد ولا مستند مرحَّل ولا قفل فترة يُعدَّل بهذه الحزمة؛ والمحاسبة معطَّلة أو مفعَّلة تعملان كما كانتا (D1) ما عدا الرفض الجديد في الحالات الموصوفة. ولم أكتب عبارة «يطابق دفترة» لأي بند: هذه ضوابط في الأول برو نفسه ولا مقابل لها في المصفوفة المرصودة.

## 1. ما نُفِّذ

| البند | ما كان في الكود | ما صار |
|---|---|---|
| **`P6-SENSITIVE-PERMS`** صلاحيتا الخزائن ودفعات الموردين | `AccountingGuard::SENSITIVE` يسرد خمس صلاحيات فقط (الإعدادات وإقفال الفترات وإعادة فتحها والأرصدة الافتتاحية والتوجيه)، فتبقى `acc_treasuries_manage` (إنشاء الخزائن والتحويل بينها) و`acc_payables_manage` (دفعات الموردين والأرصدة الافتتاحية) خارج القائمة: يمنحهما أو يسحبهما محرر موظفين غير مدير، وتدخلان في «تحديد الكل»، ولا يُسجَّلان في سجل المنح الحساس | **القائمة سبع صلاحيات بعد الإضافة** (`TREASURIES_MANAGE` و`PAYABLES_MANAGE`). فلا يمنحهما ولا يسحبهما إلا المدير، **ولا يحفظ محرر غير مدير نموذجًا يُسقط ما يحمله الموظف منهما** (يحتفظ الحفظ بما يحمله الموظف من الحساسة)، ولا «تحديد الكل» يمنحهما، ويُسجَّل كل منح وسحب في سجل التدقيق (`SensitivePermissions::recordChange`). **لم أمسّ `Employees.php`**: منطقه كان صحيحًا ويقرأ القائمة نفسها، فالإصلاح مصدره واحد. أُضيف اسما الصلاحيتين إلى أسماء شاشة الإعداد (`AccountingSetup::PERMISSION_LABELS`) ونصّ `sensitive_note` في نموذج الموظف (إنجليزي وإنجليزي بريطاني وعربي مصري وعربي لبناني) |
| **`A2A-STOCK-ACCESS`** شاشات الأصناف وتسوية المخزون | شاشات الأصناف (تغيير الكمية بالجرد السريع، حفظ نموذج الصنف، استيراد CSV، حذف الصنف) تقبل أي موقع مخزون بصرف النظر عن فروع الموظف، فيستطيع موظف فرع «أ» تحريك مخزون موقع في فرع «ب» وترحيل قيد تسوية في فرع لا يعمل فيه (قاعدة الفروع مطبّقة في البيع والاستلام وحدهما) | **القاعدة نفسها في البيع والاستلام (منحة الموقع على وحدة الأصناف **و**موقع في فرع يعمل فيه الموظف):** (1) `Items::itemLocations()` تعيد `get_allowed_locations('items', true)` وتُستعمل في قائمة الأصناف وقالب الاستيراد وفحص ترويسة CSV وفحص كل صف (عمود موقع فرع آخر لا يُقبل فيُرفض الصف ولا يُحفظ شيء). (2) `postSaveInventory` يرفض الموقع غير المسموح برسالة `Items.stock_location_not_allowed` **ولا يكتب صف جرد ولا قيدًا**، مع المحاسبة مفعّلة أو معطّلة. (3) `postSave` يتجاهل كمية موقع غير مسموح **ولا يصفّر مخزون فرع آخر** حين لا يعرضه النموذج. (4) نماذج الصنف والجرد والتفاصيل تعرض مواقع الموظف المسموحة وحدها، وتعود الشاشة إلى موقع مسموح لو كان الموقع المحفوظ في الجلسة خارج فروعه، ومن لا موقع له في فروعه يُحوَّل إلى صفحة «لا صلاحية». (5) **حذف الصنف** (`delete` و`delete_list`) يُرفض بلا أثر إن كان للصنف مخزون غير صفري في فرع لا يعمل فيه الموظف، لأن الحذف يُخرج المخزون من كل رف؛ وموظف كل الفروع والنظام نفسه بلا قيد. (6) **حارس في الخدمة لا في الشاشة وحدها:** `StockAdjustment::post` يطلب `BranchGuard::requireBranch` لفرع الموقع، فيُرفض قيد التسوية (`BranchException`) أيًّا كان المنادي، والقيد يحمل فرع الموقع ويطابق `StockLedger::reconcile` حساب المخزون الفرعي `1203xxx` |
| **`G-48`** التقارير القديمة والفروع | نماذج `app/Models/Reports` وشاشات `Reports` تقرأ كل الفروع لأي موظف يحمل صلاحية التقرير؛ ولوحة البداية تخبّئ أرقامها بمفتاح واحد للجميع | **كل نموذج تقرير يقرأ المبيعات أو الاستلامات أو المخزون يقيّد صفوفه بفروع الموظف المسجَّل** (`Report::branchIds` من `BranchGuard::allowedBranchIds`): لا فرع يعني لا شيء (`1 = 0`)، وكل الفروع والنظام (بلا موظف) بلا قيد. **المغطّى:** ملخصات المبيعات والفئات والعملاء والموردين والأصناف والموظفين والمدفوعات والخصومات والضرائب وضرائب المبيعات (عبر `Summary_report` وحدها أو مباشرة)، والتفصيلي للمبيعات وللأصناف وللاستلامات، وتقارير العميل والخصم والموظف والمورد، ومخزون الأصناف والأصناف تحت حد الطلب (بفرع موقع المخزون). والجدولان المؤقتان `Sale::create_temp_table` و`Receiving::create_temp_table` يقبلان `branch_ids` (استلام التحويل بين فرعين يُرى لموظفي أيٍّ من الفرعين كما في G-41). **صفوف الفرع الافتراضي القديمة** (مبيعات سبقت وجود الفروع) تبقى ظاهرة لموظف الفرع الافتراضي. **تقرير المصروفات للشركة كلها فقط** (لا فرع للمصروف): يُرفض بصفحة «لا صلاحية» لمن لا يعمل في كل الفروع ويعيد نموذجه فارغًا، وتُخفى بطاقة المصروفات من لوحة البداية. **التفاصيل بالمعرّف** (`detail_sales` و`detail_receivings`) تعيد 404 لمستند فرع آخر. **القوائم المنسدلة** لمواقع المخزون في شاشات التقارير تعرض مواقع فروع الموظف وحدها. **الوسم:** رأس التقرير (`reports/_head.php`) يقول **«فروع محدودة (…)»** تحذيرًا لمن يقرأ بعض الفروع أكثر من فرع، و«الشركة كلها» لمن يقرأ كلها، ولا يظهر لمن يقرأ فرعًا واحدًا، بالصياغة نفسها التي اعتمدتها P8 (E45) لميزان المراجعة (مفاتيح `Accounting_reports.scope_*` أعيد استعمالها ولم يُضَف مفتاح). **ذاكرة لوحة البداية** صار مفتاحها يحمل فروع الموظف فلا يرى موظف رقمَ فرع آخر |
| **`G41-A5`** وجهة التحويل | `postChangeMode` يفحص منحة موقع **المصدر** وحده ويحفظ موقع **الوجهة** كما جاء (التحويل أو طلب التوريد داخل الفرع يرسل مخزونًا إلى موقع لا يملك الموظف منحته) | الوجهة تُفحص بمنحة وحدة الاستلام **وبفرع الموظف** (`Receivings::destinationAllowed`) عند تغيير الوضع، فيُرفض بلا تغيير في السلة برسالة `Receivings.location_not_allowed`، ويُحفظ رقمها وحده (`(int)`) لا النص الوارد؛ وتُفحص **المنحتان مرة أخرى عند «إنهاء طلب التوريد»** (`postRequisitionComplete`) فمن سُحبت منحته بين الخطوتين لا يمرّ. (أُنجز هذا البند أولًا في الإيداع `45861e3` من جلسة موازية، وراجعتُه وأعدتُ تشغيل اختباراته وعطّلتُ حرّاسه الخمسة) |
| **`P5-LOCK-POOLING`** أقفال العميل والمورد | `CustomerLock` و`SupplierLock` يطلبان `GET_LOCK` ويعدّان `1` نجاحًا. خلف مجمّع اتصالات أو وكيل (`pgbouncer` بنمط المعاملة أو ProxySQL) قد يُمنح القفل لاتصال غير الذي ينفّذ العمل، أو يتشارك عميلان اتصالًا واحدًا، **فتعمل عمليتان معًا ويُنفَق الرصيد الدائن مرتين بصمت** | صنف جديد `NamedLock` يعدّ القفل **مأخوذًا فقط إذا قال الخادم إن هذا الاتصال نفسه يملكه** (`IS_USED_LOCK(name) = CONNECTION_ID()`) بعد `GET_LOCK` ولا يكون الاتصال قد حمله قبل أن يطلبه (قفل «محتل» يعني اتصالًا مشتركًا). والنتيجة `LockOutcome` (`Taken` أو `Busy` أو `NotHeld` أو `Occupied`)؛ **ما عدا `Taken` يرفض العملية** (`CustomerBusy` أو `SupplierBusy` بسياق `lock`) فتُغلق على الفشل بدل أن تعمل بلا حماية، ويُسجَّل `critical` في السجل. ما أُخذ دون أن يمسكه الاتصال **يُفكّ فورًا** فلا يبقى مثبّتًا. وإعادة أخذ الاسم نفسه من العملية نفسها (طلبات متداخلة) تُعدّ بسجل ثابت فلا تُحسب «اتصالًا مشتركًا»، ويفك آخر `release` القفل. **وأمر تشغيل جديد `php spark accounting:check_locks`** يجرّب قفلًا لا يطلبه أحد ويقول هل يحفظ الاتصال الأقفال المسماة (يخرج بـ `EXIT_ERROR` وبرسالة صريحة إن لم يحفظ) ليُشغَّل قبل النشر وبعده في بيئة فيها مجمّع |

## 2. الأدلة

- **اختبارات جديدة (165 دالة اختبار مضافة في 35 ملف اختبار: 22 ملفًا جديدًا و13 موجودًا؛ العدد مقيس بعدّ دوال `test*` في الرأس مطروحًا منها ما كان عند `f5d4927`؛ ملف واحد لكل صنف تحت الاختبار):** `SensitivePermissionsTest` (2) و`EmployeesControllerTest` (4: المدير يمنح الصلاحيتين ويسحبهما ويُسجَّل الأمران، ولا يمنحهما غير المدير ولا يسحبهما، وحفظ غير المدير يبقي ما يحمله الموظف منهما، و«تحديد الكل» لا يمنحهما) و`AccountingSetupControllerTest` (1)؛ `ItemsControllerTest` (19: الجرد السريع والنموذج والاستيراد والحذف وقوائم المواقع وصفحة «لا صلاحية» ومسار `stock_location` في العنوان لموقع فرع آخر وتسجيل قيد في فرع لا يعمل فيه الموظف دون استثناء، مع المحاسبة معطّلة ومفعّلة، وموظف فرع واحد وكل الفروع) و`ItemTest` (14: حذف صنف وقائمة أصناف لموظف فرع واحد ولمن لا فرع له ولكل الفروع، ولا يمنع الحذفَ رفٌّ فارغ ولا موقع محذوف في فرع آخر، ورفض سابق لا يمتد إلى الحذف التالي) و`StockAdjustmentTest` (5: رفض موظف خارج الفرع ولا يُرحَّل شيء، ورفض من لا فرع له، وقيد الفرع الصحيح مع مطابقة المخزون، والفرع الافتراضي، والنظام نفسه بلا قيد)؛ `ReportsControllerTest` (20: صفحات التقارير والوسم وتفاصيل المستند بالمعرّف وشاشات الإدخال الست لمواقع فروع الموظف وحدها وتقرير المصروفات للشركة كلها، كل تقرير منه في اختبار مستقل) وواحد وعشرون ملفًا تحت `tests/Models/Reports` (70 دالة: نموذج لكل تقرير: يرى موظف فرع «أ» صفوفه وحدها، وموظف «ب» صفوفه، وموظف الفرعين وكل الفروع والنظام الكل، ومن لا فرع له **لا شيء**، وصف الفرع الافتراضي القديم)، و`ReportScopeTest` (5: الوسم) و`Dashboard_libTest` (3: مفتاح الذاكرة والمصروفات)؛ `ReceivingsControllerTest` (5: وجهة التحويل وطلب التوريد، من الإيداع الموازي)؛ `NamedLockTest` (8: يؤخذ ويُفك، «مشغول»، «محتل قبل الطلب»، «مأخوذ على اتصال آخر» بمحاكاة مجمّع، إعادة الأخذ مرتين، والمجسّ في الحالتين) و`CustomerLockTest` (3) و`SupplierLockTest` (4) و`AccountingCheckLocksTest` (2). **محاكي المجمّع** في `tests/Support/PooledConnection.php` و`PooledResult.php` يوجّه أمر القفل إلى اتصال والفحص إلى اتصال آخر.
- **اختبارات الحدود والتجاوز:** لا فرع (`[]`) ≠ كل الفروع (`null`) في كل نموذج؛ النظام بلا موظف غير مقيَّد؛ المحاسبة معطّلة لا تعطّل الرفض في شاشات الأصناف؛ وجهة بمنحة وبلا منحة وبمنحة في فرع آخر؛ ملف CSV بعمود موقع فرع آخر لا يحفظ شيئًا؛ حذف صنف بمخزون في فرعين لموظف فرع واحد لا يغيّر شيئًا؛ حذف صنف مخزونه كله في فرع الموظف يمرّ ويُرحَّل فيه.
- **تعطيل الحراس:** عطّلتُ **كل حارس على حدة** بسكربت `mutate_generic.py` وشغّلتُ اختبارات كلٍّ منها: **101 حارسًا** (8 للصلاحيات الحساسة، و26 لشاشات الأصناف وخدمة التسوية، و5 لوجهة التحويل، و8 للأقفال، و54 للتقارير القديمة ولوحة البداية؛ والتعداد مشتق من فروق `app/` بين `f5d4927` والرأس، ولم يبقَ ملف تغيّر فيه حارس بلا تعطيل له)، **فظهر فشل أو خطأ أو توقف في اختبارات كلٍّ منها (101 من 101)**، في 11 دفعة (عشرة حراس أو أقل في كل دفعة) تلخيص كلٍّ منها في الملحق أدناه كما طُبع. وأظهر القياس في الدفعات من السادسة إلى التاسعة (وهي التي شغّلتُها في الجلسة الأخيرة، أما الدفعات الخمس الأولى فسجّلتها جلسة سابقة كما طُبعت) **اثني عشر حارسًا لم تلتقطه الاختبارات في أول تشغيل** (`G48-23` و`G48-27` و`G48-29` و`A2A-13` و`A2A-14` و`A2A-16` و`A2A-18` إلى `A2A-23`)، فأضفتُ لكلٍّ منها اختبارًا ثم أعدتُ تشغيل الدفعة كلها وصارت 10 من 10؛ وكان `G48-23` مخفيًّا بخلل في الاختبار نفسه (ترويسة `Location` باقية من طلب سابق تُرضي `assertRedirect()`)، فصار الاختبار يتحقق من رمز الحالة 302. ولم أُبقِ شيئًا معطّلًا.
- **ملفات الاختبار التي شغّلتُها (بلا المجموعة الكاملة بطلب التكليف)** على قاعدة `ospos_test_a` وحدها، بعد آخر تعديل في الاختبارات والشيفرة: **30 تشغيلًا (مجلد `tests/Models/Reports` كاملًا و29 ملفًا)، 2478 اختبارًا و8623 تأكيدًا، كلها ناجحة** (الصيغة `OK` من PHPUnit في كل سطر، ولا فشل ولا خطأ ولا اختبار متجاوَز). المجموعة الكاملة يشغّلها المُدمِج بعد الدمج.

```
tests/Models/Reports: 71 tests, 873 assertions
tests/Controllers/ItemsControllerTest.php: 84 tests, 579 assertions
tests/Controllers/ReceivingsControllerTest.php: 78 tests, 666 assertions
tests/Controllers/ReportsControllerTest.php: 25 tests, 287 assertions
tests/Controllers/EmployeesControllerTest.php: 42 tests, 214 assertions
tests/Controllers/AccountingSetupControllerTest.php: 23 tests, 129 assertions
tests/Libraries/Accounting/NamedLockTest.php: 8 tests, 18 assertions
tests/Libraries/Accounting/CustomerLockTest.php: 10 tests, 55 assertions
tests/Libraries/Accounting/SupplierLockTest.php: 17 tests, 122 assertions
tests/Libraries/Accounting/StockAdjustmentTest.php: 15 tests, 136 assertions
tests/Libraries/Accounting/SensitivePermissionsTest.php: 9 tests, 55 assertions
tests/Libraries/Accounting/ReportScopeTest.php: 17 tests, 36 assertions
tests/Libraries/Accounting/AccountingGuardTest.php: 4 tests, 12 assertions
tests/Libraries/Accounting/CreditSettlementTest.php: 18 tests, 169 assertions
tests/Libraries/Accounting/SupplierSettlementTest.php: 19 tests, 241 assertions
tests/Libraries/Accounting/SupplierAdvancesTest.php: 26 tests, 237 assertions
tests/Libraries/Dashboard_libTest.php: 9 tests, 58 assertions
tests/Commands/AccountingCheckLocksTest.php: 2 tests, 4 assertions
tests/Models/ItemTest.php: 30 tests, 284 assertions
tests/Models/ReceivingTest.php: 12 tests, 75 assertions
tests/Models/SaleTest.php: 18 tests, 87 assertions
tests/Models/Stock_locationTest.php: 11 tests, 35 assertions
tests/Models/ItemQuantityTest.php: 3 tests, 7 assertions
tests/Controllers/SalesControllerTest.php: 97 tests, 592 assertions
tests/Controllers/HomeTest.php: 22 tests, 55 assertions
tests/Controllers/AccountingReportsControllerTest.php: 49 tests, 278 assertions
tests/Controllers/AccountingTreasuriesControllerTest.php: 46 tests, 367 assertions
tests/Controllers/AccountingPayablesControllerTest.php: 30 tests, 250 assertions
tests/LanguageFilesTest.php: 1680 tests, 2692 assertions
tests/helpers/report_helperTest.php: 3 tests, 10 assertions
```

## 3. ما افترضتُه (بنود قرار للمالك)

1. **تقرير المصروفات للشركة كلها فقط.** المصروف لا يحمل فرعًا، فلا يصح أن يرى موظف بعض الفروع رقمًا عن الشركة كلها كأنه رقم فروعه. اخترتُ الرفض (صفحة «لا صلاحية») على عرض رقم جزئي مضلِّل. إن أردتَ تقرير مصروفات لكل فرع فيلزم أولًا فرع للمصروف (WP3).
2. **حذف الصنف ذي المخزون في فرع آخر يُرفض كله** على أن يُحذف ما في فروعه ويبقى الباقي، لأن الحذف عملية على الصنف لا على الموقع، وإبقاء الصنف محذوفًا مع مخزون حيّ في فرع آخر يكسر المخزون.
3. **موظف لا موقع له في فروعه يُحوَّل إلى «لا صلاحية»** في شاشة الأصناف بدل أن يرى قائمة فارغة.
4. **رفض المجمّع يستعمل رسالتي «مشغول» نفسيهما** (`CustomerBusy` و`SupplierBusy`) والفرق في سياق الخطأ (`lock = not_held` أو `occupied`) والسجل `critical`، فلا أضيف نوع خطأ ولا تغيّر شاشة. إن أردتَ رسالة بمعنى «اتصال قاعدة البيانات لا يحفظ الأقفال» صريحة للمستخدم فهو مفتاح لغة وخطأ جديدان.
5. **وسم التقارير يعيد استعمال مفاتيح P8** (`Accounting_reports.scope_*`) ولا يضيف مفاتيح جديدة؛ ويظهر الوسم في رأس كل تقرير يستعمل `reports/_head.php`.
6. **كشوف العملاء والموردين والتقارير المالية (P8)** لم أمسّها: لها نطاقها الخاص من P8/E45.

## 4. حدود معروفة وما لم يُنفَّذ

- **ملفا اختبار قديمان لا يعملان أصلًا:** `tests/Models/Reports/Summary_discounts_test.php` و`Summary_taxes_test.php` (لاحقة `_test.php` لا يجمعها PHPUnit، وإن شُغّلا صراحةً فشلا على الأساس: الأول يفترض أن رمز العملة يُهرَّب بدالة غير موجودة، والثاني يفشل بمفتاح أجنبي في بذرته). **لم أصلحهما ولم أحذفهما** (خارج الحزمة)، وجعلتُ اختباراتي في `Summary_discountsTest.php` و`Summary_taxesTest.php` المجموعين. للمُدمِج أن يدمجهما أو يحذف القديمين.
- **شاشات المبيعات والاستلام وسجل البيع** لم تتغير (قاعدة الفروع مطبّقة فيها منذ A3a وP4).
- **تقارير الأصناف (`Summary_items`) تتقيد ببيع الفرع** (مبيعاتها)، أما **كمية الصنف في الرفوف** فتقيّدها تقارير المخزون بفرع الموقع.
- **تنبيه للاختبارات الموجودة:** في بيئة `FeatureTestTrait` تبقى ترويسة `Location` من طلب سابق في العملية نفسها، فيُرضي `assertRedirect()` طلبًا لم يُحوَّل أصلًا (هكذا اختفى غياب الحارس `G48-23`). اختباراتي تتحقق من رمز الحالة 302 حيث يلزم إثبات الرفض. ثلاثة اختبارات أقدم في `ReportsControllerTest` (`testNonAdminWithoutReportGrantIsDeniedSummaryCustomers` وأختاها) تستعمل `assertRedirect()` وحدها ولم أغيّرها لأنها خارج الحزمة؛ وقد تُرضيها الترويسة الباقية إن زال الحارس الذي تحميه بعد اختبار سبقها بتحويل في العملية نفسها (لم أقس ذلك لها، ولم تدخل في الحراس المعطَّلة).
- **لا يمنع القفل المسمّى بحد ذاته الوصول لقاعدة بيانات بلا قفل** (مجمّع يوزّع الاتصالات): الحارس يكشف ويرفض، ولا يصلح التوزيع نفسه. الحل التشغيلي أن يتصل التطبيق بقاعدة البيانات مباشرة أو بمجمّع بنمط الجلسة.
- **سجل الصلاحيات الحساسة للصلاحيتين الجديدتين** يبدأ من الحفظ التالي لكل موظف؛ من يحملهما اليوم لا يُسجَّل لهما منح سابق، ويبقيان له (لا يُسحب شيء).

## 5. قبل النشر

**لا ترحيل في هذه الحزمة** ولا جدول ولا عمود. أثرها عند التشغيل: (1) موظف غير مدير كان يمنح صلاحيتي الخزائن أو دفعات الموردين لا يستطيع بعد الآن، ويبقى ما مُنح لغيره كما هو. (2) موظف مقيَّد بفرع يفقد رؤية مواقع المخزون وبيانات التقارير القديمة لبقية الفروع، ومن لا مواقع له في فروعه يرى «لا صلاحية» في الأصناف. (3) في بيئة فيها مجمّع اتصالات شغّل `php spark accounting:check_locks`: إن أعاد خطأ فتعديلات العملاء والموردين تُرفض إلى أن يتصل التطبيق بقاعدة البيانات دون مجمّع يشارك الاتصالات.

## 6. تحديثات الوثائق المشتركة للمُدمِج

لم أعدّل أيًّا من `decisions.md` و`conformance-matrix.md` و`gap-report.md` و`backlog.md` ولا أي `README` ولا `deploy-checklist.md`. فيما يلي النصوص جاهزة للصق.

### 6.1 `decisions.md` (نص مقترح بلا رقم E)

> **ضوابط الصلاحيات والفروع والأقفال (WP1، 2026-10-27):** صلاحيتا `acc_treasuries_manage` و`acc_payables_manage` **حساستان** كالخمس التي سبقتهما (فالقائمة سبع): لا تُمنحان تلقائيًا ولا بـ«تحديد الكل»، ولا يمنحهما أو يسحبهما إلا المدير، وحفظ غير المدير يُبقي ما يحمله الموظف منهما، ويُسجَّل كل منح وسحب. **شاشات الأصناف (الجرد السريع، حفظ النموذج، استيراد CSV، حذف الصنف) وخدمة تسوية المخزون** تطبّق قاعدة البيع والاستلام: لا يغيّر الموظف مخزون موقع إلا بمنحة وحدة الأصناف على الموقع **وبأن يعمل في فرعه**، ولا يُرحَّل قيد تسوية في فرع لا يعمل فيه (حارس في الخدمة لا في الشاشة وحدها)، ولا يُحذف صنف له مخزون في فرع لا يعمل فيه. **التقارير التشغيلية القديمة** (المبيعات والاستلام والمخزون والخصومات والضرائب ولوحة البداية) تُقيَّد بفروع الموظف: لا فرع = لا شيء، كل الفروع والنظام بلا قيد، ويقول رأس التقرير «فروع محدودة» أو «الشركة كلها» بحسب القاعدة نفسها لميزان المراجعة (E24/E45)؛ **وتقرير المصروفات للشركة كلها فقط** (لا فرع للمصروف) فيُرفض لمن لا يعمل في كل الفروع. **وجهة طلب التوريد والتحويل** تُفحص بمنحة الاستلام وبفرع الموظف عند تغيير الوضع وعند الإنهاء. **أقفال العميل والمورد المسماة** لا تُعدّ مأخوذة إلا إذا أكّد الخادم أن الاتصال الذي ينفّذ العمل هو الذي يملكها، وإلا **رُفضت العملية** (إغلاق على الفشل) بدل العمل بلا حماية من الإنفاق المزدوج، ويفحص `accounting:check_locks` بيئة التشغيل. لا ترحيل ولا قيد ولا رصيد يتغير.

### 6.2 `conformance-matrix.md` (صفوف مقترحة)

يُغيَّر صفّا `ACC-28` و`INV-09` ويُضاف صفان في الصيغة نفسها (المعرّف | الموضوع | دفترة | الأول برو | الحالة | ملاحظة):

| المعرّف | الموضوع | دفترة | الأول برو | الحالة | ملاحظة |
|---|---|---|---|---|---|
| ACC-28 (تعديل خانة الأول برو) | صلاحيات الحسابات | 13 صلاحية: القيود (كل/الخاصة، مسودة، حذف)، حسابات القيود، مراكز التكلفة، الفترات المقفلة، الأصول | ثماني صلاحيات `acc_*` وحساسة **وصلاحيتا الخزائن ودفعات الموردين حساستان أيضًا (WP1)**، فالحساسة سبع: لا يمنحها ولا يسحبها غير المدير، وحفظ غير المدير يُبقي ما يحمله الموظف منها، ويُسجَّل كل تغيير. ضابط في الأول برو لا مقابل مرصودًا له في دفترة | مختلف ويحتاج تعديلًا | addI |
| INV-09 (إضافة إلى ملاحظة الصف) | مستودع وخزينة افتراضيان للموظف | (دون تغيير) | مواقع الموظف بالصلاحيات **وبفروعه** (WP1): الأصناف والتقارير والاستلام تقبل موقعًا بمنحة الوحدة وفي فرع يعمل فيه الموظف وحده | مختلف ويحتاج تعديلًا | setC. القاعدة ضابط محلي؛ سلوك الموظف المقيَّد في دفترة (`S-32`، مصفوفة الأدوار `R-04`) غير مرصود، فلا ادّعاء تطابق |
| REP-WP1 (صف جديد، معرّف مؤقت يضع المُدمِج غيره) | تقييد التقارير التشغيلية بفرع الموظف | لم يُرصد | **تقارير الأول برو القديمة تقرأ فروع الموظف وحدها** (WP1): لا فرع = لا شيء، كل الفروع بلا قيد، والتفاصيل بالمعرّف 404 لمستند فرع آخر، ووسم «فروع محدودة/الشركة كلها»، ولوحة البداية بمفتاح ذاكرة لكل مجموعة فروع، والمصروفات للشركة كلها فقط | غير قابل للتحقق بعد | ضابط محلي لا مقابل مرصودًا له، فلا يُحتسب في «مطابق» ولا «مختلف» ولا يغيّر الإجمالي (149)؛ يحتاج WP20 لقراءة سلوك الموظف المقيَّد |
| LOCK-WP1 (صف جديد، معرّف مؤقت) | الحماية من الإنفاق المزدوج لرصيد العميل والمورد | لم يُرصد | **القفل المسمّى لا يُعدّ مأخوذًا إلا إذا ثبت أن الاتصال الذي ينفّذ يملكه، وإلا رُفضت العملية** (WP1)، وأمر `accounting:check_locks` للتشغيل | غير قابل للتحقق بعد | ضابط هندسي لا يخص مقارنة دفترة، ولا يغيّر الإجمالي (149) |

### 6.3 `gap-report.md` (تحديث صفوف)

يُحذف الصف `G-48` من جدول المفتوح ويُنقل إلى المُنجَز بهذا النص، ويُضاف إلى قسم ما أُغلق:

| المعرّف | الوصف | الأدلة | متى | الحجم |
|---|---|---|---|---|
| `G-48` | **تقارير الاستلام وفرع الموظف — أُغلقت 2026-10-27 (WP1):** شمل تقييد الفرع الآن **كل نماذج `app/Models/Reports` وشاشات `Reports`** لا تقارير الاستلام وحدها: المبيعات والاستلام والمخزون والخصومات والضرائب وضرائب المبيعات وتفاصيل المستند بالمعرّف ولوحة البداية، بالقاعدة نفسها (لا فرع = لا شيء، كل الفروع بلا قيد) ووسم «فروع محدودة/الشركة كلها». **والمصروفات للشركة كلها فقط** (لا فرع للمصروف) | `Models/Reports/*Test` و`ReportsControllerTest` و`Dashboard_libTest` | WP1 | — |
| `A2A-STOCK-ACCESS` و`G41-A5` و`P5-LOCK-POOLING` و`P6-SENSITIVE-PERMS` | أُغلقت في WP1 بالنصوص في القسم 1 من [wp1-report.md](wp1-report.md) | الاختبارات في القسم 2 | WP1 | — |

### 6.4 `backlog.md` (حالات)

صفوف قسم البنود: `P6-SENSITIVE-PERMS` و`A2A-STOCK-ACCESS` و`G-48` و`G41-A5` و`P5-LOCK-POOLING` تصير الحالة **`done (WP1)`**، ويُعلَّم `WP1` منجزًا في جدول الحزم ويُسقط من اعتماد `WP2` و`WP3` و`WP7` و`WP11` و`WP17` و`WP18` و`WP30` و`WP32` شرطُه. ويُضاف إلى `WP3` (المصروفات) ملاحظة: **المصروف بلا فرع اليوم فتقريره للشركة كلها فقط**؛ إن رُحِّل المصروف بفرع فيُفتح للموظف المقيَّد.

### 6.5 `docs/ui-redesign/deploy-checklist.md` (بند مقترح)

- [ ] **ضوابط الصلاحيات والفروع والأقفال (WP1):** **بلا ترحيل ولا جدول ولا عمود**، فلا يتغير قيد ولا رصيد ولا مستند. بعد النشر: (1) صلاحيتا `acc_treasuries_manage` و`acc_payables_manage` صارتا حساستين: من يحملهما يبقيهما، ولا يمنحهما أو يسحبهما بعد الآن إلا المدير (راجع من يحملهما في شاشة الإعداد). (2) **موظف مقيَّد بفرع** لا يغيّر مخزون موقع فرع آخر من شاشات الأصناف ولا يحذف صنفًا له مخزون هناك، ويرى في التقارير القديمة ولوحة البداية أرقام فروعه وحدها، **وتقرير المصروفات ولوحة المصروفات للشركة كلها فقط (موظف كل الفروع)**؛ تأكد أن الموظفين الذين تتوقع أن يروا الشركة كلها يعملون في كل الفروع. (3) **شغّل `php spark accounting:check_locks` على كل بيئة**: يجب أن يقول «The connection to the database keeps named locks». إن أخطأ فبيئتك فيها مجمّع/وكيل اتصالات يشارك الاتصالات، وتُرفض تعديلات العملاء والموردين (إغلاق على الفشل) إلى أن تُصحَّح.

## ملحق: نتائج تعطيل الحراس (قياس فعلي بـ mutate_generic.py)

كل سطر حارس عُطِّل وحده وشُغِّلت اختباراته (`DETECTED` تعني أن اختبارًا فشل أو توقف). النتائج كما طُبعت، مقسومة على دفعات من عشرة حراس أو أقل.

### الدفعة 1

```
DETECTED  P6-1 treasuries permission is sensitive: Tests: 9, Assertions: 47, Failures: 3.; failing: 3
DETECTED  P6-2 payables permission is sensitive: Tests: 9, Assertions: 48, Failures: 3.; failing: 3
DETECTED  P6-3 treasuries permission is sensitive (employee form): Tests: 42, Assertions: 187, Failures: 4.; failing: 4
DETECTED  P6-4 payables permission is sensitive (employee form): Tests: 42, Assertions: 203, Failures: 4.; failing: 4
DETECTED  P6-5 non-administrator cannot hand out a sensitive grant: Tests: 42, Assertions: 201, Failures: 4.; failing: 4
DETECTED  P6-6 non-administrator save keeps the sensitive grants held: Tests: 42, Assertions: 210, Failures: 2.; failing: 2
DETECTED  A2A-1 item locations are limited to the branches of the employee: Tests: 81, Assertions: 545, Errors: 1, Failures: 1.; failing: 2
DETECTED  A2A-2 quantity change refused in a location outside the branches: Tests: 81, Assertions: 544, Failures: 3.; failing: 3
DETECTED  A2A-3 item form saves only the quantities of allowed locations: Tests: 81, Assertions: 544, Errors: 1, Failures: 1.; failing: 2
DETECTED  A2A-4 import header check uses the allowed locations: Tests: 81, Assertions: 541, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 2

```
DETECTED  A2A-5 import row check uses the allowed locations: Tests: 81, Assertions: 541, Failures: 1.; failing: 1
DETECTED  A2A-6 item list offers only the allowed locations: Tests: 81, Assertions: 549, Errors: 1.; failing: 1
DETECTED  A2A-7 no access when the employee holds no location of the branches: Tests: 81, Assertions: 549, Errors: 1.; failing: 1
DETECTED  A2A-8 item form lists only the allowed locations: Tests: 81, Assertions: 540, Failures: 1.; failing: 1
DETECTED  A2A-8b inventory form lists only the allowed locations: Tests: 81, Assertions: 544, Failures: 1.; failing: 1
DETECTED  A2A-8c count form lists only the allowed locations: Tests: 81, Assertions: 547, Failures: 1.; failing: 1
DETECTED  A2A-9 an item with stock outside the branches cannot be deleted: Tests: 21, Assertions: 178, Failures: 1.; failing: 1
DETECTED  A2A-10 a list of items with stock outside the branches cannot be deleted: Tests: 21, Assertions: 177, Failures: 1.; failing: 1
DETECTED  A2A-10b a list of items with stock outside the branches cannot be deleted (screen): Tests: 6, Assertions: 72, Failures: 1.; failing: 1
9 of 9 guards detected
```

### الدفعة 3

```
DETECTED  A2A-11 the saved location of the screen is checked against the branches: Tests: 81, Assertions: 551, Failures: 1.; failing: 1
DETECTED  A2A-12 the journal of a stock adjustment needs the branch of its location: Tests: 15, Assertions: 133, Failures: 2.; failing: 2
DETECTED  G41-A5-1 the destination of a requisition is checked when the mode changes: Tests: 78, Assertions: 661, Failures: 3.; failing: 3
DETECTED  G41-A5-2 the destination is checked against the grants and the branches: Tests: 78, Assertions: 661, Failures: 3.; failing: 3
DETECTED  G41-A5-3 the destination is checked again when the requisition is completed: Tests: 78, Assertions: 661, Failures: 2.; failing: 2
DETECTED  P5-LOCK-1 a lock the connection held before asking is refused: Tests: 8, Assertions: 16, Failures: 2.; failing: 2
DETECTED  P5-LOCK-2 a lock taken on another connection is refused: Tests: 8, Assertions: 11, Failures: 4.; failing: 4
DETECTED  P5-LOCK-3 a refused lock is let go of: Tests: 8, Assertions: 12, Failures: 3.; failing: 3
DETECTED  P5-LOCK-4 a name taken again is counted: Tests: 8, Assertions: 17, Failures: 1.; failing: 1
DETECTED  P5-LOCK-5 the probe reports what the lock gave: Tests: 8, Assertions: 18, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 4

```
DETECTED  P5-LOCK-6 the customer is refused when the lock is not held: Tests: 10, Assertions: 47, Failures: 2.; failing: 2
DETECTED  P5-LOCK-7 the supplier is refused when the lock is not held: Tests: 17, Assertions: 110, Failures: 3.; failing: 3
DETECTED  P5-LOCK-8 the check command fails when locks are not kept: Tests: 2, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G48-1 limitToBranches limits the query: Tests: 8, Assertions: 38, Failures: 2.; failing: 2
DETECTED  G48-2 limitToBranches refuses everything to an employee of no branch: Tests: 8, Assertions: 38, Failures: 1.; failing: 1
DETECTED  G48-3 branchCondition limits a hand-written query: Tests: 8, Assertions: 32, Failures: 1.; failing: 1
DETECTED  G48-4 branchCondition refuses everything to an employee of no branch: Tests: 8, Assertions: 35, Failures: 1.; failing: 1
DETECTED  G48-5 limitedInputs carries the branches: Tests: 8, Assertions: 35, Failures: 1.; failing: 1
DETECTED  G48-6 the table aliases of the query are put back: Tests: 8, Assertions: 37, Failures: 1.; failing: 1
DETECTED  G48-7 summary reports limit their sales: Tests: 4, Assertions: 37, Failures: 3.; failing: 3
10 of 10 guards detected
```

### الدفعة 5

```
DETECTED  G48-9 summary of taxes limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-10 summary of sales taxes limits its sales (summary): Tests: 2, Assertions: 31, Failures: 1.; failing: 1
DETECTED  G48-11 summary of sales taxes limits its sales (rows): Tests: 2, Assertions: 27, Failures: 1.; failing: 1
DETECTED  G48-12 expenses report shows nothing to an employee of some branches (rows): Tests: 2, Assertions: 23, Failures: 1.; failing: 1
DETECTED  G48-13 expenses report shows nothing to an employee of some branches (summary): Tests: 2, Assertions: 26, Failures: 1.; failing: 1
DETECTED  G48-14 stock of locations of other branches is left out of the inventory summary: Tests: 4, Assertions: 43, Failures: 4.; failing: 4
DETECTED  G48-15 items running low in other branches are left out: Tests: 3, Assertions: 32, Failures: 2.; failing: 2
DETECTED  G48-16 Detailed_sales builds its table from the limited inputs: Tests: 4, Assertions: 43, Failures: 4.; failing: 4
DETECTED  G48-16 Detailed_item_sales builds its table from the limited inputs: Tests: 3, Assertions: 34, Failures: 3.; failing: 3
DETECTED  G48-16 Specific_customer builds its table from the limited inputs: Tests: 3, Assertions: 41, Failures: 2.; failing: 2
10 of 10 guards detected
```

### الدفعة 6

```
DETECTED  G48-16 Specific_discount builds its table from the limited inputs: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-16 Specific_employee builds its table from the limited inputs: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-16 Specific_supplier builds its table from the limited inputs: Tests: 3, Assertions: 34, Failures: 2.; failing: 2
DETECTED  G48-17 Detailed_receivings builds its table from the limited inputs: Tests: 5, Assertions: 61, Failures: 5.; failing: 5
DETECTED  G48-18 the table of sales keeps only the branches: Tests: 4, Assertions: 43, Failures: 4.; failing: 4
DETECTED  G48-19 the table of sales refuses everything to an employee of no branch: Tests: 4, Assertions: 56, Failures: 2.; failing: 2
DETECTED  G48-20 the table of receivings keeps only the branches: Tests: 5, Assertions: 61, Failures: 5.; failing: 5
DETECTED  G48-21 the table of receivings refuses everything to an employee of no branch: Tests: 5, Assertions: 74, Failures: 2.; failing: 2
DETECTED  G48-22 the expenses report is refused to an employee of some branches (page): Tests: 20, Assertions: 216, Failures: 1.; failing: 1
DETECTED  G48-23 the expenses report is refused to an employee of some branches (graph): Tests: 20, Assertions: 216, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 7

```
DETECTED  G48-24 the company-wide check looks at the branches of the employee: Tests: 25, Assertions: 285, Failures: 2.; failing: 2
DETECTED  G48-25 a sale of another branch is not found: Tests: 25, Assertions: 287, Failures: 1.; failing: 1
DETECTED  G48-26 a receiving of another branch is not found: Tests: 25, Assertions: 287, Failures: 1.; failing: 1
DETECTED  G48-27 the inventory screen lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-28 the date screen of the summary reports lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-29 the screen of receivings by date lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-30 the report page says whose figures it holds: Tests: 25, Assertions: 278, Failures: 1.; failing: 1
DETECTED  G48-31 the report scope reads the branches of the employee: Tests: 25, Assertions: 279, Failures: 2.; failing: 2
DETECTED  G48-32 the label tells a partial scope from the company: Tests: 17, Assertions: 35, Failures: 1.; failing: 1
DETECTED  G48-33 the dashboard hides expenses from an employee of some branches: Tests: 9, Assertions: 54, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 8

```
DETECTED  G48-34 the dashboard cache is kept by branches: Tests: 9, Assertions: 57, Failures: 2.; failing: 2
DETECTED  P6-7 the setup screen names who holds the treasuries permission: Tests: 23, Assertions: 119, Errors: 2.; failing: 2
DETECTED  P6-8 the setup screen names who holds the payables permission: Tests: 23, Assertions: 119, Errors: 2.; failing: 2
DETECTED  A2A-13 the location of the URL is checked against the allowed locations: Tests: 84, Assertions: 578, Failures: 1.; failing: 1
DETECTED  A2A-14 a refused journal of the screen is reported, not thrown: Tests: 84, Assertions: 576, Errors: 1.; failing: 1
DETECTED  A2A-15 the refusal of a delete is explained to the employee: Tests: 84, Assertions: 574, Failures: 1.; failing: 1
DETECTED  A2A-16 a refused journal of a delete is reported, not thrown: Tests: 26, Assertions: 235, Errors: 1.; failing: 1
DETECTED  A2A-17 an employee of every branch is not limited when deleting: Tests: 26, Assertions: 208, Errors: 6.; failing: 6
DETECTED  A2A-18 stock inside the branches of the employee does not block a delete: Tests: 26, Assertions: 230, Failures: 2.; failing: 2
DETECTED  A2A-19 an employee of no branch cannot delete an item with stock: Tests: 26, Assertions: 230, Errors: 2.; failing: 2
10 of 10 guards detected
```

### الدفعة 9

```
DETECTED  A2A-20 an empty shelf of another branch does not block a delete: Tests: 30, Assertions: 282, Failures: 1.; failing: 1
DETECTED  A2A-21 a deleted location of another branch does not block a delete: Tests: 30, Assertions: 282, Failures: 1.; failing: 1
DETECTED  A2A-22 the refusal of an earlier delete is not carried to the next one: Tests: 30, Assertions: 284, Failures: 1.; failing: 1
DETECTED  A2A-23 the refusal of an earlier list delete is not carried to the next one: Tests: 30, Assertions: 284, Failures: 1.; failing: 1
DETECTED  G41-A5-4 the destination is kept as a number: Tests: 78, Assertions: 666, Failures: 1.; failing: 1
DETECTED  G41-A5-5 the refusal of a destination is told to the employee: Tests: 78, Assertions: 663, Failures: 2.; failing: 2
DETECTED  G48-35 the system with nobody logged in is not limited: Tests: 8, Assertions: 38, Failures: 1.; failing: 1
DETECTED  G48-7 Summary_categories limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-7 Summary_customers limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-7 Summary_discounts limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
10 of 10 guards detected
```

### الدفعة 10

```
DETECTED  G48-7 Summary_employees limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-7 Summary_items limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-7 Summary_payments limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-7 Summary_suppliers limits its sales: Tests: 3, Assertions: 31, Failures: 2.; failing: 2
DETECTED  G48-28b the discounts screen lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-28c the screen of detailed sales lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-28d the screen of sales by item lists the locations of the branches: Tests: 25, Assertions: 283, Failures: 1.; failing: 1
DETECTED  G48-36 a single branch is not told whose figures a report holds: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
DETECTED  G48-37 the label of the whole company: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
DETECTED  G48-38 the label of the selected branches: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 11

```
DETECTED  G48-39 a partial scope is shown as a warning: Tests: 25, Assertions: 279, Failures: 1.; failing: 1
DETECTED  G48-40 a transfer is seen by the branch that receives it: Tests: 5, Assertions: 71, Failures: 1.; failing: 1
2 of 2 guards detected
```

### الدفعة 12

```
DETECTED  FIX-1a the form of an item cannot mark it deleted when it has stock outside the branches: Tests: 92, Assertions: 671, Failures: 2.; failing: 2
DETECTED  FIX-1b an item that is already deleted can still be edited: Tests: 92, Assertions: 678, Failures: 1.; failing: 1
DETECTED  FIX-1c an item that is not marked deleted can still be edited: Tests: 92, Assertions: 679, Failures: 1.; failing: 1
DETECTED  FIX-2a the count details screen lists the log of the allowed locations only: Tests: 92, Assertions: 676, Failures: 1.; failing: 1
DETECTED  FIX-2b an empty list of locations matches nothing: Tests: 4, Assertions: 3, Errors: 1.; failing: 1
DETECTED  FIX-2c the log is limited to the location given: Tests: 4, Assertions: 4, Failures: 1.; failing: 1
DETECTED  FIX-3a a new item gets an empty shelf in every location when it is saved: Tests: 92, Assertions: 674, Failures: 1.; failing: 1
DETECTED  FIX-3b a new item of the CSV import gets an empty shelf in every location: Tests: 92, Assertions: 671, Failures: 1.; failing: 1
DETECTED  FIX-3c the shelves that exist are left as they are: Tests: 4, Assertions: 7, Errors: 1.; failing: 1
DETECTED  FIX-3d a deleted location gets no shelf: Tests: 4, Assertions: 13, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 13

```
DETECTED  FIX-4 an employee with no usable location is sent to the no-access page by every items action: Tests: 3, Assertions: 10, Failures: 1.; failing: 1
DETECTED  FIX-5a the prefill of the cash-up reads every branch: Tests: 3, Assertions: 26, Failures: 1.; failing: 1
DETECTED  FIX-5b a read of the whole company is not limited to the branches: Tests: 10, Assertions: 45, Failures: 1.; failing: 1
DETECTED  FIX-5c the reads that follow a read of the whole company are limited again: Tests: 10, Assertions: 46, Failures: 2.; failing: 2
DETECTED  FIX-6a the refusal of the expenses report says it concerns the whole company: Tests: 26, Assertions: 300, Failures: 2.; failing: 2
DETECTED  FIX-6b the refusal of the expenses report is a 403: Tests: 26, Assertions: 298, Failures: 2.; failing: 2
DETECTED  FIX-6c the listing shows the error it is given: Tests: 26, Assertions: 300, Failures: 2.; failing: 2
DETECTED  FIX-6d the expenses reports are left out for an employee of some branches: Tests: 8, Assertions: 28, Failures: 1.; failing: 1
DETECTED  FIX-6e the list of reports leaves the expenses reports out: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  FIX-6f the search index leaves the expenses reports out: Tests: 2, Assertions: 4, Failures: 1.; failing: 1
10 of 10 guards detected
```
