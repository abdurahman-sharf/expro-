# تقرير WP14: دورة المسودة وعرض السعر (SAL-13 وSAL-21)

> الحالة (2026-10-31): **نُفِّذ على الفرع `wp/WP14`** بتفويض المالك التنفيذي الشامل (E47) ومن غير دمج في `master` ولا نشر ولا طلب سحب: **للفاتورة المسودة رقم مؤقت لا يستهلك تسلسل الفواتير ولا يُخزَّن**، وتُصدَر من **صفحة المستند بطلب `POST` ورمز الحماية** (لا رابط `GET`) فتأخذ **رقم الفاتورة التالي من خدمة الترقيم (WP8)** وتاريخها المكتوب وشروطها (WP13) داخل أقفال الفترات وتُرحَّل قيدا الإيراد والتكلفة كما هو اليوم؛ **ولعرض السعر ثماني حالات** (مسودة ومفتوح وتم الإرسال وتمت مشاهدته وتم الرد وتمت الموافقة وحُوِّل إلى فاتورة وتم الطلب) **تُخزَّن في عمود مستقل عن `sale_status`**، **تنتقل بجدول انتقالات يفرضه الخادم** ويُدوَّن كل تغيير في سجل التدقيق، **والتحويل إلى فاتورة يملأ نموذج الفاتورة في الكاشير ولا يحفظ ولا يرحِّل شيئًا**: يتحوّل عرض السعر في معاملة حفظ الفاتورة المصدَرة نفسها. **لا مسودة ولا عرض يرحّل قيدًا أو يحرّك مخزونًا. ونقطة البيع والبيع المعلّق القديم بلا تغيير** (عدا ما في القسم 4 البندين 14 و15). والعمل مع المحاسبة مفعّلة ومعطّلة.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الترحيل** `20271100000000_QuoteLifecycle` | يضيف إلى `sales` عمودين **يقبلان الفراغ بلا قيمة افتراضية**: `quote_status` (TINYINT) و`source_quote_id` (INT) وفهرسًا على الثاني، **ولا يقرأ ولا يكتب ولا يعيد حساب أي صف**: عرض سعر قائم بلا حالة يُقرأ «مفتوحًا»، وكل فاتورة وبيع معلّق يبقيان كما كانا. يُعاد تشغيله بأمان (يتحقق من كل عمود والفهرس)، و`down()` **يرفض** إن حمل عرضٌ حالة أو سمّت فاتورةٌ عرضها (لأنه المكان الوحيد لحفظها) فيُستعاد من النسخة الاحتياطية. **لا وحدة ولا صلاحية جديدة**. صُنِّف `HEAVY` في `MigrationChecks::SENSITIVE` (يغيّر جدولًا فيه صفوف أعمال) |
| **`QuoteStatus`** | تعداد الحالات الثماني بقيمها المرصودة (−1 و0 إلى 6) وجدول الانتقالات: **المسودة تُفتح فقط**؛ **المفتوح والمُرسَل والمشاهَد والمردود عليه** تنتقل بينها وإلى «تمت الموافقة»؛ **الموافَق عليه** يُعاد فتحه أو يُطلَب؛ **المطلوب** يعود موافقًا عليه؛ **المحوَّل نهائي**. لا انتقال إلى «مسودة» ولا إلى «محوَّل» باليد، ولا إلى الحالة نفسها. **ما يُضبط باليد ست حالات**، أما «تم الإرسال» و«تمت مشاهدته» و«تم الرد» فتبقى **يدوية** لأن الإرسال والمشاركة خارج النظام (E47). وقاعدة حفظ العرض مرة أخرى: **لا تتغير حالة عرض ليس مسودة**، والمسودة تُفتح بحفظها عرضًا |
| **`QuoteLifecycle`** (خدمة التغيير) | `change` يقفل صف العرض (`FOR UPDATE`) ويتحقق من الفرع (`BranchGuard`) ويفرض الجدول **في الخادم لا في الصفحة** ويكتب الحالة الجديدة ويدوّن `quote_status_changed` (من وإلى ومن غيّر وفرع العرض)، وفتح المسودة **يعطيها رقم تسلسل العروض من خدمة الترقيم** (إن كان التسلسل مفعّلًا وما لم تحمل رقمًا مكتوبًا)؛ `markConverted` يحوّل العرض **داخل معاملة حفظ الفاتورة** ويدوّن `quote_converted` (ورقم الفاتورة)؛ `assertUnlocked` يمنع حفظ العرض المحوَّل أو إلغاءه؛ `invoiceOf` يجد الفاتورة المصدَرة منه. **`recordChange` يكتب التدوين وحده، ويستعمله الكاشير أيضًا حين يحفظ مسودة عرض فيفتحها** (من «مسودة» إلى «مفتوح» مع الرقم الذي أخذته) فلا تفلت حركة من السجل. **التدوين لا يُكتب والمحاسبة معطّلة** (قاعدة سجل التدقيق القائمة) |
| **المسودة** (`DraftInvoice` و`DraftIssue`) | المسودة **بيع معلّق نوعه فاتورة** (`SUSPENDED` و`SALE_TYPE_INVOICE`)؛ **رقمها المؤقت `draft-<المعرّف>` يُحسب ولا يُخزَّن أبدًا** (فلا يمسّ `invoice_number` الفريد ولا سجل الأرقام ولا التسلسل). تُقرأ بالشكل الذي يحفظه النموذج مصدَرةً: أسطرها بسعرها المحفوظ **وتكلفة الصنف لحظة الإصدار** (كما يأخذها الكاشير عند استعادة المعلّق)، **وضرائبها تُحسب لحظة الإصدار بـ`Tax_lib` نفسه** (المسودة لا تخزّن ضرائب: «البيع المعلّق لا ضريبة له بعد» في `Sale::invoiceDiscountPlan`)، وحصص خصم الفاتورة، ودفعاتها، ومؤشر «دُفعت ببطاقة هدية أو نقاط مكافآت». و`figures` يعطي الإجمالي والضريبة والخصم للصفحة. **`Tax_lib::get_taxes` أخذ معاملين اختياريين (العميل والوضع) بلا تغيير لمن لا يمرّرهما**، وتُحسب الضرائب بمقياس الكاشير مهما كان مقياس المستدعي ثم يُعاد |
| **الإصدار** `Sale::issueDraft` | يحفظ المسودة **فاتورة مصدَرة** عبر `save_value` نفسه (فيمرّ كل ما يمرّ به الحفظ في الكاشير: قفل العميل والمخزون والترقيم والترحيل وقفل الفترات) بتاريخ المسودة المكتوب وشروطها ما لم يُكتب غيرهما (والتاريخ لا يكتبه إلا من يحمل `sales_invoice_date` أو من تحمل المسودة ذلك اليوم)، **ويدوّن `draft_issued`** (الرقم واليوم ومن أصدر). **`save_value` صار يقفل صف البيع المحفوظ ويرفض حفظ ما لم يعد معلّقًا، إتمامًا كان الحفظ أم تعليقًا** (`DraftNotFound`): فلا تُصدَر مسودة مرتين ولا يُستعاد بيع مكتمل **ولا يُرجَع بيعٌ صدر أو أُلغي إلى الانتظار من كاشير ما زال يحمله** (فتُمسح أسطره ودفعاته وقد رُحِّلت قيوده)، ولو بطلبين في اللحظة نفسها (اختبار سباق بعمليتين). **والإصدار يمرّر بصمة المسودة التي قرأها** (`DraftInvoice::fingerprint`: رأس المسودة وأسطرها ودفعاتها، تؤخذ قبل القراءة) **فيرفضه القفل (`DraftChanged`) إن حُفظت المسودة من جديد بين القراءة والقفل**، فلا يضيع تعديل ولا دفعة بطاقة هدية أُضيفت في تلك الفجوة؛ **ويطلب صلاحية خصم الفاتورة (`sales_invoice_discount`) حين تحمل المسودة خصمًا على كامل الفاتورة** كما يطلبها الكاشير عند الحفظ والتعليق والإتمام، فلا يُنسَب الخصم في التدقيق إلى من لا يملكه. **تُرفض بمسمّى** مسودة دُفعت ببطاقة هدية أو نقاط مكافآت (تُكمَل في الكاشير: البطاقة تُخصم عند الحفظ المعلّق فلا تُخصم ثانية) |
| **الصفحات والإجراءات** (`Sales`) | `getDocuments` (قائمة المسودات وعروض الأسعار بنطاق فروع الموظف وترشيح الحالة) و`getDocument` (صفحة المستند: رقمه والعميل والأسطر والإجماليات وما يجوز فعله) و`postIssue` و`postQuoteStatus` و`postConvertQuote`: **كلها تحت `reports_sales` ونطاق الفروع** (حارس واحد `documentRefusal`)، **والثلاثة التي تغيّر حالة `POST` وحده** (لا `getIssue` ولا رابط `GET`، ونماذج الصفحة تحمل رمز الحماية و`$.post` يضيفه) وتُجيب JSON برسالة السبب. في الصفحة **«فتح في الكاشير»** (يستعيد المستند كما يفعل استئناف المعلّق) و**«حذف المسودة»** (الإلغاء القائم: لا قيد لها فلا عكس) و**«أصدر الفاتورة»** مع حقل التاريخ لمن يملك صلاحيته وحقل الشروط للجميع **والمحاسبة مفعّلة**. والرقم بصيغة الإعدادات (`sales_invoice_format`) يُولَّد كما في الكاشير **حين يكون التسلسل معطّلًا** مع رفض تكرار الرقم |
| **تحويل عرض السعر** | `postConvertQuote` يستدعي `Sale_lib::prepareInvoiceFromQuote`: **يفرّغ الكاشير ويملؤه بفاتورة جديدة** (وضع الفاتورة، `sale_id` جديد، أسطر العرض وعميله وملاحظته وخصمه، **بلا رقم العرض**) ويحفظ **العرض المصدر في الجلسة**؛ **لا يُحفظ شيء ولا يُرحَّل**. **حفظ الفاتورة في الكاشير يمرّر العرض المصدر إلى `save_value`** فيصير العرض «محوَّلًا» **في المعاملة نفسها** وبعد الترحيل (فشل الترحيل أو تحويل عرض محوَّل أو مسودة أو عرض فرع لا يعمل فيه الموظف يُرجع الفاتورة كلها)؛ **فاتورة تُحفظ مسودة تتذكّر عرضها** (`source_quote_id`) **ولا يتحوّل العرض إلا بإصدارها**. **عرض محوَّل لا يُحفظ ولا يُلغى ولا يُستأنف في الكاشير**. التأكيد قبل التحويل **حين لم يوافق العميل بعد** (نص دفترة المرصود في S-07) |
| **الكاشير** | **«تعليق» في وضع العرض يحفظه مسودة** (لا يأخذ رقمًا من التسلسل)، و«إتمام» في وضع العرض يحفظه مفتوحًا بالرقم؛ **ولا يُنزَل عرض مفتوح إلى مسودة بتعليقه**. **تعليق الفاتورة المحوّلة يحفظ مصدرها**، وتغيير الوضع ينسى العرض المصدر. زر «المسودات وعروض الأسعار» لمن يملك `reports_sales`، **وقائمة المعلّقات في الكاشير و«استئناف» صارا بنطاق فروع الموظف** (فجوة قائمة: كان يستأنف من أي فرع)، وتُظهر القائمة رقم المسودة المؤقت لعرض مسودة |
| **ما لم يتغير** | القيود المرحَّلة لأي مستند قائم؛ ترحيل الفاتورة والتكلفة والدفعات؛ الترقيم؛ تعليق بيع نقطة البيع واستئنافه وإتمامه (`quote_status` فارغ لغير العروض). وعرض قديم بلا حالة يُقرأ «مفتوحًا» **بلا كتابة** حتى يُحفظ ثانية |
| **اللغة** | `Sales` (45 سطرًا) و`Accounting` (10: سبعة أخطاء مسمّاة وثلاثة إجراءات تدقيق، **وأُعيدت صياغة `error_draft_not_found` لتصلح للإصدار وللحفظ معًا**): الإنجليزية والعربية الحقيقية (`ar-EG` و`ar-LB`) والباقي فارغ فيرجع للإنجليزية؛ ورسائل الرفض تأخذ «من» و«إلى» (`AccountingMessage`) |

