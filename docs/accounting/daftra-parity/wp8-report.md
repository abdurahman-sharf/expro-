# تقرير WP8: خدمة الترقيم لكل مستند (G-08 وCUS-02)

> الحالة (2026-10-27): **نُفِّذ على الفرع `wp/WP8`** (من `f5d4927`) بتفويض E47، بلا دمج في `master` ولا نشر ولا كتابة في دفترة. **خدمة ترقيم** لتسعة أنواع من المستندات: تسلسل لكل نوع بـ**بادئة وعدد منازل ورقم تالٍ**، يُحجز **داخل معاملة الحفظ نفسها** بقفل صف التسلسل فلا يتكرر رقم حتى مع اتصالين متزامنين، ويُعرض على المستندات والقوائم التي تعرض الرقم اليوم، وله **شاشة إعدادات** تحت منطقة الإعدادات وصلاحيتها القائمة (`config`). **الافتراضات تعيد الترقيم الحالي بالتمام** (كل تسلسل معطَّل حتى يفعّله المالك، ولا مستند قائم يُعاد ترقيمه أو يُعاد تاريخه)، وفيه اختبار ذهبي يثبت ذلك على المستندات التي ينشئها التطبيق (فاتورة ودفعتان وإشعار دائن وعرض سعر وعميل وفاتورة شراء وإشعار مدين ودفعة مورد). **بُني النصف الآمن فقط:** الرقم التالي لا يُعدَّل إلا للأعلى، والفرادة مفروضة؛ وتعديله للأسفل وتكرار الأرقام المكتوبة يدويًا ومتغيرات البادئة **تنتظر رصد دفترة (`S-33`)** فلا أدّعي فيها تطابقًا. **بعد مراجعتين مستقلتين (مرحلة الإصلاح):** صُحّحت ثلاث ثغرات حقيقية (حقل رقم الفاتورة في السجل، والفحص عن بُعد لرقم العميل في نموذجه، واستيراد العملاء من CSV)، وأُعيدت تسمية المتغيرات المحلية في الدوال القديمة التي عدّلتُها وأسماء بيانات العروض الجديدة إلى camelCase، وحُدّثت الاختبارات وأُعيد تعطيل الحراس على الشيفرة النهائية؛ التفصيل في القسم 8.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الجدولان** (ترحيل `20261210000000_DocumentSequences`) | `document_sequences`: صف لكل (شركة، نوع) فيه التفعيل والبادئة (حتى 16 حرفًا) وعدد المنازل (0 إلى 10) والرقم التالي (≥ 1) وخيار منع التكرار ومن عدّل ومتى. `document_numbers`: **سجل الأرقام المعطاة**، صف لكل (نوع، جدول المصدر، معرّف المصدر) بمفتاح فريد، **ومفتاح فريد ثانٍ على (شركة، نوع، رقم)** شبكةَ أمان تحت الشيفرة. الترحيل إضافي (`CREATE TABLE IF NOT EXISTS`) ويُعاد تشغيله بأمان ولا يمنح صلاحية ولا وحدة ولا يمس صفًّا قائمًا |
| **الأنواع التسعة** (`DocumentType`) | فاتورة بيع، عرض سعر، إشعار دائن، دفعة عميل، عميل، فاتورة شراء، إشعار مدين، دفعة مورد، مصروف. **لكل نوع الجدول الذي يخزَّن فيه** (فلا يُرقَّم مستند من جدول لا يخصّه: يُرفض `numbering_source_invalid`)، وما يعرضه المستند **قبل أن يُرقَّم** (`POS 12` للإشعار الدائن، `RECV 7` للشراء والإشعار المدين، المعرّف المجرد للدفعتين والمصروف، والفراغ للباقي) |
| **متى يُعطى الرقم** | **فاتورة البيع** عند إتمام البيع (لا مسودة ولا معلَّق)، **وعرض السعر** عند حفظه، **والإشعار الدائن** عند إتمام مرتجع البيع، **وكل دفعة عميل** عند إتمام البيع (ودفعة تُضاف لاحقًا من نموذج التعديل إلى بيع مكتمل؛ ودفعات البيع المعلَّق تُرقَّم عند إتمامه)، **وفاتورة الشراء والإشعار المدين** عند حفظ الاستلام (الاستلام وبه المرتجع)، **ودفعة المورد** (صف دفعة الاستلام الجديد، والدفعة المستقلة من `supplier_advances`)، **والمصروف** عند إضافته، **والعميل** عند إضافته بلا رقم حساب مكتوب. **لا يُرقَّم:** صف دفعة «آجل» (`Due`) ولا تقريب النقد، ولا طلب التوريد (التحويل بين مواقع) ولا الاستلام الافتتاحي، ولا الرصيد الافتتاحي للمورد |
| **الحجز والقفل** | `DocumentNumbering::assign` يقرأ صف التسلسل بـ`SELECT … FOR UPDATE` **داخل معاملة المتصل** (نفس انضباط `accounting_sequences` وقفل `lockSequence`: `INSERT IGNORE` ثم قراءة مقفولة)، فيُحجز الرقم بين اتصالين على الترتيب. الإدراج في السجل وتحريك العدّاد **في المعاملة نفسها**: **ما يُلغى حفظه لا يترك رقمًا ولا ثغرة**، والمستند المحذوف **يبقى رقمه في السجل ولا يُعطى لغيره أبدًا**. وإن وجدت الخدمة رقمًا مأخوذًا (في السجل أو في عمود المستند القديم) **تتخطاه** حتى 1000 محاولة ثم ترفض `numbering_exhausted` |
| **حدّ الرقم التالي** | **عند أول تفعيل أو تغيير البادئة** لا يقل عن: الرقم المخزَّن، و**1 + أعلى رقم أُعطي**، و**1 + أعلى رقم في عمود المستند القديم من شكل بادئة + أرقام** (`invoice_number` و`quote_number` و`account_number`)، **و1 + أعلى معرّف لمستند ما زال يظهر بمعرّفه** (الدفعتان والمصروف) حين تكون البادئة فارغة. فلا يتضارب تسلسل جديد مع ما تعرضه الشاشات. **أما التسلسل الجاري بالبادئة نفسها** فحدّه الأدنى **1 + أعلى رقم أُعطي** فقط (والحجز نفسه يتخطى الأرقام المأخوذة في السجل أو في أعمدة المستند القديم أثناء العمل، ولا يدفعه للأعلى معرّف صف لا يُرقَّم كدفعة «آجل»: انظر الحدّ في القسم 6). **يُرفع الرقم التالي للأعلى فقط**؛ تخفيضه يُرفض `numbering_next_too_low` برسالة تذكر الحدّ الأدنى |
| **الأعمدة القديمة** | الفاتورة والعرض والعميل **تُكتب أرقامها في أعمدتها القديمة** (`sales.invoice_number` و`sales.quote_number` و`customers.account_number`) فتعمل كل شاشة وبحث يقرؤها كما كانت. **رقم مكتوب بيد المستخدم يبقى** ولا يُستبدل، ويُتخطى رقم التسلسل الذي يساويه. **تعديل فاتورة يترك رقمها فارغًا لا ينزع رقم التسلسل عنها** |
| **سجل البيع** | فاتورة وعرض: **إن كان التسلسل مفعّلًا** لا يُولَّد الرقم من تنسيق الإعدادات (`sales_invoice_format` وعدّاده) بل يمرّ `null` فيُحجز داخل المعاملة، ويقرأ الإيصال الرقم الممنوح بعد الحفظ؛ وحقل رقم الفاتورة في السجل **يعرض الرقم التالي نصًّا إرشاديًا (`placeholder`) ولا يملأ الحقل به** (`peek` بلا حجز): الحقل الفارغ يترك الرقم للتسلسل، **والرقم المكتوب باليد يُحفظ كما كُتب** (حروفه وفواصله وأصفاره) ويُرفض إن كان لفاتورة أخرى، ولا يستهلك رقمًا من التسلسل. **إن كان معطَّلًا** فالحقل يُملأ بتنسيق الإعدادات كما كان |
| **الشاشات والقوائم** | الإشعار الدائن (إيصال البيع بأنواعه الأربعة ونموذج التعديل) وأرقام دفعات البيع في نموذج تعديل البيع؛ رقم فاتورة الشراء أو الإشعار المدين وأرقام دفعات المورد في إيصال الاستلام ونموذج تعديله؛ **عمود «الرقم» في قائمة المصروفات** (يظهر والتسلسل مفعّل فقط، غير قابل للفرز) ورقمه في نموذجه؛ وتلميح «الرقم تلقائي: …» في نموذج العميل الجديد. **كل ما لا رقم له يعرض ما كان يعرضه** |
| **شاشة الإعدادات** | الإعدادات ← مجموعة المبيعات ← **«الترقيم»**: نموذج لكل نوع (تفعيل، بادئة، عدد منازل، رقم تالٍ، وخيار «رفض الرقم الموجود» للعميل وحده) مع شكل الرقم التالي وحالة التسلسل، ويُحفظ كل نوع بنموذجه (`config/saveNumbering/{type}`). **صلاحية التغيير `config` في الخدمة نفسها** لا في الشاشة وحدها (موظف بلا منحة تُرفض عمليته؛ ولا موظف = النظام) |
| **سجل التدقيق** | كل تغيير فعلي لإعداد الترقيم يُكتب في `accounting_audit_log` (الكيان `document_numbering`، الفعل `numbering_configured`، قبل وبعد)، ويظهر في شاشة السجل بمرشّح الكيان. **حفظ بلا تغيير لا يكتب شيئًا** |
| **منع تكرار رقم العميل** | رقم حساب العميل المكتوب يُرفض **في الخادم** إن كان لعميل آخر ما دام خيار المنع مفعّلًا (**هو الافتراضي**)، وكان الفحص في الواجهة وحدها. **السؤال واحد في ثلاثة مواضع** (`Customer::refusesAccountNumber`): حفظ النموذج، والفحص عن بُعد في نموذج العميل (`checkAccountNumber`) فيتبع إيقاف الخيار، **واستيراد CSV** (كان يرفض الصف حين يوجد البريد والرقم معًا فقط). **الرفض فحص ثم كتابة وليس ذريًّا** (انظر الحدّ في القسم 6). **وأُصلح خلل قديم في `check_account_number_exists`** كان يقول «متاح» حين يشترك عدة عملاء في الرقم (`== 1`) فصار `> 0` |
| **الأخطاء والرسائل** | سبعة أنواع جديدة في `AccountingError` (نوع مجهول، مصدر غير صالح، بادئة، منازل، رقم تالٍ غير صالح، رقم تالٍ أقل من الحد، استنفاد) بمفاتيح `Accounting.error_numbering_*` في **كل** اللغات (العربيتان عربيتان فعليًا، والإنجليزيتان إنجليزيتان، وغيرها `''` فتسقط إلى الإنجليزية)، ووسيط `min` في `AccountingMessage`. وفشل الترقيم داخل بيع أو استلام أو مصروف أو عميل أو دفعة مورد **يُلغي العملية كلها** (`ACCOUNTING_FAILED` أو `false`) ولا يترك شيئًا |
| **اللغات** | `Numbering.php` جديد في **46 لغة** (ar-EG وar-LB عربيتان، en وen-GB إنجليزيتان، والباقي `''`)، ومفاتيح `Accounting` (7 أخطاء + الفعل والكيان) و`Customers.account_number_automatic` في كل اللغات بالترتيب الأبجدي والمحاذاة |

