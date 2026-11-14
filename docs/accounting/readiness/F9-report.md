# تقرير المصلح F9: الاستقرار (R6-07 وR6-08 وR6-09 وR6-11، وR6-10 لم يُؤخذ)

الفرع `wp/F9` (الأساس `int/r1` عند `4706fac`). قواعد الاختبار `ospos_test_f9` (الاختبارات) و`ospos_test_f9_big` (نسخة مستعادة من لقطة R6 بـ40,004 مستندًا لقياسات القفل والقائمة). الآلة مشتركة (4 أنوية لعدة مقيّمين، وحصرتُ عمليات السباق والقياس بنواتين). الأدلة تحت
`/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/readiness/F9/`.

## 1. الخلاصة

| البند | الحالة | الإصلاح باختصار | الإيداع |
|---|---|---|---|
| **R6-07** التقارير القديمة وبناة `Customer` و`Receiving` تتشابك (`Deadlock`) مع حفظ المبيعات | **أُصلح** | دالة مشتركة واحدة `ReadCommittedStatement::run` تُشغّل كل `CREATE TEMPORARY TABLE ... AS SELECT` بقراءة دون أقفال؛ استُعملت في **12 عبارة** بـ5 ملفات (وقائمة المبيعات نفسها صارت تستعملها بدل الدالة الخاصة بها) | `2488b0d` و`ee267ae` |
| **R6-08** بعد خطأ واحد لقاعدة البيانات تفشل كل المعاملات التالية في العملية | **أُصلح** على مستوى حافظي المستندات | `FailedTransaction::forget`: صاحب المعاملة ينسى الفشل بعد أن قرأ حالته وما دام لا معاملة لمستدعٍ مفتوحة؛ في `Sale::save_value` و`Receiving::save_value` و`Expense::save_value`؛ والمولّد والاستهلاك كانا سليمين (انظر 3) | `1a43c2d` |
| **R6-09** سطر `log_message('error', SQL)` عند كل طلب قائمة | **أُصلح** | حُذف (السطر الوحيد من نوعه)؛ العتبة الافتراضية للإنتاج 4 فكان يُكتب | `2488b0d` و`00301f0` (الاختبار) |
| **R6-11** `DATE(sale_time)` في قائمة المبيعات | **أُصلح** | `DayRange::condition`: مدى نصف مفتوح على العمود الخام؛ النتائج نفسها (مسح 134 منطقة زمنية) | `00301f0` |
| **R6-10** استعلامات N+1 | **لم يُؤخذ** | ليس صغيرًا ولا آمنًا (انظر 6) | — |

لم أغيّر قرارًا من `decisions.md`، ولا هجرة، ولا مفتاح لغة (فلا `LanguageFilesTest` جديد؛ شغّلتُه مع ذلك)، ولا اعتماد Composer.

## 2. R6-07: القفل بين التقارير وحفظ المبيعات