## 2. الأدلة

- **القاعدة المرصودة وما قابلها هنا:** مسودة بلا رقم تسلسلي ولا قيد ولا مخزون، وإصدارها يعطي الرقم التالي ويولّد قيدي الإيراد والتكلفة ويخصم الكمية (`S-06`، `T-07`)؛ عرض سعر «مفتوح» ثم «تمت الموافقة» ثم «حولت لفاتورة» بلا قيد ولا مخزون وبحوار تأكيد ثم نموذج فاتورة معبأ لا يُنشأ إلا بحفظه (`S-07`)؛ والحالات الثماني وكونها ليست حقلًا في النموذج بل تُضبط من إجراءات القائمة والعرض (`FRM-R11`)، وزر «حفظ كـ مسودة» في إضافة العرض لا في تعديله (`FRM-R12`). **أُضيفت إلى `tests/Support/Parity/T-07.json` أربع حالات «مطابق» (بنيوية) بأدلة `draft` و`draft_issued` و`quote` و`quote_conversion_dialog` و`quote_converted_invoice`** وتجري في `DocumentNumberingTest` و`SalePostingTest`: `draft-takes-no-sequence-number` و`issued-draft-takes-the-next-number` و`quote-states-and-no-posting` و`quote-converted-by-saving-the-invoice`. **ولا أقول إن شكل الرقم المؤقت `draft-11` أو ترتيب حالات الانتقال «مطابق لدفترة»**: ما رُصد مسودة بلا رقم تسلسلي وحالات العرض الثلاث (القسم 4).
- **ملفات اختبار جديدة (لكل صنف ملفه):** `QuoteStatusTest` (21)، `QuoteLifecycleTest` (32: الانتقالات كلها بالجدول وما يُرفض ورسالته والتدوين والفرع **وقفل الصف بين اتصالين وقراءة ما أنجزه طلب آخر لحظتها لا ما رآه snapshot المعاملة** وفتح المسودة بالرقم والتحويل والمعاملة والقفل بعد التحويل)، `DraftInvoiceTest` (18: الرقم المؤقت لا يُخزَّن، وقراءة الأسطر والتكلفة والضرائب لحظة الإصدار وعميل غير خاضع للضريبة وحصص الخصم ومشكلته والبطاقة والنقاط والإجماليات)، `SaleDocumentListTest` (13: القائمتان ونطاق الفروع والمسمّيات والمجموع الفرعي والترشيح)، `QuoteLifecycleMigrationTest` (7: الأعمدة تقبل الفراغ وإعادة التشغيل وإكمال ما توقف **وعرض قائم يبقى كما كان بلا حالة** ورفض `down()`).
- **اختبارات أُضيفت إلى ملفات قائمة (لكل صنف ملفه):** `SalePostingTest` (48 دالة و55 اختبارًا ببياناتها، من 246 إلى 301، «المسودة ثم الإصدار T-07 بتاريخ مكتوب وفترة مقفلة»: الإصدار يطابق قيود الكاشير وضرائبه، وتاريخ يُكتب عند الإصدار ويُبقى تاريخ المسودة، ويرفض يومًا في فترة مقفلة **وفترة الفرع وحده** ويوم المستقبل وقبل الدفاتر وغير المخوَّل والشروط، **ولا يُصدَر مرتين ولو بطلبين معًا (بعميل وبلا عميل: لا قفل عميل فصف المسودة هو الذي يمنع الثاني)**، ويرفض ما ليس مسودة وبطاقة الهدية ويتراجع كله حين يرفض الدفتر، **ويعمل والمحاسبة معطّلة**، **وتحويل العرض بحفظ الفاتورة**، ومسودة تتذكر عرضها، وعرض محوَّل لا يُحفظ ولا يُلغى ولا يُرمى، **والبيع المعلّق لنقطة البيع لا يتأثر**) و`DocumentNumberingTest` (5: **أرقام المسودات لا تحرّك التسلسل** وتتبع ترتيب الإصدار لا الحفظ، والرقم المكتوب باليد يبقى، ولا رقم يبقى بعد رفض الإصدار، ومسودة العرض بلا رقم حتى تُفتح) و`SalesControllerTest` (71 دالة و103 اختبارات ببياناتها، من 189 إلى 292: الصفحتان، **والإصدار بـ`POST` ورمز الحماية ولا يستجيب لـ`GET` ولا يتغير شيء به، والتحويل يملأ الكاشير ولا يحفظ، والانتقال غير المسموح يُرفض برسالته وتبقى الحالة، والصلاحية والفرع، والكاشير بين الطلبين، وتهريب الأسماء (`esc`)**) و`Sale_libTest` (5: العرض المصدر والتفريغ).
- **مراجعة الحالات الحدّية:** لا مسودة ولا عرض يكتب قيدًا (`countJournals() = 0` في كل اختبار حفظ مسودة أو عرض)؛ **كل ربط ثلاثي للعميل ينطبق بعد الإصدار** (`assertTies`)؛ **التأكد من أن الإصدار يطابق ما كان يفعله الكاشير بالمسودة نفسها** (`testIssueDraft_IssuesARegisterDraftLikeOneThatTheRegisterTookUpAndCompleted` و`…WorksTheTaxesOutAsTheRegisterDoesForTheSameLines`: جدول الضرائب وقيد الإيراد سطرًا بسطر).
- **الملفات التي شُغّلت وكل ما فيها ناجح:** **31 ملفًا، 3715 اختبارًا** (شُغّل كل ملف وحده على قاعدة `ospos_test_wp14` بعد آخر تعديل في الكود؛ ودالة من `SalesControllerTest` كان فيها خطأ في استدعاء الاختبار نفسه فأُصلحت وأُعيد تشغيلها، **وأُعيد تشغيل الملفات المعدَّلة كلها بعد آخر تعديل في الاختبارات** فنجحت): ملفات الحزمة الجديدة `QuoteStatusTest` (21) و`QuoteLifecycleTest` (32) و`DraftInvoiceTest` (18) و`SaleDocumentListTest` (13) و`QuoteLifecycleMigrationTest` (7)؛ والمعدَّلة `SalePostingTest` (301: كان 246) و`DocumentNumberingTest` (90) و`Sale_libTest` (48) و`SalesControllerTest` (292: كان 189)؛ **وما يلمسه ما غيّرته**: `DocumentTypeTest` (6) و`NumberingSequenceTest` (5) و`Sale_libPaymentTest` (12) و`Tax_libTest` (13: غُيّر `Tax_lib::get_taxes`) و`SaleTest` (52) و`SaleLoaderTest` (3) و`SalePostingBuilderTest` (66) و`AccountingMessageTest` (207: أُضيف «من» و«إلى») و`MigrationChecksTest` (17) و`ReadinessRunbookTest` (15) و`AccountingReadinessTest` (25) و`LanguageFilesTest` (1872) و`ParityFixtureTest` (391) و`ParityNotImplementedTest` (14) وترحيلات `sales` الأربعة السابقة `SalesAccountingMigrationTest` (5) و`SalesInvoiceDiscountMigrationTest` (5) و`RefundInvoiceMigrationTest` (5) و`InvoiceDatesAndPaymentTermsMigrationTest` (7) و`AccountingReceivablesControllerTest` (80) و`CustomerLedgerTest` (37) و`CreditSettlementTest` (22) و`CustomerDocumentsTest` (34).
- **لم تُشغَّل المجموعة الكاملة** (نحو ساعة، والمعالجات مشتركة مع وكلاء آخرين): يشغّلها المُدمِج بعد الدمج. **ولم تُشغَّل هذه الحزمة في متصفح** (القسم 5).

