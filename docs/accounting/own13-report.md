# إنهاء المعاملة التي تتركها شيفرة رمت استثناءً، وإصلاح المواضع الأربعة عشر المؤكَّدة — تقرير (OWN13)

الفرع: `wp/OWN13` **فوق `wp/OWN12`** (`eee5f1e8`) الذي هو فوق `master` الحالي (`c0065411`). **لم يُدمج**: دُفع الفرع وحده بانتظار موافقتك الصريحة، وترتيب الدمج المقترح OWN12 ثم OWN13 (لا يعتمد OWN13 على شيفرة OWN12 لكنهما يتقاسمان ملفات الحارسات والتقارير). لا ترحيلات ولا تغيير في قاعدة البيانات ولا في الصلاحيات ولا في ملفات اللغة. تغيير الشيفرة الإنتاجية: ملفان جديدان (`app/Libraries/Database/AbandonedTransaction.php` و`OpenTransactionHandler.php`) وتعديل صغير في تسعة ملفات قائمة.

## العيب

أوصى تقرير OWN12 بفرع يعمّم إصلاحه على المواضع التي وجدها جردُه (24 وكيلًا، للقراءة فقط): **14 موضعًا مؤكَّدًا** من `transStart()` / `transBegin()` تُخلّف الاتصال المشترك بالطلب داخل معاملة معلَّقة. العيب واحد في كل منها: يخرج الطلب بعد استثناء (أو بعد `return` مبكر) والمعاملة لم تُنهَ، فتبقى كل كتابة لاحقة في العملية داخلها، وأولها **كتابة الجلسة في نهاية الطلب** (معالج الجلسة `DatabaseHandler` على الاتصال نفسه)، وتُلغى كلها بإغلاق الاتصال. ثلاثة منها أشدّ من الباقي:

- **`Attribute::convert_definition_data` فرع DROPDOWN←CHECKBOX** يفتح `transStart()` ولا يستدعي `transComplete()` (الاستدعاء الوحيد في كتلته معلَّق في تعليق)، فلا يُثبَّت التحويل أبدًا **والمتحكم يعيد «تم تحديث السمة بنجاح»**. فقدان صامت للتعديل، بلا استثناء أصلًا.
- **مواضع `Sale` و`Receiving` الخمسة** (حفظ البيع، إلغاؤه، إلغاء المعلَّق، حذف الاستلام ودفعته): كلها داخل `CustomerLock::run` أو `SupplierLock::run`، وتوثيق القفلين نفسه يقول «يُترك القفل بعد انتهاء المعاملة» بينما `finally` يفلته الآن والمعاملة قد تبقى مفتوحة بعد استثناء.
- **`Tax_jurisdiction::save_jurisdictions` و`Tax_category::save_categories`**: إدخال صف جديد يفشل (مثلًا `tax_group` محذوف ناعمًا يصطدم بالفهرس الفريد) فتعود `save_value` بـ`false` ثم يُقرأ مفتاح غير موجود (`ErrorException`) والعمق 1. عيب منطقي، وهو **الأسهل وقوعًا** من الأربعة عشر.

والبقية: `Dinner_table::occupy/release(int)` يُنادَيان بـ`null` لطاولة غير معيّنة فيقع `TypeError` (موضعا `Sale::discardSuspendedSaleLocked` و`clear_suspended_sale_detail`)، و`Price_list::saveList` يقرأ `FOR UPDATE` خامًا فيسلسل `->getRow()` على `false`، و`Attribute::storeCSVAttributeValue` يرجع `false` بعد `transStart()` بلا تراجع، و`Items::postSave` و`postImportCsvFile` يعبر منهما استثناء (غالبًا `RoleTablesUnavailableException`) والمعاملة مفتوحة.

## الدليل قبل وبعد

**١) الاختبارات (قبل الإصلاح، على `eee5f1e8` + الاختبارات وحدها):** كل اختبار جديد يخص موضعًا **يفشل**: الوحدة الجديدة (7 من 11 اختبارًا ظهرت إخفاقاتها في الجولة الأولى، والبقية بعد إضافتها)، والمعالج (3 من 3)، والقفلان (3 من 3 في كلٍّ منهما)، و`Attribute` (التحويل لا يُثبَّت، وربط CSV الفاشل يترك مستواه)، و`Dinner_table` (`TypeError`)، و`Tax_jurisdiction` (`ErrorException`) و`Tax_category`، و`Price_list` (`Error`)، واستيراد `Items` (العمق ≠ 0). بعد الإصلاح تنجح كلها (سجل التشغيل النهائي: `after-final.txt` في مجلد العمل المؤقت للجلسة).

