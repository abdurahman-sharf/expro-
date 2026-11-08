# تقرير WP42: الوحدات المتعددة والحزم والتتبع بالرقم المسلسل والدفعة وتاريخ الصلاحية

> الحالة (2026-11-08): **نُفِّذ على الفرع `wp/WP42`** بتفويض المالك التنفيذي الشامل (E47): قوالب وحدات، وأسطر مبيعات ومشتريات وأذون مخزن تُدخَل بوحدة أكبر وتُخزَّن بالوحدة الأساسية، وحزم على الأطقم القائمة، وتتبع بالرقم المسلسل وبالدفعة مع تاريخ صلاحية، وثلاثة خيارات للمبيع الذي يُنزل المخزون عن الصفر. **كل شيء خلف إعدادات مطفأة افتراضيًا** والصلاحيات الثلاث الجديدة **غير ممنوحة لأحد**، والمحاسبة المطفأة = OSPOS كما هو (D1). **لا كتابة في دفترة، ولا دمج في `master`، ولا نشر، ولا طلب سحب.** سلوك دفترة هنا **مرصود إعدادات ونماذج فقط** (انظر القسم 4): كل ما يخص التحويل وتكلفة الحزمة واختيار الدفعة **تصميمنا المحافظ وافتراضات موثّقة** لا ادعاء تطابق.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الترحيل** `20273100000000_StockUnitsAndTracking` (إضافي وآمن للتكرار، مصنَّف HEAVY في `MigrationChecks::SENSITIVE`) | ثمانية جداول جديدة: `unit_templates` و`unit_template_units` و`item_stock_profiles` و`item_unit_barcodes` و`stock_serials` و`stock_batches` و`stock_batch_quantities` و`stock_tracking_lines`. وأعمدة قابلة للفراغ على `sales_items` و`receivings_items` و`stock_permit_lines` (`unit_id` و`unit_factor` و`unit_quantity` وسعر أو تكلفة الوحدة المُدخَلة) **مع قيد `CHECK` في القاعدة** يُبقي الكمية الأساسية = الكمية المُدخَلة × المعامل. ووحدة `stock_units` وثلاث صلاحيات (`stk_units_manage` و`stk_tracking_manage` و`stk_batch_manual_pick`) **لا تُمنح لأحد**. لا صف قائم يُعاد كتابته: **صنف قديم = وحدة واحدة وبلا تتبع**. `down()` يرفض إن وُجد سطر بوحدة أو سجل تتبع |
| **الإعدادات** (`StockOptions`) | `stock_units_enabled` و`stock_bundles_enabled` و`stock_tracking_enabled` **مطفأة**، و`stock_expired_sale_policy` (افتراضها **رفض**)، و`negative_stock_option` (افتراضها **`allow`** أي سلوك اليوم). تغييرها لمن يملك صلاحية إعدادات المحاسبة، ويُكتب في سجل التدقيق بقيمته قبل |
| **قوالب الوحدات** (`UnitTemplates`) | شكل النموذج المرصود (FRM-R50): اسم الوحدة الأساسية وتمييزها (حتى 25 حرفًا) واسم القالب ونشط وصفوف وحدات أكبر (اسم ومعامل وتمييز). **المعامل عدد موجب بأربع منازل عشرية على الأكثر.** **لا يتغير معامل ولا وحدة قالب استعمله سطر مستند قط**: التعديل يُرفض باسمه (`unit_template_used`) ويُحفظ التغيير **نسخةً جديدة** (`newVersion`: الإصدار التالي بالاسم نفسه ويُطفأ القديم، وتبقى أسطره تشير إلى وحداتها). يتغير اسم القالب ونشاطه بعد الاستعمال. **خيار «تحديث كل التكاليف» المخفي في دفترة (`update_all_costs`) لم يُرصد له أثر ولم يُبنَ، ونُصّ على ذلك في الشاشة** |
| **الصنف** (`ItemStockProfile`) | قالب اختياري ونوع تتبع (بلا، رقم مسلسل، دفعة) في جدول جانبي: **لا ALTER على `items`**. **لا يتغير نوع التتبع إلا والصنف بلا رف ولا رقم مسلسل متوفر ولا دفعة** (`tracking_change_with_stock`)، لأن مخزونًا لا يفسّره رقم ولا دفعة يكسر قاعدة المساواة |
| **باركود لكل وحدة** (`UnitBarcodes`) | باركود فريد لوحدة (أو للأساسية) لا يطابق رقم أي صنف ولا باركود آخر؛ مسحه في سجل المبيعات يُدخل الصنف بتلك الوحدة (يُضاف معامل الوحدة بالأساسية ويُعرض السطر بالوحدة) |
| **أسطر بوحدة أكبر** (`UnitLine` و`UnitLines` و`UnitCart`) | سجل المبيعات وشاشة الشراء يحوّلان السطر **إلى الوحدة الأساسية عند إدخاله**: الكمية الأساسية = المُدخَلة × المعامل **ويجب أن تكون دقيقة إلى الألف**، والسعر الأساسي = المُدخَل ÷ المعامل **ويجب أن يكون دقيقًا إلى القرش** (إلى ست منازل في تكلفة الإذن). **ما لا ينقسم بدقة يُرفض باسمه (`unit_quantity_inexact` و`unit_price_inexact`) ولا يُقرَّب قط**، فمجموع السطر والضريبة والمخزون والتكلفة والقيد هو ما كُتب. ويحفظ السطر الوحدة **والمعامل وقت الإدخال** وما كُتب، ويتحقق النموذج الذي يحفظ المستند من السطر مرة أخرى (وحدة من قالب الصنف، والمعامل، والكمية والسعر الأساسيان، ولا وحدة وعبوة معًا). **الإعداد مطفأ ← يُرفض السطر بوحدة (`stock_units_off`)** |
| **الحزم** (`Bundles`) | **الحزمة طقم قائم** (`item_kits`): مع الإعداد يُحفظ الطقم حزمةً فقط إن كان منتجه المسعَّر من نوع طقم ولا يحتفظ بمخزون وخيار سعره هو خيار الطقم، **وإن لم يكن أحد مكوناته الحزمةَ نفسها ولا منتجَ حزمة أخرى ولا من نوع طقم** (`bundle_contains_itself` و`bundle_contains_bundle` و`bundle_keeps_stock` و`bundle_shape_invalid`)، في الشاشة وفي نموذج بنود الطقم معًا. **بيع الحزمة** يقيّد إيراد سعرها ويُخرج مكوناتها بتكلفة كلٍّ منها (متوسطها)، فتكلفة القيد مجموع تكاليف المكونات **ولا مخزون للحزمة نفسها** (افتراض موثّق). الإيراد والتكلفة والإلغاء ثُبتت باختبار على السجل |
| **التتبع** (`StockTracking`) | **رقم مسلسل:** وحدة واحدة بحالة (`in_stock` و`sold` و`returned` و`removed`) لا تتحرك إلا بآلة الحالات: **موجود مرة واحدة** (مفتاح فريد `(صنف، رقم)`)، **يُباع مرة واحدة** (تحديث شرطي ذري بصفوف متأثرة)، **والمرتجع يعيد الوحدة نفسها** (لا يُقبل رقم لم يُبَع، ولا رقم باعته فاتورة غير المُرتجَعة منها). **دفعة:** كمية لكل (دفعة، موقع) بتاريخ صلاحية، **الأقرب انتهاءً أولًا** افتراضًا (`FEFO`) أو **باختيار يدوي بصلاحية `stk_batch_manual_pick`**، **والدفعة المنتهية لا تُباع والإعداد «رفض»** (الاختيار الآلي يتخطاها واليدوي يُرفض `batch_expired`)، والمرتجع يعود إلى الدفعات التي أخذ البيعُ منها بترتيب أخذها وبما لم تُعِده مرتجعات سابقة. **كل تغيير لصنف متتبَّع يمرّ من `StockTracking` وهو الذي يغيّر الرف** فمجموع الدفعات في موقع (أو الأرقام المتوفرة) = كمية الموقع **دائمًا** (يُتحقق عقب كل عملية)، **وأي طريق آخر لتغيير رف صنف متتبَّع يُرفض باسمه** (`tracked_path_unsupported`) في `Item_quantity`. **كل سطر مستند يسجل ما حرّك** (`stock_tracking_lines`) فإلغاء المستند يعيد ما حرّكه تمامًا (ويُرفض إن تحرك رقم أو دفعة بعده)، وكل إلغاء عكس لا حذف |
| **حركة لكل رقم مسلسل** (`StockLedger::record`) | في مستوى `StockLedger` نفسه: حركة وحدات صنف مسلسل تُكتب **حركةً لكل رقم** (كمية ±1) ويُقسَّم المبلغ بينها **إلى آخر منزلة** فمجموعها ما يقوله القيد، كما رُصد لأذون المنتج المتتبَّع (E79): الحسابان الآليان نفسهما والقيد واحد |
| **المخزون السالب** (`NegativeStockGuard`) | ثلاثة خيارات: `allow` (السلوك الحالي، الافتراضي) و`refuse` (يرفض بيعًا بأكثر مما في الموقع) و`refuse_below_available` (أقل من المتاح = ما في الموقع ناقصًا ما وعدت به فواتير صدرت قبل خروج بضاعتها). الرف يُقرأ **تحت قفل** داخل معاملة البيع. يشمل **البيع المكتمل المسلَّم وتسليم الفاتورة**؛ ولا يُرفض مرتجع. وتبقى أذون المخزن والجرد ومرتجع المورّد بقواعدها |
| **الشاشات** (`/stock_units`) | شاشة واحدة بالعربية والإنجليزية: الإعدادات (لمن يملك صلاحيتها) وقوالب الوحدات ونموذج القالب (ونسخة جديدة بعد الاستعمال) وبطاقة الصنف (قالب ونوع تتبع وباركود لكل وحدة). في **سجل المبيعات وشاشة الشراء:** قائمة الوحدة لكل سطر، وقائمة الأرقام المسلسلة (شاشة الشراء) والدفعة وتاريخها (شاشة الشراء) واختيار الدفعة يدويًا (سجل المبيعات لمن يملك الصلاحية). الوحدة تحت «المخزون» في القائمة |
| **فحوص الجاهزية** (`StockTrackingChecks`، فحصان) | `WP42-TRACKING`: رف كل صنف متتبَّع في كل موقع = أرقامه المتوفرة أو مجموع دفعاته (يفشل ويسمّي الصنف)، ودفعات منتهية ما زالت على رف (للمراجعة). `WP42-BUNDLES`: أطقم لا تتبع قواعد الحزمة (للمراجعة، والإعداد مفعّل)، وأسطر بوحدات والإعداد مطفأ (للمراجعة). صار عدد فحوص الجاهزية **27** (كان 25) في `ReadinessRunnerTest` و`AccountingReadinessTest` |
| **تعديلات صغيرة على ملفات مشتركة** | `AccountingError` (حالات الرفض، وكل منها برسالته في 46 لغة)، `Routes.php` (مجموعة `stock_units`)، `navigation_helper.php`، `MigrationChecks::SENSITIVE` (سطر واحد)، `ReadinessRunner` (مجموعة فحوص)، `Module.php` (مفتاحان) و`Stock_units.php` جديد في كل اللغات (عربي مصري ولبناني حقيقيان وإنجليزيتان والباقي `''`)، و`RaceWorker.php` (ثلاثة عاملين للاختبار) |
| **ما لم يتغير** | ملف دفتر قائم، ولا قيد مرحَّل، ولا حركة قائمة تُعاد كتابتها. الصنف بلا قالب ولا تتبع يعمل كما كان (اختبارات تثبت: سطر قديم بلا وحدة، وشراء وبيع بلا تتبع والتتبع مفعّل، والتتبع مطفأ لصنف له ملف)، والاختبارات الذهبية T-06 إلى T-19 لم تُمَسّ |