## 3. الحراس

كل حارس عُطِّل وحده بـ`tools/dev/guard_mutations.py` وقائمته في [wp14-guards.json](wp14-guards.json) (كشفه = فشل اختبار من الملفات المسمّاة، وبعضها بمرشّح لدوال الحزمة). **معادل حُذف من القائمة (ومعه السبب):** في `Sale::issueDraft` الفرع `if ($issue->problem !== null)` (يرجع `INVOICE_DISCOUNT_INVALID` قبل الحفظ): تعطيله لا يغيّر سلوكًا لأن `save_value` يحسب خطة الخصم نفسها فيرفضه بالرمز نفسه وبالمشكلة نفسها ويتراجع، فالفرع اختصار لا حارس (والاختبار `testIssueDraft_RejectsADiscountThatNoLongerFitsTheLinesAndLeavesTheDraftAsItWas` يثبّت النتيجة) ولذلك **غاب الرقم G67 من القائمة**. **وستة حراس لم يكشفها التشغيل الأول فقُوّيت لها الاختبارات حتى كُشفت:** G27 (قفل صف العرض: اختبار القفل بين اتصالين كان يقبل «مهلة القفل» التي يرميها `UPDATE` لاحقًا، فصار الاختبار أن ما أنجزه طلب آخر لحظتها هو ما يُقرأ لا ما رآه snapshot المعاملة)، وG36 وG46 (مرشّح الدوال كان مشتركًا مع `SalePostingTest` فأسقط دوال `DraftInvoiceTest` فلم تعمل، والمرشّح اليوم يضمّ دوال الملفين، **وأُعيدت بهذا كل حراس `DraftInvoice` وحارسا G60 وG69 فكُشفت**)، وG54 (قفل صف البيع: مع عميل يمنع قفلُ العميل السباق وحده، فأُضيف اختبار سباق بعمليتين على مسودة بلا عميل)، وG106 (تفريغ الكاشير قبل التحويل: أُضيف اختبار الرقم المكتوب وخيار البريد اللذين لا ينسخهما `copy_entire_sale`)، وG111 (رمز الحماية في نموذج الإصدار: صار الاختبار يستخرج النموذج نفسه ويطلب فيه الرمز بدل عدّ الرموز في الصفحة كلها).


**النتيجة النهائية:** `{"DETECTED": 114, "UNDETECTED": 0, "INVALID": 0}`.

أسماء الحراس بالإنجليزية كما في الملف `wp14-guards.json`؛ وكل صف عُطِّل فيه النص المذكور وحده وشُغّلت الملفات المسمّاة.