**٢) المتصفح بصيغة الإنتاج** (`CI_ENVIRONMENT=production` أي `DBDebug=false`؛ خادم MariaDB مؤقت على المنفذ 3317 بقاعدة مستنسخة من قاعدة اختبار مهاجَرة بكل ترحيلات `master`؛ مستخدم قاعدة بيانات بامتيازات `SELECT/INSERT/UPDATE/DELETE` لكل جدول؛ متصفح حقيقي بحساب المدير؛ كل طلب بـ`$.post` بعد أكثر من ثانيتين من آخر طلب كي يظهر تغيّر `timestamp` الجلسة). «قبل» = `eee5f1e8` (OWN12) و«بعد» = `wp/OWN13`. المخرجات في مجلد العمل المؤقت (`probe-before.txt` و`probe-after.txt` و`probe-before-p1.txt` و`probe-after-p1.txt`):

| الطلب | قبل | بعد |
|---|---|---|
| **P1** `POST attributes/saveDefinition/1` تحويل DROPDOWN←CHECKBOX لسمة مربوطة بصنف | **200** «You have successfully updated attribute»؛ **القاعدة لم تتغير** (`DROPDOWN`، الصنف ما زال «red»)؛ صف الجلسة **لم يُكتب** (`07:06:42` ← `07:06:42`) | **200** بالرسالة نفسها؛ **`CHECKBOX`** والصنف مربوط بقيمة «1»؛ صف الجلسة **كُتب** (`07:06:49` ← `07:06:53`) |
| **P2** `POST taxes/save_tax_jurisdictions` بإعادة إضافة `tax_group` محذوف ناعمًا | **500** (جسم فارغ)؛ الجلسة لم تُكتب | **200** `{"success":false,"message":"Tax Jurisdiction changes not saved"}`؛ الصف بلا تغيير؛ الجلسة كُتبت |
| **P3** `POST taxes/save_tax_categories` بمعرّف غير رقمي (`TypeError` قبل أن تُحفظ شيئًا) | **500**؛ الجلسة لم تُكتب | **500** (الطلب مشوَّه فيبقى فشلًا)؛ **الجلسة كُتبت**: المعالج العام أنهى المعاملة قبل الإجابة |

P1 هو فقدان البيانات الصامت: قبل الإصلاح يقول المتحكم «نجح» ولا يتغير شيء. وP3 يثبت المعالج العام على متحكم لا يمسّه أي إصلاح موضعي. **ما لم أقِسه بالمتصفح:** مسارات `Sale` و`Receiving` (تُغطّيها اختبارات القفلين لا طلب حقيقي)، و`Items::postSave`، واستيراد CSV (اختبار المتحكم). وكما في OWN12 **لا أدّعي تسجيل خروج**: المثبَت هو ضياع كتابة الجلسة قبل الإصلاح وحفظها بعده، وتجديد معرّف الجلسة يتخطاه الإطار لطلبات AJAX.

## التغيير

**نقطتا اختناق بدل 14 نسخة من الإصلاح نفسه** (أُبقي `Employee::inTransaction` من OWN12 كما هو):

1. **`AbandonedTransaction`** (جديد، بجوار `FailedTransaction`):
   - `close($db, $depth)`: يتراجع عن كل مستوى فوق `$depth` ويتوقف عند أول `transRollback()` يعود بـ`false`، ويبتلع (مع `log_message('error', …)`) استثناء التراجع نفسه كي لا يحجب الاستثناء الأصلي، ثم `FailedTransaction::forget($db)` (لا يعيد الحالة إلا عند العمق 0).
   - `guard($db, $work)`: يسجّل العمق، يشغّل العمل، وإن رمى `Throwable` (ومنه `Error`) استدعى `close` ثم **أعاد رمي الاستثناء نفسه**.