## 2. الأدلة

كل ملف شُغِّل وحده على قاعدة الاختبار `ospos_test_wp42`: `MYSQL_DB_NAME=ospos_test_wp42 vendor/bin/phpunit --no-coverage <الملف>`، من `/home/user/wt/WP42`، بعد آخر تعديل على الكود (2026-11-08). **كلها نجحت.** لم تُشغَّل المجموعة الكاملة (القيد: قاعدة اختبار واحدة وحاسوب مشترك بأربع أنوية).

**ملفات الحزمة الجديدة** (192 اختبارًا في 11 ملفًا، ملف واحد لكل صنف تحت الاختبار):

| الملف | الاختبارات | التأكيدات | النتيجة |
|---|---|---|---|
| `tests/Libraries/Stock/UnitLineTest.php` | 11 | 21 | نجح |
| `tests/Libraries/Stock/StockOptionsTest.php` | 6 | 25 | نجح |
| `tests/Libraries/Stock/UnitTemplatesTest.php` | 23 | 104 | نجح |
| `tests/Libraries/Stock/ItemStockProfileTest.php` | 14 | 57 | نجح |
| `tests/Libraries/Stock/UnitBarcodesTest.php` | 10 | 41 | نجح |
| `tests/Libraries/Stock/BundlesTest.php` | 12 | 57 | نجح |
| `tests/Libraries/Stock/StockTrackingTest.php` | 68 | 680 | نجح |
| `tests/Libraries/Stock/NegativeStockGuardTest.php` | 15 | 141 | نجح |
| `tests/Libraries/Accounting/Readiness/StockTrackingChecksTest.php` | 7 | 47 | نجح |
| `tests/Controllers/StockUnitsControllerTest.php` | 16 | 99 | نجح |
| `tests/Database/StockUnitsAndTrackingMigrationTest.php` | 10 | 37 | نجح |

**ملفات موجودة أُضيفت إليها اختبارات** (ملف واحد لكل صنف، فالأسطر الجديدة في ملف الصنف القائم):

| الملف | الاختبارات الجديدة | الاختبارات الكلية | التأكيدات | النتيجة |
|---|---|---|---|---|
| `tests/Libraries/Accounting/SalePostingTest.php` (وحدات وسجل مبيعات وحزم) | 24 | 465 | 3572 | نجح |
| `tests/Libraries/Accounting/ReceivingPostingTest.php` (وحدات وشاشة شراء وتتبع) | 13 | 326 | 2992 | نجح |
| `tests/Libraries/Accounting/StockLedgerTest.php` (تحويل الوحدات وعكس البيع) | 2 | 21 | 123 | نجح |
| `tests/Libraries/Stock/StockPermitTest.php` (أذون بوحدات) | 6 | 75 | 781 | نجح |
| `tests/Controllers/ItemKitsControllerTest.php` (رفض الحزمة في الشاشة) | 3 | 5 | 25 | نجح |
| `tests/LanguageFilesTest.php` (`Stock_units` والوحدة) | 2 (8 حالات) | 2930 | 8434 | نجح |