| الحارس | الملف الذي يحرسه | الاختبار الذي يكشفه | النتيجة |
|---|---|---|---|
| G01 a draft is only opened and goes nowhere else | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G02 an approved quote is reopened or ordered and is never sent again | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G03 an ordered quote goes back to approved only | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G04 a converted quote is final | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G05 a quote is not moved to the state it has | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G06 an invoice is not made from a draft quote | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G07 an invoice is not made twice from a quote | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G08 a converted quote is locked | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G09 saving a quote again never changes a state that is not draft | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G10 a draft is opened by being saved as a quote | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G11 a stored value that is no state reads as open | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G12 a state is set by hand only among the six | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G13 a quote is accepted when approved or ordered | `QuoteStatus.php` | `QuoteStatusTest` | كُشف |
| G14 the branch of the quote is checked before its state moves | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G15 the state a quote has is not a move | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G16 only the moves of the table are made | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G17 a draft that is opened takes the number of the sequence | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G18 a number typed on a draft is kept when it is opened | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G19 only the opening of a draft takes a number | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G20 the new state is stored | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G21 every change of state is written in the audit log | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G22 the audit log is written only while the books are on | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G23 the branch of the quote is checked before it is converted | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G24 a draft quote and a converted quote do not become an invoice | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G25 the quote is stored as converted | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G26 the conversion is written in the audit log | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G27 the quote row is locked while its state is read and changed | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G28 what is not a quote is refused | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G29 a quote that was cancelled or completed is not live | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G30 a sale that is not a quote is not a quote | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G31 a converted quote is not saved or cancelled | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G32 only a completed invoice is the invoice made from the quote | `QuoteLifecycle.php` | `QuoteLifecycleTest` و`SaleDocumentListTest` | كُشف |
| G33 the state is read of a live quote only | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G34 a sale that is not a quote may not be converted | `QuoteLifecycle.php` | `QuoteLifecycleTest` | كُشف |
| G35 the message of a refused move names the two states | `AccountingMessage.php` | `QuoteLifecycleTest` | كُشف |
| G36 only a suspended invoice is a draft | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G37 a suspended sale that is not an invoice is not a draft | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G38 the preview reads suspended documents only | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G39 the preview reads invoices and quotes only | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G40 a draft paid with a gift card is known | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G41 a draft paid with reward points is known | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G42 the cost of the item at the issue is taken | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G43 the taxes follow the customer of the draft | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G44 the taxes are worked out with the scale of the register | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G45 the scale of the caller is put back | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G46 a discount that no longer fits is kept as a problem | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G47 the shares of the discount go to the lines | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G48 no tax is worked out for a discount that does not fit | `DraftInvoice.php` | `DraftInvoiceTest` و`SalePostingTest` | كُشف |
| G49 a reader of no branch sees no document | `SaleDocumentList.php` | `SaleDocumentListTest` | كُشف |
| G50 the lists are of the branches of the reader | `SaleDocumentList.php` | `SaleDocumentListTest` | كُشف |
| G51 only suspended documents are listed | `SaleDocumentList.php` | `SaleDocumentListTest` | كُشف |
| G52 the drafts are the invoices | `SaleDocumentList.php` | `SaleDocumentListTest` | كُشف |
| G53 a quote that has no state is open when the list is narrowed to open | `SaleDocumentList.php` | `SaleDocumentListTest` | كُشف |
| G54 the stored sale is locked while it is saved again | `Sale.php` | `SalePostingTest` | كُشف |
| G55 a converted quote is not saved again | `Sale.php` | `SalePostingTest` | كُشف |
| G56 a sale that is not waiting is not completed again | `Sale.php` | `SalePostingTest` | كُشف |
| G57 the state of a quote that is saved again follows the rules of the states | `Sale.php` | `SalePostingTest` | كُشف |
| G58 a sale that is not a quote carries no state of a quote | `Sale.php` | `SalePostingTest` | كُشف |
| G59 the quote an invoice was made from is stored with it | `Sale.php` | `SalePostingTest` | كُشف |
| G60 a draft quote takes no number of the sequence | `Sale.php` | `SalePostingTest` و`DocumentNumberingTest` | كُشف |
| G61 the quote is converted with the invoice | `Sale.php` | `SalePostingTest` | كُشف |
| G62 the quote of a draft is remembered until the draft is issued | `Sale.php` | `SalePostingTest` | كُشف |
| G63 an invoice whose quote cannot be converted is undone | `Sale.php` | `SalePostingTest` | كُشف |
| G64 the issue of a draft is written in the audit log | `Sale.php` | `SalePostingTest` | كُشف |
| G65 only an invoice that was suspended is a draft that is issued | `Sale.php` | `SalePostingTest` | كُشف |
| G66 a draft paid with a gift card or reward points is not issued here | `Sale.php` | `SalePostingTest` | كُشف |
| G68 the customer of the draft is kept | `Sale.php` | `SalePostingTest` | كُشف |
| G69 the number typed on the draft is kept | `Sale.php` | `SalePostingTest` و`DocumentNumberingTest` | كُشف |
| G70 the payments of the draft are saved with the invoice | `Sale.php` | `SalePostingTest` | كُشف |
| G71 the delivery of the draft is kept | `Sale.php` | `SalePostingTest` | كُشف |
| G72 the day of the draft is kept when none is given | `Sale.php` | `SalePostingTest` | كُشف |
| G73 the terms of the draft are kept when none are given | `Sale.php` | `SalePostingTest` | كُشف |
| G74 a converted quote is not cancelled | `Sale.php` | `SalePostingTest` | كُشف |
| G75 a converted quote is not discarded | `Sale.php` | `SalePostingTest` | كُشف |
| G76 the books write the audit of an issue only while they are on | `Sale.php` | `SalePostingTest` | كُشف |
| G77 only an employee who opens sales documents lists them | `Sales.php` | `SalesControllerTest` | كُشف |
| G78 the lists are of the branches of the employee | `Sales.php` | `SalesControllerTest` | كُشف |
| G79 the page of a document is for an employee who opens sales documents | `Sales.php` | `SalesControllerTest` | كُشف |
| G80 the page of a document hides the documents of other branches | `Sales.php` | `SalesControllerTest` | كُشف |
| G81 the page of a document is for a draft or a quote only | `Sales.php` | `SalesControllerTest` | كُشف |
| G82 the grant is asked by every action on a document | `Sales.php` | `SalesControllerTest` | كُشف |
| G83 the branch is checked by every action on a document | `Sales.php` | `SalesControllerTest` | كُشف |
| G84 the issue asks the shared guard | `Sales.php` | `SalesControllerTest` | كُشف |
| G85 the change of status asks the shared guard | `Sales.php` | `SalesControllerTest` | كُشف |
| G86 the conversion asks the shared guard | `Sales.php` | `SalesControllerTest` | كُشف |
| G87 a list sent in place of the day is no day | `Sales.php` | `SalesControllerTest` | كُشف |
| G88 a number of the format that another invoice has is refused | `Sales.php` | `SalesControllerTest` | كُشف |
| G89 a value that is no status is refused | `Sales.php` | `SalesControllerTest` | كُشف |
| G90 a quote is not converted while invoices are off | `Sales.php` | `SalesControllerTest` | كُشف |
| G91 a sale that is no quote is not converted | `Sales.php` | `SalesControllerTest` | كُشف |
| G92 a draft quote and a converted one are not converted | `Sales.php` | `SalesControllerTest` | كُشف |
| G93 the register forgets the quote when the mode changes | `Sales.php` | `SalesControllerTest` | كُشف |
| G94 the invoice saved at the register names its quote | `Sales.php` | `SalesControllerTest` | كُشف |
| G95 a quote suspended at the register is a draft | `Sales.php` | `SalesControllerTest` | كُشف |
| G96 an invoice suspended at the register keeps its quote | `Sales.php` | `SalesControllerTest` | كُشف |
| G97 a suspended sale of another branch is not taken up | `Sales.php` | `SalesControllerTest` | كُشف |
| G98 a converted quote is not taken up at the register | `Sales.php` | `SalesControllerTest` | كُشف |
| G99 the list of suspended sales is of the branches of the employee | `Sales.php` | `SalesControllerTest` | كُشف |
| G100 a refusal of a branch is said in words | `Sales.php` | `SalesControllerTest` | كُشف |
| G101 the register forgets the quote after a sale | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G102 a draft brings back the quote it was made from | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G103 the invoice made from a quote is a new sale | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G104 the invoice made from a quote names it | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G105 the invoice made from a quote does not carry its number | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G106 the register is emptied before a quote is converted | `Sale_lib.php` | `Sale_libTest` | كُشف |
| G107 the migration is safe to run again | `20271100000000_QuoteLifecycle.php` | `QuoteLifecycleMigrationTest` | كُشف |
| G108 the migration cannot be taken back once a quote carries a state | `20271100000000_QuoteLifecycle.php` | `QuoteLifecycleMigrationTest` | كُشف |
| G109 the comment of a document is escaped | `document.php` | `SalesControllerTest` | كُشف |
| G110 the names of the lists are escaped | `documents.php` | `SalesControllerTest` | كُشف |
| G111 the issue form carries the token | `document.php` | `SalesControllerTest` | كُشف |
| G112 the day is offered to an employee who may type it only | `document.php` | `SalesControllerTest` | كُشف |
| G113 a quote is converted only while invoices are on | `document.php` | `SalesControllerTest` | كُشف |
| G114 a converted quote offers no way to change it | `document.php` | `SalesControllerTest` | كُشف |
| G115 a draft quote is known by its temporary number in the list of the register | `Sale.php` | `SalesControllerTest` | كُشف |


## 4. ما افترضتُه