## 2. ما لم يتغيّر (الافتراضيات تعيد الترقيم الحالي بالتمام)

- **كل التسلسلات معطَّلة بعد الترحيل والجدولان فارغان**: لا يتغير رقم ولا عرض ولا ترتيب. فاتورة البيع تتبع `sales_invoice_format` وعدّاده كما كانت، والعميل بلا رقم حساب كما كان، والدفعة تعرض معرّفها، والإشعار الدائن `POS n`، والشراء `RECV n`.
- **لا مستند قائم يُرقَّم بأثر رجعي**: التفعيل يرقّم ما يُحفظ بعده فقط، ويظهر القديم كما هو. **لا إعادة تاريخ** (الخدمة لا تلمس تاريخ أي مستند).
- **القيود والدفاتر لا تتغير بتغيّر الترقيم ولا تتوقف عليه**: الترقيم يعمل والمحاسبة معطَّلة (D1) ويترك الدفاتر فارغة، ولا يكتب قيدًا ولا يغيّر قيدًا مرحَّلًا.
- **عدّاد الفاتورة وعرض السعر في الإعدادات** وسلسلة القيود اليدوية **لم تُمس** (لا تُحذف ولا تُصفَّر).

## 3. الأدلة

- **اختبارات الكود (ملف واحد لكل صنف، بأسماء `test{الدالة}_{السلوك}`):**
  - `tests/Libraries/Numbering/DocumentNumberingTest.php` (82 اختبارًا بمجموعات البيانات، منها 9 لـ`typed` أي ما يبقى من الرقم المكتوب بيد المستخدم: الأنواع والجداول، تسلسل لكل نوع ولكل شركة ولفرع المستند، التتابع والبادئة والمنازل، الرقم الموجود للمستند، تخطي رقم مأخوذ في السجل أو عند عميل آخر، عدم إعادة رقم حُذف مستنده، التراجع، الانضمام لمعاملة المتصل، الانتظار على اتصال آخر يمسك التسلسل واتصالين متتاليين على الترتيب، حد الرقم التالي (المعرّفات والأعمدة القديمة)، التهيئة وكل رفض (سبعة أنواع) وحواف الإعدادات والعربية في البادئة، الصلاحية، السجل، رسائل الرفض بكل اللغات المترجمة، **أربعة اختبارات ذهبية**، **وعمليتان حقيقيتان متزامنتان** (واحدة تحفظ فاتورتين كاملتين معًا، وأخرى تمسك معاملتها مفتوحة 400 ملّي ثانية لتتداخل)).
  - `tests/Database/DocumentSequencesMigrationTest.php` (12 اختبارًا: الجدولان والأعمدة، البداية فارغة ومعطَّلة، الإعادة بلا فقد، عدم مسّ أرقام المستندات القائمة، لا صلاحية ولا منحة ولا وحدة، رفض تسلسلين من نوع واحد، رفض منازل فوق عشرة ورقم تالٍ دون الواحد، رفض شركة غير موجودة، رفض الرقم نفسه مرتين لنوع واحد ورقمين لمستند واحد، قبول الرقم نفسه لنوعين، `down` ثم `up`). **يُعيد بناء الجدولين قبل كل اختبار** ليكشف أي تغيير في مخططهما.
  - الموجودة وقد زيدت: `Sale_libTest` (الرقم المحفوظ في جلسة السجل: يُحفظ كما كُتب، ويُمسح إن أُفرغ الحقل، ويحتفظ بما سبق إن طُلب ذلك) و`SaleTest` و`ReceivingTest` و`CustomerTest` و`ExpenseTest` (جديد) و`SupplierAdvancesTest` (النماذج الحقيقية: الترقيم مع المحاسبة ومعطَّلة، التراجع الكامل عند الرفض أو عند استحالة الرقم)، و`SalesControllerTest` و`ReceivingsControllerTest` و`CustomersControllerTest` (جديد) و`ExpensesControllerTest` (جديد) و`ConfigTest` (الشاشات والمتحكمات: الإيصالات ونموذج التعديل والقائمة والاقتراح والرفض في الخادم وشاشة الإعدادات بصلاحيتها)، و`NavigationHelperTest` (أقسام الإعدادات 12 ← 13).