وعُدِّل عدّ الفحوص إلى 27 في `tests/Libraries/Accounting/Readiness/ReadinessRunnerTest.php` و`tests/Commands/AccountingReadinessTest.php` (كان 25).

**انحدار على ما يلمسه الكود المشترك** (الأذون والجرد والطلبات والاستلام ودفتر الحركات وتسويات الكمية والأرصدة الافتتاحية والجاهزية والسجل والتنقل والصلاحيات والأطقم):

| الملف | الاختبارات | التأكيدات | النتيجة |
|---|---|---|---|
| `tests/Libraries/Stock/StockAccessTest.php` | 5 | 37 | نجح |
| `tests/Libraries/Stock/StockDocumentSearchTest.php` | 3 | 12 | نجح |
| `tests/Libraries/Stock/StockDocumentSettingsTest.php` | 5 | 46 | نجح |
| `tests/Libraries/Stock/StockPermitBuilderTest.php` | 12 | 105 | نجح |
| `tests/Libraries/Stock/StockPermitTypeTest.php` | 4 | 12 | نجح |
| `tests/Libraries/Stock/StockRequestTest.php` | 20 | 230 | نجح |
| `tests/Libraries/Stock/StockShelvesTest.php` | 12 | 93 | نجح |
| `tests/Libraries/Stock/StocktakeTest.php` | 65 | 616 | نجح |
| `tests/Database/StockDocumentsMigrationTest.php` | 10 | 44 | نجح |
| `tests/Database/AccountingModulesMigrationTest.php` | 8 | 41 | نجح |
| `tests/Database/RolesPermissionsMigrationTest.php` | 4 | 18 | نجح |
| `tests/Libraries/Accounting/StockAdjustmentTest.php` | 18 | 171 | نجح |
| `tests/Libraries/Accounting/ReceivingPostingBuilderTest.php` | 63 | 154 | نجح |
| `tests/Libraries/Accounting/InventoryValuationTest.php` | 51 | 398 | نجح |
| `tests/Libraries/Accounting/OpeningStockTest.php` | 78 | 826 | نجح |
| `tests/Libraries/Accounting/Readiness/ReadinessRunnerTest.php` | 13 | 34 | نجح |
| `tests/Libraries/Accounting/Readiness/MigrationChecksTest.php` | 18 | 33 | نجح |
| `tests/Commands/AccountingReadinessTest.php` | 25 | 131 | نجح |
| `tests/Models/ItemQuantityTest.php` | 4 | 13 | نجح |
| `tests/Models/InventoryTest.php` | 4 | 4 | نجح |
| `tests/Models/ReceivingTest.php` | 75 | 654 | نجح |
| `tests/Controllers/StockPermitsControllerTest.php` | 23 | 335 | نجح |
| `tests/Controllers/StocktakesControllerTest.php` | 19 | 257 | نجح |
| `tests/Controllers/StockRequestsControllerTest.php` | 12 | 167 | نجح |
| `tests/Controllers/ReceivingsControllerTest.php` | 221 | 1812 | نجح |
| `tests/helpers/NavigationHelperTest.php` | 16 | 22 | نجح |
| `tests/Libraries/Roles/PermissionCatalogTest.php` | 6 | 82 | نجح |
| `tests/Libraries/Sale_libTest.php` | 88 | 238 | نجح (فشل 9 اختبارات في التشغيل الأول لأن صفوف الاختبار الوهمية لا تحمل عمود `unit_id`، فصار القراءة `?? null` وأُعيد التشغيل فنجح) |
| `tests/Libraries/Receiving_libTest.php` | 24 | 48 | نجح |
| `tests/Support/ParityFixtureTest.php` | 411 | 2915 | نجح |

**الاختبارات الذهبية T-06 إلى T-19** تُشغَّل داخل `SalePostingTest` و`ReceivingPostingTest` و`ParityFixtureTest` وكلها نجحت دون تعديل أي ملف تثبيت.

**ما يثبته الاختبار (من أسماء الاختبارات):** سطر مبيعات ومشتريات وإذن بوحدة أكبر يُخزَّن بالأساسية (24 قطعة بسعر 10.00 لكرتونتين بـ120) ويرحّل الإيراد والتكلفة منها، **وتتطابق حركات المخزون وحساب الموقع بالأساسية بالضبط** (`StockLedger::reconcile` و`mismatchedJournals`) مع شراء بالكراتين وبيع بالقطع وإلغاء البيع؛ رفض السعر الذي لا ينقسم والكمية التي لا تتحول والمعامل والكمية والسعر الأساسيين اللذين لا يطابقان وحدة السطر ووحدة من قالب آخر ووحدة والإعداد مطفأ ووحدة وعبوة معًا؛ سطر قديم بلا وحدة؛ تصحيح سطر شراء بوحدة؛ سجل المبيعات (تغيير الوحدة ونص بالوحدة وسعر لا ينقسم ودمج سطر ومسح باركود وحدة واستعادة الوحدة من مبيعة محفوظة)؛ حزمة مباعة بإيرادها وتكلفة مكوناتها وإلغاؤها وقواعدها (ذاتها وحزمة داخلها ومنتج بمخزون وشكلها) في المكتبة والنموذج والشاشة؛ رقم مسلسل يُستلم ويُباع مرة ويعود بمرتجع ويُباع ثانية، ويُرفض المكرر والمباع والذي في موقع آخر والذي باعته فاتورة غير المرتجَع منها، وحركة لكل رقم بقسمة المبلغ إلى آخر منزلة؛ دفعة تُستلم بتاريخها وتُؤخذ الأقرب انتهاءً أولًا وباليد بصلاحية ولا تُباع المنتهية وتُباع بإعداد يسمح؛ مرتجع الدفعة إلى دفعات البيع وبقسمة مرتجعين؛ إلغاء المستند يعيد ما حرّكه ويُرفض إن تحرك رقم أو دفعة بعده؛ مساواة الرف بالأرقام والدفعات عقب كل عملية وفي الجاهزية؛ **سباق اتصالين حقيقيين** على الرقم المسلسل نفسه في إذنين وفي بيعين (واحد ينجح والآخر يُرفض) وسباق بيعين للوحدة الأخيرة بخيار الرفض؛ رفض مرتجع المورّد وفاتورة بلا تسليم وتسليم فاتورة وتصحيح بنود استلام وجرد وتعديل الرف لصنف متتبَّع؛ والأصناف غير المتتبَّعة تعمل كما كانت والتتبع مفعّل.

## 3. الحراس

أُضيف هنا كل حارس في الكود وأُثبت بـ`tools/dev/guard_mutations.py`: يُعطَّل الحارس (أو تُعكس شرطيته) ويُشغَّل ملف الاختبار المسمّى، فإن لم يفشل فالحارس غير مثبت. قائمة التعطيلات في `wp42-guards.json` (**143 تعطيلًا**). **النتيجة: 143 من 143 كُشفت** (DETECTED) و0 غير مثبتة و0 غير صالحة.

