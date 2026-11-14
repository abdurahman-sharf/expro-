# تقرير F2: هروب النصوص المخزَّنة في القوائم والتسميات (R3-I5)

> الحالة (2026-11-14): **إصلاح على الفرع `wp/F2`** (من `61d4015`، قمة `int/r1`)، على قواعد `ospos_test_f2*` وحدها. شُغِّل خادم PHP المدمج على المنفذ **8192** لا 8092 (المنفذ 8092 كان يخدمه خادم `R1-tools` فلم أمسّه). **لا دمج ولا نشر.** لا مفتاح لغوي جديد ولا ترحيل ولا إعداد ولا صلاحية، ولا قرار من `decisions.md` تغيّر.
> الأدلة في `/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/f2/` (القسم 9).

## 1. الحكم المختصر

| السؤال | الجواب |
|---|---|
| هل أُغلقت R3-I5؟ | **نعم.** `form_dropdown` و`form_label` يهرّبان نصهما في مساعد واحد (`app/Helpers/form_helper.php`). **زحف التسميم المعاد في كروم (391 صفًا مسمَّمًا في 218 عمودًا نصيًا من 102 جدولًا، 305 شاشة): قبل الإصلاح 24 عنوانًا تنفِّذ السكربت أو تُنبت عناصر مُحقنة (17 شاشة)، بعده صفر.** |
| هل يضمن ذلك ألا يتكرر الصنف نفسه؟ | يقلّ احتماله بنيويًا: أي استدعاء جديد للمساعدين يرث الهروب. **لا يغطي** قيمة تُطبع مباشرة بـ`<?= $x ?>` خارجهما؛ تلك تكشفها الأداة الجديدة `tools/dev/xss-poison-crawl.mjs` حين تُعاد (القسم 7). |
| نقاط JSON وتحديث الجداول بعد التعديل | **لا ثغرة ولا إصلاح مطلوب** (القسم 4): الجداول تهرّب كل عمود بـ`escape: true`، و`$.notify` يمرّر الرسالة على `DOMPurify` بقائمة بيضاء فارغة؛ جُرّب في كروم بتمرير `row/ID` إلى كل جدول وبحفظ أول صف في كل قائمة. |
| ما وُجد زيادة على R3 | **ثغرتان لم تُرَيا في R3 وأُغلقتا**: `sales/edit/ID` يطبع بريد العميل داخل سلسلة JavaScript بلا هروب، و`accounting_journals/reverse_form/ID` يطبع رقم القيد خامًا (الرقم محكوم بنمط البادئة فهو دفاع بالعمق). وتسعة عناوين أخرى لم تكن في عيّنة R3 لأن قاعدتها كانت فارغة منها (نموذجا دفعة العميل المقدمة واستردادها بأسماء الخزائن، و`config`، و`taxes/view` بعرضيه، و`receivings/edit`). |
| قيد على R3 (مهم) | **زحف R3 في المتصفح لم ينفِّذ سكربتات التطبيق.** موجِّه PHP المدمج الذي استعمله R3 (`r3tools/dev/router.php`، وكذلك موجِّه `R1-tools` القائم على المنفذ 8092) يستدعي `require rewrite.php` بلا `return`، فيخدم كل ملف ثابت (`vendor.min.js` و`app.js`) **فارغًا** (200 وطول 0)؛ فلم تعمل الجداول المحمَّلة بـAJAX ولا الإكمال التلقائي في ذلك الزحف، وفشل `seed-sales.mjs` بالعَرَض نفسه (`.ui-autocomplete li`) في R3 وفي أول محاولاتي، ثم نجح بـ`return require`. فحكم R3 «الجداول المحمَّلة بـAJAX كلها آمنة» كان على مستوى الاستجابة؛ **هذا التقرير يتحقق منه في متصفح بجافاسكربت كاملة.** (استنتاج لا ملاحظة: لم أرَ خادم R3.) |

## 2. القياس والقاعدة المقرَّرة

**ما قِيس قبل القرار:**