- **الاختبار الذهبي** (`DocumentNumberingTest::testLabel_KeepsWhatEveryExistingDocumentShowedWithDefaultSettings` و`testConfigure_NumbersNewDocumentsAndNoneThatExistWhenASequenceIsSwitchedOn` و`testAssign_KeepsTheBooksTheSameWhetherOrNotTheDocumentsAreNumbered` و`testAssign_NumbersWhileAccountingIsOffAndLeavesTheBooksEmpty`): (1) **بالإعدادات الافتراضية** (لا صف في الجدولين) يُنتج التطبيق، عبر نماذجه الحقيقية، فاتورة بدفعتين وإشعارًا دائنًا وعرض سعر ورقم عرض وفاتورة مكتوبَين بيد المستخدم وعميلًا برقم حساب مكتوب وفاتورة شراء وإشعارًا مدينًا ودفعة مورد: **كل مستند يعرض ما كان يعرضه** (`POS n` و`RECV n` والمعرّف المجرد، والأرقام المكتوبة كما هي، والفاتورة بلا رقم تبقى بلا رقم)، والسجل فارغ، وكل تسلسل معطَّل بلا بادئة ولا منازل؛ (2) **تفعيل التسلسلات التسعة يرقّم ما يُحفظ بعده ولا يمس واحدًا قائمًا**؛ (3) **حركة أرصدة الدفاتر لمجموعة المستندات نفسها متطابقة** سواء رُقّمت أم لا، ولا اختلاف بين قيد مخزون وحركاته (`mismatchedJournals`)؛ (4) **الترقيم يعمل والمحاسبة معطَّلة ويترك القيود كما هي**. **وملف التثبيت `ParityFixtureTest` (كل أرقام `T-xx` بقيود دفترة) يمرّ بلا تغيير** وهو يعمل بالإعدادات الافتراضية.
- **التزامن:** تسلسلان مفتوحان في اتصالين يحجزان بالترتيب (10 أرقام متتالية بلا تكرار بين اتصالين)، **وعمليتان حقيقيتان** (`RaceWorker` في عمليتي PHP منفصلتين): الأولى تحفظ فاتورتين كاملتين معًا فتأخذان `…-0001` و`…-0002`؛ **والثانية تُبقي كل معاملة مفتوحة 400 ملّي ثانية فتتداخلان ويأخذ كل مستند رقمًا مختلفًا** (بدون قفل الصف تتصادم الثانية على المفتاح الفريد). **تعديل `RaceWorker`:** صار يحمّل صفوف التطبيق من نسخة العمل التي يقع فيها (في شجرة عمل git تشارك `vendor` مع الفرع الرئيس، فكان العامل يشغّل شيفرة الفرع الرئيس القديمة ولا يرى الميزة)، ولا أثر له في مستودع عادي.
- **تعطيل الحراس (`mutate_generic.py`، قياس فعلي):** عطّلتُ **108 حراس** على حدة (التوزيع التالي للأولى منها وعددها 77، ثم 17 للتراجع والإعدادات والرقم المكرر، ثم 14 لإصلاحات المراجعة) (30 في الخدمة وكائنَي القيمة والنوع، و10 في نموذج البيع، و6 في نموذج الاستلام ودفعة المورد، و5 في نموذجَي المصروف والعميل، و3 في متحكم العملاء، و8 في متحكم المبيعات، و1 في متحكم الاستلام، و2 في المصروفات، و5 في الإعدادات والتنقل ورسالة الرفض، و7 في الترحيل) **فتفشل اختبارات كلٍّ منها** (**108 من 108** على الإيداع النهائي). **كُشف 70 من أول مرة وبقيت ستة بلا كشف** فأضفتُ لها اختبارات: (1) حدّ الرقم التالي فوق سجل الأرقام حين يضيع صف العدّاد، (2) استبعاد المستند المرقَّم من الأرقام المعروضة بمعرّفها (كان الاختبار يرتب المعرّفات بحيث لا يفرّق)، (3) التسلسل بعد تعطيله لا يعطي رقمًا (حارسان متداخلان، عُطِّلا معًا)، (4) معاينة الرقم لا تُظهر شيئًا والتسلسل معطَّل، (5) دفعة تُضاف من نموذج التعديل إلى بيع غير مكتمل لا تُرقَّم بعد، (6) فحص التكرار لفاتورة بلا رقم بعد: **كان تعطيلي له مكافئًا للأصل** (تحويل `null` إلى نص فارغ) **فصحّحتُ التعطيل** بحذف الفحص نفسه فكُشف، وأضفتُ حارسًا سابعًا لنظيره في عرض السعر. **وأضفتُ اختبار معاينة الرقم** لأنه لم يكن له اختبار في الخدمة نفسها. **ولا يعدّ حارسًا مستقلًا:** قفل `FOR UPDATE` في `configure` (قفل `INSERT IGNORE` يحمي الصف قبله، وهو الانضباط نفسه في `lockSequence` القائم) فلم أدّعِ له دليلًا. ولم أُبقِ شيئًا معطّلًا؛ نتائج كل حارس في آخر هذا القسم
- **اختبارات ذات صلة شُغِّلت على الإيداع `a55237e` (آخر إيداع يمس الشيفرة؛ ما بعده تقرير ووثيقة الحراس فقط) والنتائج كما طُبعت، كلها بقاعدة `ospos_test_c`:**
  - `tests/Database/DocumentSequencesMigrationTest.php`: OK (12 tests, 50 assertions)
  - `tests/Libraries/Numbering/DocumentNumberingTest.php`: OK (82 tests, 310 assertions)
  - `tests/Libraries/Numbering/DocumentTypeTest.php`: OK (6 tests, 43 assertions)
  - `tests/Libraries/Numbering/NumberingSequenceTest.php`: OK (5 tests, 9 assertions)
  - `tests/Models/SaleTest.php`: OK (39 tests, 219 assertions)
  - `tests/Models/ReceivingTest.php`: OK (22 tests, 145 assertions)
  - `tests/Models/CustomerTest.php`: OK (22 tests, 77 assertions)
  - `tests/Models/ExpenseTest.php`: OK (7 tests, 40 assertions)
  - `tests/Libraries/Accounting/SupplierAdvancesTest.php`: OK (32 tests, 281 assertions)
  - `tests/Libraries/Accounting/AccountingMessageTest.php`: OK (163 tests, 163 assertions)
  - `tests/Libraries/Sale_libTest.php`: OK (18 tests, 56 assertions)
  - `tests/Libraries/Sale_libPaymentTest.php`: OK (12 tests, 21 assertions)
  - `tests/Controllers/SalesControllerTest.php`: OK (120 tests, 723 assertions)
  - `tests/Controllers/ReceivingsControllerTest.php`: OK (78 tests, 670 assertions)
  - `tests/Controllers/CustomersControllerTest.php`: OK (19 tests, 38 assertions)
  - `tests/Controllers/CustomersCsvImportTest.php`: OK (6 tests, 23 assertions)
  - `tests/Controllers/ExpensesControllerTest.php`: OK (6 tests, 15 assertions)
  - `tests/Controllers/ConfigTest.php`: OK (34 tests, 112 assertions)
  - `tests/Controllers/AccountingJournalsControllerTest.php`: OK (65 tests, 279 assertions)
  - `tests/helpers/NavigationHelperTest.php`: OK (16 tests, 22 assertions)
  - `tests/LanguageFilesTest.php`: OK (1734 tests, 2922 assertions)
  - `tests/Libraries/Accounting/SalePostingTest.php`: OK (112 tests, 777 assertions)
  - `tests/Libraries/Accounting/ReceivingPostingTest.php`: OK (138 tests, 1263 assertions)
  - `tests/Libraries/Accounting/CustomerLockTest.php`: OK (7 tests, 36 assertions)
  - `tests/Libraries/Accounting/SupplierLockTest.php`: OK (13 tests, 96 assertions)
  - `tests/Libraries/Accounting/OpeningStockTest.php`: OK (47 tests, 576 assertions)
  - `tests/Models/CustomerRewardPointsTest.php`: OK (5 tests, 10 assertions)
  - `tests/Models/GiftcardTest.php`: OK (7 tests, 14 assertions)
  - `tests/Models/ItemQuantityTest.php`: OK (3 tests, 7 assertions)
  - `tests/Libraries/Accounting/CustomerLedgerTest.php`: OK (18 tests, 152 assertions)
  - `tests/Libraries/Accounting/CreditSettlementTest.php`: OK (18 tests, 169 assertions)
  - `tests/Libraries/Accounting/SupplierLedgerTest.php`: OK (20 tests, 208 assertions)
  - `tests/Libraries/Accounting/SupplierSettlementTest.php`: OK (19 tests, 241 assertions)
  - `tests/Libraries/Accounting/PaymentTreasuryStampingTest.php`: OK (14 tests, 71 assertions)
  - `tests/Libraries/Accounting/StockLedgerTest.php`: OK (18 tests, 100 assertions)
  - `tests/Support/ParityFixtureTest.php`: OK (100 tests, 1090 assertions)
  - **لم أشغّل المجموعة الكاملة** (بحسب القيد): شغّلتُ ملفات الحزمة الجديدة والمعدَّلة وكل ملف يستعمل نموذج البيع أو الاستلام أو دفعة المورد أو عامل التزامن `RaceWorker` أو ملفات اللغة أو سجل التدقيق أو أقسام الإعدادات. كلها بقاعدة `ospos_test_c`.