1. **المسودة بيع معلّق نوعه فاتورة (`SUSPENDED` و`SALE_TYPE_INVOICE`)**: هذا ما يحفظه زر «تعليق» في وضع الفاتورة اليوم، ولا نوع جديد ولا عمود حالة للمسودة. **رقمها المؤقت `draft-<المعرّف>`:** دفترة تعرض `draft-11` ولم يُرصد هل 11 معرّف المستند أم عدّاد مسودات، فاخترت المعرّف لأنه فريد وثابت ولا يحتاج عدّادًا ولا يُخزَّن. (بند رصد دفترة.)
2. **تُحسب ضرائب المسودة لحظة الإصدار** بمعدلات الأصناف الحالية وإعدادات الضريبة والعميل، **ويُؤخذ سعر السطر المحفوظ وتكلفة الصنف لحظته**: هذا ما يفعله الكاشير حين يستعيد المعلّق ويتمّه (`copy_entire_sale` ثم الإتمام)، والمسودة لا تخزّن ضرائب (صراحة في `Sale::invoiceDiscountPlan`). فالإجمالي المعروض في الصفحة **تقدير** يُنبَّه عليه (`document_figures_note`) وقد يتغير حتى الإصدار.
3. **صلاحية الإصدار وتغيير الحالة والتحويل وفتح الصفحتين هي `reports_sales`** (التي يشترطها تعديل البيع واستئناف المعلّق)، **والتاريخ المكتوب `sales_invoice_date` كما في WP13**، **والحذف `sales_delete` كما هو**. **لم أُضف صلاحية جديدة** (بند قرار للمالك: صلاحية مستقلة للإصدار؟).
4. **مسودة العرض بلا رقم تسلسل حتى تُفتح** (تعليق العرض في الكاشير يحفظه مسودة): لم يُرصد في دفترة هل تستهلك مسودة العرض رقمًا (رُصد للفاتورة فقط، `S-06`)، فنسخت قاعدة الفاتورة. **هذا تغيير على «تعليق» في وضع العرض** (كان يأخذ رقمًا من التسلسل المفعَّل): العروض المعلّقة قبله تُقرأ «مفتوحة» ولا تتغير.
5. **جدول انتقالات الحالات افتراضي بعد المرصود وحده** (مفتوح ثم موافَق ثم محوَّل، وأن الحالة تُضبط من القائمة والعرض لا من النموذج): ما عداه (المسودة تُفتح فقط، والمفتوح والمُرسَل والمشاهَد والمردود عليه تتبادل، وأن «تم الطلب» لا يُبلَغ إلا من الموافقة وأنه يعود إليها، وأن الموافق عليه يُعاد فتحه) **اجتهاد محافظ**. لم يُرصد ما يضبط الحالات 1 و2 و3 و6 في دفترة (`OBS-D`)، فهي يدوية هنا.
6. **يتحوّل العرض عند إصدار الفاتورة المصنوعة منه (حفظها مصدَرة)** لا عند الضغط على «تحويل» ولا عند حفظها مسودة: رُصدت الحالة النهائية «حولت لفاتورة» بعد حفظ فاتورة التحويل فقط. **وعرض محوَّل لا يُحفظ ولا يُلغى ولا يُستأنف** (افتراض: هو سجل الفاتورة).
7. **يجوز تحويل كل عرض ليس مسودة ولا محوَّلًا** ولو لم تتم الموافقة (حوار دفترة «لم يقبل العميل عرض السعر حتى الآن…» يسبق التحويل ولا يمنعه) **وأسأل التأكيد عند عدم الموافقة**؛ وفي دفترة قد يُسأل في كل حالة غير «تمت الموافقة»، لم يُرصد غير الافتتاح.
8. **تُنسخ ملاحظة العرض إلى الفاتورة** (دفترة: `copy_notes` غير مفعّل عندهم) وأسطره بأسعارها وخصمه وعميله؛ **ولا يُنسخ تاريخ ولا شروط** (للعرض «صالح لمدة» وليس «شروط الدفع»: لم يُبنَ).
9. **التدوين في سجل التدقيق لا يُكتب والمحاسبة معطّلة** (قاعدة الإجراءات التي لا تمسّ إلا حين تكون الدفاتر قائمة)؛ تغيّر الحالة يبقى مخزَّنًا.
10. **مسودة دُفعت ببطاقة هدية أو نقاط مكافآت تُرفض في الصفحة وتُكمَل في الكاشير**: البطاقة والنقاط تُخصم عند حفظ المعلّق (`Sale::saveValueLocked`)، وإتمامه يعيد الخصم (عيب قديم لم أغيّره، القسم 8).
11. **الرقم بصيغة الإعدادات (`sales_invoice_format`)** حين يكون تسلسل الفواتير معطّلًا يُولَّد في المتحكم بـ`Token_lib` كما في الكاشير، **فرمز `{CU}` فيه (اسم العميل) يقرأ عميل الكاشير لا عميل المسودة**؛ الصيغة الافتراضية (`$CO`) لا تحمله.
12. **الإجمالي في قائمة المسودات والعروض بلا ضريبة** (المجموع الفرعي بعد الخصومات)، **والإجمالي في صفحة المسودة بضريبتها لحظة العرض** (الافتراض 2).
13. **بصمة المسودة عند الإصدار (أُغلق بها ما كان حدًّا هنا):** يُرفض إصدار مسودة حُفظت من جديد بين قراءة الإصدار وقفله (`DraftChanged`) **ولو كان ما تغيّر لا يمسّ المبلغ**، فيُعيد الموظف الفتح والإصدار؛ **والصفحة نفسها تعرض ما قرأته لحظة فتحها** (فالإصدار بعد دقائق قد يُرفض لتغيّر المسودة، وهو المقصود). **والبصمة بلا وقت الحفظ ولا معرّف الدفعة**: حفظ لا يغيّر شيئًا لا يُرفض به الإصدار.
14. **«استئناف المعلّق» و«قائمة المعلّقات» صارا بنطاق فروع الموظف** (كانا بلا فحص فرع: يرى موظف فرع معلّقات فرع آخر ويستأنفها). **تغيير على سلوك قائم لإغلاق ثغرة**، ومن يحمل «كل الفروع» لا يتغير عليه شيء.
15. **«تعليق» في وضع العرض يحفظ مسودة** (البند 4)، **و«إتمام» في وضع العرض يحفظ «مفتوحًا»**؛ وعرض مفتوح يُستأنف ثم يُعلَّق لا يعود مسودة.

## 5. ما بقي

- **فحص المتصفح** (محور الوصول axe، والفيض الأفقي، والهاتف، والعربية بالاتجاه من اليمين، وحوار التأكيد) **لم يُجرَ**: لا قاعدة `ospos_ui` لهذا الوكيل. الصفحتان تستعملان فئات الواجهة نفسها لشاشات الذمم، **وما اختُبر هو الـHTML والـJSON والنماذج ورمز الحماية لا الشكل**.
- **«نسخ» المسودة و«إضافة دفعة» من صفحتها** (إجراءا دفترة في `S-06`): لم يُبنيا؛ **و«صالح لمدة» (due_after) في العرض**، **وإخفاء تاريخ الإصدار**، وقالب العرض وما يخص `SAL-21` من حقول النموذج.
- **تبويبا «مستحقة الدفع» و«متأخر» في قائمة الفواتير ومرشح «متأخر» في تقارير الإيراد** (`WP13-INVOICE-LIST-DUE-TABS`)، **وأرقام أوامر العمل** (`WP8-WORK-ORDERS`)، **وسلة المسودات والحذف النهائي** (`G-148`: المسودة تُحذف بالإلغاء القائم ولا سلة): خارج ما طُلب في هذه الحزمة (إدخالها في الجرد تحت WP14 كان للتتبع).
- **رصد ما يضبط حالات 1 و2 و3 و6** وانتقالات القائمة والعرض في دفترة (`OBS-D`)، وما يحدث عند حذف عرض محوَّل أو تعديل فاتورة صدرت من مسودة.
- **ترقيم صفحات** قائمتي المسودات والعروض (الإشعار عند القطع عند 200 قائم، والترقيم غير مبني: `WP14-LIST-PAGING`).
- **استنفاد الإصدار من الصفحة لمسودات الدفع ببطاقة هدية أو نقاط** بدل رفضها، وهو مرهون بإصلاح الخصم المزدوج القديم (القسم 8).
- **رفض الكاشير لحالة `INSUFFICIENT_STOCK`**: يرجعها `save_value` ولا يعالجها `postComplete` (عيب قديم، القسم 8)؛ صفحة المسودة تعالجها (`Sales.item_out_of_stock`).
- **المجموعة الكاملة لم تُشغَّل** (نحو ساعة، والمعالجات مشتركة مع وكلاء آخرين): يشغّلها المُدمِج بعد الدمج.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرار المالك**
1. هل تُنشأ **صلاحية مستقلة** لإصدار المسودة وتغيير حالة العرض وتحويله بدل `reports_sales` (الافتراض)؟ (ترحيل صلاحية بلا منح كما في WP13.)
2. هل تأخذ **مسودة العرض رقمًا من التسلسل عند حفظها** كما كان تعليق العرض يفعل، أم عند فتحها (الافتراض)؟
3. هل يبقى **العرض المحوَّل مقفلًا** لا يُحفظ ولا يُلغى ولا يُستأنف (الافتراض)؟ وهل يُسمح بحذف الفاتورة المصدَرة منه (لا تُحذف الفواتير الصادرة: E21)؟
4. هل **تُصلَح مسودات الدفع ببطاقة الهدية ونقاط المكافآت** (الخصم المزدوج بين التعليق والإتمام) بدل رفضها في الصفحة؟ الإصلاح يعكس الخصم عند إعادة الحفظ ويمسّ مسار الكاشير القائم.
5. هل تُبنى **سلة لمسودات البيع** كسلة المشتريات (`G-133`، `G-148`)؟
6. هل يُعطَّل **التعليق في وضع العرض** إن أراد المالك أن يبقى حفظ العرض مفتوحًا بالرقم كما كان؟ (التغيير في البند 4 من القسم 4.)

**رصد دفترة (قراءة بكتابة محكومة عند توفر بيانات الدخول، وتنتهي الباقة المجانية في 04/11/2026)**
1. **هل يُحسب رقم المسودة المؤقت من المعرّف أم من عدّاد؟** وهل تستهلك مسودة **العرض** رقمًا؟
2. **ما الإجراءات التي تضبط حالات «تم الإرسال» و«تمت مشاهدته» و«تم الرد» و«تم الطلب»** وما الانتقالات التي تمنعها الواجهة (قائمة العروض وصفحة العرض)؟
3. **متى تتحوّل حالة العرض إلى «حولت لفاتورة»:** عند الضغط على «تحويل» أم عند حفظ الفاتورة (أمسودة كانت أم مصدَرة)؟ وهل يمكن تحويل عرض واحد مرتين؟
4. **ماذا يظهر عند تعديل عرض مضى على تحويله أو حذف الفاتورة المحوَّل إليها؟**
5. هل **تاريخ القيد** لمسودة بلا تاريخ مكتوب هو يوم حفظها أم يوم إصدارها؟ (الافتراض: يوم الإصدار، كما يفعل الكاشير بالمعلّق).

## 7. قبل النشر