| القياس | العدد |
|---|---|
| استدعاءات `form_dropdown(` | 148 في 50 عرضًا |
| استدعاءات `form_label(` | 527؛ 507 منها `lang()` وحدها، و6 `lang() . " $i"`، و4 متغيرات (`$license['title']` و`$label` و`$specific_input_name` مرتين)، والباقي نص لغة مع قيمة مهرَّبة أو اسم موقع (`items/form.php:468` وهو الذي سرّب) |
| مواضع تمرّر نصًا **مهرَّبًا أصلًا** بـ`esc()` | **25**: 16 في العروض (`sales/register` و`sales/form` و`customers/manage` و`customers/form` و`items/form` و`receivings/form` و`accounting_settings/manage`)، و9 تبني مصفوفة الخيارات في المتحكم (`Expenses` ×3 و`Expenses_categories` و`Sales` و`Customers` و`Reports` ×2 وهو إصلاح المقيِّم، و`esc($filters)` في `expenses/manage`) |
| مواضع الكتابة التي تخزّن **كيانات مرمّزة** (`FILTER_SANITIZE_FULL_SPECIAL_CHARS`) | 154 في 18 متحكمًا (الموظفون والموردون والمصروفات والأصناف...)؛ القوائم القديمة تطبع هذه النصوص خامة فتبدو سليمة |
| سطور اللغة التي فيها وسم HTML | سطر واحد (`Config.payment_reference_code_length_limits` فيه `<br>`) في 46 لغة، يُستعمل في `form_label` واحد |

**القاعدة المقرَّرة:** المساعد يهرّب، والمتحكمات والعروض تمرّر النص كما خُزِّن. اخترتُ **الهروب بلا ترميز مزدوج** (`htmlspecialchars` مع `double_encode` معطّل) لا الهروب الكامل مع إزالة `esc()` من المواضع الـ25، لثلاثة أسباب: (1) الهروب الكامل يطبع `&amp;lt;` على كل موضع يمرّر `esc()` وعلى كل اسم موظف أو مورد خزّنه الكود القديم مرمَّزًا (`R&amp;D`)؛ (2) إزالة `esc()` تمسّ 12 ملفًا مشتركًا يعمل عليها مصلحون آخرون الآن، فتزيد خطر التعارض؛ (3) الهروب بلا ترميز مزدوج **لا يفتح ثغرة**: الكيان الجاهز يظلّ نصًا في عقدة نصية ولا يُنهي قيمة سمة. **الثمن المقبول:** اسم كتبه مستخدم حرفيًا `&amp;` يظهر `&` في القوائم التي تمرّر نصها خامًا (وهو ما كانت تفعله القوائم القديمة)؛ والمواضع الـ25 المهرَّبة تظهره كما كُتب. **العلامة الآمنة:** `App\Libraries\SafeHtml` لمن يعطي المساعد نص HTML موثوقًا (استُعملت مرة واحدة: سطر اللغة ذو `<br>` في `configs/locale_config.php`). بقيت `esc()` في المواضع الـ25 دون مسّ (لا ضرر منها، وتُزال حين تفرغ الملفات).

**ما يفعله المساعد:** `form_dropdown` (نسخة من إصدار 4.7.4 مع تهريب نص الخيار ونص `optgroup`) و`form_label` (تهريب النص و`for` وقيم السمات). يحمّل CodeIgniter ملف التطبيق قبل ملفه فتُتخطّى تعريفاته (`function_exists`)، وهو النمط الموثّق للتوسيع. `form_multiselect` يمرّ على `form_dropdown` فيُغطّى. **تنبيه صيانة:** `form_dropdown` نسخة من إطار العمل، فعند ترقية CodeIgniter يُقارن جسمها بالأصل.

**إصلاحان خارج المساعد:** `sales/form.php` يطبع بريد العميل في `confirm("…")` فصار `esc(…, 'js')` (كالمواضع الأخرى التي تفعل ذلك)، و`accounting_journals/reverse_form.php` يهرّب رقم القيد.

## 3. الشاشات قبل وبعد

**المنهج:** قاعدة معاينة كاملة (`ui-smoke` بكل بذوره: مبيعات ومشتريات ومرتجع ودفعة مورد وخزائن وتحويل وقيود ومخزون افتتاحي) + 32 صفًا رئيسيًا ومستندًا مضافًا بأسماء عدائية + تسميم **218 عمودًا** في **102 جدولًا** (391 صفًا في 52 جدولًا منها صفوف) بالسلسلة `"'></script><img src=x onerror=zq9(1)>` (أو `<b zq9>` للأعمدة القصيرة)، ثم زحف **1680 طلب GET** وتحميل **305 شاشة HTML** في كروم 153 بجلسة المدير، يرصد `pageerror` وحوارات `alert` وعناصر `img[src=x]` و`[onerror]` و`b[zq9]` في DOM النهائي. الجدول من تشغيل **قبل** على `61d4015` (شجرة عمل منفصلة) وتشغيل **بعد** على `wp/F2`، **على القاعدة نفسها** (نسخة `mysqldump` واحدة تُحمَّل قبل كل تشغيل).