- **التنسيق:** `php-cs-fixer` على كل ملف جديد غير قديم (الترحيل وأصناف `Numbering` الأربعة وعرض الإعدادات وملفات `Numbering.php` الستة والأربعين): **صفر ملف يحتاج إصلاحًا (52 من 52 نظيفة)**. وتصحيح أثناء العمل: كانت وثيقة `applicationsOf` في `Receivings` قد انزاحت إلى دالتي الجديدة فأعدتُها.

### نتائج تعطيل الحراس كما طُبعت

**أُعيد تشغيل المجموعة كلها (108 تعطيلات) على الإيداع `a55237e`** (آخر إيداع يمس الشيفرة) وقائمتها المحفوظة في المستودع للإعادة: [`wp8-guards.json`](wp8-guards.json) (الصيغة والتشغيل: `mutate_generic.py WORKTREE wp8-guards.json` بعد ضبط `MYSQL_DB_NAME`) بعد إصلاحات المراجعة المستقلة (القسم 8): الحراس الـ94 المسجَّلة في التشغيل السابق (77 في التقرير الأول، و17 أُضيفت بعده: تراجع الحجز عند رفض الدفاتر، وشاشة الإعدادات، ورفض الرقم المكتوب المكرر) بقيت كلها مكتشَفة، وحُدّثت خمسة منها لتوافق الشيفرة بعد الإصلاح (`G52` و`G53` و`G59` و`G60` و`G61`)، وأُضيف **14 تعطيلًا جديدًا** (`G88` إلى `G101`) للمواضع التي أضفتها: فحص رقم العميل في النموذج والنموذج الفرعي والاستيراد، وحقل رقم الفاتورة في السجل (نص إرشادي، وحفظ الرقم المكتوب كما كُتب، ومسح الحقل الفارغ، ونوع النص). وهذا هو الناتج كما طُبع:

```
DETECTED  G01: configure needs the settings permission: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G02: the sequence row is locked before a number is taken: Tests: 3, Assertions: 10, Failures: 1.; failing: 1
DETECTED  G03: a document that has a number keeps it and takes no second one: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G04: a number already in the register is never given again: Tests: 1, Assertions: 1, Errors: 1.; failing: 1
DETECTED  G05: a number typed in the column of another document is skipped: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G06: the search for a free number stops after a thousand: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G07: the counter stops below what its column can hold: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G08: the next number stays above every number given: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G09: the next number stays above the numbers the documents carry: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G10: the next number stays above the ids that documents still show: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G11: a document that has a number no longer counts as showing its id: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G12: ids count only for an empty prefix: Tests: 41, Assertions: 114, Errors: 1, Failures: 1.; failing: 2
DETECTED  G13: the next number is never lowered: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G14: a prefix is no longer than 16 characters: Tests: 13, Assertions: 25, Failures: 1.; failing: 1
DETECTED  G15: a prefix takes letters, digits and a few separators only: Tests: 13, Assertions: 23, Failures: 3.; failing: 3
DETECTED  G16: the digits are a whole number: Tests: 13, Assertions: 24, Failures: 2.; failing: 2
DETECTED  G17: the digits are between 0 and 10: Tests: 13, Assertions: 24, Failures: 2.; failing: 2
DETECTED  G18: the next number is a whole number: Tests: 13, Assertions: 25, Failures: 1.; failing: 1
DETECTED  G19: the next number is between 1 and the maximum: Tests: 13, Assertions: 23, Failures: 3.; failing: 3
DETECTED  G20: every change of the numbering is written in the audit log: Tests: 2, Assertions: 3, Errors: 1, Failures: 1.; failing: 2
DETECTED  G21: nothing is written or audited when nothing changed: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G22: a document takes the sequence of the company of its branch: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G23: only a table that holds the kind of document is accepted: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G24: the number is written in the column the document always had: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G25: a document without a number shows what it showed before: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G26: the preview takes nothing while the sequence is off: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G27: the digits pad the number with zeros: Tests: 5, Assertions: 9, Failures: 2.; failing: 2
DETECTED  G28: the prefix starts the number: Tests: 5, Assertions: 9, Failures: 3.; failing: 3
DETECTED  G29: a credit note shows POS and its id until it has a number: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G30: a sequence that is off gives no number: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G31: an invoice is numbered only when the sale is completed: Tests: 2, Assertions: 6, Failures: 2.; failing: 2
DETECTED  G32: an invoice number typed by hand is kept: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G33: a quote is numbered when it is saved: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G34: a return is numbered as a credit note: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G35: each payment of a completed sale is numbered: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G36: what only records what stays unpaid is not a payment document: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G37: a sale that cannot be numbered is undone as a whole: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G38: an edit that leaves the number blank keeps the number of the sequence: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G39: a payment added to a completed sale is numbered: Tests: 1, Assertions: 7, Failures: 1.; failing: 1
DETECTED  G40: a payment added to a sale that is not completed is not numbered yet: Tests: 1, Assertions: 7, Failures: 1.; failing: 1
DETECTED  G41: a completed purchase is numbered: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
DETECTED  G42: a return to the supplier is a debit note: Tests: 1, Assertions: 8, Failures: 1.; failing: 1
DETECTED  G43: a transfer between locations gets no number: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G44: each new supplier payment of a purchase is numbered: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
DETECTED  G45: an opening balance of a supplier is not a payment document: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G46: a payment to a supplier is numbered: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G47: a new expense is numbered: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G48: an expense that cannot be numbered is undone: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G49: an account number typed by hand is kept: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G50: a customer that cannot be numbered is undone: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G51: an account number is taken even when several customers share it: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G54: only a new customer is offered the automatic number: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G55: the invoice format of the settings gives way to the sequence: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G56: the quote format of the settings gives way to the sequence: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G57: an invoice that has no number yet is not looked up as a duplicate: Tests: 1, Assertions: 2, Errors: 1.; failing: 1
DETECTED  G70: a quote that has no number yet is not looked up as a duplicate: Tests: 1, Assertions: 2, Errors: 1.; failing: 1
DETECTED  G58: the invoice shows the number the sequence gave it: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G62: the receipt of a purchase shows its number: Tests: 1, Assertions: 5, Failures: 1.; failing: 1
DETECTED  G63: the expense list shows the number column only while the sequence is on: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G64: the expense rows show their numbers: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G65: an unknown kind of document is refused by the settings screen: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G66: the settings screen saves as the employee who is logged in: Tests: 2, Assertions: 7, Failures: 1.; failing: 1
DETECTED  G67: the duplicate option belongs to the customer sequence only: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G68: the numbering is a section of the settings: Tests: 16, Assertions: 20, Failures: 2.; failing: 2
DETECTED  G69: a message names the lowest next number: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  M01: a number is unique per company and kind: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  M02: a document has one number per kind: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  M03: one sequence per company and kind: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  M04: the digits are at most ten: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  M05: the next number is at least one: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  M06: a sequence belongs to a company that exists: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  M07: the migration is safe to run twice: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G71: a sale the books refuse after its invoice was numbered leaves no number: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
DETECTED  G72: a payment that cannot be numbered takes back the invoice number already given: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G73: a purchase the books refuse after it was numbered leaves no number: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
DETECTED  G74: a transaction the work owns is rolled back when the work fails after a number was taken: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
DETECTED  G75: a running sequence keeps its next number when a row that never gets a number appears: Tests: 2, Assertions: 2, Errors: 1, Failures: 1.; failing: 2
DETECTED  G76: a running sequence that changes its prefix starts above the ids shown bare: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G77: a sequence that is off starts above the numbers the documents carry or show: Tests: 2, Assertions: 2, Failures: 2.; failing: 2
DETECTED  G78: the service refuses a prefix that is not text: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G79: the settings screen reads a list given as the prefix without a PHP warning: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G80: the settings screen reads a list given as the digits without a PHP warning: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G81: the settings screen reads a list given as the next number without a PHP warning: Tests: 1, Assertions: 0, Errors: 1.; failing: 1
DETECTED  G82: a sequence that is off may be started lower when its prefix changes (the field does not block it): Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G83: a running sequence cannot be lowered from the field: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G84: the settings page hands the numbering to its tab: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G85: the settings page has a pane for the numbering: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G86: an invoice number typed by hand that another invoice has is refused: Tests: 2, Assertions: 10, Failures: 2.; failing: 2
DETECTED  G87: a quote number typed by hand that another quote has is refused: Tests: 2, Assertions: 10, Failures: 2.; failing: 2
DETECTED  G52: a customer account number another customer has is refused: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G53: the duplicate option of the customer sequence is honoured: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G88: an empty account number is never refused: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G89: the form save asks the model whether a customer number is refused: Tests: 1, Assertions: 2, Failures: 1.; failing: 1
DETECTED  G90: the remote check of the form follows the duplicate option: Tests: 3, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G91: the CSV import refuses a taken account number even with a new email: Tests: 4, Assertions: 7, Failures: 2.; failing: 2
DETECTED  G92: the CSV import follows the duplicate option: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G59: the register offers the next number of the invoice sequence: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G93: the next number is a placeholder and not a value that the field would post: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G94: the placeholder shows the next number: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G95: the invoice format of the settings still fills the field while the sequence is off: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G96: a number typed in the register keeps its letters and separators: Tests: 4, Assertions: 16, Failures: 2.; failing: 2
DETECTED  G97: a number typed in the register loses what a number cannot have: Tests: 1, Assertions: 4, Failures: 1.; failing: 1
DETECTED  G98: the typed number keeps only letters, digits and the separators of a prefix: Tests: 9, Assertions: 9, Failures: 6.; failing: 6
DETECTED  G99: the typed number keeps the separators of a prefix: Tests: 9, Assertions: 9, Failures: 5.; failing: 5
DETECTED  G100: an emptied invoice number field leaves the number to the sequence: Tests: 1, Assertions: 1, Failures: 1.; failing: 1
DETECTED  G101: a number typed in the register is held as text: Tests: 1, Assertions: 1, Errors: 1.; failing: 1
DETECTED  G60: the receipt of a return shows the credit note number: Tests: 1, Assertions: 3, Failures: 1.; failing: 1
DETECTED  G61: the edit form shows the number of each payment: Tests: 1, Assertions: 6, Failures: 1.; failing: 1
108 of 108 guards detected
```