**العائلات:** `UnitLine` (التحويل) (2)، `StockOptions` (الإعدادات) (5)، `UnitTemplates` (القوالب) (17)، `ItemStockProfile` (ملف الصنف) (10)، `UnitBarcodes` (الباركود) (8)، سطر بوحدة (`UnitLines` والنماذج) (10)، سجل المبيعات (`UnitCart`) (4)، `Bundles` (الحزم) (8)، `StockTracking` (التتبع) (40)، `StockLedger` (حركة لكل رقم) (3)، ربط المستندات (22)، `NegativeStockGuard` (7)، فحوص الجاهزية (4)، الشاشات (3).

**التشغيل الأول** (140 تعطيلًا على الكود النهائي): كشف **131** وترك **تسعة** غير مثبتة، فعولجت كلها:
- `B02` (الباركود والإعداد مطفأ) و`BD06` (منتج حزمة من النوع العادي) و`K10` (مرتجع رقم لم يُبَع ولا يسمّي فاتورة) و`K16` (دفعة كميتها سالبة أو صفر والدفعات تساوي السطر) و`K37` (استدعاء مباشر لصنف غير متتبَّع) و`N02` (مرتجع والرف تحت الصفر) و`N07` (فاتورة سُلِّمت لا تَعِد): **أُضيف اختبار لكل** (`testAssign_RefusesWhileTheUnitsSettingIsOff` و`testAssertValid_RejectsAComponentThatIsTheProductOfAnotherKitEvenWhenThatProductIsOfTheOrdinaryType` و`testSave_RefusesToReturnASerialNumberThatWasNeverSoldEvenWhenTheReturnNamesNoSale` و`testReceive_RejectsABatchWhoseQuantityIsNegativeOrNothingEvenWhenTheBatchesAddUpToTheLine` و`testReceive_RefusesAnItemThatIsNotTrackedWhateverTheMovement` و`testAssertSale_NeverRefusesAReturnEvenWhenTheShelfIsAlreadyBelowNothing` و`testPromised_LeavesOutInvoicesWhoseGoodsHaveLeft`)، وأُضيف `K38` إلى `K40` للفروع الثلاثة الباقية من الحارس نفسه.
- `K08` (البيع مرة واحدة): كان التعطيل يمسّ شرط الحالة وحده والموقع يحميه أيضًا (الرقم المباع بلا موقع بقيد في القاعدة)، فصار التعطيل **يرفع الشرطين معًا**.
- `R03` (فحص الأطقم): كان التعطيل مكافئًا لحارس `BD01` (`Bundles::violations` يعود فارغًا والإعداد مطفأ)، فاستُبدل به تعطيل **حالة المراجعة نفسها**.

**التشغيل الثاني** لهذه الاثني عشر (التسعة المعدّلة والثلاثة الجديدة): كُشفت **كلها**. بقية التعطيلات (131) لم يتغير الكود تحتها بعد التشغيل الأول (أُضيفت اختبارات ولم يُضعَف واحد).