| العنوان | الحقل | قبل | بعد |
|---|---|---|---|
| `accounting_receivables/advance_form/ID` و`refund_form/ID` (**جديدان**) | قائمة الخزينة `treasury_id` (اسم الخزينة) | تنفِّذ | نص حرفي |
| `api_keys` | قائمة الفرع `branch_id` (اسم الفرع) | تنفِّذ | نص حرفي |
| `accounting_journals/reverse_form/ID` ×2 (**جديد**) | رقم القيد في الفقرة | عناصر مُحقنة | نص حرفي |
| `reports/inventory_summary` | قائمة موقع المخزون | تنفِّذ | نص حرفي |
| `reports/specific_employees` | قائمة الموظف | تنفِّذ | نص حرفي |
| `reports/specific_suppliers` | قائمة المورد | تنفِّذ | نص حرفي |
| `cashups/view` و`cashups/view/1` | قائمتا الموظفين | تنفِّذ | نص حرفي |
| `config` (**جديد**) | قائمة فئة الضريبة الافتراضية `default_tax_category` | عناصر مُحقنة | نص حرفي |
| `expenses/view` و`expenses/view/1` | قائمة فئة المصروف وقائمة الموظف | تنفِّذ | نص حرفي |
| `items/view` و`items/view/1` | قائمة المورد **وتسمية «الكمية <الموقع>» (`form_label`)** | تنفِّذ | نص حرفي |
| `items/inventory` و`/1` و`items/countDetails` و`/1` | قائمة موقع المخزون | تنفِّذ | نص حرفي |
| `items/bulkEdit` | قائمة المورد | تنفِّذ | نص حرفي |
| `receivings/edit/1` (**جديد**) | قائمة الموظف | تنفِّذ | نص حرفي |
| `sales/edit/1` (**جديد**) | **بريد العميل داخل `confirm("…")` في سكربت الصفحة** (ليس قائمة؛ الإغلاق `esc(…, 'js')`) | تنفِّذ | آمن |
| `taxes/view` و`taxes/view/1` (**جديدان**) | قائمة فئة الضريبة `rate_tax_category_id` | عناصر مُحقنة | نص حرفي |

**المجموع:** 24 عنوانًا (17 شاشة) قبل، **صفر** بعد. R3 عدّت 16 عنوانًا (11 شاشة): 15 منها في جدولي، و`reports/specific_customers` أصلحه المقيِّم فبقي سليمًا؛ والتسعة الأخرى جديدة (موسومة «جديد»). **الحالة بعد:** «نص حرفي» تعني أن النص الخام (`<img src=x onerror=zq9(1)>`) يظهر في `innerText` الصفحة كما هو؛ عدد الشاشات التي تعرضه حرفيًا: **209 قبل و217 بعد**. و«**لا ترميز مزدوج**»: 0 استجابة HTML فيها `&amp;lt;` أو `&amp;amp;` أو `&amp;quot;` أو `&amp;#039;` قبل وبعد (1680 طلبًا).

**أسماء عادية بالفاصلة العليا والعلامتين و`&`** (فحص «الحرفية» `literal` على قاعدة نظيفة، **11 قائمة وتسمية، بالإنجليزية والعربية**، الاسم `Tom & Jerry's "Café" مرحبا`): تظهر كما كُتبت في كل المواضع، **ولا `&amp;` ظاهر**: `items/inventory` و`items/view` (التسمية) و`api_keys` وقائمة الموظف في `cashups/view` وموردو وعملاء التقارير وموقع `inventory_summary` وخزينة `advance_form` وفئة الضريبة `taxes/view` وفئة المصروف وفرع `expenses/view`. و**`ui-smoke` على 20 شاشة (نوافذ الأصناف والمبيعات والمشتريات والمصروفات والإغلاق اليومي والضرائب ومفاتيح الواجهة وخمس صفحات إعدادات والتقارير والخزائن وعكس القيد) بالعربية والإنجليزية: 40 زيارة (20 شاشة × لغتين)، 37 نظيفة في التشغيل الأول والثلاث الباقية (بالعربية: `config-barcode` و`config-invoice` و`cashups-new-modal`) أفشلها طلب `?debugbar_time=…` (500 من شريط التطوير لا من الصفحة) وأعدتُها فنجحت؛ صفر أخطاء صفحة وصفر عقد axe وصفر انزلاق أفقي وصفر وسوم Bootstrap 3.**