## 4. ما افترضتُه (للمراجعة)

1. **التسلسل لكل (شركة، نوع)** والمستند يأخذ تسلسل **شركة فرعه** (فرع بلا شركة معروفة يأخذ شركة السياق). **ليس لكل فرع تسلسل** ولا يُصفَّر سنويًا ولا بالشهر.
2. **تسعة أنواع، وكل نوع تسلسله مستقل:** الإشعار الدائن لا يشارك فاتورة البيع تسلسلها، ودفعتا المورد (الاستلام والمستقلة) تشتركان في تسلسل `supplier_payment` (اختبار يثبته)، وكذلك الاشعار المدين تسلسله خاص.
3. **الافتراضي معطَّل وبلا بادئة ولا منازل** كما تعرض الشاشات اليوم، **لا ستة منازل كدفترة** (`000003`): ما يعرضه الأول برو اليوم هو الأساس وتغييره قرارك (`ownerDecision`).
4. **حين يُفعَّل تسلسل الفاتورة أو العرض يحلّ محل تنسيق الإعدادات وعدّاده** للمستندات الجديدة (الرقم المكتوب يبقى)، ولا يُحذف التنسيق القديم فيعود العمل به بتعطيل التسلسل.
5. **عرض السعر يُرقَّم عند حفظه** (وليس عند تحويله إلى فاتورة)، **والفاتورة عند إتمام البيع** (لا في المسودة ولا المعلَّقة).
6. **الإشعار الدائن = مرتجع بيع مكتمل، والإشعار المدين = استلام من نوع مرتجع.** **دفعة «آجل» وتقريب النقد ليسا مستندَي دفعة** فلا يُرقَّمان.
7. **الرقم الممنوح لا يُسحب ولا يُعاد** حين يُحذف المستند (يبقى في السجل)، بينما **الرقم الذي ألغيت معاملته لا يُحجز أصلًا** (لا ثغرة، والثغرات عند التراجع غير مطلوبة).
8. **رفض تكرار رقم حساب العميل في الخادم مفعّل افتراضيًا** (كان في الواجهة وحدها)، ويمكن للمالك إيقافه من شاشة الترقيم؛ ومنع التكرار **لا ينطبق** على غير العميل (الفاتورة لها فحصها القديم).
9. **الرقم التالي يرتفع تلقائيًا فوق ما يعرضه المستند القائم عند بدء التسلسل أو تغيير بادئته** (حدّ الأرقام والمعرّفات)، **والتسلسل الجاري بالبادئة نفسها يتخطى ما يأخذه غيره أثناء الحجز** ولا يرتفع بسبب معرّفات صفوف لا تُرقَّم؛ ويُرفض ما دون الحدّ.
10. **لا أدّعي تطابقًا مع دفترة** في أي من هذه النقاط: ما رُصد فقط أن لكل مستند شاشة ترقيم برقم تالٍ (`setA`، `setB`) وأن الرقم التالي للعميل مقفول للقراءة (`CUS-02`).

## 5. ما يحتاج رصد دفترة أو قرار المالك

**يلزم رصد دفترة (`S-33` في WP21):** تعديل الرقم التالي **للأسفل** (وهل يُسمح به ومع أي ضوابط)، **وتكرار الأرقام المكتوبة يدويًا** للفواتير والدفعات وغيرها وهل تُرفض، **ومتغيرات البادئة** (سنة، شهر، فرع) وإعادة التصفير سنويًا، وهل للفرع تسلسله، وهل تتشارك الفاتورة والمرتجع والإشعار تسلسلًا، وهل تُرقَّم المسودة وعرض السعر عند الحفظ أو عند الإصدار، وهل لدفعة «آجل» رقم، **وعدد منازل دفترة الافتراضي لكل نوع** (رُصد `000003` للفاتورة و`#00001` بخمس منازل للإشعار المدين، دون ما عداهما).

**قرارات المالك:** (1) هل يحلّ تسلسل الفاتورة محل التنسيق القديم عند تفعيله (المفترض نعم)؛ (2) افتراضي منع تكرار رقم حساب العميل (المفترض مفعّل في الخادم)؛ (3) عدد المنازل الافتراضي إن أراد الستّ كدفترة عند تفعيل التسلسل (حاليًا صفر)؛ (4) هل يريد تسلسلًا لكل فرع بدل الشركة؛ (5) **هل يريد ضمانًا ذريًا لرقم حساب العميل المكتوب** (فهرس فريد على `customers.account_number` بعد تنظيف المكرر القائم، فهو اليوم فحص ثم كتابة)؛ (6) **هل يريد فرض بادئة** لتسلسل دفعات العميل (يمنع التباس عرض معرّف صف «آجل» برقم دفعة، انظر القسم 6).

## 6. حدود معروفة