| # | الحارس | الملف الذي يحرسه | الاختبار الذي يكشفه | النتيجة |
|---|---|---|---|---|
| U01 | a quantity that does not convert to whole thousandths is refused | `UnitLine.php` | `UnitLineTest.php` | كُشف |
| U02 | a price that does not divide exactly into a base price is refused | `UnitLine.php` | `UnitLineTest.php` | كُشف |
| O01 | changing the stock options needs the accounting settings permission | `StockOptions.php` | `StockOptionsTest.php` | كُشف |
| O02 | an unknown negative stock option is refused | `StockOptions.php` | `StockOptionsTest.php` | كُشف |
| O03 | a change is written to the audit log with what it was before | `StockOptions.php` | `StockOptionsTest.php` | كُشف |
| O04 | nothing is written when nothing changes | `StockOptions.php` | `StockOptionsTest.php` | كُشف |
| O05 | an unknown stored value of negative stock is read as allow | `StockOptions.php` | `StockOptionsTest.php` | كُشف |
| T01 | managing templates needs the permission | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T02 | managing templates needs the units setting on | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T03 | a template that no document line used is the only one whose units change | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T04 | a template name is unique among the versions of a name when created | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T05 | a template name is unique when another one is renamed to it | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T06 | a factor is a number | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T07 | a factor is above zero | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T08 | a unit name is not repeated and is not the main unit | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T09 | a name is not empty and is not too long | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T10 | a template has from one to twenty larger units | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T11 | a new version switches the old template off | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T12 | a template that does not exist is refused | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T13 | a unit of a template is used when a sale line, a purchase line or a permit line names it | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T14 | a template is created only by who may manage units | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T15 | a template is changed only by who may manage units | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T16 | a new version is made only by who may manage units | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| T17 | a template is switched on and off only by who may manage units | `UnitTemplates.php` | `UnitTemplatesTest.php` | كُشف |
| P01 | giving an item a template needs the permission | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P02 | giving an item a template needs the units setting on | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P03 | a template that does not exist or is switched off is refused | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P04 | changing the tracking of an item needs the permission | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P05 | changing the tracking of an item needs the tracking setting on | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P06 | the tracking of an item changes only while it holds nothing and keeps stock | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P07 | an item holds stock when a shelf holds some | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P08 | an item holds stock when a serial number is on hand | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P09 | an item holds stock when a batch holds some | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| P10 | documents see no tracking while the setting is off | `ItemStockProfile.php` | `ItemStockProfileTest.php` | كُشف |
| B01 | giving a unit a barcode needs the permission | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B02 | giving a unit a barcode needs the units setting on | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B03 | a barcode that another unit has is refused | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B04 | a barcode that is the number of an item is refused | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B05 | a unit of another template is refused | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B06 | a blank or too long barcode is refused | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B07 | removing a barcode needs the permission | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| B08 | a barcode means nothing while the units setting is off | `UnitBarcodes.php` | `UnitBarcodesTest.php` | كُشف |
| L01 | a line in a unit is refused while the units setting is off | `UnitLines.php` | `SalePostingTest.php` | كُشف |
| L02 | a unit that is not one of the template of the item is refused | `UnitLines.php` | `SalePostingTest.php` | كُشف |
| L03 | a line that names a unit and a pack is refused | `UnitLines.php` | `ReceivingPostingTest.php` | كُشف |
| L04 | the factor of a line must be the one of its unit | `UnitLines.php` | `SalePostingTest.php` | كُشف |
| L05 | the base quantity of a line must be what its unit converts to | `UnitLines.php` | `SalePostingTest.php` | كُشف |
| L06 | the base price of a line must be what its unit converts to | `UnitLines.php` | `SalePostingTest.php` | كُشف |
| L07 | a sale line keeps its unit columns | `Sale.php` | `SalePostingTest.php` | كُشف |
| L08 | a purchase line keeps its unit columns | `Receiving.php` | `ReceivingPostingTest.php` | كُشف |
| L09 | a correction that changes the quantity or the price of a purchase line takes its unit off | `Receiving.php` | `ReceivingPostingTest.php` | كُشف |
| L10 | a correction that changes nothing of the figures keeps the unit of a purchase line | `Receiving.php` | `ReceivingPostingTest.php` | كُشف |
| C01 | a line whose quantity does not divide into a unit is not shown in it | `UnitCart.php` | `SalePostingTest.php` | كُشف |
| C02 | a unit of another item is refused on the register | `UnitCart.php` | `SalePostingTest.php` | كُشف |
| C03 | a line whose quantity stops dividing goes back to base units | `UnitCart.php` | `SalePostingTest.php` | كُشف |
| C04 | what is typed in a unit is converted before it is kept | `Sale_lib.php` | `SalePostingTest.php` | كُشف |
| BD01 | a kit is asked nothing while the bundles setting is off | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD02 | a bundle needs a kit product that carries the price and the price option of the kit | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD03 | the product of a bundle keeps no stock | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD04 | a bundle cannot contain itself | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD05 | a component of the kit type is a bundle | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD06 | the product of another bundle is a bundle | `Bundles.php` | `BundlesTest.php` | كُشف |
| BD07 | the model of the kit items refuses what the rules refuse | `Item_kit_items.php` | `BundlesTest.php` | كُشف |
| BD08 | the kit screen refuses a bundle before it saves anything | `Item_kits.php` | `ItemKitsControllerTest.php` | كُشف |
| K01 | one serial number for each unit | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K02 | a serial item takes no batch entry | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K03 | serial units are whole numbers | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K04 | a serial number is not empty or longer than thirty characters | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K05 | a serial number is not repeated in a line | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K06 | a serial number that is on record is not received again | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K07 | a serial number is sold only from where it is on hand | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K08 | a serial number is sold once, from where it is on hand | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K09 | a sale of a serial number that is not on hand is refused | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K10 | a return puts back only a serial number that was sold | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K11 | a return puts back only a serial number the sale it returns sold | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K12 | a transfer moves a serial number only from where it is on hand | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K13 | a batch needs an expiry date when it comes in | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K14 | a batch has a number of at most sixty characters | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K15 | a batch has a real date | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K16 | a batch has a quantity above zero | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K17 | the batches of a line add up to its quantity | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K18 | a purchase of a batch item names its batch | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K19 | a batch is on record with one expiry date only | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K20 | a sale never takes an expired batch while the setting refuses it | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K21 | picking a batch by hand needs its permission | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K22 | a batch picked by hand that has expired is refused | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K23 | the batch that expires first is taken first | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K24 | the automatic pick leaves out batches that have expired | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K25 | a sale of more than the unexpired batches hold is refused | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K26 | a batch is never taken below nothing | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K27 | a batch picked by hand must exist | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K28 | a return of batches names where the goods go back | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K29 | a return goes back into the batches the sale took, less what earlier returns put back | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K30 | taking a document back is refused when a serial number has moved on | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K31 | a document is taken back once | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K32 | the shelf of a tracked item must equal its serial numbers or batches | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K33 | the tracking itself may change the shelf of a tracked item | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K34 | nothing else may change the shelf of a tracked item | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K35 | the shelf of an item is free while tracking is off | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K36 | a tracked item is refused where it has no serial number or batch to name | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K37 | an item that is not tracked takes no entry | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K38 | a sale or an issue of an item that is not tracked takes no entry | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K39 | a return of an item that is not tracked takes no entry | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| K40 | a transfer of an item that is not tracked takes no entry | `StockTracking.php` | `StockTrackingTest.php` | كُشف |
| SL01 | the movements of a serial item are written one for each serial number | `StockLedger.php` | `StockTrackingTest.php` | كُشف |
| SL02 | the value is shared among the serial numbers to the last digit | `StockLedger.php` | `StockTrackingTest.php` | كُشف |
| SL03 | nothing is split while tracking is off | `StockLedger.php` | `StockTrackingTest.php` | كُشف |
| W01 | an invoice issued before its goods leave is refused when it has a tracked item | `Sale.php` | `StockTrackingTest.php` | كُشف |
| W02 | a sale of a tracked item goes through its serial numbers and batches | `Sale.php` | `StockTrackingTest.php` | كُشف |
| W03 | the option on negative stock is asked of every sale line that is not tracked | `Sale.php` | `NegativeStockGuardTest.php` | كُشف |
| W04 | cancelling a sale puts its serial numbers and batches back | `Sale.php` | `StockTrackingTest.php` | كُشف |
| W05 | cancelling a sale changes the shelf of a tracked item only once | `Sale.php` | `StockTrackingTest.php` | كُشف |
| W06 | the delivery of an invoice refuses tracked items | `Sale.php` | `StockTrackingTest.php` | كُشف |
| W07 | the delivery of an invoice asks the option on negative stock | `Sale.php` | `NegativeStockGuardTest.php` | كُشف |
| W08 | a return to a supplier or a transfer between locations refuses tracked items | `Receiving.php` | `StockTrackingTest.php` | كُشف |
| W09 | a purchase of a tracked item goes through its serial numbers and batches | `Receiving.php` | `StockTrackingTest.php` | كُشف |
| W10 | a purchase line of an item that is not tracked takes no entry | `Receiving.php` | `StockTrackingTest.php` | كُشف |
| W11 | cancelling a purchase takes its serial numbers and batches out | `Receiving.php` | `StockTrackingTest.php` | كُشف |
| W12 | cancelling a purchase changes the shelf of a tracked item only once | `Receiving.php` | `StockTrackingTest.php` | كُشف |
| W13 | a permit moves the serial numbers and batches of its tracked lines | `StockPermit.php` | `StockTrackingTest.php` | كُشف |
| W14 | a permit line of an item that is not tracked takes no entry | `StockPermit.php` | `StockTrackingTest.php` | كُشف |
| W15 | cancelling a permit takes its serial numbers and batches back | `StockPermit.php` | `StockTrackingTest.php` | كُشف |
| W16 | a permit leaves the shelf of a tracked item to its serial numbers and batches | `StockPermit.php` | `StockTrackingTest.php` | كُشف |
| W17 | a stocktake leaves out tracked items that are named | `Stocktake.php` | `StockTrackingTest.php` | كُشف |
| W18 | a stocktake of a whole location leaves out tracked items | `Stocktake.php` | `StockTrackingTest.php` | كُشف |
| W19 | the shelf of a tracked item changes only through its tracking | `Item_quantity.php` | `StockTrackingTest.php` | كُشف |
| W20 | a typed quantity cannot change the shelf of a tracked item | `Item_quantity.php` | `StockTrackingTest.php` | كُشف |
| W21 | a tracked item cannot be zeroed | `Item_quantity.php` | `StockTrackingTest.php` | كُشف |
| W22 | a list of tracked items cannot be zeroed | `Item_quantity.php` | `StockTrackingTest.php` | كُشف |
| N01 | a sale below nothing is allowed only when the option allows it | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N02 | a return is never refused | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N03 | the shelf is read under a lock | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N04 | the third option subtracts what issued invoices promise | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N05 | a sale that would go below nothing is refused | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N06 | what is promised leaves out the invoice that is being delivered | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| N07 | only invoices whose goods have not left promise stock | `NegativeStockGuard.php` | `NegativeStockGuardTest.php` | كُشف |
| R01 | a shelf that is not what its batches or serial numbers add up to fails the check | `StockTrackingChecks.php` | `StockTrackingChecksTest.php` | كُشف |
| R02 | an expired batch on a shelf is for review | `StockTrackingChecks.php` | `StockTrackingChecksTest.php` | كُشف |
| R03 | kits that break the rules of a bundle are for review | `StockTrackingChecks.php` | `StockTrackingChecksTest.php` | كُشف |
| R04 | lines in units while the units setting is off are for review | `StockTrackingChecks.php` | `StockTrackingChecksTest.php` | كُشف |
| Q01 | the form of a template is forbidden to who may not manage units | `StockUnits.php` | `StockUnitsControllerTest.php` | كُشف |
| Q02 | the settings are shown only to who may change them | `StockUnits.php` | `StockUnitsControllerTest.php` | كُشف |
| Q03 | the screens belong to the module of units and tracking | `StockUnits.php` | `StockUnitsControllerTest.php` | كُشف |