## 4. نقاط JSON وتحديث الجداول بعد التعديل

**ما رصده R3:** ردود `row/ID` و`search` وطرق `suggest` تحمل نصًا خامًا (`customer_name` و`description` و`first_name` و`[].label`) والجدول لم يُجرَّب بعد تعديل في متصفح. **الواقع:** الاستجابات تحمل النص خامًا فعلًا (153 استجابة JSON على 80 مسارًا في زحف القاعدة الكاملة، **ولم أغيّرها**: التهريب عند العرض لا عند الإخراج هو التصميم هنا)، **والمستهلكون كلهم يهرّبون**:

| المستهلك | ما يفعله | الدليل |
|---|---|---|
| جداول bootstrap-table (كل القوائم) | `escape: true` على الجدول كله (`resources/assets/js/30-tables.js:195`)؛ عمود الإجراءات `escape: false` لكنه يحمل HTML يبنيه الخادم بـ`anchor()` من نصوص اللغة لا من القاعدة، والبريد (`mailto`) مهرَّب في الخادم؛ والأعمدة التي يبنيها الخادم HTML (`escape => false`) تهرّب قيمها بـ`esc()` (قرأتُ `AccountingCheques::row` و`Receipt_vouchers`) | زحف الصفحات وخطوتا `refresh` و`edit` أدناه |
| الإكمال التلقائي (`suggest`) و`accounting_lookup` | `.text(entry.label)` في `45-accounting.js` و`75-search.js` و`jQuery UI` الافتراضي | قراءة الكود + فتح الصفحات |
| `$.notify(response.message)` | الغلاف يمرّر الرسالة على `DOMPurify.sanitize(message, {ALLOWED_TAGS: [], ALLOWED_ATTR: []})` قبل `bootstrap-notify`، فلا وسم يبقى (لوحظ: رسالة «تم تحديث العميل» لاسم عدائي تظهر بلا الوسوم) | `$.notify.toString()` في كروم + خطوة `edit` |

**التشغيل في المتصفح بعد الإصلاح:**

- **`refresh`:** 41 شاشة جدول × عرضان (1440 للمكتب و390 للهاتف حيث تُعرض بطاقات) = 82 حالة؛ في كلٍّ منها يُجلب `row/ID` لكل صف ويُدفع إلى الجدول بـ`updateByUniqueId` كما يفعل `table_support.handle_submit` بعد حفظ: **76 منها حدّثت صفًا على الأقل، صفر تنفيذ وصفر عناصر مُحقنة.** الباقي ست حالات: ثلاث شاشات (`accounting_receivables/credit_notes` و`accounting_treasuries/inter_branch` و`taxes`) بعرضيها، بلا نقطة `row/ID` أو بلا صفوف؛ وقد زرعتُ شيكات وسندات قبض وإقفالات يومية وأوامر وجردًا في القاعدة الأخيرة لتغطية ما أمكن (في التشغيل الأول على قاعدة أقل زرعًا حدّث 68 من 82).
- **`edit`:** أول صف في **23 قائمة** يُفتح في نافذته ويُحفظ كما هو (فتجري رسالة الرد وتحديث الصف): **23 حُفظت، صفر تنفيذ وصفر عناصر مُحقنة** (بعضها رفض الحفظ برسالة تحقق، مثل رقم الصنف والرمز في الفرع والحساب ومركز التكلفة وقد سُمِّمت أرقامها، ولا يضرّ: الرفض دليل التحقق عند الكتابة).
- **بوابة العميل:** 7 شاشات (الرئيسية وكشف الحساب والفواتير والإشعارات الدائنة والمدفوعات وكلمة السر وفاتورة) بحساب بوابة لعميل مسمَّم: 200 كلها، **صفر تنفيذ**، وكلها تعرض النص حرفيًا.

**لا إصلاح لنقاط JSON ولا لتحديث الجداول** (لا ثغرة لإصلاحها)؛ وسُجّل أن نص الرسالة في الإشعار يُجرَّد من الوسوم ولا يُعرض حرفيًا (تجميلي).

## 5. الاختبارات