2. **`CustomerLock::run` و`SupplierLock::run`** يشغّلان العمل عبر `guard` في الفرعين (بلا معرّف وبمعرّف)، والتراجع يسبق إفلات القفل المسمّى فيحترم ما يقوله توثيقهما. هذا وحده يغلق مواضع `Sale` و`Receiving` الخمسة **دون لمس جسم أيٍّ منهما**. وفي الاستدعاء داخل معاملة مستدعٍ (`RunsInTransaction`) يقتصر الأثر على إنقاص العمق ويُترك الإلغاء الفعلي للمالك.
3. **`OpenTransactionHandler`** (جديد): مغلِّف لـ`ExceptionHandlerInterface` يستدعي `close(Database::connect(), 0)` قبل تسليم الاستثناء لما يليه، ومسجَّل أبعد مغلِّف في `Config\Exceptions::handler()`: `OpenTransactionHandler(ScopeUnavailableHandler(MemoryExhaustedHandler))`. لا يمسّ الاستجابة ولا السجل. وهو يغطي كل استثناء غير ملتقَط في أي متحكم، فيشمل ما سيُضاف لاحقًا.
4. **إصلاحات الأسباب الجذرية** (حيث الخطأ منطقي لا استثناء):
   - `Attribute`: `transComplete()` حي في فرع DROPDOWN←CHECKBOX وحذف سطر التعليق المضلِّل؛ و`storeCSVAttributeValue` يتراجع وينسى الحالة الفاشلة قبل `return false`.
   - `Tax_category::save_categories` و`Tax_jurisdiction::save_jurisdictions`: إن فشل `save_value` يتراجعان وينسيان الحالة ويرجعان `false` (الشاشة تعرف «فشل الحفظ»). وإعادة تسمية المتغيرات المحلية في الدالتين إلى `camelCase` بحكم القاعدة.
   - `Dinner_table::occupy/release(?int)`: `null` تعني «بلا طاولة» وسلوكهما لها (`> 2` خاطئ ← `true`) واضح.
   - `Price_list::saveList`: يتحقق ثم يشغّل جسمه (انتقل حرفيًّا إلى دالة خاصة `saveListInTransaction`) عبر `guard`، والقراءة `FOR UPDATE` عبر `LockedRead::row` فيرمي `AccountingException(WriteFailed)` المعالَجة في المتحكم بدل `Error`.
   - `Items::postImportCsvFile`: `AbandonedTransaction::close(db_connect(), 0)` في `catch (Exception)` قبل إرجاع JSON.

## خريطة المواضع الأربعة عشر

| # | الموضع | الآلية التي تغلقه | الاختبار |
|---|---|---|---|
| 1 | `Sale::saveValueLocked` | `CustomerLock::run` ← `guard` | `CustomerLockTest::testRun_EndsTheTransactionTheWorkLeftOpenWhenItFails` (+ المعالج) |
| 2 | `Sale::deleteLocked` | القفل نفسه | الاختبار نفسه |
| 3 | `Sale::discardSuspendedSaleLocked` | القفل + `Dinner_table ?int` (السبب الجذري) | `CustomerLockTest` و`Dinner_tableTest::testOccupy/testRelease_AcceptsASaleWithoutATable` |
| 4 | `Sale::clear_suspended_sale_detail` | `Dinner_table ?int` (يُنادى من المتحكم مباشرة بلا قفل؛ ومتداخلًا في 1 يغطيه القفل أيضًا) | `Dinner_tableTest` |
| 5 | `Receiving::deleteListLocked` | `SupplierLock::run` ← `guard` | `SupplierLockTest::testRun_EndsTheTransactionTheWorkLeftOpenWhenItFails` |
| 6 | `Receiving::deleteValueLocked` | القفل نفسه | الاختبار نفسه |
| 7 | `Attribute::convert_definition_data` | `transComplete()` حي | `AttributeTest::testSaveDefinition_CommitsTheConversionOfADropdownToACheckbox` + P1 |
| 8 | `Attribute::saveDefinition` | الإصلاح نفسه (لا استثناء، التسرّب بنيوي) | الاختبار نفسه + P1 |
| 9 | `Attribute::storeCSVAttributeValue` | `transRollback()` + نسيان الحالة | `AttributeTest::testSaveCSVRowAttributeData_ClosesItsTransactionWhenTheLinkCannotBeSaved` |
| 10 | `Items::postSave` | `OpenTransactionHandler` | `OpenTransactionHandlerTest` + P3 (الآلية على متحكم آخر؛ **لا اختبار يقود `postSave` نفسه إلى استثناء**) |
| 11 | `Items::postImportCsvFile` | `close` في `catch` + المعالج لما لا يُلتقَط | `ItemsControllerTest::testPostImportCsvFile_EndsItsTransactionWhenARowRaisesAnException` |
| 12 | `Price_list::saveList` | `guard` + `LockedRead` | `Price_listTest::testSaveList_RefusesWithTheNamedExceptionWhenTheListCannotBeLocked` |
| 13 | `Tax_category::save_categories` | إيقاف الحفظ والتراجع | `Tax_categoryTest::testSaveCategories_AnswersFalseAndLeavesNoTransactionWhenANewRowCannotBeSaved` + P3 للمعالج |
| 14 | `Tax_jurisdiction::save_jurisdictions` | إيقاف الحفظ والتراجع | `Tax_jurisdictionTest::testSaveJurisdictions_AnswersFalseAndLeavesNoTransactionWhenANewRowCannotBeSaved` + P2 |

**صراحةً عن حدّ الاختبار:** المواضع 1 و2 و5 و6 تُثبَت باختبارات القفلين (عمل يرمي داخل `run` ← المعاملة تُغلق قبل إفلات القفل)، لا بقيادة `Sale::saveValueLocked` أو `Receiving::deleteValueLocked` نفسيهما إلى استثناء؛ وهذا بحكم أن الإصلاح في القفل لا في جسم الدالتين. الموضع 10 كذلك يعتمد على اختبار المعالج وقياس P3.