**ترحيل واحد إضافي:** `20271100000000_QuoteLifecycle` يغيّر `sales` (عمودان وفهرس) فيُصنَّف **ثقيلًا (`HEAVY`)** في أمر الجاهزية ويُشغَّل في ساعة هادئة بعد نسخة احتياطية؛ **لا يلمس صفًا ولا يعيد حسابًا**، و`down()` يرفض بعد أن تحمل أي صف حالة (يُستعاد من النسخة). **لا صلاحية ولا وحدة جديدة، ولا يُمنح شيء لأحد.** بعد التشغيل: **العروض المعلّقة القائمة تُقرأ «مفتوحة»، والفواتير المعلّقة القائمة تظهر في صفحة «المسودات وعروض الأسعار» بأرقام مؤقتة وتُصدَر من صفحتها، والبيع المعلّق لنقطة البيع لا يظهر فيها ولا يتغير.** **سلوك يتغير بعد النشر:** (أ) «تعليق» في وضع العرض يحفظه مسودة بلا رقم تسلسل؛ (ب) «استئناف» المعلّق وقائمته بنطاق فروع الموظف؛ (ج) **لا يُحفظ بيع لم يعد معلّقًا، إتمامًا كان الحفظ أم تعليقًا:** كاشير يحمل مسودة صدرت أو أُلغيت من مكان آخر يُرفض حفظه برسالة «لم يعد هذا البيع ينتظر…» ويفرّغ الكاشير ويعيد البدء؛ **ومسودة تغيّرت بين قراءة الإصدار وقفله تُرفض (`DraftChanged`) ويُعاد فتحها؛ ومسودة تحمل خصمًا على كامل الفاتورة لا يصدرها من لا يملك `sales_invoice_discount`**؛ (د) لا يُحفظ عرض محوَّل ولا يُلغى. **قبل الاعتماد على الإصدار من الصفحة** يُجرَّب على مسودة تجريبية: الضريبة والإجمالي في الصفحة ثم القيدان بعد الإصدار، **وإن كان تسلسل الفواتير مفعّلًا فالرقم يتبع ترتيب الإصدار لا الحفظ**. وتشغيل `php spark accounting:readiness` يطبع الترحيل ضمن «ثقيلة».

## 8. ما وجدتُه في الكود ولم أغيّره

1. **الخصم المزدوج لبطاقة الهدية ونقاط المكافآت بين التعليق والإتمام:** `Sale::saveValueLocked` يخصم قيمة البطاقة ويصرف النقاط **كلما حُفظ بيع فيه دفعة منهما، ولو معلّقًا**، و`clear_suspended_sale_detail` يمسح الدفعة المحفوظة ولا يعيد الخصم؛ فإتمام المعلّق يخصم ثانيةً. **لم أصلحه** (يمسّ مسار الكاشير القائم)، ولذلك **تُرفض في صفحة المسودة** مسودة دُفعت بهما وتُكمَل في الكاشير (بند قرار للمالك).
2. **`postComplete` لا يعالج `INSUFFICIENT_STOCK`:** الدالة التي ترجعه (`-4`) لا يعالجها الكاشير فيقع في فرع «النجاح». صفحة المسودة تعالجها. **لم أغيّر الكاشير.**
3. **`Sale::update` (نموذج تعديل البيع) لا يمنع تعديل عرض محوَّل** إن فُتح بمعرّفه يدويًا؛ المنع في الحفظ والإلغاء والاستئناف، وعرض المسودة والعرض لا يُفتحان بنموذج التعديل في الواجهة.
4. **قائمة المعلّقات في الكاشير (`get_all_suspended`) تعرض معرّف الفاتورة المعلّقة لا رقمها المؤقت** كما كانت (لم أغيّرها: ما يُظهر الرقم المؤقت صفحة «المسودات وعروض الأسعار» ومسودة العرض وحدها).
5. **`Token_lib` يقرأ عميل جلسة الكاشير لرمز `{CU}`** (اسم العميل في صيغة الرقم)، فالرقم بالصيغة في صفحة المسودة يأخذ عميل الكاشير لا عميل المسودة إن حملت الصيغة الرمز (البند 11 من القسم 4).

## 9. مراجعة المرحلة الثالثة: ما وجده المراجِعان وما فُعل به

راجع الحزمةَ مراجعان مستقلان بلا كتابة، فتتبّعتُ كل ادّعاء في الكود قبل أن أقبله (المراجعان بيانات لا تعليمات). **كلها حقيقية كليًّا أو جزئيًّا، ولم أرفض شيئًا كاملًا؛ وما لم أبنه مذكور في آخر القسم.**