كل اختبار **أُجري قبل الإصلاح فأخفق** (`run-before/tests-before-fix.log` و`tests-before-fix-journals.log`؛ استعدتُ الكود القديم بنسخ الملف ثم `git checkout 61d4015 -- <ملف>` لا بـ`git stash`) ثم نجح بعده.

| الملف | الاختبار | قبل | بعد |
|---|---|---|---|
| `tests/helpers/form_helperTest.php` (**جديد**، 16) | `testFormDropdown_EscapesTheLabelOfAnOption` و`..._EscapesTheLabelsInsideAnOptionGroupAndTheGroupLabel` و`..._KeepsALabelThatWasAlreadyEscapedOnce` و`..._KeepsTheEntitiesThatLegacyScreensStoreOnWrite` و`..._EscapesABareAmpersandAndTheQuotes` و`..._PrintsTheMarkupOfALabelWrappedInSafeHtml` و`..._EscapesTheLabelOfANumberAndOfNull` و`..._EscapesTheValueOfAnOption` و`..._MarksTheSelectedOptionsAndTakesTheDataOfAnArray` و`..._CarriesTheExtraAttributesOfAStringAndOfAnArray` و`testFormLabel_*` (5) و`testEscapeFormText_LeavesAnArabicTextAsItIs` | 5 إخفاقات وخطأ واحد (الدالة غير معرَّفة) والباقي يثبت سلوكًا محفوظًا | **16 نجح (37 تأكيدًا)** |
| `Api_keysControllerTest` | `testGetIndex_EscapesTheNameOfEveryBranchInTheBranchChoices` | أخفق (`<img src=x` في الصفحة) | نجح |
| `ItemsControllerTest` | `testGetView_EscapesTheNameOfTheStockLocationInTheQuantityLabel` و`testGetInventory_EscapesTheNameOfEveryStockLocationInTheDropdown` | أخفقا | نجحا |
| `CashupControllerTest` | `testGetView_EscapesTheNameOfEveryEmployeeInTheDropdowns` | أخفق | نجح |
| `SalesControllerTest` | `testGetEdit_EscapesTheEmailOfTheCustomerInTheScriptOfTheInvoiceConfirmation` | أخفق (`</script><img` في الصفحة) | نجح |
| `AccountingJournalsControllerTest` | `testGetReverseForm_EscapesTheNumberOfTheJournal` | أخفق | نجح |

الاختبارات تقرأ الجسم الذي أرسله التطبيق (`response()->getBody()`) لا الجسم المحلَّل (قاعدة `EscapingAssertionsTest`)، وتعيد ما غيّرته (اسم موقع المخزون وصف الفرع المضاف والموظف المضاف وعميل البيع) في `finally`.

## 6. تشغيلات الجوار

كل ملف في عملية PHPUnit وحده على قاعدته (`ospos_test_f2` أو `ospos_test_f2_b`، لا عمليتان على قاعدة واحدة)، والآلة مشتركة ومحمَّلة (حِمل 12 تقريبًا) فالأزمنة لا تُقاس.

| الملف | النتيجة |
|---|---|
| `tests/helpers` | نجح مع قفز اختبارات: 207, تأكيدات: 702, مقفوز: 4. |
| `tests/Views` | نجح (24 tests, 65 assertions) |
| `tests/EscapingAssertionsTest.php` | نجح (1 test, 1 assertion) |
| `tests/Controllers/Api_keysControllerTest.php` | نجح (15 tests, 82 assertions) |
| `tests/Controllers/CashupControllerTest.php` | نجح مع قفز اختبارات: 25, تأكيدات: 172, مقفوز: 1. |
| `tests/Controllers/ItemsControllerTest.php` | نجح (190 tests, 1254 assertions) |
| `tests/Controllers/ReportsControllerTest.php` | نجح (50 tests, 709 assertions) |
| `tests/Controllers/CustomersControllerTest.php` | نجح (131 tests, 600 assertions) |
| `tests/Controllers/SuppliersControllerTest.php` | نجح (25 tests, 126 assertions) |
| `tests/Controllers/EmployeesControllerTest.php` | نجح (71 tests, 437 assertions) |
| `tests/Controllers/ExpensesControllerTest.php` | نجح (73 tests, 525 assertions) |
| `tests/Controllers/ExpensesCategoriesControllerTest.php` | نجح (12 tests, 81 assertions) |
| `tests/Controllers/ReceivingsControllerTest.php` | نجح (270 tests, 2258 assertions) |
| `tests/Controllers/TaxesControllerTest.php` | نجح (12 tests, 48 assertions) |
| `tests/Controllers/Tax_definitionsControllerTest.php` | نجح (13 tests, 111 assertions) |
| `tests/Controllers/BranchesControllerTest.php` | نجح (23 tests, 92 assertions) |
| `tests/Controllers/StockUnitsControllerTest.php` | نجح (28 tests, 181 assertions) |
| `tests/Controllers/Price_listsControllerTest.php` | نجح (13 tests, 74 assertions) |
| `tests/Controllers/Revenue_categoriesControllerTest.php` | نجح (10 tests, 84 assertions) |
| `tests/Controllers/AccountingReceivablesControllerTest.php` | نجح (151 tests, 1309 assertions) |
| `tests/Controllers/AccountingPayablesControllerTest.php` | نجح (115 tests, 1094 assertions) |
| `tests/Controllers/SalesControllerTest.php` | لم يكتمل وقت كتابة هذه النسخة |
| `tests/Controllers/AccountingJournalsControllerTest.php` | نجح (173 tests, 916 assertions) |
| `tests/Controllers/ConfigTest.php` | نجح (54 tests, 228 assertions) |