- **لا قوائم جديدة** للدفعات والإشعارات (`G-34`، `G-51`، WP9 وWP10): الرقم يُعرض في النماذج والإيصالات وقائمة المصروفات فقط.
- **أوامر العمل (`work_order_number`)** لم تدخل (ليست في نطاق الحزمة).
- **`configure` يستعمل `INSERT IGNORE` ثم `FOR UPDATE`** وهو انضباط `lockSequence` القائم نفسه، فلا حارس مستقل له؛ **ولم أجرّب حفظين متزامنين لإعداد نوع واحد من اتصالين** (جرّبتُ تزامن الحجز لا تزامن التهيئة).
- **أسماء المتغيرات (`AGENTS.md`):** أُعيدت تسمية **المتغيرات المحلية** إلى camelCase في **كل دالة قديمة أضفتُ فيها أسطرًا** (`Sale::updateLocked` و`Receiving::saveValueLocked` ودوال `Sales` و`Customers` و`Receivings` و`Expenses` و`Config` المعدَّلة)، **وأسماء بيانات العروض الجديدة** (`creditNoteNumber` و`paymentNumbers` و`documentLabel` و`documentNumber` و`expenseNumber` و`automaticAccountNumber` و`invoiceNumberPlaceholder`). **لم تُمَس** معاملات الدوال العامة القديمة (`$sale_id` و`$customer_data` و`$expense_data`…) لأنها ليست متغيرات محلية وتغيير اسمها يغيّر واجهة الدالة، **ولا** معرّفات HTML (`id="credit_note_number"`) **ولا** مفتاح عمود قائمة المصروفات `expense_number` (اسم حقل JSON كأخواته `expense_id`). الإعادة التسمية **التزام صريح بالقاعدة** رغم احتمال تعارض الدمج في الدوال التي تعدّلها حزم أخرى؛ التعارض إن حدث في أسطر إعادة التسمية فقط ويُحلّ بأخذ أحد الطرفين ثم إعادة تطبيق التسمية.
- **الأرقام المُنحت بقاعدة الفروع** تتبع شركة الفرع عند الحفظ؛ نقل مستند من فرع إلى فرع لا يغيّر رقمه.
- **رفض تكرار رقم حساب العميل المكتوب هو فحص ثم كتابة** (قراءة ثم إدراج بلا قفل): عميلان يحفظان رقمًا واحدًا جديدًا في اللحظة نفسها قد ينجحان معًا. **ولا أغلقتُ الثغرة عمدًا:** الفهرس الفريد على `customers.account_number` أسقطه ترحيل قديم (`20230412000001`) فلا ضمان في قاعدة البيانات، والقفل الوحيد المتاح بلا نظام قفل جديد هو صف `document_sequences` ولا صف له حتى يفعّل المالك أو يضبط تسلسلًا، وإنشاؤه عند كل حفظ يكسر مبدأ «الافتراضيات لا تترك أثرًا». **الأرقام التي يعطيها التسلسل نفسه فريدة دائمًا** (قفل الصف ومفتاحان فريدان). لو أراد المالك الضمان الذري للرقم المكتوب فالطريق فهرس فريد بعد تنظيف المكرر القائم (**قرار مالك**).
- **صفوف الدفعات التي لا تُرقَّم تعرض معرّفها:** دفعة «آجل» وتقريب النقد يبقيان بلا رقم ويعرضهما نموذج تعديل البيع بمعرّفهما كما كانا. فمع تسلسل دفعات عميل **ببادئة فارغة وبلا منازل** قد يساوي رقمُ دفعة لاحقة معرّفَ صف «آجل» في بيع آخر (**التباس في العرض**، غالبًا بين نموذجَي تعديل مختلفين، لا أثر على قيد ولا رصيد ولا على فرادة الأرقام الممنوحة). **لا يحدث** إن كان للتسلسل بادئة أو منازل. **قيّمتُ (دون تنفيذ)** تخطّي هذه المعرّفات داخل الحجز فوجدتُ أنه يحتاج قفزة فوق مئات الصفوف «الآجلة» المتتالية أو يستنفد حدّ البحث (1000) فيُفشل بيعًا مشروعًا، وهذا أسوأ من التباس عرض؛ **فتركتُ السلوك وأوصي ببادئة (`PAY-`) لتسلسل دفعات العميل.**
- **صفوف تُدرَج خارج النماذج** (استيراد مباشر في قاعدة البيانات أو سكربت) لا تُرقَّم ولا يتخطاها تسلسل جارٍ ببادئة فارغة لدفعة أو مصروف.
- **ملفات مشتركة عُدِّلت وليست من صلب الترقيم:** (1) `app/Views/configs/system_info.php`: الدالة المسماة `getBrowserNameAndVersion` كانت معلنة **داخل العرض** فصارت دالة مجهولة؛ لأن عرض شاشة الإعدادات أكثر من مرة في عملية PHP واحدة (اختبارات `ConfigTest` الجديدة تقرأ تبويب الترقيم) كان يفشل بـ«Cannot redeclare»، والسلوك المعروض لم يتغير. (2) `tests/Support/RaceWorker.php`: يحمّل صفوف التطبيق من نسخة العمل التي يقع فيها بدل الفرع الرئيس الذي يشير إليه `vendor` المشترك في شجرة عمل git، ويضيف طريقة `assignNumber`؛ **لا أثر له في مستودع عادي** (الشرط `vendorHome !== root`) ولا مساس بالطرق القائمة. (3) `app/Libraries/Sale_lib.php`: `set_invoice_number` يقبل نصًّا (كان عددًا صحيحًا فيقطع أي رقم بحروف) ويمسح الرقم المحفوظ إن أُفرغ الحقل.

## 7. قبل النشر

الترحيل `20261210000000_DocumentSequences` **إضافي** (جدولان فارغان) ويُنفَّذ تلقائيًا عند أول دخول ولا يغيّر شيئًا مما يُعرض ولا يمنح صلاحية. **لا أثر حتى يفعّل المالك تسلسلًا.** **افتراض محرك قاعدة البيانات:** MySQL 8 أو MariaDB ≥ 10.2.4 (عدّاد `AUTO_INCREMENT` مخزَّن ولا يُعاد حسابه عند إعادة التشغيل؛ المحرك المُختبَر MariaDB 10.11). على MySQL 5.7 أو MariaDB 10.1 يعيد المحرك حساب العدّاد من أعلى معرّف بعد إعادة التشغيل، فإن كان آخر صف دفعة مرقَّمة حُذفت (تعديل بيع يصفّر دفعة، أو حذف استلام) ورث الصفُّ الجديد الذي يأخذ معرّفها رقمَها من السجل بدل رقم جديد. **تحقق من إصدار المحرك قبل تفعيل تسلسل الدفعات.** `down()` يحذف الجدولين فيحذف سجل الأرقام الممنوحة: **بعد تفعيل حقيقي يُفضَّل استعادة النسخة الاحتياطية** (أعمدة الفاتورة والعرض والعميل تحتفظ بأرقامها، أما أرقام الدفعات والإشعارات والمشتريات والمصروفات فلا مصدر لها غير السجل).

## 8. المراجعة المستقلة بعد التنفيذ: ما قُبل وما رُفض

راجع الفرعَ مراجعان مستقلان ورفعا 13 ادّعاءً (بعضها مكرر). تتبّعتُ كلًّا في الكود قبل أن أقبله أو أرفضه:

| الادّعاء | الحكم | ما فُعل |
|---|---|---|
| **حقل رقم الفاتورة في السجل يرسل الاقتراح ببادئته إلى دالة تُبقي الأرقام فقط ثم إلى `set_invoice_number(int)`**، فيُحفظ البيع بـ`-1` خارج التسلسل، ويُرفض لاحقًا «مكررًا»، وتتعطل خانة فارغة بخطأ نوع (ادّعاءان) | **صحيح** (تحققتُ في PHP 8.3: `INV-0001` ← `-1`، و`2026-0007` و`''` ← `TypeError`) | **أُصلح** (`e9e2e9e`): الحقل يعرض الرقم التالي **نصًّا إرشاديًا** ولا يملأه، والحقل الفارغ يترك الرقم للتسلسل، والمكتوب باليد يُحفظ بحروفه وفواصله وأصفاره (`DocumentNumbering::typed`، و`Sale_lib::set_invoice_number(string)` يمسح الفارغ). اختبارات جديدة: ثمانية للمتحكم، وثلاثة لصنف السجل `Sale_lib`، وتسع مجموعات بيانات لـ`typed` |
| **فحص الرقم المكرر للعميل في الخادم يغفل استيراد CSV** (الصف يُرفض فقط حين يوجد البريد والرقم معًا) (ادّعاءان) | **صحيح** | **أُصلح** (`e9e2e9e`): الاستيراد يسأل `Customer::refusesAccountNumber` فيرفض الصف المكرر ويتبع إيقاف الخيار، واختبارات: صف برقم موجود وبريد جديد، وصفّان مكرران في ملف واحد، والخيار موقوف، وأرقام مختلفة |
| **الجزء الذري من الادّعاء السابق** (حفظان متزامنان لرقم جديد واحد ينجحان معًا) | صحيح لكن **لا يُصلَح بلا نظام قفل جديد** | **تُرك ووُثّق** في القسم 6 مع السبب (الفهرس الفريد مسقط بترحيل قديم، وإنشاء صف قفل يكسر مبدأ «الافتراضيات لا أثر لها») وقرار المالك المقترح |
| **الفحص عن بُعد في نموذج العميل لا يتبع إيقاف الخيار** | **صحيح** (`postCheckAccountNumber` تستدعي `check_account_number_exists` دائمًا) | **أُصلح** (`e9e2e9e`): تستدعي `refusesAccountNumber`؛ اختباران (الخيار موقوف، ثم يُفعَّل ثانية) |
| **السجل يربط الرقم بـ(نوع، جدول، معرّف) فمعرّف يُعاد استعماله يرث رقم مستند محذوف** | **صحيح بشرط** محرك لا يحفظ `AUTO_INCREMENT` (MySQL 5.7 وMariaDB < 10.2.4) | **لم يُغيَّر الكود**: الإصلاح يمسّ أربعة مواضع حذف في نماذج المال (بيع واستلام) لحالة محركات منتهية الدعم؛ **وُثّق افتراض المحرك** في القسم 7 وأنه يُتحقق منه قبل تفعيل تسلسل الدفعات |
| **شجرة العمل غير نظيفة: تعديلات غير مودعة في أربعة ملفات** (ادّعاءان؛ وثالث بالمعنى نفسه) | **صحيح**: كانت إعادة تسمية متغيرات محلية غير مودعة (بلا تغيير منطق) | **أُودعت** (`b77e321`) **بعد إكمالها** في كل دالة قديمة عدّلتُها (`Receiving::saveValueLocked`، ودوال `Expenses` و`Receivings` وغيرها)، ثم **أُعيد تشغيل الاختبارات وتعطيل الحراس على الإيداع النهائي نفسه** (القسم 3) |
| **أدلة التقرير أقدم من آخر ثلاثة إيداعات**: عدّادات وأسماء اختبارات وقاعدة «الرقم التالي لا يقل عن معرّف معروض» | **صحيح** | **حُدّث التقرير**: عدّادات الاختبارات من تشغيل فعلي، وأعيد تشغيل كل حارس، وصُحّح وصف الحدّ الأدنى (التسلسل الجاري يرتفع فوق ما أُعطي فقط) وأسماء الاختبارات الذهبية |
| **تسلسل دفعات ببادئة فارغة قد يعطي رقمًا يساوي معرّف صف «آجل» لا يُرقَّم** | **صحيح لكنه التباس عرض** ولا أثر على القيود ولا على فرادة الأرقام الممنوحة | **تُرك ووُثّق** في القسم 6 (الإصلاح بالتخطي يستنفد حدّ البحث فيُفشل بيعًا مشروعًا) مع توصية ببادئة |
| **اسم اختبار يشير إلى دالة غير موجودة** (`testRegister_…` والبيع ليس فيه `register`) | **صحيح** | **أُعيدت تسميته** `testGetIndex_OffersTheNextNumberOfTheInvoiceSequenceAsThePlaceholderOfTheInvoiceField`؛ وفحصتُ بقية أسماء الاختبارات الجديدة فكلها لدوال موجودة |
| **أسماء المتغيرات (`AGENTS.md`)**: متغيرات محلية قديمة في دوال عدّلتُها، ومتغيرات عروض جديدة بصيغة snake_case | **صحيح** (والتقرير كان يعدّه قرارًا) | **أُصلح**: المتغيرات المحلية (`b77e321`) وأسماء بيانات العروض الجديدة (`a55237e`) بصيغة camelCase؛ والمعاملات القديمة ومعرّفات HTML ومفتاح عمود المصروفات تُركت (القسم 6) |
| **تعديل `system_info.php` و`RaceWorker.php` غير مذكورَين وغير لازمَين للترقيم** | **صحيح أنهما غير مذكورَين**، وكلاهما لازم لاختبارات الحزمة | **وُثّقا** في القسم 6 بسببهما وأثرهما (لا أثر للثاني في مستودع عادي) |

## 9. تحديثات الوثائق المشتركة للمُدمِج

لم أعدّل أيًّا من `decisions.md` و`conformance-matrix.md` و`gap-report.md` و`backlog.md` ولا أي `README` ولا `deploy-checklist.md`. فيما يلي النصوص جاهزة للصق.

### 9.1 `decisions.md` (نص مقترح بلا رقم E)

> **الترقيم لكل مستند (WP8، 2026-10-27):** نُفِّذت خدمة ترقيم لتسعة أنواع (فاتورة بيع، عرض سعر، إشعار دائن، دفعة عميل، عميل، فاتورة شراء، إشعار مدين، دفعة مورد، مصروف): تسلسل لكل (شركة، نوع) ببادئة وعدد منازل ورقم تالٍ، **معطَّل افتراضيًا** فتعرض المستندات ما تعرضه اليوم ولا يُعاد ترقيم قائم ولا يُعاد تاريخه، ويُحجز الرقم **داخل معاملة الحفظ بقفل صف التسلسل** (انضباط `accounting_sequences`) مع مفتاح فريد في السجل، فلا تكرار حتى باتصالين متزامنين، وما يُلغى حفظه لا يترك رقمًا وما يُحذف مستنده لا يُعاد رقمه. **الرقم التالي يُرفع للأعلى فقط** ولا يقل عن رقم موجود أو معرّف ما زال مستند يعرضه. **يُكتب رقم الفاتورة والعرض والعميل في أعمدتها القديمة** ويبقى الرقم المكتوب يدويًا، **ويحلّ التسلسل المفعَّل محل تنسيق الفاتورة في الإعدادات**. دفعة «آجل» وتقريب النقد ليسا مستندي دفعة. صلاحية التغيير `config` **في الخدمة** وكل تغيير في سجل التدقيق. **منع تكرار رقم حساب العميل في الخادم افتراضي** (خيار يوقفه المالك؛ يشمل حفظ النموذج والفحص عن بُعد واستيراد CSV، وهو فحص ثم كتابة غير ذري). **لا قيد ولا دفتر يتغير بالترقيم والمحاسبة اختيارية.** **لم يُدّعَ تطابق مع دفترة:** تعديل الرقم التالي للأسفل وتكرار الأرقام المكتوبة ومتغيرات البادئة تنتظر الرصد `S-33`. قرارات المالك المفتوحة: استبدال تنسيق الفاتورة القديم، وافتراضي منع تكرار العميل (وهل يريد فهرسًا فريدًا ذريًا)، وعدد المنازل الافتراضي، وتسلسل لكل فرع، وفرض بادئة لدفعات العميل.

### 9.2 `conformance-matrix.md` (صفوف مقترحة)

تُحدَّث خانة «الأول برو» وملاحظة الصفوف التالية (الحالة تبقى «مختلف ويحتاج تعديلًا» ولا يتغير الإجمالي):