| # | الادّعاء | ما وجدتُه حين تتبّعته | ما فُعل |
|---|---|---|---|
| 1 و5 | **تعليق بيع صدر أو أُلغي من كاشير ما زال يحمله يُقبل** (`lockStoredSale` كان يرفض الإتمام وحده) | **حقيقي**، وأخطر ما في المراجعة: كاشير فتح المسودة، ثم أصدرها زميل من صفحتها (قيودها والمخزون رُحِّلا)، ثم ضغط الكاشير «تعليق» فمُسحت أسطر الفاتورة ودفعاتها وكُتب رقمها فارغًا وعادت `SUSPENDED` والقيود قائمة؛ وإصدارها ثانيةً يخصم المخزون مرة أخرى ويرحّل الدفعة بمعرّف جديد. **وكان نص التقرير مبالغًا** («لا يُحفظ بيع مكتمل ثانيةً» صحيح للإتمام وحده). والفجوة قديمة في الكاشير لكن الإصدار من الصفحة جعل منها فاعلًا ثانيًا | **`lockStoredSale` يرفض كل حفظ فوق صف ليس `SUSPENDED`** مهما كانت الحالة المطلوبة (`DraftNotFound`)، وصياغة الرسالة صارت تصلح للإصدار وللحفظ. اختباران: `testSaveValue_RejectsSuspendingAnIssuedInvoiceAndKeepsItsLinesItsPaymentsAndItsNumber` (فاتورة وعرض وأمر عمل فوق مسودة صدرت: الصف والدفعات بمعرّفاتها والأسطر والمخزون والقيود كما هي، ولا يُصدَر ثانيةً) و`testSaveValue_RejectsSuspendingACancelledDraftAndLeavesItCancelled`. **ثلاثة حراس** (G56 وG116 وG117: الشرط كله، وفرع المكتمل وحده، وفرع الملغى وحده). مسارات الاستعادة (`update_sale_status`) لا تمرّ بـ`save_value` فلا تتأثر |
| 2 | **إصدار مسودة من الصفحة يتخطى صلاحية خصم الفاتورة ويُنسب الخصم لمن أصدر** | **حقيقي**: الكاشير يتحقق منها في ثلاثة مواضع و`postIssue` لا يتحقق | **`postIssue` يطلب `sales_invoice_discount` إذا حملت المسودة خصمًا** (`DraftInvoice::carriesInvoiceDiscount`) ويرد 403 برسالة `not_authorized`. ثلاثة اختبارات: يُرفض بلا صلاحية ولا يُرحَّل شيء ولا يُدوَّن خصم، ويُصدَر لمن يملكها ويُنسب إليه، **ويُصدَر مسودة بلا خصم لمن لا يملكها**. ثلاثة حراس (G127 إلى G129) |
| 3 | **المسودة تُقرأ خارج القفل فيضيع تعديل أو دفعة بطاقة هدية أُضيفت في الفجوة** | **حقيقي بنافذة ضيقة**: `forIssue` يقرأ قبل قفل العميل وقفل الصف و`clear_suspended_sale_detail` يعيد إدراج ما قُرئ؛ فبطاقة هدية خُصمت في الفجوة تبقى مخصومة بلا دفعة تسجّلها (والفاتورة كاملة على العميل). **وكنت قد سجّلتُه حدًّا (البند 13 من القسم 4) ولم أنتبه لأثر القيمة المخزَّنة** | **بصمة للمسودة** (`DraftInvoice::fingerprint`: رأسها وأسطرها ودفعاتها بلا معرّف الدفعة ولا وقت الحفظ) **تؤخذ قبل أي قراءة** (فالتغيّر بعدها يُرفض والخطأ لا يكون إلا في اتجاه الأمان)، يمرّرها `issueDraft` إلى `save_value` فيقارنها `lockStoredSale` **بعد قفل الصف** فيرفض بـ`DraftChanged` ورسالة مسمّاة. اختبار يعيد حفظ المسودة **بين القراءة والقفل فعلًا** (يستمع إلى `DBQuery` ويحفظها بعد أخذ قفل العميل مباشرة) فيرفض الإصدار وتبقى الدفعة المحفوظة ثم يُصدَر بما فيها؛ واختبار للبصمة نفسها: تتغير مع كل حقل من الرأس والأسطر والدفعات ولا تتغير بحفظ لا يغيّر شيئًا. ستة حراس (G118 وG119 وG130 إلى G133) |
| 4 | **حفظ مسودة عرض في الكاشير (من مسودة إلى مفتوح) لا يكتب `quote_status_changed`** | **حقيقي**: التدوين كان في `QuoteLifecycle` وحده والكاشير يغيّر الحالة بـ`QuoteStatus::afterSave` | **`QuoteLifecycle::recordChange`** عام يستعمله `change` والكاشير معًا (من وإلى والرقم الذي أخذه عند فتح المسودة)، فلا يُكتب لعرض جديد ولا لحفظ في الحالة نفسها ولا والمحاسبة معطّلة. ثلاثة اختبارات وخمسة حراس (G120 إلى G123 وG21 بعد نقله) |
| 6 | **قائمة المعلّقات بالعميل لا اختبار لها ولا حارس** | **حقيقي**: الاستعلامان كانا نسختين، وكل اختبار يمرّ بالأول (بلا عميل) | **`Sale::get_all_suspended` يشترك استعلاماه في تعبير رقم المستند** (نسخة واحدة)، واختبار بعميل الكاشير يتحقق من الفرع والعميل ومسمّى مسودة العرض `draft-<id>`، وثلاثة حراس على الاستعلام الثاني وحده (G124 إلى G126) |
| 7 | **`draft_issued` يُكتب أيضًا لعرض أو بيع نقطة بيع أُتمّ فاتورةً في الكاشير** | **حقيقي**: الشرط لا يفحص نوع الصف المخزَّن | **الشرط يشترط أن يكون الصف المخزَّن فاتورة**؛ اختبار: عرض وبيع نقطة بيع يُتمّان فاتورة لا يكتبان `draft_issued` ومسودة فاتورة يُتمّها الكاشير تكتبه. حارس G65 |
| 8 | **القائمتان تقطعان عند 200 بلا إشعار** | **حقيقي**، وللعروض المحوَّلة (تبقى `SUSPENDED` دائمًا) أثر في دفع القديمة المفتوحة | **إشعار أسفل كل قائمة قطعت عند الحد** (`SaleDocumentList::LIMIT` عام، والمفتاحان `documents_drafts_limited` و`documents_quotes_limited` بالعربية الحقيقية) يدلّ على مكان الأقدم (المعلّقات في الكاشير، وترشيح الحالة في العروض). **لم أبنِ ترقيم صفحات** (بند مفتوح). أربعة اختبارات حدّية (200 و201 و199) وخمسة حراس (G138 إلى G142) |
| 9 | **معاملا `Tax_lib::get_taxes` الجديدان بلا اختبار في ملف الصنف و`registerMode` بلا حارس** | **حقيقي** | أربعة اختبارات في `Tax_libTest` (العميل المعطى لا عميل الكاشير، والكود بوضع الفاتورة والكود الافتراضي بوضع البيع، وقيمة الكاشير عند عدم التمرير) **واختبار في `DraftInvoiceTest` بضريبة بحسب الوجهة وكودين مختلفين** (10% للعميل و20% افتراضي: مسودة تُصدَر بكود العميل). أربعة حراس (G134 إلى G137) |
| 10 | **اختبارات «التهريب» و«الرقم في التدقيق» أقل مما تسمّي** | **حقيقي**: التعليق وحده واسم عميل المسودات | اختبار يهرّب **اسم الصنف ووصفه والعميل والموظف والفرع ورقم المستند** في صفحة المستند، **وكل أعمدة جدول العروض** وأرقام المسودات في القائمتين، **واختبار الرقم في تدقيق الإصدار والتسلسل مفعّل** (`INV-0001`). **أربعة عشر حارسًا للتهريب** (G146 إلى G159) |
| 11 | **رقم الفاتورة المحوَّل إليها يُطبع بلا `esc`** | **حقيقي بنافذة ضيقة** (صيغة الرقم تحمل `{CU}` واسم عميل فيه وسوم، والتسلسل معطّل) | **`esc()` على الرقم** في `document.php`؛ اختبار وحارس |
| 12 | **أزرار الصفحة لا تعالج رفضًا بحالة غير 200 ولا تُقفل أثناء الطلب** | **حقيقي**: `documentRefusal` يرد 403 بـJSON و`$.post` بلا `fail` لا يستدعي رسالته | **دالة واحدة `post` تقفل الأزرار أثناء الطلب وتفتحها عند الرد، ويعالج `.fail` الرسالة القادمة من الخادم (`responseJSON.message`) أو رسالة عامة** (`Sales.document_request_failed`). اختبار على نص الصفحة (لا متصفح) وثلاثة حراس (G160 إلى G162) |
| 13 | **رأس عمود فارغ وروابط «فتح» متطابقة** | **حقيقي** | **رأس مخفي بصريًّا `visually-hidden` («الإجراءات») ورابط بـ`aria-label` فيه رقم المستند**؛ اختبار وثلاثة حراس (G143 إلى G145). **لم يُجرَ فحص axe أو متصفح** (القسم 5) |
| 14 | **اختبارات «POST ورمز الحماية» نصية** | **حقيقي جزئيًّا**: بيئة الاختبار تُسقط مرشّح الحماية عمدًا (`Config\Filters`) فلا يُمكن اختبار طلب بلا رمز عبر `FeatureTestTrait` | **`tests/Config/FiltersTest.php` (جديد): يستعيد إعداد الإنتاج ويسأل الإطار نفسه** (`Filters::initialize`) عن مرشّحات كل مسار `POST` في المبيعات فيجد `csrf` قبله، **ويُجري المرشّح الحقيقي على طلب بلا رمز فيُرمى `SecurityException`**؛ **واختبارات `GET` صارت تفرض 404 أو `PageNotFoundException`** (كانت تبتلعها) **وحُذف اختبار قراءة `Filters.php` نصًّا**. حارسان على الإعداد وثلاثة على تسمية الدوال (G163 إلى G167) |
| 15 | **أسماء اختبارات لا تتبع `test{Method}_{Behavior}` بفعل** | **حقيقي لما أضفتُه**: 44 دالة `Refuses` (يقرّ الدليل `Rejects`) وأخرى تبدأ بـ«Never» و«Then» و«Two» و«A…» أو تسمّي ما ليس دالة الصنف | **73 دالة مُعاد تسميتها** (مفاتيح المرشّحات في `wp14-guards.json` عُدّلت معها)؛ **اختبارات الأساس القديمة (`Refuses` فيها 460) لم ألمسها** لأنها ليست من هذه الحزمة. **أثر على وثيقة مشتركة: `obs-a/synthesis.md` يذكر `testCases_ListTheEightObservedStates` وصار `testCases_ListsTheEightObservedStates`** (للمُدمِج) |

**ما لم أبنه ولماذا:** **ترقيم صفحات القائمتين** (الإشعار يكفي ما دام قرارا الحد والترشيح قائمين، والترقيم يغيّر استعلامًا يشترك فيه الكاشير: بند `WP14-LIST-PAGING`)؛ **اختبار طلب `POST` بلا رمز عبر طبقة المتحكم كاملةً** (البيئة تُسقط المرشّح عمدًا فيُثبَت الإعداد والمرشّح كلٌّ على حدة)؛ **فحص axe والمتصفح** (القسم 5).

## 10. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر ولا دليل الجاهزية. هذا ما يُضاف (يعيّن المُدمِج رقم القرار، وهو بعد E80):

**decisions.md** (صف جديد، رقمه E-التالي):