**المقفوز** (4 في `tests/helpers` و1 في `CashupControllerTest`) في اختبارات لم أمسّها (أحدها في `security_helperTest` صراحةً لأن التشغيل بجذر)؛ لم أحقّق في بقية أسبابه. **لم أشغّل المجموعة الكاملة** (ساعات وتحتاج كل القواعد)، فلا أقول إن بقيتها سليمة؛ المساعد عام يمسّ كل شاشة فيها قائمة، وهذه الملفات هي التي تُصيّر الشاشات التي تحوي 148 استدعاء `form_dropdown` في الغالب. لم أشغّل `LanguageFilesTest` لأني لم أمسّ ملف لغة.

## 7. الأداة: `tools/dev/xss-poison-crawl.mjs`

تُعاد بلا تحضير يدوي سوى خادم وقاعدة:

```sh
# قاعدة ospos_test_<id>_ui بعد ui-smoke/setup.sh، وخادم php -S على المنفذ نفسه المذكور في allowedHostnames
export NODE_PATH=/usr/local/lib/node_modules_global XSS_DB=ospos_test_<id>_ui XSS_BASE=http://127.0.0.1:<port>
node tools/dev/xss-poison-crawl.mjs seed   --out build/xss     # صفوف ومستندات إضافية بنص عدائي
node tools/dev/xss-poison-crawl.mjs poison --out build/xss     # 218 عمودًا
mysqldump ... > poisoned.sql                                   # خذ نسخة: خطوة edit تحفظ سجلات
node tools/dev/xss-poison-crawl.mjs crawl  --out build/xss     # 1680 طلبًا ثم كل شاشة في كروم
node tools/dev/xss-poison-crawl.mjs refresh|edit|portal --out build/xss
node tools/dev/xss-poison-crawl.mjs literal --lang ar          # على قاعدة غير مسمَّمة
```

يخرج `html-raw.txt` (عناوين فيها السلسلة خامة)، و`json-raw.txt`، و`double-escaped.txt`، و`browser-pages.json` و`refresh.json` و`edit.json` و`portal.json` و`literal-*.json`. يرفض العمل على قاعدة لا يبدأ اسمها بـ`ospos_test_`، ولا يطبع كلمة سر القاعدة (يقرؤها من `.env`).

**دروس تشغيل:** (1) موجِّه `php -S` يجب أن ينتهي بـ`return require .../rewrite.php;` وإلا خُدمت الملفات الثابتة فارغة (القسم 1). (2) `ALLOWED_HOSTNAMES` يجب أن يحمل المنفذ (`127.0.0.1:8192`) وإلا حُذف المنفذ من إعادات التوجيه وفشل تسجيل الدخول في كروم. (3) خادمان يشتركان في `writable/debugbar` يتبادلان حذف ملفات الشريط فتعيد `?debugbar_time=...` الرمز 500 وتُفشل `ui-smoke` ظاهريًا؛ شغّل خادمًا واحدًا لكل شجرة أو عطّل الشريط.

## 8. ما لم يُصلح وما لم يُتحقق منه