## 4. ما افترضتُه

كل ما يلي **افتراض تصميم محافظ** (لم يُرصد في دفترة) ويحتاج مراجعة المالك. لا أقول إن شيئًا منها «يطابق دفترة».

1. **ما رُصد فعلًا:** نموذج قالب الوحدات (FRM-R50) وخيارات المخزون السالب الثلاثة (FRM-R51)، وأن الوحدات والحزم والتتبع تضيف نموذجًا وإعدادًا (الدفعة C)، وأن أذون المنتج المتتبَّع تُرحَّل بالحسابين الآليين وحركة لكل رقم مسلسل (E79). **لم يُرصد:** منتج متعدد الوحدات في فاتورة، وإنشاء حزمة وتكلفتها وبيعها، واختيار الدفعة، والصلاحية، (G-149). كل ما بُني فوق ذلك تصميمنا.
2. **الدقة بدل التقريب (الوحدات):** الكمية الأساسية يجب أن تكون دقيقة إلى 0.001 والسعر الأساسي دقيقًا إلى القرش (والتكلفة إلى ست منازل)، وإلا يُرفض السطر باسمه. **أثر ذلك:** كرتونة من 12 بسعر 100 (8.3333 للقطعة) **تُرفض**، وسعر 120 يُقبل. **السبب:** سعر البند مخزَّن بمنزلتين وكل ما بعده (الإجمالي والضريبة والقيد والتقارير) يقرؤه؛ تقريبه كان سيغيّر إجمالي سطر مرحَّل بقرشين أو أكثر في الكميات الكبيرة. **سؤال للمالك:** أيُوسَّع تخزين السعر (وما يقرؤه) إلى أربع منازل ليقبل مثل هذا السعر؟
3. **المعامل وقت الإدخال:** يُخزَّن المعامل مع السطر ولا يتغير معامل مستعمل؛ التغيير نسخة جديدة. **سطر خرج من الدفاتر لا يُعاد تسعيره.**
4. **تصحيح سطر شراء بوحدة:** تصحيح كميته أو سعره (WP17) يجعله سطرًا بالوحدة الأساسية (تُمسح الوحدة وتبقى الأرقام الأساسية)، لأن ما كُتب لم يعد يصف السطر.
5. **كلفة الحزمة:** مجموع تكاليف مكوناتها وقت البيع (متوسط كلٍّ)، والإيراد سعر الحزمة (خيار «سعر الطقم»)، **ولا مخزون للحزمة ولا تجميع مسبق** (النوع «معد مسبقًا» في FRM-R52 لم يُبنَ؛ النوع «فوري» هو ما يقابل الطقم القائم). الحزمة داخل حزمة **مرفوضة**.
6. **التتبع:** تكلفة الصنف تبقى متوسطه (لا تكلفة لكل دفعة ولا رقم). الرقم المسلسل حتى 30 حرفًا (حجم العمود القائم). تغيير نوع التتبع بعد وجود مخزون **مرفوض**: يُصفَّر المخزون بإذن صرف ثم يُغيَّر ثم يُستلم بأرقامه أو دفعاته. **الرقم الذي خرج (`removed`) يعود بالاستلام أو بإذن إضافة**، والمباع لا يعود إلا بمرتجع.
7. **الدفعة:** يُعرَّف تاريخ الصلاحية للدفعة (لا لأكثر منها بالرقم نفسه)؛ المنتهية تُرفض في البيع والإعداد «رفض» (الافتراضي)، ولا يشمل الرفض إذن الصرف ولا التحويل. **تاريخ الفحص** تاريخ الفاتورة إن كُتب وإلا اليوم. **مرتجع الدفعة** يعود إلى دفعات البيع الأصلي بترتيب أخذها دون سقف لكل دفعة غير ما أخذه ذلك البيع ناقصًا ما أُعيد، ويلزم بيان الدفعة لمرتجع بلا فاتورة أصلية.
8. **المسارات المدعومة لصنف متتبَّع:** الشراء، والبيع المكتمل المسلَّم، ومرتجع العميل، وأذون الإضافة والصرف والتحويل، وإلغاء كلٍّ منها. **المرفوض باسمه** (`tracked_path_unsupported`): مرتجع المورّد، والتحويل عبر استلام (`requisition`)، وتصحيح بنود استلام، **وفاتورة تصدر قبل خروج بضاعتها** (لا تخرج وحدة بلا رقم ولا دفعة)، وتسليم فاتورة، والجرد، وتعديل الكمية من شاشة الصنف أو بالتصفير، وأي تغيير آخر لرف صنف متتبَّع. الرفض الأخير في `Item_quantity` فيشمل ما لم أعدّه.
9. **المخزون السالب:** `refuse_below_available` يحسب «المتاح» = الرف − ما وعدت به الفواتير الصادرة غير المسلَّمة في الموقع نفسه (لا حجوزات ولا أوامر بيع). الخيار الثاني المرصود «السماح للمنتجات القابلة للتتبع فقط» (FRM-R51) **لم يُبنَ** (معناه لم يوضَّح)، ومقابله عندنا: الصنف المتتبَّع لا يهبط أبدًا عن صفر مهما كان الإعداد.
10. **ثغرة المراقبة الواحدة:** الحركات تُكتب **حركةً لكل رقم مسلسل** في `StockLedger` دون عمود يسمّي الرقم في صف الحركة؛ تسمية الرقم في `stock_tracking_lines` (المصدر والسطر والصنف والموقع).
11. **سجل المبيعات:** الباركود المرصود لكل وحدة يُضيف معاملها بالوحدات الأساسية ويُظهر السطر بالوحدة؛ **اختيار السطر بوحدة آخر** يعرض السطر بها ما دامت كميته تنقسم بدقة وإلا يُرفض. ومسح باركود وحدة يُدمج في سطر قائم فيبقى بوحدته إن انقسم المجموع بدقة وإلا يعود إلى الأساسية.

## 5. ما بقي

- **لم يُنفَّذ:** نوع الحزمة «معد مسبقًا» (تجميع المكونات إلى مخزون الحزمة)، وسعر لكل دفعة وتكلفة لكل دفعة، وأرقام مسلسلة أطول من 30 حرفًا، وتقارير الدفعات والصلاحيات (قائمة بالقريب انتهاؤه)، وتتبع مرتجعات المورّد وتحويلات الاستلام وتصحيح الاستلام لصنف متتبَّع، ونقل صنف قائم إلى التتبع بمخزونه (دفعة افتتاحية)، وباركود الدفعة، وطباعة الوحدة في الإيصال (يُطبع المقدار الأساسي)، وعرض الرقم المسلسل لكل وحدة في سطر الشراء المُصحَّح.
- **شاشات الكتابة (متحكّمات السجل وشاشة الشراء) لها اختبارات للمكتبات والنماذج ولا اختبارات متحكّم لإرسال الوحدة والدفعة** (`Sales::postEditItem` و`Receivings::postEditItem`)، ولم يُجرَ فحص المتصفح.
- **لم تُشغَّل المجموعة الكاملة** (القيد: قاعدة اختبار واحدة وحاسوب مشترك)؛ ما شُغِّل مذكور في القسم 2.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرارات للمالك:**
- أيُوسَّع سعر البند (وما يقرؤه) إلى أربع منازل ليُقبل سعر وحدة لا ينقسم إلى القرش (القسم 4 البند 2)؟
- أيُبنى نوع الحزمة «معد مسبقًا» (تجميع بقيد انتقال مخزون بين المكونات والحزمة) أم يبقى الطقم الفوري وحده؟
- أيُسمح بدفعة منتهية في البيع بإذن (صلاحية) بدل الإعداد وحده؟
- هل يُبنى مسار نقل صنف قائم إلى التتبع بمخزونه (دفعة افتتاحية)؟