> | E-؟؟ | **دورة المسودة وعرض السعر (WP14: `SAL-13` و`SAL-21`)** (2026-10-31، [التقرير](daftra-parity/wp14-report.md)): **قرار تنفيذي أُخذ تحت E47 على المرصود في `S-06` و`S-07` وOBS-A، وللمالك حق نقضه.** **(1)** المسودة بيع معلّق نوعه فاتورة ورقمها **مؤقت `draft-<المعرّف>` يُحسب ولا يُخزَّن ولا يستهلك تسلسل الفواتير**؛ **تُصدَر من صفحة المستند بطلب `POST` ورمز الحماية (لا رابط `GET`: يخالف دفترة التي تغيّر الحالة بروابط `GET`)** فتأخذ **رقم الفاتورة التالي** وتاريخها المكتوب وشروطها ضمن أقفال الفترات وتُرحَّل كأي فاتورة؛ **تُحسب ضرائبها وتكلفة أصنافها لحظة الإصدار** (لا تخزّنها المسودة)؛ **لا تُصدَر مسودة مرتين ولا يُحفظ بيع لم يعد معلّقًا، إتمامًا كان الحفظ أم تعليقًا** (يقفل `save_value` الصف: فلا يُرجَع بيع صدر أو أُلغي إلى الانتظار من كاشير ما زال يحمله)؛ **وتُرفض مسودة تغيّرت بين قراءة الإصدار وقفله** (بصمة تُقارَن تحت قفل الصف)؛ **وإصدار مسودة تحمل خصمًا على كامل الفاتورة يشترط `sales_invoice_discount` كالكاشير**؛ ومسودة دُفعت ببطاقة هدية أو نقاط تُكمَل في الكاشير. **(2)** لعرض السعر **ثماني حالات تُخزَّن في عمود مستقل عن `sale_status`** (`quote_status`)، **تنتقل بجدول يفرضه الخادم** (المسودة تُفتح؛ المفتوح والمُرسَل والمشاهَد والمردود عليه تتبادل وتنتقل إلى الموافقة؛ الموافَق عليه يُعاد فتحه أو يُطلَب؛ المحوَّل نهائي) **ويُدوَّن كل تغيير**، و**«تم الإرسال» و«تمت مشاهدته» و«تم الرد» حالات يدوية** (الإرسال خارج النظام: E47). **(3)** **التحويل إلى فاتورة يملأ نموذج الكاشير ولا يحفظ ولا يرحّل، ويتحوّل العرض في معاملة حفظ الفاتورة المصدَرة** (لا عند الضغط ولا عند حفظها مسودة)، **والعرض المحوَّل لا يُحفظ ولا يُلغى ولا يُستأنف**؛ **و«تعليق» العرض في الكاشير يحفظه مسودة بلا رقم**. **افتراضات للمراجعة:** جدول الانتقالات (المرصود: مفتوح ثم موافَق ثم محوَّل فقط)، ورقم المسودة من المعرّف وأن مسودة العرض لا تأخذ رقمًا (لم يُرصد)، ولحظة «حولت لفاتورة» عند إصدار الفاتورة، وصلاحية `reports_sales` للإصدار والحالة والتحويل بلا صلاحية جديدة. **وسدّت الحزمة ثغرة قائمة:** «استئناف المعلّق» وقائمته صارا بنطاق فروع الموظف. **لا «صالح لمدة» ولا نسخ المسودة ولا دفعة من صفحتها ولا سلة، ولا كتابة في دفترة، ولا دمج في `master`، ولا نشر** |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `SAL-13`: عمود «ما عندنا»: «المسودة بيع معلّق نوعه فاتورة برقم مؤقت `draft-<المعرّف>` لا يُخزَّن ولا يستهلك التسلسل، بلا قيد ولا حركة مخزون (`T-07`)، وتُصدَر من صفحة المستند بـ`POST` ورمز الحماية فتأخذ الرقم التالي من خدمة الترقيم وتاريخها المكتوب وشروطها داخل أقفال الفترات وتُرحَّل قيدا الإيراد والتكلفة (WP14). لا رابط `GET` يغيّر الحالة (فرق مقصود عن دفترة)، ولا مسودة محلية في المتصفح (فرق مقصود)». التصنيف: **يبقى «مختلف ويحتاج تعديلًا»** لأن إجراءي «نسخ» و«إضافة دفعة» من صفحة المسودة لم يُبنيا (والأخرى فروق مقصودة). الأدلة: `SalePostingTest` (`testIssueDraft_*`) و`DocumentNumberingTest` و`SalesControllerTest` و`DraftInvoiceTest` و`T-07` (حالتان جديدتان).
- الصف `SAL-21`: عمود «ما عندنا»: «ثماني حالات تُخزَّن في `quote_status` بعيدًا عن `sale_status`، وجدول انتقالات يفرضه الخادم، وتدوين في سجل التدقيق، وتحويل يملأ نموذج الفاتورة ولا يحفظ ولا يرحّل ويتحوّل العرض بحفظ الفاتورة المصدَرة (WP14)؛ «تم الإرسال» و«تمت مشاهدته» و«تم الرد» يدوية. لا «صالح لمدة» بعد». التصنيف: **يبقى «مختلف ويحتاج تعديلًا»** (جدول الانتقالات افتراض وحقل «صالح لمدة» وما يضبط الحالات 1 و2 و3 و6 في دفترة لم يُرصد). الأدلة: `QuoteStatusTest` و`QuoteLifecycleTest` و`SalesControllerTest` و`SalePostingTest` و`T-07` (حالتان جديدتان).
- فقرة تحديث بعد الجدول: «**تحديث بعد WP14 (2026-10-31):** نُفِّذت دورة المسودة وعرض السعر (`SAL-13` و`SAL-21`، `G-14`) ([تقرير WP14](wp14-report.md)). لا يتغير تصنيف أي صف ولا الأعداد (227 صفًّا).»

**تقرير الفجوات** (`gap-report.md`): الصف `G-14`: يُضاف في آخره «**(WP14، 2026-10-31: صارت المسودة برقم مؤقت يُصدَر من صفحتها بـ`POST` وحالات عرض السعر الثماني بجدول انتقالات وتحويل يملأ النموذج ولا يرحّل. بقي: نسخ المسودة وإضافة دفعة من صفحتها، وحقل «صالح لمدة»، وما يضبط الحالات 1 و2 و3 و6 في دفترة)**»؛ الصف `G-148`: يُضاف «**(WP14: تُحذف المسودة بالإلغاء القائم من صفحتها ولا سلة بعد)**».

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **دورة المسودة وعرض السعر (WP14):** ترحيل واحد `20271100000000_QuoteLifecycle` (عمودان يقبلان الفراغ وفهرس على `sales`، **ثقيل** فيُشغَّل في ساعة هادئة بعد نسخة احتياطية؛ `down()` يرفض بعد أن تحمل أي صف حالة)؛ **لا صلاحية ولا وحدة جديدة**. بعد التشغيل: العروض المعلّقة القائمة «مفتوحة»، والفواتير المعلّقة القائمة تظهر في `/sales/documents` بأرقام مؤقتة وتُصدَر من صفحتها بـ`POST`. **سلوك يتغيّر:** تعليق الكاشير في وضع العرض يحفظ مسودة بلا رقم تسلسل؛ «استئناف المعلّق» وقائمته بنطاق فروع الموظف؛ لا يُحفظ بيع لم يعد معلّقًا (إتمامًا أو تعليقًا)؛ تُرفض مسودة تغيّرت أثناء إصدارها؛ لا يُصدِر مسودة بخصم من لا يملك `sales_invoice_discount`؛ لا يُحفظ ولا يُلغى ولا يُستأنف عرض محوَّل. **قبل الاعتماد:** أصدِر مسودة تجريبية وراجع الضريبة والإجمالي ثم القيدين، وإن كان تسلسل الفواتير مفعّلًا فالرقم يتبع ترتيب الإصدار. التراجع بإزالة الملفات الجديدة وسطور المسار، ولا يُعاد الترحيل بعد أن تحمل صفوف حالة (يُستعاد من النسخة).

**ملاحظة لوثيقة مشتركة:** `docs/accounting/daftra-parity/observations/obs-a/synthesis.md` يذكر اسم الاختبار `testCases_ListTheEightObservedStates` وصار `testCases_ListsTheEightObservedStates` (مراجعة الأسماء، القسم 9 الصف 15)؛ وأسماء اختبارات أخرى للحزمة تغيّرت (`Refuses` إلى `Rejects` وغيرها) فمن ذكر اسمًا من أسماء الحزمة في وثيقة يراجعه بـ`git grep`.

**backlog.md:** صف WP14 «نُفِّذ (2026-10-31): `SAL-13` و`SAL-21` `partial`» وتُضاف البنود: `WP14-DRAFT-COPY-PAYMENT` (نسخ المسودة وإضافة دفعة من صفحتها)، `WP14-QUOTE-VALIDITY` («صالح لمدة» وتاريخ الإصدار المخفي)، `WP14-OBS-QUOTE-ACTIONS` (رصد ما يضبط الحالات 1 و2 و3 و6، `OBS-D`)، `WP14-STORED-VALUE-DRAFT` (إصلاح الخصم المزدوج لبطاقة الهدية والنقاط ثم إصدار مسوداتها من الصفحة)، `WP14-LIST-PAGING` (ترقيم صفحات قائمتي المسودات والعروض: اليوم إشعار عند القطع عند 200)، `WP14-STOCK-REFUSAL-MESSAGE` (رسالة `INSUFFICIENT_STOCK` في الكاشير)، `WP14-BROWSER-PASS` (فحص المتصفح، WP45)؛ وتبقى `WP13-INVOICE-LIST-DUE-TABS` و`WP8-WORK-ORDERS` و`G-148` مفتوحة تحت WP14. وتُحمل بنود قرار المالك (القسم 6) إلى WP52.

**دليل الجاهزية** (`docs/accounting/readiness-runbook.md`، السطر 104): تُضاف `QuoteLifecycle` إلى قائمة الترحيلات `HEAVY` (السطر في `MigrationChecks::SENSITIVE` أُضيف ضمن الحزمة، وهو ما يطبعه الأمر).