| البند | الحالة |
|---|---|
| `esc()` في المواضع الـ25 | باقية بلا ضرر (الهروب بلا ترميز مزدوج)؛ تُزال في عمل لاحق حين تفرغ الملفات المشتركة. إصلاح المقيِّم في `Reports::specific_customer_input` صار زائدًا |
| قيمة تُطبع مباشرة بـ`<?= $x ?>` خارج `form_dropdown` و`form_label` | لا يحميها هذا الإصلاح. الزحف لم يجد غير `sales/form` و`reverse_form` (أُغلقتا)، لكنه يغطي ما في قاعدته فقط: عناوين GET بمعاملات 1 و2 وأول صف في كل قائمة |
| أعمدة لم تُسمَّم | `username` وكلمات السر والرموز والحالات والأنواع وطرق الدفع والعملات واللغة (محكومة بقيود `CHECK` أو بتحقق عند الكتابة؛ مثلًا `PaymentMethods::cleanName` يرفض `<>`)، والمفاتيح الأساسية |
| لم يُزحف | طلبات POST وحدها (R3 غطّت مصفوفة الصلاحيات)، وPDF والبريد وCSV (R3: `safeCell` وقوالب `documents/table` تهرّب)، وعناوين تحتاج معاملات غير 1 و2، وشاشات الزحف كلها بالإنجليزية (الهروب مستقل عن اللغة؛ أسماء الأعمدة العادية فحصتُها بالعربية والإنجليزية في `literal` و`ui-smoke`) |
| نص الرسالة في `$.notify` | يُجرَّد من الوسوم ولا يُعرض حرفيًا (آمن، تجميلي) |
| منفذ 8092 | يخدمه `R1-tools` فلم أستعمله ولم أقتل عمليته؛ استعملتُ 8192 و8193 |
| `git stash` مشترك | **حادثة تشغيل:** `git stash` يشترك فيه كل شجر العمل. استعملتُه مرة لاختبار «قبل الإصلاح» (دفعتُ مخبأً بملف واحد هو `sales/form.php`)؛ ظهر تعديلي بعدها في شجرة F1، ثم نفّذتُ `stash pop` فأتتني ملفات ليست لي (`app/Config/*` و`app/Filters/TransportHeaders.php`، وهي في شجرة F1 الآن)؛ بعد دقائق وجدتُ شجرتي نظيفة منها وفيها تعديلي من جديد، فالتبديل عُكس من طرف آخر دون أن ألمسه. **لم أستعمل `git stash` بعدها** (لاختبار «قبل» استعملتُ شجرة عمل منفصلة على `61d4015` أزلتُها)، وقائمة المخابئ فارغة الآن. تحقّقتُ بـ`git diff --stat` أن شجرتي لا تحوي إلا تعديلاتي قبل كل التزام. |

## 9. الأدلة

في `/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/f2/`:

- `run-before3/` (قبل، على `61d4015`): `crawl.log` و`html-raw.txt` (24 عنوانًا) و`json-raw.txt` و`http-walk.json` و`browser-pages.json` و`before-contexts.txt` (سياق كل تسرّب: أي قائمة)؛ `run-before3-dbl/` فحص الترميز المزدوج قبل.
- `run-after3/` (بعد، على `wp/F2`): `steps.log` و`html-raw.txt` (فارغ) و`json-raw.txt` و`browser-pages.json` و`refresh.json` و`edit.json` و`portal.json`.
- `run-before/` و`run-after1/` و`run-after2/`: التشغيلات الأولى على قاعدة أصغر (22 عنوانًا قبل، ثم صفر، ثم `reverse_form` وحده بعد توسيع الأعمدة)، و`tests-before-fix.log` (إخفاق الاختبارات الجديدة قبل الإصلاح).
- `run-literal/` (`literal-en.json` و`literal-ar.json` وسجلاتهما) و`ui-smoke/` (`report.json` و`run.log`: 40 زيارة) و`ui-smoke-rerun/` (إعادة الثلاث التي أفشلها الشريط).
- `neighbours/` (سجل كل ملف جوار و`summary.txt`).
- القواعد: `base-fixture.sql` و`poisoned-fixture3.sql` (نسخ `mysqldump` للقاعدة النظيفة والمسمَّمة)، السكربتات: `contexts.py` و`steps.sh` و`neighbours.sh`.

**الالتزامات:** `0556678` (المساعد و`SafeHtml` والاختبارات الخمسة و`sales/form`)، `1218ec5` (`reverse_form`)، `39d3ea0` (الأداة و`AGENTS.md`)، وهذا التقرير.