**ملاحظات دفترة مطلوبة (`daftraObservationsNeeded`):** منتج متعدد الوحدات في فاتورة بيع وشراء (كيف تُكتب الكمية والسعر والمعامل، وهل تُقرَّب)؛ إنشاء حزمة وتكلفتها وقيدها عند بيعها؛ اختيار الدفعة وترتيبها وأثر الصلاحية في البيع؛ سلوك رقم مسلسل مباع ومرتجع؛ هل الخيار الثاني المرصود للمخزون السالب يعني المنتجات القابلة للتتبع بالكمية.

## 7. قبل النشر

1. `php spark migrate`: ترحيل واحد (`20273100000000_StockUnitsAndTracking`، **HEAVY**: يضيف أعمدة قابلة للفراغ وقيدًا إلى `sales_items` و`receivings_items` و`stock_permit_lines` ويفتح ثمانية جداول جديدة)؛ شغّله في ساعة هادئة بنسخة احتياطية. لا صف يتغير.
2. بعد الترحيل: امنح الصلاحيات الثلاث (`stk_units_manage` و`stk_tracking_manage` و`stk_batch_manual_pick`) ووحدة `stock_units` لمن يعمل بها يدويًا؛ **لا منح تلقائي**. من يعدّل مخزون موقع يحتاج صلاحية الأصناف لذلك الموقع كما في الأذون.
3. الإعدادات كلها مطفأة (والمخزون السالب `allow`): لا شيء يتغير حتى تُفعَّل من `/stock_units`. **فعّل التتبع قبل أن تبدأ بإدخال مخزون متتبَّع**، وصفّر الصنف الذي سيُتتبَّع قبل تغيير نوعه.
4. شغّل `php spark accounting:readiness`: فحصان جديدان (`WP42-TRACKING` و`WP42-BUNDLES`)، والعدد الكلي صار 27.
5. **اختبر في المتصفح** سجل المبيعات (وحدة وباركود وفاتورة ورقم مسلسل ودفعة) وشاشة الشراء (وحدة وأرقام ودفعة) بالعربية والإنجليزية وبالأحجام الثلاثة: لم يُجرَ فحص متصفح في هذه الحزمة.

## 8. ما وجدتُه بقراءة الكود (ليس في هذه الحزمة)

- `Sale_lib::add_item_kit` تعلن `bool $kit_price_option` فيتحول خيار السعر `PRICE_OPTION_KIT_STOCK` (2) إلى `true` ثم إلى 1 عند تمريره إلى `add_item`: **خيار «سعر أصناف المخزون فقط» يعمل كخيار «سعر الطقم»**. وجدته وأنا أبني الحزم؛ لم أغيّره (ليس من نطاق الحزمة) وأسجّله بندًا جديدًا.
- `Receiving::unitsOf` و`ReceivingLine::units()` يضربان الكمية في «وحدات العبوة»؛ **سطر بوحدة يحمل عبوة 1** عمدًا (القسم 1)، فلا يتداخل المساران.

## 9. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار، وأرقام القرارات تتابع بعد E122):

**decisions.md** (صف جديد، رقمه E-التالي بعد E122):