## الاختبارات والحارسات

- **32 اختبارًا جديدًا**: `AbandonedTransactionTest` (12: أنواع الإنهاء والعمق والحالة وتراجع يرمي وتراجع يفشل، ومعها بدائل `Connection` مجهولة الصنف)، `OpenTransactionHandlerTest` (3)، `CustomerLockTest` و`SupplierLockTest` (3 لكل منهما)، `AttributeTest` (3)، `Dinner_tableTest` (2)، `Tax_jurisdictionTest` (2)، `Tax_categoryTest` (2)، `Price_listTest` (1)، `ItemsControllerTest` (1)؛ وتحديث `ExceptionsTest` لتتوقع سلسلة المعالجات الجديدة. وأُنشئ `tests/Support/TransactionStateTrait` (`assertNoTransactionIsOpen` و`closeOpenTransaction` و`anotherConnection`) ونُقل إليه ما كان منسوخًا في `RoleFixtureTrait`. اختبار الإيقاف عند أول تراجع فاشل (`testClose_StopsAtTheFirstRollbackThatFails`) أُضيف لأن الحلقة بلا إيقاف كانت تدور بلا نهاية على اتصال يرفض التراجع.
- **31 حارسًا جديدًا** في `docs/accounting/daftra-parity/own13-guards.json` (طفرات يجب أن تُكتشف: حذف `guard` من كل فرع في القفلين، `close` بلا تراجع / يتجاوز العمق / لا يتوقف / لا يبتلع / لا ينسى الحالة، `guard` يبتلع الاستثناء، المعالج بلا `close` أو يمرّر قبله، التسجيل خارج `Exceptions`، حذف `transComplete()` الجديد، حذف التراجع ونسيان الحالة في `Attribute` والفئتين الضريبيتين، حذف إيقاف الحفظ، `?int` ← `int`، `LockedRead` ← قراءة خام، حذف `close` من `catch` الاستيراد). TODO-GUARDS
- **الحارسات القديمة المرساة على الملفات المعدَّلة:** 63 مرساة في 11 ملف حارسات (`w91` و`wp36` و`own9` و`own10` و`w94` و`wp17` و`wp24` و`wp37` و`wp40` و`wp42`) تتطابق كلها بنصها مرة واحدة بعد التعديل (لا مرساة متقادمة). وحارس `own9:G11` عُدِّل نصه ليقابل `Exceptions.php` الجديد بمعناه نفسه. TODO-OLDGUARDS
- **المجموعة الكاملة** (728 ملف اختبار، ملفًا ملفًا بثلاثة عمّال على نسخة لقطة): TODO-SUITE

## المراجعة المستقلة

TODO-REVIEW

## ما لم يُعالج (حدود الفرع المعلنة)

- **`Employee::inTransaction` يبقى** كما هو (مراجَع ومحروس بـ23 طفرة في OWN12)؛ لا أنقل الموظفين إلى `AbandonedTransaction` في هذا الفرع.
- **الرفض المسمّى لمسار الدُّفعات المتتبَّعة** في `Sale::deleteLocked` و`Receiving` (يرمي `AccountingException(TrackedPathUnsupported)` فيعبر الآن إلى 500): التسرّب نفسه مغلق بالقفل والمعالج، أما تحويله إلى رفض بلغة المستخدم فتغيير سلوك منفصل لم أبدأه.
- **حفظ الصنف `Items::postSave` وموضع استيراده من جهة الجلسة** مغطّيان بالمعالج العام لا بإصلاح موضعي.
- **RecurringGenerator** وكل مستدعٍ آخر للقفلين يستفيد تلقائيًّا دون لمس.

## ما يحتاج قرارك

1. **دمج OWN12 ثم OWN13** (أو أيٍّ منهما) في `master` ونشره: لا أفعل شيئًا من ذلك قبل أمرك الصريح.
2. **الموقع الافتراضي** (رمي 503 أم الرجوع إلى الأول): معلَّق منذ OWN10 ولم يتغير.
3. **سطرا OWN12 المؤجَّلان:** `if ($success)` قبل `releaseEmployees`، وحماية التراجع داخل `Employee::inTransaction`.
4. **الرفض المسمّى لمسار الدُّفعات المتتبَّعة** (أعلاه): تغيير سلوك أطلبه منك قبل أن أبدأه.
5. **تنبيه تشغيلي مهم:** تحويل سمة من DROPDOWN إلى CHECKBOX **سيبدأ بالتثبيت فعلًا** بعد هذا الفرع (كان يقول «نجح» ولا يُثبَّت). ومن حوّل سمة سابقًا وظنّه ثُبِّت فليراجعها: ستبقى كما كانت. وعند التحويل المثبَّت تُحذف خيارات القائمة التي لا يستعملها أي صنف (التنظيف نفسه الذي كان يُلغى مع المعاملة).