**السبب (كما في R6 وأكدتُه):** `CREATE TEMPORARY TABLE ... AS SELECT` تقرأ جداولها بأقفال مشتركة تحت `REPEATABLE READ`: تنتظر كل مستند قيد الحفظ وتمسك صفوفًا يحتاجها الحفظ التالي (ومدى `sales` بأقفال الفجوات) فيتشابكان وتقول الشاشة «The change could not be saved». الإصلاح الذي أصلح به R6 قائمة المبيعات (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` لـ«المعاملة التالية» وهي العبارة نفسها) صار **دالة واحدة** تستعملها كل البناة:

- `app/Libraries/Database/ReadCommittedStatement.php`: `run(BaseConnection $db, string $sql)`. **الاستعادة:** الإعداد بلا `SESSION` يسري لمعاملة واحدة فقط ويُسقطه الخادم عند نهاية العبارة التالية **نجحت أم فشلت** (جرّبتُ العبارة الناجحة والتي بجدول غير موجود والتي بخطأ صياغة ثم `START TRANSACTION`: المستوى بعدها `REPEATABLE-READ` في الحالات الثلاث). لهذا لا يحتاج الأمر `try/finally` ولا يُترك الاتصال على `READ COMMITTED` بعد بنّاء فاشل؛ وجرّبتُ بديلًا بـ`SET SESSION` فرسب اختباراي الاستعادة (فهما يكشفان إن بقي المستوى). داخل معاملة مفتوحة لا يمكن تغيير المستوى (خطأ 1568) فتُنفَّذ العبارة بمستوى المعاملة كما كانت، ورفض الإعداد لا يوقف التقرير (يُبلَع).
- **المواضع الـ12:** `Sale::create_temp_table` (3: `sales_items_taxes_temp` و`sales_payments_temp` و`sales_items_temp`، وتصل إليها `Detailed_sales` و`Detailed_item_sales` و`Specific_customer` و`Specific_discount` و`Specific_employee` و`Specific_supplier` و`Sale::get_info`)، و`Sale::create_temp_table_sales_payments_data` و`..._items_taxes_data` (قائمة المبيعات، 2)، و`Receiving::create_temp_table` (`Detailed_receivings`)، و`Customer::get_stats`، و`Summary_report::__common_select` (2؛ يصل إليها كل ما يرث منه: `Summary_sales` و`_items` و`_categories` و`_customers` و`_suppliers` و`_employees` و`_discounts`...)، و`Summary_payments::create_summary_payments_temp_tables` (3).
- **ما لم يُغيَّر:** `Sale::assertChangeStaysCovered` تنادي `get_info` **داخل** معاملة تعديل الدفعات (لا يمكن تغيير المستوى هناك، وهي تقرأ بيعًا واحدًا تحمل قفله). `RoleService.php:360` (`INSERT IGNORE ... SELECT` على `role_permissions`: عملية إدارية صغيرة لا علاقة لها بالمبيعات). بقية التقارير (`Inventory_*` و`Summary_taxes` وغيرها) `SELECT` عادية بقراءة متسقة بلا أقفال.

**الاختبارات (أولًا اختبار ثم إصلاح):**
- **حتمي، لكل بنّاء** (كاتب على اتصال ثانٍ يبقي بيعًا أو شراءً غير مثبّت بكل جداوله، والقارئ ينتظر ثانية واحدة للقفل بدل خمسين): `SaleTest::testCreateTempTable_...`، `CustomerTest::testGetStats_ReadsTheSalesOfACustomer...`، `ReceivingTest::testCreateTempTable_...`، `Summary_salesTest::testGetData_BuildsTheDays...`، `Summary_paymentsTest::testGetData_BuildsThePayments...`، و`SaleTest` القديم للقائمة. **قبل: الخمسة الجديدة تفشل بـ«Lock wait timeout exceeded»** (`r6-07-tests-before-fix.txt`)؛ بعد: تنجح.
- **الاستعادة والمعاملة:** `ReadCommittedStatementTest` (5 دوال): بلا انتظار؛ المعاملة التالية على مستوى الاتصال بعد النجاح وبعد الفشل؛ داخل معاملة؛ وعند تعذّر الإعداد (`START TRANSACTION` يدوي).
- **جلستان حقيقيتان** (`RaceWorker.php` طريقة `lockRace` الجديدة، وعامل `ConcurrentDbRaceTrait::raceSessions`، ويُحصي `Innodb_deadlocks` من الخادم): جلسة تحفظ 40 بيعًا (أو شراء) في حلقة وأخرى تبني البنّاء مرارًا؛ يؤكد الاختبار 0 حفظًا مرفوضًا و0 `Deadlock` و0 بناء فاشلًا لكل من البناة الستة (`testRun_LetsTheDocumentsBeSavedWhileTheBuilderRunsOverAndOverInAnotherSession`). نفس الجلستين على نسخة 40k بـ`tools/benchmarks/f9/lock-race.sh` (حفظ وبناء على نافذة 2025-06-01 إلى 2025-09-30).

**عدد الـ`Deadlock` قبل وبعد** («قبل» = العبارة بمستوى الاتصال، أي الدالة بلا إعداد المستوى):

| البنّاء | قاعدة الاختبار الصغيرة (3000 بيع تاريخي، حافظ واحد × 40) قبل → بعد | نسخة 40k (حافظ واحد × 120) قبل → بعد | نسخة 40k (3 حافظين × 60): بناءات تمت في المدة نفسها قبل → بعد |
|---|---|---|---|
| قائمة المبيعات | 0 → 0 | 0 → 0 | 1 → 55 |
| جداول التقارير (`Sale::create_temp_table`) | 0 → 0 | 0 → 0 | 1 → 18 |
| **أرقام العميل (`Customer::get_stats`)** | **39 `Deadlock` و39 بناءً ضاع → 0** | **2 `Deadlock` وبيعان رُفضا → 0** | 65 → 1693 |
| ملخص المبيعات (`Summary_report`) | 0 → 0 | 0 → 0 | 1 → 66 (`summarysales-3savers-*.txt`) |
| **ملخص الدفعات (`Summary_payments`)** | **12 `Deadlock` و12 بيعًا رُفضت → 0** | 0 → 0 | 1 → 146 |
| جدول المشتريات (`Receiving`) | 0 → 0 | 0 → 0 | 181 → 88 (لا تنازع في الحالتين) |

**قراءة صادقة:** الـ`Deadlock` **احتمالي**: ظهر في بنّاءين في التشغيلات القصيرة (أرقام العميل وملخص الدفعات) ولم يظهر في الباقي رغم أن الانتظار المتبادل ثابت: **قبل الإصلاح كان البنّاء يبني مرة واحدة في المدة التي يبني فيها بعد الإصلاح 18 إلى 1693 مرة** لأنه ينتظر الحافظين (الصف الأخير من الجدول). R6 رصد 2 إلى 3 `Deadlock` في 23 طلبًا بحمل توليد مختلف (محاسبة وأنواع مستندات أكثر). الاختبار الحتمي (انتظار القفل) هو الدليل القاطع على أن القراءة تأخذ أقفالًا؛ وجلستا السباق دليل أن النتيجة بعد الإصلاح 0 `Deadlock` و0 حفظًا مرفوضًا في **كل** التشغيلات.

## 3. R6-08: المعاملة المسمومة

**السبب:** `BaseConnection::$transStatus` في الوضع الصارم (`transStrict`، الافتراضي) يصير `false` عند فشل عبارة داخل معاملة ولا يعود إلا بـ`resetTransStatus()`؛ و`transStart()` لا يعيده، فكل `transComplete()` لاحق في العملية ينظر `false` فيتراجع (دون أي خطأ قاعدة) ويعيد `-1`. من يملك المعاملة ويعيد الضبط عند الفشل: `RunsInTransaction` و`PostingService` و`ReadinessRunner` و`AccountingBackfill` و`AccountingBenchmark`. من لا يعيده: حافظو المستندات في النماذج (`Sale::saveValueLocked` و`Receiving::saveValueLocked` و`Expense::save_value/saveRow` تنادي `transStart/transComplete` مباشرة).

**أين الحل ولماذا:** `transStrict(false)` عامًا يكسر المعاملات المتداخلة (فشل الداخلية يُعاد ضبطه فتثبّت الخارجية نصف العمل)؛ وصنف اتصال خاص يعيد الضبط داخل `transComplete` يمنع من يقرأ `transStatus()` بعده (والنماذج تقرؤه). فالحل أن **من بدأ المعاملة ينساها بعد أن قرأ جوابها** وما دامت لا معاملة لمستدعٍ مفتوحة: `app/Libraries/Database/FailedTransaction.php` (`forget`: يعيد الضبط إن كان `transDepth === 0`)، يُنادى في `finally` لـ`Sale::save_value` و`Receiving::save_value` و`Expense::save_value` (الأخير صار غلافًا حول `saveExpense` الخاص، المتن نفسه). لم أضف إعادة ضبط عند كل عنصر في الحلقات لأن الحافظين والخدمات صارت كلها تعيده.

**ما أثبتّه (حقن خطأ قاعدة حقيقي بـ`TRIGGER` يرفض إدراج صف علامته معروفة؛ ثلاثة مستندات والثاني يُرفض والثالث يجب أن ينجح؛ `tests/Support/DatabaseFailureTrait.php`):**

| الاختبار | قبل | بعد |
|---|---|---|
| `SaleTest::testSaveValue_SavesTheNextSaleOfTheProcessAfterTheDatabaseRefusedOne` | **يفشل** (الثالث `false`) | ينجح |
| `ReceivingTest::testSaveValue_SavesTheNextPurchaseOfTheProcessAfterTheDatabaseRefusedOne` | **يفشل** | ينجح |
| `ExpenseTest::testSaveValue_SavesTheNextExpenseOfTheProcessAfterTheDatabaseRefusedOne` (المحاسبة تعمل) | **يفشل** | ينجح |
| `ExpenseTest::...WhileAccountingIsOff` | **يفشل** | ينجح |
| `RecurringGeneratorTest::testRun_MakesTheInvoiceAfterOneWhoseSaveTheDatabaseRefused` و`...MakesTheExpenseAfter...` (مولّد الفواتير والمصروفات المتكررة: ثلاثة جداول والثاني يفشل في القاعدة) | **ينجحان قبل الإصلاح أيضًا** | ينجحان |
| `AccountingDepreciateTest::testRun_PostsTheNextBranchAfterTheDatabaseRefusedTheRunOfOne` (الأمر لثلاثة فروع في عملية واحدة والثاني يفشل في القاعدة: الخروج 0 ثم 1 ثم 0) | **ينجح قبل الإصلاح أيضًا** | ينجح |
| `FailedTransactionTest` (2) | — | ينجح |

(`r6-08-tests-before-fix.txt`.) أي إن **`accounting:generate_recurring` و`accounting:depreciate` كانا محميين** لأن كليهما يمر بـ`RunsInTransaction` (يعيد الضبط عند الفشل)؛ العيب يصيب من يحلق بحافظي المستندات مباشرة (مولّد R6 وأي سكربت أو أمر مستقبلي). بقية الأوامر: `accounting:backfill` يعمل بـ`transactional` لكل دفعة ويقف عند الخطأ **عمدًا** (يكمل من المؤشر)؛ `accounting:readiness` و`accounting:benchmark` يعيدان الضبط؛ لا أمر آخر يحلق على مستندات ويكمل بعد فشل عنصر.

**ما بقي:** نماذج أخرى تبدأ معاملات بنفسها (`Sale::delete` و`Sale::issueDraft` و`Receiving::delete_value` و`Customer::save_customer` و`Item` و`Employee`...) لم ألمسها: لا حلقة أمر تناديها اليوم؛ تُضاف `FailedTransaction::forget` في `finally` عند أول حلقة تحتاجها.

## 4. R6-09: السجل

- السطر `log_message('error', $sub_query)` في `Sale::create_temp_table_sales_payments_data` (كان `Sale.php:3358` في R6) حُذف مع تحويل الدالة إلى المساعد؛ **وهو السطر الوحيد من نوعه**: فحصتُ كل `log_message` في `app/Models` و`app/Libraries` و`app/Controllers` و`app/Helpers` و`app/Filters` و`app/Config`، والباقي في معالجات خطأ فعلية أو عند سوء ضبط (مثل `app.allowedHostnames` الفارغ).
- **معنى العتبة:** `app/Config/Logger.php:42`: `threshold = production ? 4 : 9`؛ المستويات 1 إلى 4 هي الطوارئ والتنبيه والحرج والخطأ، فالسطر بمستوى `error` **كان يُكتب بالقيمة الافتراضية للإنتاج** (سطر لكل طلب قائمة ولكل طلب عدّ). `0` يعطي `range(1, 0)` أي الطوارئ تقريبًا فقط (كما كتب F5)، و`9` كل شيء. **ملاحظة:** `.env.example:86` فيه `logger.threshold=0` وهو ما يحذّر منه الدليل التشغيلي للإنتاج؛ لم أغيّره (ملف مشترك)، والأولى حذف السطر أو التعليق عليه.
- **الاختبار:** `SaleTest::testSearch_WritesNothingToTheLog` (القائمة والعدّ لا يكتبان شيئًا في `TestLogger`): **فشل (84 مقابل 82) والسطر موضوع، ونجح بعد حذفه**.

## 5. R6-11: `DATE(sale_time)` في القائمة

- `app/Libraries/Database/DayRange.php`: `condition($db, $column, $first, $last)` يعطي `(col >= 'أول يوم 00:00:00' AND col < 'اليوم بعد الأخير 00:00:00')` على العمود الخام ليخدمه فهرس `sale_time`؛ وحدّ ليس يومًا صافيًا (فيه وقت أو غير صالح أو `null` أو بعد 9998) يبقى على `DATE(col) BETWEEN` كما كان. مستعمل في `Sale::search` (وتمر به `get_found_rows`) و`Sale::getPaymentsSummary`، أي قراءات الشاشة الثلاث. لم ألمس `DATE()` في التقارير القديمة (`create_temp_table` و`Summary_report` و`Receiving`): خارج النص المطلوب.
- **التكافؤ:** العمود `TIMESTAMP` والجلسة بمنطقة الاتصال، وكلا الشكلين يقرأ اللحظة بها. **مسح** (`dst-sweep.sh`، `dst-sweep.txt`): لكل واحدة من **134 منطقة** يتغير فيها الإزاحة خلال 2024 (من بينها القاهرة وبيروت وهافانا وسانتياغو التي تتحرك ساعاتها عند منتصف الليل)، لحظة كل 15 دقيقة طوال السنة: **0 مناطق فيها لحظة يضعها المدى في يوم غير يوم `DATE()`**. وفي `DayRangeTest` 11 اختبارًا: الثانية الأولى والأخيرة من اليوم، وخمس مناطق متحركة وواحدة ثابتة بيومين قبل وبعد كل انتقال، وتواريخ بعد 2038 و9999، وأشكال الحدود غير الصافية، و`EXPLAIN` (المدى `range` وبضع مئات من الصفوف مقابل أكثر من 5000 لـ`DATE()`). وفي `SaleTest` اختباران للقائمة نفسها: حدود الأيام (23:59:59 و00:00:00) وأيام القاهرة حول انتقال الربيع، مقارنةً بـ`DATE()`.
- **القياس** (نسخة 40k المستعادة، `tools/benchmarks/f9/list-bench.php`: قراءات الشاشة الثلاث، وسيط 7 تشغيلات، نواتان من آلة مشتركة تحمّلها ~1، `r6-11-bench-old.txt` و`-new.txt`): يوم واحد (136 بيعًا) **150 ms ← 17 ms**؛ 30 يومًا (5088) 437 ← 327 ms؛ أربعة أشهر (16,588) 1069 ← 1016 ms؛ كل التواريخ (44,921) 2486 ← 2460 ms؛ **العدد نفسه في كل مدى**. أي إن الربح كبير للمدى الضيق (صفحة اليوم الافتراضية) وضئيل للواسع (الوقت فيه بناء الجداول المؤقتة لا مسح `sales`).

## 6. R6-10: لم يُؤخذ

هو عدة مواضع (`suspended` استعلامان، بحث العملاء 101 استعلامًا لـ25 صفًا، `readiness::ties` نحو 19 ألفًا، تقارير المشتريات نحو 1,640)، كلٌّ منها يحتاج تجميعًا في استعلام واحد وإعادة اختبار نتائجه (ترتيب وحدود وصلاحيات): ليس صغيرًا ولا آمنًا ضمن هذا الإصلاح، وهو بلا أثر على التشغيل بالحجم الواقعي (R6: زمن ثابت). التقدير: يوم لكل موضع مع اختبار تكافؤ النتائج.

## 7. ما لاحظتُه ولم أصلحه (خارج البنود)

1. **IMPORTANT (جديد، نفس عيب R6-03):** `ENGINE=MEMORY` في بناة التقارير القديمة يسقط عند نحو 35 ألف بيع في المدى (`max_heap_table_size` الافتراضي 16 MB): على نسخة 40k (44,921 بيعًا) مدى كل التواريخ في `Sale::create_temp_table` (التقارير التفصيلية والنوعية) و`Summary_report` (ملخصات المبيعات) يعطي «The table 'ospos_sales_items_taxes_temp' is full» (`memory-table-full-legacy-builders.txt`)؛ `Summary_payments` و`Customer::get_stats` نجحا. التقرير السنوي لمنشأة بنحو 5 آلاف بيع شهريًا يبلغ الحد في أقل من سنة. **العلاج:** حذف `ENGINE=MEMORY` من `Sale.php` (جدول الضرائب في `create_temp_table`) و`Summary_report.php:45` و`Summary_payments.php` (الثلاثة) كما فعل R6 في القائمة (`3518da8`)، مع اختبار 16,000 سطر ضريبة وحد 1 MB؛ نحو ساعتين. لم آخذه لأنه خارج بنود التكليف.
2. بنّاء التقارير القديمة `Summary_report::getData` يقرأ `config(OSPOS::class)` **بعد** `table('sales_items AS sales_items')`؛ فإن كانت الإعدادات غير محمّلة (أول استعمال في عملية بلا طلب ويب، أو بعد مسح مخزن الإعدادات) فاستعلام تحميلها يمسح أسماء الجداول المستعارة فيفشل التقرير بـ«Unknown column 'ospos_sales_items.sale_id' in 'ON'» (عاينتُه في عامل السباق وفي اختبار ثم حمّلتُ الإعدادات قبل القراءة، وشرحتُ ذلك في `UncommittedWriterTrait`). في الويب الإعدادات محمّلة قبل أي تقرير فلا يظهر؛ العلاج: قراءة الإعدادات قبل إنشاء البنّاء.
3. المساعد يفترض أن الخادم لا يمنع `READ COMMITTED` مع `INSERT ... SELECT`: في MariaDB/MySQL مع التسجيل الثنائي مفعّلًا وصيغته `STATEMENT` (غير الافتراضية `MIXED`) ترفض InnoDB العبارة (خطأ 1665). لم أتحقق منه هنا (`log_bin=0`)؛ ينبغي أن يذكر دليل النشر ألا يُضبط `binlog_format=STATEMENT` (وهو افتراض R6 في `fa2d7ec` نفسه).
4. `.env.example` يضبط `logger.threshold=0` (انظر 4).

## 8. الاختبارات التي شغّلتُها (آخر شجرة، `ospos_test_f9`)

نتائج `suite-after.txt` (قبل آخر تعديلين للاختبارات) و`suite-final.txt` (بعدهما):

| الملف | النتيجة |
|---|---|
| `tests/Libraries/Database` (المساعدات الثلاثة والسباق، 24 اختبارًا) | تنجح |
| `SaleTest` | 148+ تنجح |
| `CustomerTest` و`ReceivingTest` و`ExpenseTest` | تنجح |
| `tests/Models/Reports` (كل `Summary_*` و`Detailed_*` و`Specific_*` و`Inventory_*`: 102 اختبارًا، 5 متخطاة وهي قديمة في `Summary_taxesTest`) | تنجح |
| `RecurringGeneratorTest` و`tests/Libraries/Recurring` كلها (197) | تنجح |
| `DepreciationRunTest` (60) و`AccountingGenerateRecurringTest` و`AccountingDepreciateTest` و`AccountingBackfillTest` و`AccountingBenchmarkTest` | تنجح |
| `tests/Libraries/Accounting/Backfill` (195) | تنجح |
| `RaceWorkerTest` (6) و`LanguageFilesTest` (3860) | تنجح |
| `ReportsControllerTest` (50) و`CustomersControllerTest` (135) و`ExpensesControllerTest` (74) | تنجح |
| **`SalesControllerTest` كاملًا (672 اختبارًا، 4727 تأكيدًا)** | تنجح |

(في التشغيل الأول رسب اختبار الملخص الجديد داخل الدليل لأن الإعدادات لم تكن محمّلة؛ انظر 7-2؛ صُحّح وأُعيد تشغيل الدليل كله.)

## 9. الملفات

- جديد: `app/Libraries/Database/{ReadCommittedStatement,FailedTransaction,DayRange}.php`.
- معدّل: `app/Models/{Sale,Receiving,Customer,Expense}.php` و`app/Models/Reports/{Summary_report,Summary_payments}.php`.
- اختبارات: `tests/Libraries/Database/{ReadCommittedStatement,FailedTransaction,DayRange}Test.php` (جديدة)، وإضافات في `SaleTest` و`ReceivingTest` و`CustomerTest` و`ExpenseTest` و`Summary_salesTest` و`Summary_paymentsTest` و`RecurringGeneratorTest` و`AccountingDepreciateTest`، و`tests/Support/{UncommittedWriterTrait,DatabaseFailureTrait}.php` (جديدان) و`tests/Support/RaceWorker.php` (`lockRace` إضافة بلا تغيير للموجود).
- أدوات تطوير: `tools/benchmarks/f9/lock-race.sh` و`list-bench.php`.
- الأدلة: `r6-07-tests-before-fix.txt` و`r6-08-tests-before-fix.txt` و`race-small-before.log` و`race-small-after.log` و`race-40k-1saver-{before,after}.txt` و`race-40k-3savers-{before,after}.txt` و`summarysales-3savers-{before,after}.txt` و`summarysales-flake*.txt` و`r6-11-bench-{old,new}.txt` و`dst-sweep.txt` و`memory-table-full-legacy-builders.txt` و`suite-after.txt` و`suite-final.txt`.

## 10. ما لم أتحقق منه

- `binlog_format=STATEMENT` مع `READ COMMITTED` (7-3).
- سلوك الإعداد عبر وسيط اتصالات (pooler) يعيد استعمال الاتصال: الإعداد يسقط بنهاية العبارة التالية فلا يتسرب، لكنني لم أجرّب وسيطًا.
- الـ`Deadlock` الاحتمالي في بناة غير أرقام العميل وملخص الدفعات: لم أحصل على حالة في تشغيلاتي (انظر القراءة الصادقة في 2).
- مسح المناطق الزمنية على 2024 فقط (لا سنوات أخرى) وبدقة 15 دقيقة.