> | E-؟؟ | **الوحدات المتعددة والحزم والتتبع بالرقم المسلسل والدفعة (`INV-04` و`INV-05` و`INV-06`، WP42، 2026-11-08):** سلوك دفترة **مرصود إعدادات ونماذج فقط** (قالب الوحدات FRM-R50، وخيارات المخزون السالب FRM-R51، وأذون المنتج المتتبَّع بحركة لكل رقم E79)، وما عداه **تصميم محافظ وافتراضات**: (1) **الوحدات:** قوالب بمعامل موجب بأربع منازل عشرية **لا يتغير بعد أن يستعمله سطر مستند** (التغيير نسخة جديدة)، وسطر المبيعات والمشتريات وإذن المخزن يُدخَل بوحدة أكبر **ويُخزَّن بالوحدة الأساسية** (المخزون والتكلفة والقيد والتقارير تقرأ الأساسية فقط) مع الوحدة **والمعامل وقت الإدخال** وما كُتب، **والكمية الأساسية دقيقة إلى 0.001 والسعر الأساسي دقيق إلى القرش وإلا يُرفض السطر باسمه ولا يُقرَّب**، وقيد `CHECK` في القاعدة يُبقي الكمية الأساسية = المُدخَلة × المعامل؛ صنف بلا قالب = وحدة واحدة؛ خيار «تحديث كل التكاليف» المخفي لم يُرصد ولم يُبنَ. (2) **الحزم:** الطقم القائم مع الإعداد: منتج مسعَّر من نوع طقم بلا مخزون وخيار سعر الطقم، **ولا حزمة داخل حزمة ولا فيها نفسها**، وبيعها يقيّد سعرها ويُخرج مكوناتها بتكلفة كلٍّ منها فتكلفتها مجموع تكاليفها **ولا مخزون لها** (النوع «معد مسبقًا» لم يُبنَ). (3) **التتبع:** رقم مسلسل بحالات (متوفر، مباع، مُرتجَع، مُخرَج) لا يتحرك إلا بآلتها: **موجود مرة ويُباع مرة والمرتجع يعيد الوحدة نفسها**، وحركة مقيَّمة لكل رقم في `StockLedger`؛ ودفعة بكمية لكل موقع وتاريخ صلاحية: **الأقرب انتهاءً أولًا** وباختيار يدوي بصلاحية، **والمنتهية مرفوضة في البيع بإعداد افتراضه رفض**؛ **مجموع الدفعات (أو الأرقام المتوفرة) في الموقع = كمية الموقع دائمًا** لأن كل تغيير لصنف متتبَّع يمرّ من `StockTracking` وكل طريق آخر يُرفض باسمه؛ تكلفة الصنف تبقى متوسطه (لا تكلفة لدفعة ولا رقم)؛ **المسارات المدعومة:** الشراء والبيع المسلَّم ومرتجع العميل وأذون الإضافة والصرف والتحويل وإلغاؤها، **والمرفوض باسمه:** مرتجع المورّد وتحويل الاستلام وتصحيح بنود الاستلام وفاتورة تصدر قبل خروج بضاعتها وتسليمها والجرد وتعديل الرف يدويًا. (4) **المخزون السالب:** ثلاثة خيارات (`allow` الافتراضي = اليوم، و`refuse`، و`refuse_below_available` = الرف ناقصًا ما وعدت به فواتير لم تُسلَّم)، يقرأ الرف تحت قفل ويشمل البيع المكتمل وتسليم الفاتورة. كل شيء **خلف إعدادات مطفأة**، والصلاحيات الثلاث (`stk_units_manage` و`stk_tracking_manage` و`stk_batch_manual_pick`) غير ممنوحة لأحد. **ما نخالف فيه عمدًا:** لا تقريب لسعر وحدة لا ينقسم (دفترة لم تُرصد). **أسئلة للمالك:** توسيع سعر البند إلى أربع منازل، ونوع الحزمة «معد مسبقًا»، ودفعة منتهية بإذن، ونقل صنف قائم إلى التتبع بمخزونه. الأدلة: [تقرير WP42](wp42-report.md)، `StockTrackingTest` و`UnitLineTest` و`UnitTemplatesTest` و`BundlesTest` و`NegativeStockGuardTest` و`SalePostingTest` و`ReceivingPostingTest` و`StockLedgerTest` و`StockPermitTest`. | يحدّد `INV-04` و`INV-05` و`INV-06` ويحدّث WP42 في [الجرد](daftra-parity/backlog.md). **لا ترحيل مرحَّل يُعاد كتابته**، ولا دمج في `master` ولا نشر |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `INV-04` (الوحدات المتعددة وقوالبها): «قالب وحدات بالشكل المرصود (الوحدة الأساسية وتمييزها حتى 25 حرفًا واسم القالب ونشط وصفوف وحدات أكبر بمعامل بأربع منازل) **لا يتغير بعد الاستعمال** (نسخة جديدة)، وباركود لكل وحدة، وسطر مبيعات ومشتريات وإذن مخزن بوحدة أكبر يُخزَّن بالأساسية بكمية دقيقة إلى 0.001 وسعر دقيق إلى القرش، **ويُرفض ما لا ينقسم بدقة**؛ خيار تحديث كل التكاليف لم يُبنَ». ينتقل من «غير منفّذ» إلى **«مختلف ويحتاج تعديلًا»** (سلوك التحويل من تصميمنا؛ منتج متعدد الوحدات في فاتورة لم يُرصد). الأدلة: `UnitLineTest` و`UnitTemplatesTest` و`SalePostingTest` و`ReceivingPostingTest` و`StockLedgerTest` و`StockPermitTest`.
- الصف `INV-05` (الحزم): «طقم قائم يُحفظ حزمةً مع الإعداد بقواعد: منتج مسعَّر من نوع طقم بلا مخزون، ولا حزمة داخل حزمة ولا فيها نفسها؛ بيعها يقيّد سعرها ويُخرج مكوناتها بتكلفة كلٍّ منها (لا مخزون للحزمة)». يبقى **«مختلف»** ويُسجَّل أن النوع «معد مسبقًا» (FRM-R52) لم يُبنَ وأن التكلفة افتراض. الأدلة: `BundlesTest` و`SalePostingTest` و`ItemKitsControllerTest`.
- الصف `INV-06` (التتبع بالرقم المسلسل والدفعة والصلاحية): «رقم مسلسل بآلة حالات (موجود مرة ويُباع مرة والمرتجع يعيد الوحدة نفسها) وحركة لكل رقم في `StockLedger` كما رُصد لأذون المنتج المتتبَّع؛ ودفعة بكمية لكل موقع وتاريخ صلاحية: الأقرب انتهاءً أولًا واختيار يدوي بصلاحية والمنتهية مرفوضة بإعداد؛ ومجموع الدفعات = كمية الموقع دائمًا (يُفحص في الجاهزية)». ينتقل إلى **«مختلف ويحتاج تعديلًا»** (آلة الحالات واختيار الدفعة من تصميمنا لا من رصد). الأدلة: `StockTrackingTest` (ومنها سباق اتصالين حقيقيين على الرقم نفسه) و`StockTrackingChecksTest`.
- صف جديد `INV-13` (خيارات المخزون السالب الثلاثة، FRM-R51): «ثلاثة خيارات (`allow` و`refuse` و`refuse_below_available`) افتراضها السلوك الحالي، يقرأ الرف تحت قفل ويشمل البيع المكتمل وتسليم الفاتورة»، الحالة **«مختلف ويحتاج تعديلًا»** (الخيار المرصود الثاني «السماح للمنتجات القابلة للتتبع» لم يُبنَ). الأدلة: `NegativeStockGuardTest`.
- فقرة تحديث بعد الجدول: «**تحديث بعد WP42 (2026-11-08):** نُفِّذت الوحدات المتعددة والحزم والتتبع وخيارات المخزون السالب خلف إعدادات مطفأة ([تقرير WP42](wp42-report.md)). صفوف `INV-04` و`INV-05` و`INV-06` تنتقل أو تبقى «مختلف» ويُضاف `INV-13`؛ فيتغير العدّ في الجدول بقدر ما يقرّره المُدمِج».

**تقرير الفجوات** (`gap-report.md`، صفوف جديدة بعد آخر رقم فجوة):

> | `G-؟؟` | **الوحدات والحزم والتتبع (`INV-04` و`INV-05` و`INV-06`):** لم يكن للمخزون إلا وحدة واحدة وعبوة على سطر الشراء وأطقم تُخرج مكوناتها ورقم مسلسل نصي لا يُفرض. **نُفِّذت (2026-11-08، WP42):** [التقرير](wp42-report.md). **ما بقي:** منتج متعدد الوحدات وإنشاء حزمة واختيار دفعة غير مرصودة (`G-149`)، والنوع «معد مسبقًا»، وتتبع مرتجع المورّد وتحويل الاستلام وتصحيح الاستلام والجرد لصنف متتبَّع (مرفوضة باسمها)، ونقل صنف قائم إلى التتبع بمخزونه، وتقارير الصلاحية، وتوسيع سعر البند لسعر وحدة لا ينقسم إلى القرش. | WP42 | open | نعم | high |
> | `G-؟؟` | **`Sale_lib::add_item_kit` يحوّل خيار السعر `PRICE_OPTION_KIT_STOCK` إلى `PRICE_OPTION_KIT`:** المعامل معلن `bool` فتتحول القيمة 2 إلى `true` ثم 1 (وجدتها WP42 وهي تبني الحزم، ولم تُغيَّر). | — | open | لا | medium |

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **الوحدات المتعددة والحزم والتتبع (WP42):** ترحيل واحد جديد `20273100000000_StockUnitsAndTracking` (**HEAVY**: أعمدة قابلة للفراغ وقيد `CHECK` على `sales_items` و`receivings_items` و`stock_permit_lines`، وثمانية جداول إضافية، ووحدة `stock_units` وثلاث صلاحيات **غير ممنوحة لأحد**): `php spark migrate` في ساعة هادئة بنسخة احتياطية. بعده امنح الصلاحيات يدويًا. **كل الإعدادات مطفأة** (`/stock_units`)؛ فعّل التتبع **قبل** إدخال أي مخزون متتبَّع، وصفّر الصنف قبل تغيير نوع تتبعه. شغّل `php spark accounting:readiness`: فحصان جديدان (`WP42-TRACKING` و`WP42-BUNDLES`) والعدد 27. اختبر في المتصفح سجل المبيعات وشاشة الشراء بالوحدة والرقم المسلسل والدفعة (لم يُجرَ فحص متصفح).

**backlog.md:** صف WP42 «نُفِّذ (2026-11-08)» مع المتبقيات المسمّاة (القسم 5 من التقرير)؛ والبنود `INV-04` و`INV-05` و`INV-06` «partial»، و`INV-13` جديد «partial»؛ وبند `G-149` يبقى مفتوحًا. **تنبيه للدمج:** عدد فحوص الجاهزية في `ReadinessRunnerTest` و`AccountingReadinessTest` ارتفع بفحصين (27)؛ فإن أضافت حزم أخرى فحوصًا فاجمع الزيادات.