| المعرّف | الموضوع | دفترة | الأول برو | الحالة | ملاحظة |
|---|---|---|---|---|---|
| SAL-12 | رقم الفاتورة: تسلسل تلقائي وتعديل يدوي بإعداد وفحص تكرار | الرقم التالي 000003 (للقراءة)، و`edit_invoice_number` مفعّل | **تسلسل اختياري لفاتورة البيع ببادئة وعدد منازل ورقم تالٍ يُرفع للأعلى فقط** يُحجز داخل معاملة الإتمام ويُكتب في `invoice_number`، والرقم المكتوب يبقى وتكراره مرفوض كما كان؛ ومعطَّل افتراضيًا فيبقى رقم الإيصال `sale_id` وتنسيق الإعدادات (WP8) | مختلف ويحتاج تعديلًا | setB. **لا ادعاء تطابق:** تعديل الرقم التالي للأسفل وتكرار الرقم اليدوي ومتغيرات البادئة تنتظر `S-33`؛ منازل دفترة (6) لم تُعتمد افتراضيًا |
| SAL-15 | إضافة دفعة على فاتورة قائمة (مستند دفعة مرقّم) | (دون تغيير) | (يُضاف إلى الخانة) **ولكل دفعة عميل رقم من تسلسل `customer_payment` اختياري** (يُرقَّم عند الإتمام، ولا يُرقَّم صف «آجل» ولا تقريب النقد) ويظهر في نموذج تعديل البيع (WP8) | مختلف ويحتاج تعديلًا | G-34: الرقم منجز، **المتبقي الشاشة والقائمة (WP9)** و«تحصيل بواسطة» |
| SAL-18 | الإشعار الدائن مستند مستقل يسوّي الفاتورة تلقائيًا | (دون تغيير) | (يُضاف إلى الخانة) **رقم إشعار دائن من تسلسل `credit_note` اختياري** للمرتجع المكتمل، يظهر في الإيصال ونموذج التعديل (WP8) | مختلف ويحتاج تعديلًا | المتبقي قائمة الإشعارات (G-34، WP9) |
| PUR-04 | مدفوعات الموردين | (دون تغيير) | (يُضاف إلى الخانة) **رقم لدفعة المورد (صف الاستلام والدفعة المستقلة) من تسلسل `supplier_payment` اختياري**، ولفاتورة الشراء والإشعار المدين تسلسلاهما، ويظهر في إيصال الاستلام ونموذج تعديله (WP8) | مختلف ويحتاج تعديلًا | G-51: الرقم منجز، المتبقي الشاشة والقائمة وحقل التاريخ (WP10) |
| CUS-02 | ترقيم العميل التلقائي | الرقم التالي 000004 مقفول للقراءة | **تسلسل اختياري لرقم حساب العميل** (بادئة ومنازل ورقم تالٍ يُرفع للأعلى فقط) يُمنح للعميل الجديد بلا رقم مكتوب ويُكتب في `account_number`، **ومنع تكرار الرقم المكتوب في الخادم افتراضي** (WP8) | مختلف ويحتاج تعديلًا | setB. لا ادعاء تطابق: دفترة تقفل الرقم التالي للقراءة وعندنا يُرفع للأعلى؛ سلوك التكرار اليدوي عندها `S-33` |
| SET-05 | الترقيم المتسلسل | شاشة لكل مستند، وحقل «الرقم التالي» داخل إعداد كل مستند | **شاشة «الترقيم» في الإعدادات (مجموعة المبيعات) بنموذج لكل نوع من تسعة** (تفعيل، بادئة، منازل، رقم تالٍ، منع تكرار العميل) بصلاحية `config` وسجل تدقيق (WP8) | مختلف ويحتاج تعديلًا | setA, setB. الأنواع الأخرى لدفترة (أوامر العمل، إذن المخزن، طلبات الشراء…) خارج التسعة |

### 9.3 `gap-report.md` (تحديث صفوف)

يُعدَّل `G-08` ويُحدَّث `G-34` و`G-51` بإضافة الحالة (الأوصاف الأصلية تبقى):

| المعرّف | التحديث |
|---|---|
| `G-08` | **الحالة (WP8، 2026-10-27):** نُفِّذت خدمة الترقيم (تسلسل لكل نوع ببادئة ومنازل ورقم تالٍ، حجز داخل المعاملة بقفل الصف، شاشة إعدادات بصلاحية `config`، عرض على المستندات والقائمة، اختبار ذهبي أن الافتراضات تحفظ كل رقم). **المتبقي (`S-33`، WP21):** تعديل الرقم التالي للأسفل، وتكرار الأرقام المكتوبة يدويًا، ومتغيرات البادئة، والتصفير السنوي، وتسلسل لكل فرع. ولا يخص `G-08` الترقيم المستقل للقيود اليدوية (000017) فهو قائم ولم يُمس |
| `G-34` | **الحالة (WP8):** صار للدفعة والإشعار الدائن **رقم مستقل** (تسلسلان اختياريان). **المتبقي:** شاشة «مدفوعات العملاء» وقائمة الإشعارات و«تحصيل بواسطة» (WP9) |
| `G-51` | **الحالة (WP8):** صار لدفعة المورد (وفاتورة الشراء والإشعار المدين) **رقم مستقل** (ثلاثة تسلسلات اختيارية). **المتبقي:** شاشة «مدفوعات الموردين» وقائمتها وحقل تاريخ الدفعة و«دُفع بواسطة» (WP10) |

### 9.4 `backlog.md` (حالات)

`WP8` تصير **`done (WP8، النصف الآمن)`** ويبقى معتمدًا على `S-33` (في WP21) **تعديلُ الرقم التالي للأسفل وتكرار الأرقام اليدوية ومتغيرات البادئة**؛ ويُسقط شرط `WP8` من اعتماد `WP9` و`WP10` و`WP12` و`WP13` و`WP14` و`WP26` و`WP27` و`WP35` و`WP39` فيما يخص وجود الخدمة (تبقى اعتمادات أخرى كما هي). ويُضاف بند جديد: **`WP8-S33`:** بعد رصد `S-33` تُنفَّذ المتبقيات أعلاه على الخدمة نفسها (`DocumentNumbering::configure` و`assign`) بلا تغيير مخطط الجدولين إلا لمتغيرات البادئة.

### 9.5 `docs/ui-redesign/deploy-checklist.md` (بند مقترح)

- [ ] **الترقيم لكل مستند (WP8):** الترحيل `20261210000000_DocumentSequences` **إضافي** (جدولا `document_sequences` و`document_numbers` فارغان ولا يمنح صلاحية ولا يمس مستندًا) ويُنفَّذ تلقائيًا عند أول دخول. **كل التسلسلات معطَّلة بعده فلا يتغير رقم ولا عرض.** بعد النشر: (1) افتح الإعدادات ← الترقيم وتأكد أن الأنواع التسعة معطَّلة. (2) **لا تفعّل تسلسلًا قبل مراجعة البادئة والرقم التالي المقترح** (يرتفع تلقائيًا فوق أي رقم موجود). (3) فعّل تسلسل دفعات العميل أو المصروف على بيانات تجريبية وأتمّ عملية، وتأكد من الرقم في النموذج والإيصال. (4) **تفعيل تسلسل الفاتورة يوقف تنسيق الفاتورة في الإعدادات للمستندات الجديدة** (الرقم المكتوب يبقى)، وتعطيله يعيده. (5) رفض تكرار رقم حساب العميل صار **في الخادم** (افتراضيًا)؛ أوقفه من الشاشة إن كانت لديك بيانات قديمة مكررة تحتاج إدخالها. (6) تعديل `check_account_number_exists`: صار يقول «موجود» حتى لو اشترك عدة عملاء في الرقم. **التراجع:** `down()` يحذف الجدولين وسجل الأرقام الممنوحة، فبعد تفعيل حقيقي يُفضَّل استعادة النسخة الاحتياطية. **ملاحظة اختبارات:** `NavigationHelperTest` يتوقع الآن 13 قسمًا للإعدادات (كان 12)، و`tests/Support/RaceWorker.php` يحمّل صفوف التطبيق من نسخة العمل التي يقع فيها. **(7) حقل رقم الفاتورة في سجل البيع:** مع تفعيل التسلسل صار يعرض الرقم التالي نصًّا إرشاديًا (يُترك فارغًا ليأخذ رقمه من التسلسل)، وما يكتبه الكاشير يُحفظ بحروفه (كان يُقطع إلى أرقام). **(8)** تحقق من إصدار قاعدة البيانات (MySQL 8 أو MariaDB ≥ 10.2.4) قبل تفعيل تسلسل الدفعات، وأوصِ ببادئة لتسلسل دفعات العميل. **(9)** `app/Views/configs/system_info.php` عُدّلت لتعمل أكثر من مرة في عملية واحدة (دالة مجهولة بدل دالة مسماة) دون تغيير ما يُعرض. تفاصيل في [تقرير WP8](../accounting/daftra-parity/wp8-report.md).
