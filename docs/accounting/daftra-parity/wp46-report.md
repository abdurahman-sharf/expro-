# تقرير WP46: طبقة المستند للفوترة الإلكترونية (ZATCA المرحلة الثانية) مرشّحًا، بلا اتصال بأي جهة

> الحالة (2026-11-08): **نُفِّذ على الفرع `wp/WP46`** (مأخوذ من `61cf5c1`) بالتفويض التنفيذي الشامل (E47). المبني **طبقة مستند مرشّحة** وليس تكاملًا: **(1)** بيانات رئيسية لتعريف الأطراف (11 رمزًا ورقم وعنوان وطني منظَّم وتحقق من الرقم الضريبي) على العميل وعلى البائع، **(2)** نموذج مستند لفاتورة ضريبية (قياسية 01 ومبسطة 02) وإشعار دائن وإشعار مدين يُبنى من **بيع مرحَّل** بأرقام قيده نفسها، بمعرّف UUID وعدّاد ICV لكل شركة وسلسلة تجزئة PIH وسجل **لا يُعدَّل ولا يُحذف**، **(3)** مولّد UBL 2.1 نقي باختبار ذهبي وتجزئة SHA-256 ورمز QR بوسوم المرحلة الثانية يمدّ صنف WP40 `SimplifiedInvoiceQr` ولا يكرّره، **(4)** توقيع ECDSA على المنحنى secp256k1 بـ`openssl` في PHP **إن وُجد المنحنى**، بمفتاح خارج شجرة التطبيق (يُرفض داخلها)، وواجهة `EInvoiceTransport` بتنفيذ فارغ يسجّل «لم يُرسل»، **(5)** الشاشات (إعدادات وتجهيز وتنزيل XML وحالة الفاتورة وحقول العميل). **الإعداد الرئيسي مطفأ افتراضيًا، والصلاحية الحساسة `acc_einvoicing_manage` لا تُمنح لأحد، ولا شيء يُرسل إلى أي خدمة خارجية، ولا يُدّعى هنا امتثال ولا اعتماد.** كل ما في الطبقة **مرشّح يُتحقَّق منه مقابل المواصفة الرسمية والبيئة التجريبية الرسمية قبل أي استعمال فعلي** (القسم 4). لا دمج ولا دفع إلى `master` ولا طلب سحب.

> **مرحلة الإصلاح بعد المراجعة (2026-11-09):** راجع الحزمةَ مراجعان مستقلان وفُحصت ملاحظاتهما السبع عشرة واحدةً واحدة في الكود؛ **كلها صحيحة كليًا أو جزئيًا فأُصلحت** (القسم 9): نطاق «سجلاتي فقط» على الإصدار والتنزيل والقائمة، وتحقق الإشعار المدين من فرع المستند الأصلي وعميله ونطاقه، وأطوال حقول العميل مساوية لأعمدة الجدول، وتجهيز يعدّ الرقم الضريبي غير الصالح مشكلة ولو وُجد معرّف، وقائمة اختيار بدل رقم يُكتب، وترقيم صفحات، ومسارات المفتاح لا تظهر للقارئ، ورسائل بالعربية بلا كلمات إنجليزية، وتقوية اختبار «التشغيل والإطفاء».

## 1. ما نُفِّذ

### 1.1 بيانات رئيسية لتعريف الأطراف (CUS-11 وSET-10)

| الجزء | ما يفعله |
|---|---|
| **الجدول** `customer_einvoice_parties` (ترحيل `20273400000000_EInvoicing`) | صف لكل عميل (`person_id` مفتاح وقيد خارجي `RESTRICT`): نوع المعرّف (يقبل الفراغ) ورقمه وعنوان وطني منظَّم (الشارع والشارع الإضافي ورقم المبنى والرقم الإضافي والحي والمدينة والمحافظة والرمز البريدي ورمز الدولة، الافتراضي `SA`). **قيد `CHECK`** يحصر النوع في الرموز الأحد عشر: `CRN` و`MOM` و`MLS` و`SAG` و`700` و`TIN` و`NAT` و`IQA` و`GCC` و`PAS` و`OTH` (الرموز والتسميات كما رُصدت في دفترة: FRM-R26 وRP-SET-05؛ وأخذ ترتيبها وصحتها من قائمة المواصفة لا من دفترة). **علَم «جهة حكومية»** لم يُكرَّر: هو العمود القائم `customers.is_government_entity` من WP37 (يُقرأ في الافتراضي بين المبسطة والقياسية وفي التجهيز). |
| **القواعد** (`EInvoiceRules`) | الرقم الضريبي **15 رقمًا يبدأ بـ3 وينتهي بـ3** (BR-KSA-40 وBR-KSA-44)، رقم المبنى **4 أرقام** (BR-KSA-37)، الرمز البريدي **5 أرقام** (BR-KSA-66 وBR-KSA-67)، رقم المعرّف أحرف وأرقام فقط (BR-KSA-08 وBR-KSA-14)، رمز الدولة حرفان كبيران، وحدود الأطوال. للبائع **ستة رموز فقط** من الأحد عشر (CRN وMOM وMLS و700 وSAG وOTH). |
| **الحفظ** (`CustomerParties`) | `find` يعيد صفًا فارغًا لمن لا بيانات له، و`save` ينظّف المدخل ويتحقق ويكتب **مع تدقيق «قبل وبعد»**، **والإفراغ الكامل يحذف الصف**. نموذج العميل (`Customers::postSave`) يعرض قسم «الفوترة الإلكترونية» **فقط حين تكون الطبقة مفعّلة** ويحفظ بعد حفظ العميل، ويرفض **قبل الحفظ** رقمًا ضريبيًا أو عنوانًا مخالفًا فلا يُحفظ نصف عميل. **حدود الأطوال (`CustomerParties::LIMITS`) هي عرض أعمدة الجدول نفسه** (المعرّف 60 والشارع 255 والحي والمدينة والمحافظة والشارع الإضافي 127 ورقم المبنى والرمز البريدي والرقم الإضافي 10)، فلا قيمة تتجاوز عمودها فتُرفض بيانات أو تُقطع؛ وحفظ الصف **يرمي عند فشل الكتابة** ويقول المتحكم في الرد إن المعرّف لم يُحفظ (والعميل محفوظ) بدل أن يعلن النجاح. |
| **البائع** (`EInvoiceSettings`) | الاسم والرقم الضريبي هما المخزَّنان في إعدادات المتجر أصلًا (لا حقل مكرَّر)؛ ويُضاف نوع المعرّف ورقمه والعنوان الوطني الذي تطلبه المواصفة. |

### 1.2 نموذج المستند (SAL-45 وSET-10)

| الجزء | ما يفعله |
|---|---|
| **المصدر** (`EInvoiceBuilder`) | `fromSale` يقبل **فاتورة (SALE_TYPE_INVOICE) أو مرتجعًا (SALE_TYPE_RETURN) مكتملًا فقط**، و`fromDebitNote` يقبل **الإشعار المدين للمبيعات القائم** (WP34). الفاتورة تصير فاتورة ضريبية (رمز النوع 388) والمرتجع إشعارًا دائنًا (381) والإشعار المدين (383). النوع الفرعي الافتراضي: **قياسية 01** لعميل له رقم ضريبي أو جهة حكومية أو عميل مُدخَل شركة، و**مبسطة 02** لغيره ولبيع بلا عميل، ويُختار يدويًا عند الإصدار. |
| **الأرقام من الدفاتر لا من حساب ثانٍ** | البنود والخصم والضريبة والإجمالي تأتي من `SaleLoader` و`SaleAmounts::figures/totals/lineGoods/lineDiscount/round` (التقريب بنصف لأعلى) **والمستند يُبنى من المخزَّن في البيع لا من السجل**. ثم `JournalTie` يقارن المستند بسطور **القيد المرحَّل نفسه** (مدين العميل، دائن الإيراد بسماح تقريب نصف هللة، دائن الضريبة) **وعند أي فرق يرفض ولا يُخزَّن شيء** (`totals_do_not_tie`). **بيع بلا قيد مرحَّل أو قيده معكوس يُرفض** (`source_not_posted`). |
| **العدّاد والسلسلة** (`EInvoiceService::lockCounter`) | جدول `einvoice_counters` صف لكل شركة (`last_icv` و`last_hash`). الإصدار داخل معاملة: **`SELECT ... FOR UPDATE` أولًا، ولا يُنشأ الصف (`INSERT IGNORE`) إلا إن غاب ثم يُقفل بالقراءة نفسها** فيأخذ كل طلب رقمًا واحدًا بلا فجوة ولا تكرار، **والرقم الأول سلسلته الثابتة** `NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==` (Base64 لـSHA-256 للصفر مكتوبًا بالست عشري، كما تنص عليه المواصفة)، وكل مستند يحمل `previous_hash` المستند الذي قبله. **رفض أي مستند يعيد العدّاد** (المعاملة كلها تتراجع). الشركة هي حدّ السلسلة (**افتراض**: القسم 4: المواصفة تربطها بوحدة الفوترة EGS). |
| **السجل الذي لا يُعدَّل** (`einvoice_documents`) | يُخزَّن **مرة واحدة**: المعرّف والمصدر والنوع والنوع الفرعي والرقم والـUUID وICV والتجزئتان وتاريخ ووقت الإصدار والمجاميع والمرجع إلى المستند الأصلي والسبب والـXML كاملًا ونص QR وعلَم التوقيع والوسم `candidate`. **فهارس فريدة** على (شركة، مصدر، معرّف مصدر) و(شركة، ICV) و(UUID)، **وقيود `CHECK`** على النوع والنوع الفرعي وICV > 0 وأن الإشعار لا يخلو من أصل (`note_original`)، **وقيد خارجي ذاتي `RESTRICT`**. **لا `UPDATE` ولا `DELETE` في أي كود** (اختبار مصدر يفتش ملفات الطبقة عن أي عبارة تعدّل أو تحذف: `testSource_HoldsNoStatementThatChangesOrDeletesAnIssuedDocument`). **الإصدار متكرر الأمان**: بيع أُصدر له مستند يُرجع المستند نفسه في الطلب الثاني (فهرس المصدر الفريد يحسم سباق طلبين). |
| **الإشعار الدائن والمدين** | يحمل `original_document_id` (المستند الأصلي **المُصدَر فعلًا**) ويُرفض إن لم يُصدَر الأصل (`original_not_issued`) أو كان الأصل إشعارًا مدينًا **أو في فرع لا يعمل فيه الموظف أو من سجلات لا يراها** (يُقال له الكلام نفسه عن مستند غير موجود فلا يُسرَّب وجوده)، **ويُرفض أصل عميله غير عميل الإشعار** (`original_of_another_customer`، وأصل بيع بلا عميل يُرفض كذلك: **افتراض محافظ**)، **والسبب مطلوب** (`reason_required`) ولا يتجاوز 1000 حرف (`reason_too_long`)؛ **تعرض الشاشة الأسباب الخمسة الرسمية** (KSA-10، ثوابت `NOTE_REASONS`) خيارات جاهزة، **ولا يقتصر الخادم عليها** (نص حر مقبول: **افتراض** يُراجع). |
| **أنواع الضريبة** | بنود بنسبة واحدة تُصنَّف S (نسبة) أو E/Z/O حين لا ضريبة، **بتصنيف يُضبط في الإعدادات** (فئة ورمز سبب `VATEX-SA-*` ونص، مع التحقق أن الرمز يوافق الفئة). **ما لا تدعمه الطبقة يُرفض باسمه ولا يُخمَّن** (القسم 5). |
| **ما يُرفض صراحة** | عملة أجنبية (`foreign_currency`)، بيع فيه شحن أو تسوية رأس الفاتورة (`header_charges_unsupported`)، بند بضريبتين أو نسبة لا تُكتب (`rate_not_representable`)، بند ضريبته غامضة (`line_tax_ambiguous`)، لا شيء يُصدَر (`nothing_to_issue`)، قياسية بلا عميل (`standard_needs_customer`)، بائع بلا اسم أو برقم ضريبي غير صالح أو بلا معرّف أو بعنوان ناقص، مشترٍ في قياسية بلا اسم أو معرّف أو عنوان كامل (أو برقم ضريبي غير صالح في أي نوع). |

### 1.3 مولّد UBL والتجزئة والرمز (G-226، السطر 2 من WP40 يمتد)

| الجزء | ما يفعله |
|---|---|
| **المولّد** (`UblInvoiceXml`) | **نقي** (لا قاعدة بيانات ولا ساعة ولا عشوائية): ملف UBL 2.1 مضغوط بلا مسافات بين العناصر ولا عنصر فارغ، بترتيب عناصر المواصفة: `UBLExtensions` (موضع الختم) ثم `ProfileID` (`reporting:1.0`) و`ID` و`UUID` و`IssueDate` و`IssueTime` (وقت محلي بلا منطقة) و`InvoiceTypeCode` بالاسم `NNPNESB` (KSA-2: 01/02 ثم الأعلام الخمسة) و`DocumentCurrencyCode` و`TaxCurrencyCode` و`BillingReference` للإشعارات و`AdditionalDocumentReference` لـICV وPIH وQR و`cac:Signature` والبائع والمشتري وتاريخ التوريد وطريقة الدفع وسبب الإشعار ومجموعا الضريبة (بالعملة وبالريال) و`LegalMonetaryTotal` والبنود. الهروب: `escape($text, $attribute)` (النص بلا `&apos;`، والخاصية بالاقتباسين)، **وإزالة الأحرف التي لا يقبلها XML**. **نفس المستند يعطي نفس البايتات** (اختبار). |
| **الاختبار الذهبي** | ملفان ذهبيان في `tests/Support/Golden/einvoice/`: فاتورة مبسطة وإشعار دائن لفاتورة قياسية، كُتبا باليد من البنية التي تنشرها المواصفة، **ويطابقهما المولّد بعد التقييس C14N** (لا تهمّ مسافات التنسيق في الملف المخزَّن؛ **والمولّد نفسه يكتب بلا مسافات**، فكل بايت يتغير يكسر التجزئة)، وملف ثالث لامتدادات الختم. **الملفات الذهبية موسومة «مرشح»** ولا تُقدَّم عينة معتمدة: لم أحصل على عينات XML الرسمية ولا قواعد Schematron (القسم 3). |
| **التجزئة** (`InvoiceHash`) | تُحذف `UBLExtensions` و`cac:Signature` ومرجع المستند الإضافي لـQR، ثم **C14N**، ثم **SHA-256**، ثم Base64 (للتجزئة 44 حرفًا). `FIRST` ثابت. **التجزئة لا تتغير بالختم** (اختبار: `testSigned_KeepsTheHashOfTheFileBeforeItWasStamped`) وتتغير بتغيّر أي مبلغ أو عدّاد. **ملف يحمل خاصية في فضاء `xml` يُرفض** لأن C14N 1.1 غير متاح في PHP (افتراض). |
| **رمز QR** (`SimplifiedInvoiceQr::encodePhaseTwo`) | **يمدّ صنف WP40 ولا يكرّره**: يستعمل الدالة نفسها لترميز TLV ويضيف الوسوم 6 (تجزئة) و7 (توقيع) و8 (المفتاح العام DER، **88 بايتًا** لـsecp256k1) و9 (توقيع الشهادة، **للمبسطة وحدها**) بعد الوسوم 1 إلى 5 القائمة، **وحد 700 حرف** (`qr_too_long`). **اختبار يفكّ الرمز الرسمي المنشور في دليل إنشاء الرمز ويعيد ترميزه بايتًا بايتًا.** الفاتورة بلا مفتاح تبقى برمز المرحلة الأولى (خمسة وسوم: `testIssueSale_WritesTheCodeOfTheFirstPhaseWhenNoKeyIsInPlace`). |

### 1.4 التوقيع والنقل (بلا إرسال)

| الجزء | ما يفعله |
|---|---|
| **المفتاح والمسار** (`KeyPath` و`StampKeys`) | المسار **مطلق** ويُحلَّل بـ`realpath` (حتى لو لم يوجد المجلد بعد) **ويُرفض داخل `ROOTPATH` و`APPPATH` و`FCPATH` و`WRITEPATH`** (وقائمة محمية اختيارية للاختبار). **يُتحقق عند الحفظ وعند الإصدار** (اختبار: `testIssueSale_RefusesAKeyPathInsideTheApplicationEvenWhenTheSettingWasWrittenPastTheScreen`). المفتاح الخاص **لا يُخزَّن في قاعدة البيانات ولا في المستودع، ومحتواه لا يُقرأ إلى الشاشة ولا إلى التدقيق**: يُسمّى بمساره فقط (والمسار نفسه هو ما يظهر ويُدقَّق). يُرفض مفتاح ليس على المنحنى `secp256k1` وشهادة لا تطابق المفتاح، ويُرفض تسمية أحدهما بلا الآخر **بدل الإصدار بلا توقيع بصمت**. المفاتيح والشهادات في الاختبارات **تُولَّد وقت الاختبار في مجلد مؤقت** ولا يوجد سر في المستودع. |
| **التوقيع** (`EcdsaSigner` و`XadesStamp` و`DocumentStamper`) | ECDSA بـSHA-256 على المنحنى secp256k1 بـ`openssl_sign`، **بعد التحقق أن المنحنى متاح** (`curve_unsupported` ويظهر تحذيرًا في التجهيز)؛ يُبنى `UBLExtensions` بمرجع XAdES (`SignedProperties` ببصمة الشهادة والجهة المصدرة والرقم التسلسلي وزمن التوقيع) **ولا يُقبل ختم تتغير بعده تجزئة الملف** (`DocumentStamper` يقارن). `StampVerifier` يقرأ الملف وحده كما يقرؤه المستلم: يعيد حساب تجزئة المستند وتجزئة `SignedProperties` ويتحقق من التوقيع بمفتاح الشهادة التي في الملف، **ويقول ما فشل ولا يرمي لملف خاطئ فقط**. **توقيع كل مرة مختلف وتجزئة الملف واحدة** (اختبار). |
| **النقل** (`EInvoiceTransport`) | واجهة بطريقة واحدة، وتنفيذ `NullEInvoiceTransport` **لا يتصل بشيء** ويعيد «لم يُرسل». `EInvoiceService::submit` (بصلاحية الإدارة وفرع المستند) يسأل النقل **ويسجّل ما قاله صفًّا إضافيًا** في `einvoice_transport_log` (إضافة فقط، حالاته: `not_submitted` و`reported` و`cleared` و`rejected`). كل إصدار يسجّل `not_submitted` تلقائيًا. **لا شهادة CSID ولا إرسال ولا تخليص**. |

### 1.5 الشاشات والصلاحية

| الجزء | ما يفعله |
|---|---|
| **الوحدة والصلاحية** | وحدة `accounting_einvoicing` (ترتيب 135) وصلاحيتان: `accounting_einvoicing` (الدخول) و**`acc_einvoicing_manage` (حساسة، في `AccountingGuard::SENSITIVE` فصارت 20)**، **بلا منح لأحد** (الترحيل لا يكتب في `grants`). المتحكم `AccountingEInvoicing` يعيد **404 إن كانت المحاسبة مطفأة**. تسمية الصلاحية في شاشة الصلاحيات (`AccountingSetup::PERMISSION_LABELS`) ورابط في القائمة الجانبية ضمن مجموعة المحاسبة. |
| **الإعدادات** | `EInvoiceSettings::update`: الصلاحية ← نطاق الشركة ← المحاسبة مفعّلة ← التحقق من كل حقل ← حفظ **مع تدقيق «قبل وبعد»**. **الإعداد الرئيسي `einvoice_enabled` مطفأ افتراضيًا** و`isEnabled()` لا يصدق إلا والمحاسبة مفعّلة. |
| **التجهيز** (`EInvoiceReadiness`) | قراءة فقط: المحاسبة والتفعيل واسم البائع **ورقمه الضريبي** (فاشل إن غاب أو لم يصحّ) والمعرّف والعنوان والتصنيف الضريبي للبند بلا ضريبة والمنحنى والتوقيع (المفتاح والشهادة) والنقل (تحذير «لا يُرسل»)، و**العملاء بلا تعريف** (الأعمال أولًا). **المشكلة هنا هي ما يرفضه المستند فعلًا:** رقم ضريبي غير صالح **ولو وُجد معرّف** (المشتري يرفضه في كل نوع)، أو لا رقم ضريبي ولا معرّف؛ والرقم يُقرأ مقصوصًا من الفراغات كما يقرؤه المستند. |
| **صفحة الفاتورة** (`EInvoicePanel`) | سطر واحد في الفاتورة والفاتورة الضريبية والإيصال: **لم يُنشأ / أُنشئ / موقَّع / لم يُرسل** مع زر الإصدار لمن يملك الصلاحية وزر تنزيل XML. التغيير الأدنى في القوالب الثلاثة: سطر تضمين واحد. |
| **نطاق السجلات (WP30)** | **من يرى سجلاته فقط** (`RecordScope`) لا يُصدر مستند بيع صنعه موظف آخر (`sales.employee_id`) ولا إشعارًا مدينًا صنعه غيره (`created_by`) ولا يأخذ ملف مستند مصدره غريب، ولا يظهر له في القائمة: **ويُقال له ما يُقال عن غير الموجود** (`source_not_found` و`document_not_found` والتنزيل 404). (**ملاحظة:** حزمة WP34 نفسها لا تطبّق النطاق على قائمة الإشعارات المدينة؛ هنا طُبّق على طبقة المستند وحدها.) |
| **شاشة الإدارة** | قائمة المستندات **50 في الصفحة** وقائمة العملاء **25 في الصفحة** بترقيم (`documents_offset` و`customers_offset`، والإزاحة الخارجة عن المدى تُعاد إلى آخر صفحة، والسالبة والنصية إلى الأولى) **ولا يظهر الترقيم إن وسعت الصفحة الواحدة القائمة**. **نموذج مستند الإشعار المدين قائمتان بدل حقل رقم:** إشعارات مدينة قائمة بلا مستند (الأحدث 100، **باسم رقمها كما تراه في شاشة الإشعارات**، أي رقم السلسلة إن كانت مفعّلة) وفواتير وإشعارات دائنة لعملاء تلك الإشعارات (حتى 500، ويُصفّيها السكربت إلى عميل الإشعار المختار، **والخادم يتحقق على كل حال**). **مسارا المفتاح والشهادة لا يظهران لمن لا يملك إدارة الإعدادات** (قارئ الوحدة وحده أو مدير فروع بعضها) ولا حقلاهما. كل بند في التجهيز يقول **بالكلمات** مستوفى أو تنبيه أو غير مستوفى (نص مخفي للقارئ الشاشي، والأيقونة زينة)، وتفاصيل التوقيع والنقل بالكلمات لا بأكواد (`key_path_unsafe` و`not_configured` و`null`)، **ومفتاح لا يُستعمل يُقال إنه سيُرفض المستند لا أنه «يصدر بلا توقيع»**. |
| **التنزيل** | `getXml/(رقم)`: صلاحية الإدارة وفرع المستند ونطاق السجلات، **404 عند أي رفض** (لا يُكشف وجود مستند)، ترويسات `application/xml` وإرفاق ومنع الاستنتاج (`nosniff`)، **واسم الملف: الرقم الضريبي للبائع ثم التاريخ والوقت ثم رقم المستند** (`IssuedEInvoice::fileName`: على نمط تسمية الملفات المعروف، **يُتحقق**). |

### 1.6 القاعدة الصلبة: لا شيء يتغير في الدفاتر

الإصدار **يقرأ** البيع والقيد ولا يكتب في أي منهما: لا قيد ولا مستند ولا رقم ولا حركة مخزون ولا رصيد. الترحيل **يضيف جداول ووحدة وصلاحيتين فقط** ولا `ALTER` على جدول قائم (لذلك لا إدراج في `MigrationChecks::SENSITIVE`). الدليل: اختبار يرحّل البيوع نفسها بالإعداد مطفأ ثم مفعّل ويقارن **كل سطر من كل قيد** وأرقام المستندات وحركات المخزون والمجاميع (`testIssueSale_LeavesTheBooksOfTheSamePostingsAsTheyAreWhenTheLayerIsOffAndWhenItIsOn` و`testIssueSale_ChangesNoJournalNoNumberNoStockMovementAndNoTotal`).

## 2. الأدلة

**الاختبارات** (ملف لكل صنف، والأسماء `test{الطريقة}_{السلوك}`):

| الملف | عدد الاختبارات | يغطي |
|---|---|---|
| `tests/Libraries/EInvoicing/EInvoiceServiceTest.php` | 56 | الإصدار والعدّاد والسلسلة والتزامن (اتصالان حقيقيان) والتوقيع والصلاحيات والفروع والنقل الفارغ وثبات الدفاتر |
| `tests/Libraries/EInvoicing/EInvoiceBuilderTest.php` | 35 | بناء المستند من البيع المرحَّل والربط بالقيود والرفض باسم كل قاعدة |
| `tests/Controllers/AccountingEInvoicingControllerTest.php` | 41 | الشاشات: 404 والصلاحية والإعدادات والإصدار والتنزيل والترقيم ونطاق السجلات وقائمتا الإشعار المدين وإخفاء المسارات وكلمات الحالة وسطر الحالة في الفاتورة والإيصال والفاتورة الضريبية |
| `tests/Libraries/EInvoicing/EInvoiceSettingsTest.php` | 19 | الإعدادات والتحقق والتدقيق والمحاسبة المطفأة ومسار المفتاح |
| `tests/Libraries/EInvoicing/EInvoiceReadinessTest.php` | 17 | فحوص التجهيز والعملاء بلا تعريف |
| `tests/Libraries/EInvoicing/CustomerPartiesTest.php` | 17 | بيانات تعريف العميل وعنوانه وحدود أطوالها مقابل الأعمدة ورفض كتابة القاعدة |
| `tests/Libraries/EInvoicing/EInvoiceChoicesTest.php` | 11 | **ملف جديد** (مرحلة الإصلاح): قائمتا نموذج الإشعار المدين بالفروع والنطاق والعميل |
| `tests/Libraries/EInvoicing/EInvoiceExceptionTest.php` | 6 | **ملف جديد**: رسائل الأخطاء بكلمات لغة القارئ |
| `tests/Libraries/EInvoicing/UblInvoiceXmlTest.php` | 12 | XML الذهبي والهروب والأنواع |
| `tests/Database/EInvoicingMigrationTest.php` | 11 | الترحيل مرتين، القيود، البذر، `down()` |
| `tests/Libraries/EInvoicing/DocumentStamperTest.php` | 10 | الوسوم التسعة والختم وتجزئة لا تتغير |
| `tests/Libraries/EInvoicing/InvoiceHashTest.php` | 10 | التجزئة وما يُحذف قبلها والسلسلة الأولى |
| `tests/Libraries/EInvoicing/KeyPathTest.php` | 10 | رفض المسار داخل التطبيق |
| `tests/Libraries/EInvoicing/EInvoicePanelTest.php` | 8 | حالة الفاتورة (لم يُنشأ، أُنشئ، موقَّع، لم يُرسل) |
| `tests/Libraries/EInvoicing/StampKeysTest.php` | 8 | المفتاح والشهادة والمنحنى |
| `tests/Libraries/EInvoicing/DerReaderTest.php` | 6 | قراءة DER |
| `tests/Libraries/EInvoicing/EInvoiceRulesTest.php` | 6 | قواعد الصيغ |
| `tests/Libraries/EInvoicing/XadesStampTest.php` | 6 | امتدادات الختم |
| `tests/Libraries/EInvoicing/StampVerifierTest.php` | 5 | التحقق من الملف الموقَّع وحده |
| `tests/Libraries/EInvoicing/PartyIdentificationTypeTest.php` | 4 | الرموز الأحد عشر وستة البائع |
| `tests/Libraries/EInvoicing/EcdsaSignerTest.php` | 3 | توقيع ECDSA والتحقق |
| `tests/Libraries/Documents/SimplifiedInvoiceQrTest.php` | 8 | **اختبارات جديدة في الملف القائم**: وسوم المرحلة الثانية |
| `tests/Controllers/CustomersControllerTest.php` | 13 | **اختبارات جديدة في الملف القائم**: قسم الفوترة في نموذج العميل (ثلاثة منها للأطوال ولكتابة ترفضها القاعدة) |
| `tests/helpers/NavigationHelperTest.php` | 1 | **اختبار جديد في الملف القائم**: وحدة الفوترة الإلكترونية في مجموعة المحاسبة |
| `tests/LanguageFilesTest.php` | 3 | **اختبارات جديدة في الملف القائم**: مفاتيح اللغة |

(الأرقام عدد دوال الاختبار لما لم يتغيّر، وكما أبلغها phpunit لما أُضيف في مرحلة الإصلاح؛ ما يستعمل مزوّد بيانات يُعدّ أكثر عند التشغيل.) ملفات الدعم الجديدة: `tests/Support/EInvoiceFixtureTrait.php` و`EInvoiceSaleFixtureTrait.php` وذراع `issueEInvoice` في `RaceWorker.php` والملفات الذهبية الثلاثة، وإضافة سطر في `SensitivePermissionsTest` (الصلاحية الحساسة العشرون).

**ما يغطيه كل متطلب من متطلبات المهمة:**

| المتطلب | الاختبار |
|---|---|
| TLV بكل الوسوم | `SimplifiedInvoiceQrTest` (الوسوم 1 إلى 9، حد 700، فك الرمز الرسمي وإعادته) و`DocumentStamperTest::testSigned_WritesTheNineTagsOfTheCodeOfASimplifiedInvoice` |
| XML ذهبي | `UblInvoiceXmlTest::testBuild_MatchesTheGoldenSampleOfASimplifiedInvoice` و`..._OfAStandardCreditNote` |
| سلسلة التجزئة عبر عدة فواتير | `EInvoiceServiceTest::testIssueSale_ChainsTheHashOfEachDocumentToTheNextAndCountsWithoutGaps` |
| ICV تحت التزامن (اتصالان حقيقيان) | `testIssueSale_AssignsTheCountersWithoutGapsOrRepeatsWhenTwoRequestsRunInTwoConnections` و`..._IssuesOneDocumentWhenTwoConnectionsAskForTheSameSale` (عامل `RaceWorker` ذراع `issueEInvoice`) و`..._WaitsForTheLockOfTheCounterBeforeItTakesTheNextNumber` |
| التوقيع والتحقق | `EcdsaSignerTest` و`StampVerifierTest` و`XadesStampTest` و`DocumentStamperTest` (مفاتيح مولَّدة وقت الاختبار) |
| عدم التعديل | `testIssueSale_NeverChangesTheStoredDocumentWhenTheBooksChangeAfterwards` و`testSource_HoldsNoStatementThatChangesOrDeletesAnIssuedDocument` وقيود `CHECK` والفهارس في `EInvoicingMigrationTest` |
| مرجع الإشعار الدائن | `testIssueSale_IssuesTheCreditNoteOfAReturnReferringToTheInvoiceItTakesBack` و`testIssueSale_RefusesACreditNoteWhoseInvoiceWasNeverIssuedAndLeavesTheCounterAlone` |
| الربط بالقيود | `testIssueSale_TiesTheDocumentToTheJournalsOfTheSale` والرفض عند اختلاف الضريبة أو الإيراد أو انعكاس القيد |
| رفض المفتاح داخل التطبيق | `KeyPathTest` و`StampKeysTest` و`testIssueSale_RefusesAKeyPathInsideTheApplicationEvenWhenTheSettingWasWrittenPastTheScreen` |
| الصلاحيات | `EInvoiceServiceTest` و`EInvoiceSettingsTest` و`AccountingEInvoicingControllerTest` و`SensitivePermissionsTest` (الصلاحية حساسة ولا تُمنح لأحد) |
| المحاسبة مطفأة | `testIssueSale_RefusesWhileTheLayerIsOffAndWhileAccountingIsOff` وفحص 404 في `AccountingEInvoicingControllerTest` |
| الترحيل آمن مرتين | `EInvoicingMigrationTest` (تشغيل مرتين، إعادة البذر، `down()` يرفض بعد الإصدار) |
| ما بعد الدمج مع الموجود | `CustomersControllerTest` (10 اختبارات جديدة لقسم الفوترة في بطاقة العميل) و`LanguageFilesTest` (مفاتيح الوحدة والملف في كل لغة) |

**ما شُغِّل في هذا العمل ونتيجته:**

كل ملف بأمر منفصل `MYSQL_DB_NAME=ospos_test_wp46 vendor/bin/phpunit --no-coverage <الملف>` وبلا أي عملية أخرى على القاعدة نفسها (**مرحلة الإصلاح بعد المراجعة**، ثم أُعيد تشغيل ما عُدِّل بعد ذلك):

| الملف | النتيجة |
|---|---|
| `tests/Libraries/EInvoicing` (19 ملفًا: كل ما فيها) | كلها OK؛ منها: `EInvoiceServiceTest` (56 tests, 719 assertions) و`EInvoiceBuilderTest` (35, 210) و`EInvoiceChoicesTest` (11, 89) و`EInvoiceExceptionTest` (6, 28) و`EInvoiceReadinessTest` (17, 54) و`CustomerPartiesTest` (17, 82) و`EInvoiceSettingsTest` (19, 267) و`EInvoicePanelTest` (8, 54) و`EInvoiceRulesTest` (22, 29) و`UblInvoiceXmlTest` (17, 21) و`KeyPathTest` (15, 17) |
| `tests/Controllers/AccountingEInvoicingControllerTest.php` | OK (41 tests, 328 assertions) |
| `tests/Database/EInvoicingMigrationTest.php` | OK (11 tests, 56 assertions) |
| `tests/Controllers/CustomersControllerTest.php` | OK (110 tests, 530 assertions) |
| `tests/helpers/NavigationHelperTest.php` | OK (20 tests, 38 assertions) |
| `tests/Libraries/Documents/SimplifiedInvoiceQrTest.php` | OK (30 tests, 47 assertions) |
| `tests/Libraries/Documents/DocumentQrTest.php` | OK (37 tests, 102 assertions) |
| `tests/Controllers/AccountingSetupControllerTest.php` | OK (77 tests, 527 assertions) |
| `tests/LanguageFilesTest.php` | OK (3516 tests, 12082 assertions) |

**شُغِّلت في المرحلة الأولى ولم يتغيّر ما تلمسه هذه المرحلة** (لم أعدّل `Sales.php` ولا نماذج الأدوار ولا الصلاحيات ولا الترحيلات): `SensitivePermissionsTest` (14) و`EmployeesControllerTest` (64) و`tests/Libraries/Accounting/Readiness` (258) و`AccountingModulesMigrationTest` (8) و`RolesPermissionsMigrationTest` (4) و`MultiCurrencyMigrationTest` (39) و`CustomerMasterDataMigrationTest` (15) و`tests/Libraries/Roles` (161) و`tests/Libraries/Documents` (251).

**لم أشغّل المجموعة الكاملة** (تعليمة الحزمة)، فالملفات أعلاه هي ما يلمسه التغيير: الطبقة نفسها، والصلاحيات الحساسة، والوحدات والأدوار، والعملاء، واللغات، والمستندات (الرمز)، والقائمة الجانبية. **php-cs-fixer** شُغِّل على كل ملفات التطبيق الجديدة والمعدَّلة للحزمة (`app/Libraries/EInvoicing` والمتحكم والعروض) فلا شيء بعده قابل للإصلاح. **لا يُشغَّل على ملفات الاختبار:** المصلح يعيد تسمية دوال `testFoo_Bar` إلى `testFooBar` (يخالف تسمية الحزمة) ويجعل الأصناف `final` (انظر القسم 9).

## 3. المصادر الرسمية: ما قُرئ وما لم يُقرأ

**قُرئ** (نُزّل من موقع الهيئة في 2026-11-08 وفُرغ نصه وقُرئ ما يخص الطبقة، والنسخ كما وُجدت وتُراجع على بوابة الهيئة قبل أي استعمال):

- **دليل المطوّر التقني التفصيلي** (`E-invoicing-Detailed-Technical-Guideline.pdf`) والدليل العام التفصيلي (`E-Invoicing_Detailed__Guideline.pdf`): أنواع المستندات ومسار الإبلاغ والتخليص.
- **معيار تنفيذ ملف XML للفاتورة الإلكترونية** نسختان (`XMLStandard.pdf` ونسخة قرار 2023-05-19 `XMLStandard_20230519.pdf`): بنية UBL وقواعد BR-KSA وBR-CO ورموز الاستثناء VATEX وأسباب الإشعارات (KSA-10) وصيغة `InvoiceTypeCode`.
- **معيار تنفيذ خصائص الأمان** نسختان (2022-06-24 ونسخة قرار 2023-05-19): التجزئة (ما يُحذف قبلها) وC14N وتوقيع ECDSA وXAdES وقيمة PIH الأولى ووسوم رمز QR.
- **دليل إنشاء رمز QR** (`QRCodeCreation.pdf`) وفيه الرمز الرسمي المنشور الذي يفكّه الاختبار.
- **قاموس البيانات** (`DataDictionary_vF.xlsx`): الحقول وقواعد وجودها وصيغتها.
- **دليل بوابة المطوّرين** (`DEVELOPER-PORTAL-MANUAL.pdf`): مسار التسجيل والشهادة، قُرئ للمعرفة فقط ولم يُبنَ منه شيء (لا CSID).

**لم يُقرأ ولم يُستعمل:**

- **حزمة SDK الرسمية** ومحقّق الفواتير فيها، **وقواعد Schematron الرسمية**، **وعينات ملفات XML الرسمية** (القياسية والمبسطة والإشعارات الموقَّعة): لم أحصل عليها في هذه البيئة، فلم يُتحقَّق من أي ملف أنتجته الطبقة بأداة رسمية. **أكبر حدّ لهذا العمل.**
- **البيئة التجريبية الرسمية (Sandbox)** وواجهات الإبلاغ والتخليص وإصدار الشهادة (CSID): لا اتصال، بالتفويض.
- **القرارات واللوائح التنفيذية** (قرار الفوترة الإلكترونية) اطُّلع على عنوانها فقط؛ **لم تُستخرج منها أحكام الاستثناءات ولا مواعيد المراحل**.
- **دفترة:** لم يُقرأ منها شيء جديد في هذا العمل. الأقسام المنقولة منها (تسميات الرموز الأحد عشر وأعمدة تقرير الإرسال) من رصد OBS-A السابق (`RSC-ZAT-01` و`FRM-R26` و`RP-SET-05`) وفي حدوده.

## 4. الافتراضات (**يُتحقق منها مقابل المواصفة الرسمية والبيئة التجريبية الرسمية قبل أي استعمال فعلي**)

1. **C14N 1.0 لا 1.1:** المعيار يسمّي C14N 1.1 وPHP لا يوفّر غير 1.0 (`DOMDocument::C14N`)؛ تتطابق النتيجتان حين لا تظهر خصائص `xml:*` في الملف، **ولذلك يرفض `InvoiceHash::load` ملفًا فيه خاصية من فضاء `xml`** بدل أن يحسب تجزئة قد تختلف.
2. **الوسمان 6 و7 نصٌّ Base64 والوسمان 8 و9 بايتات خام:** الوثائق ليست متسقة في موضع واحد (500 حرف في موضع و700 في آخر؛ نصّ أو بايتات للوسمين 6 و7)؛ اتُّخذ ما تجمع عليه العينة المنشورة، **والحد المطبَّق 700** (والاختبار يفكّ الرمز الرسمي ويعيد ترميزه بالصيغة نفسها)، وفي `encodePhaseTwo` معامل `hashAsBytes` يبدّل صيغة الوسم 6.
3. **الطابع الزمني حرفي لا عالمي:** `IssueTime` ووسم QR رقم 3 يكتبان **وقت الإصدار المحلي المخزَّن كما هو** بلا منطقة (`testBuild_WritesTheIssueTimeAsLocalTimeWithoutAZone`)؛ لم تحسم الوثائق أيهما تقبله البوابة.
4. **قاعدة بصمة الشهادة وتجزئة `SignedProperties`:** بصمة الشهادة = Base64 لـ**نص SHA-256 الست عشري** لنص الشهادة Base64 (على نمط ما تصفه المواصفة، **لم يُتحقق بأداة رسمية**)؛ وتجزئة `SignedProperties` = Base64 لـSHA-256 الخام لصيغة C14N للعنصر **في موضعه من الملف**؛ والتحويلات في المرجع تسمّي c14n11 والحساب C14N 1.0 (البند 1)؛ **ولا معرّف سياسة توقيع** في الختم لأن الوثائق المقروءة لا تنشر له قيمة.
5. **لا شهادة حقيقية:** الشهادة في الاختبارات ذاتية التوقيع تُولَّد وقت الاختبار؛ **شهادة CSID الصادرة عن الهيئة** لم تُجرَّب، فلا يُعرف إن كانت بنيتها (الحقول والامتدادات واسم الجهة) تمرّ من `StampKeys::load` و`XadesStamp`.
6. **المنحنى:** تقبل الطبقة **secp256k1 وحده** (وتتحقق من وجوده في OpenSSL المثبّت)؛ بعض مواضع الوثائق تذكر P-256 (`prime256v1`) في اسم خوارزمية التوقيع، **فإن جاءت الشهادة الرسمية على منحنى آخر رفضتها الطبقة بـ`curve_unsupported` ولم تقبلها بصمت**.
7. **طريقة الدفع:** الربط من طرق الدفع في المتجر إلى رموز UN/CEFACT (نقد 10، شيك 20، تحويل ومحفظة وUPI 30، بطاقة مدين أو ائتمان 48؛ وللإشعار بلا دفع النقد 10) **افتراضي** ويُراجع.
8. **المشتري المجهول** في المبسطة يُكتب بالاسم الثابت `Cash customer` (`UblInvoiceXml::CASH_BUYER`)؛ **تاريخ التوريد = تاريخ الفاتورة** (لا حقل في البيع لتاريخ آخر).
9. **النوع الفرعي الافتراضي** (قياسية لمن له رقم ضريبي أو جهة حكومية أو شركة) **قاعدة منا لا من المواصفة**، ويمكن تغييرها عند الإصدار.
10. **عدّاد واحد للشركة:** المواصفة تقيّد ICV بوحدة الفوترة (EGS) لا بالشركة؛ المتجر الواحد بشركة واحدة وجهاز واحد يطابق، **وإن تعدّدت أجهزة الفوترة كان لكل جهاز عدّاد وسلسلة ولم تُبنَ** (القسم 5).
11. **السماح بنصف هللة في ربط الإيراد بالقيد** (`JournalTie`): الإيراد في القيد هو الرصيد الموازن فيحمل تقريب الهللة (E14)، **والضريبة بلا سماح**.
12. **أسباب الإشعارات الخمسة** كما صيغت في القاموس بالإنجليزية (ثوابت `NOTE_REASONS`)؛ لا ترجمة ولا اختصار.

## 5. ما لم يُبنَ وما يُترك

- **شحن وتسوية رأس الفاتورة (WP34):** يُرفضان باسمهما (`header_charges_unsupported`)؛ يلزم تمثيلهما `AllowanceCharge` على مستوى المستند وربطهما بالقيد.
- **عملة أجنبية:** تُرفض (`foreign_currency`)؛ تلزم قاعدة سعر الصرف في `TaxCurrencyCode` ومبلغ الضريبة بالريال.
- **أكثر من ضريبة في البند ونسب مركّبة** وبنود **الخصم على مستوى المستند** وبنود **الدفعة المقدمة (Prepayment)** والاشتراك والتوريد الدوري: غير مدعومة.
- **شهادة CSID والتسجيل (Onboarding) والإبلاغ والتخليص (Clearance):** لا شيء منها؛ الواجهة `EInvoiceTransport` فقط.
- **التحقق بأدوات الهيئة:** لم يُشغَّل المحقّق ولا Schematron على أي ملف (القسم 3).
- **PDF/A-3 المضمَّن فيه XML** (مطلوب للفاتورة المطبوعة بصيغة PDF): غير مبني.
- **تقرير «إرسال الفواتير إلى هيئة الزكاة» (RSC-ZAT-01)** بأعمدته وتجميعاته ومرشّحاته: لم يُبنَ؛ المبني جدول المستندات المُصدَرة وحالة كل مستند في شاشة الإدارة.
- **منع تعديل أو إلغاء بيع صدر له مستند:** **لم يُمنع** (القرار معلَّق للمالك، القسم 6)؛ الطبقة تُبقي المستند كما صدر ولا تتبع تغيّر الدفاتر بعده، **والتصحيح الصحيح إشعار** (D7).
- **عدّاد لكل وحدة فوترة (EGS)**، **وعدّاد لكل فرع** (الحالي لكل شركة).
- **استيراد معرّفات العملاء** من ملف (يُدخَل الآن من بطاقة العميل).

## 6. قبل أي نشر وقرارات المالك

**قبل النشر:**

1. الطبقة **مرشّحة** وتحمل في الواجهة بطاقة «مرشّح» وفي كل مستند `profile = candidate`؛ **لا تُقدَّم فواتيرها للعملاء ولا للهيئة** قبل التحقق في القسم 4.
2. **المفتاح الخاص يوضع خارج شجرة التطبيق** (خارج `ROOTPATH`) بصلاحيات تقرأه حساب الخدمة وحده، ولا يُنسخ إلى المستودع ولا إلى النسخ الاحتياطية العامة.
3. **الصلاحية `acc_einvoicing_manage` لا تُمنح لأحد افتراضيًا؛** منحها قرار مسجَّل بعد مراجعة الأدوار.
4. **الإعداد مطفأ افتراضيًا.** تفعيله يحتاج المحاسبة مفعّلة **فقط** (`EInvoiceSettings::update`)؛ **بيانات البائع الناقصة لا تمنع التفعيل**، ويقول التجهيز ما ينقص، ويرفض الإصدار باسم الناقص (`seller_*_missing` وما شابه).
5. **قاعدة الترحيل:** ترحيل `20273400000000_EInvoicing` يضيف أربعة جداول ويبذر صف عدّاد لكل شركة؛ آمن إعادة التشغيل؛ و`down()` يرفض بعد إصدار أي مستند.
6. **الجداول لا تُفرَّغ ولا تُنسخ جزئيًا:** المستند المُصدَر جزء من السلسلة؛ استعادة قاعدة بيانات أقدم تكسر ICV وPIH (تُذكر في دليل الاستعادة).

**قرارات يحتاجها المالك:**

- **OD-1:** هل يُمنع **تعديل أو إلغاء أو حذف** بيع أُصدر له مستند؟ (اقتراح: يُمنع ويُصحَّح بإشعار)
- **OD-2:** أتبقى السلسلة **لكل شركة** أم **لكل وحدة فوترة** (EGS) أم **لكل فرع**؟
- **OD-3:** السيناريوهات غير المدعومة (القسم 5): أيها يسبق (شحن وتسوية، عملة أجنبية، بنود متعددة الضريبة)؟
- **OD-4:** من يُمنح الصلاحية الحساسة ومتى يُفعَّل الإعداد؟
- **OD-5:** هل تُحصَّل عينات XML الرسمية وSDK لاستكمال التحقق، ومن يفتح حساب البوابة التجريبية؟

## 7. حراس الاختبارات (تعطيل كل حارس يُكتشف)

كل حارس يُعطَّل وحده بنص يُستبدل في ملف الإنتاج، وتُشغَّل ملفات الاختبار المسمّاة، **ويُرجع الملف دائمًا** (`tools/dev/guard_mutations.py` والقائمة `docs/accounting/daftra-parity/wp46-guards.json`). المكتشَف: فشلت الاختبارات.

**174 حارسًا: 174 DETECTED** (129 من التنفيذ الأول، و45 أُضيفت في مرحلة الإصلاح، بعد تصحيح أربع طفرات قديمة تغيّر نص الكود تحتها: `S18` و`CP12` و`X02` و`X03`). أُعيد تشغيل القائمة كلها في مرحلة الإصلاح: **كل الحراس مكتشَفة، ولا طفرة `INVALID`**.

| # | الحارس | الملف | الاختبارات | النتيجة |
|---|---|---|---|---|
| B01 | only a completed invoice or return becomes a document | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B02 | a sale in a foreign currency is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B03 | a sale with shipping or an adjustment in its header is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B04 | a debit note that was taken back is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B05 | a note without an issued original is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B06 | a note without a reason is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B07 | a reason longer than the standard allows is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B08 | a sale with no line to write is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B09 | a line that comes to less than nothing is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B10 | a line that is not its quantity times its price less its discount is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B11 | a line with two taxes is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B12 | a VAT rate with more than two decimals is refused | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B13 | a line without tax needs the classification of the settings | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B14 | the tax of a category must be its taxable amount times its rate | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B15 | the total of the document must be the total of the sale | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B16 | the tax of the document must be the tax of the sale | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| B17 | the total of a debit note document must be the total of the note | `app/Libraries/EInvoicing/EInvoiceBuilder.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P01 | a company without a name is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P02 | a seller tax number that is not fifteen digits beginning and ending with 3 is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P03 | a seller without an identification is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P04 | a seller address with a missing part is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P05 | a buyer tax number that is not valid is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P06 | a tax invoice to a buyer with no name is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P07 | a tax invoice to a buyer with neither a tax number nor an identification is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P08 | a tax invoice without a customer is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P09 | a tax invoice to a buyer with an incomplete address is refused | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P10 | a Saudi buyer needs building number, postal code and district | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P11 | a tax number makes the customer a business | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P12 | a government entity is a business | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| P13 | a customer entered as a company is a business | `app/Libraries/EInvoicing/EInvoiceParties.php` | `EInvoiceBuilderTest` | **DETECTED** |
| J01 | a document for a sale with no posted journal is refused | `app/Libraries/EInvoicing/JournalTie.php` | `EInvoiceServiceTest` | **DETECTED** |
| J02 | the total of the document must be what the books put on the customer | `app/Libraries/EInvoicing/JournalTie.php` | `EInvoiceServiceTest` | **DETECTED** |
| J03 | the tax of the document must be the tax the books hold | `app/Libraries/EInvoicing/JournalTie.php` | `EInvoiceServiceTest` | **DETECTED** |
| J04 | the amount without tax must be the revenue the books hold | `app/Libraries/EInvoicing/JournalTie.php` | `EInvoiceServiceTest` | **DETECTED** |
| J05 | the lines of tax are told apart from the lines of revenue | `app/Libraries/EInvoicing/JournalTie.php` | `EInvoiceServiceTest` | **DETECTED** |
| S01 | issuing a document needs the permission | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S02 | issuing a debit note document needs the permission | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S03 | taking the file or submitting needs the permission | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S04 | nothing is issued while accounting is off | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S05 | nothing is issued while the layer is off | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S06 | a document is issued only for a branch the employee works in | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S07 | a debit note document is issued only for a branch the employee works in | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S08 | a file is given only for a branch the employee works in | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S09 | a sale is issued once | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S10 | a debit note is issued once | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S11 | the counter is locked before the next number is taken | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S12 | a document carries the hash of the document before it | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S13 | the counter goes up by one | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S14 | a document is held against the journal of its sale | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S15 | a debit note document is held against the journal of its note | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S16 | a credit note refers to the document of the invoice it takes back | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S17 | a return whose invoice was never issued is refused | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S18 | a debit note refers to an issued invoice or credit note, not to another debit note | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S19 | the counter keeps the hash of the last document | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S20 | the transport is asked and what it says is recorded | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S21 | the issue of a document is audited | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S22 | a key named without a certificate (or the reverse) is refused, never skipped | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S23 | a submission is audited | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| S24 | the documents listed are those of the branches the reader may see | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest`، `AccountingEInvoicingControllerTest` | **DETECTED** |
| E01 | changing the settings needs the permission | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E02 | changing the settings needs every branch | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E03 | the layer is turned on only while accounting is on | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E04 | the path of a key or a certificate must be outside the application | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E05 | the seller can use only the schemes the standard lets it use | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E06 | an identification number holds letters and digits only | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E07 | a building number has four digits | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E08 | a postal code has five digits | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E09 | an address field is not longer than the standard allows | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E10 | the additional number holds digits only | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E11 | the category of a line without tax is Z, E or O | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E12 | a reason is kept only with a category | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E13 | the reason code belongs to the category | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E14 | the reason text is not longer than the standard allows | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| E15 | a change of the settings is audited | `app/Libraries/EInvoicing/EInvoiceSettings.php` | `EInvoiceSettingsTest` | **DETECTED** |
| K01 | a path of a key must be absolute and have no byte of zero | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| K02 | a path inside the application directory is refused | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| K03 | a link is followed before the path is judged | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| K04 | a path that climbs out of a missing directory is refused | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| K05 | the application directory itself is refused | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| K06 | a directory whose name only begins like a protected one is allowed | `app/Libraries/EInvoicing/KeyPath.php` | `KeyPathTest` | **DETECTED** |
| C01 | the path of the key is judged | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C02 | the path of the certificate is judged | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C03 | an OpenSSL without the curve of the standard is refused | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C04 | a key on another curve is refused | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C05 | a certificate that is not the one of the key is refused | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C06 | a key that cannot be read is refused | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| C07 | a certificate that cannot be read is refused | `app/Libraries/EInvoicing/StampKeys.php` | `StampKeysTest` | **DETECTED** |
| D01 | a stamped file must have the hash that was signed | `app/Libraries/EInvoicing/DocumentStamper.php` | `DocumentStamperTest` | **DETECTED** |
| D02 | the signature of the certificate is in the code of a simplified invoice | `app/Libraries/EInvoicing/DocumentStamper.php` | `DocumentStamperTest` | **DETECTED** |
| H01 | the extensions are left out of the hash | `app/Libraries/EInvoicing/InvoiceHash.php` | `InvoiceHashTest` | **DETECTED** |
| H02 | the signature is left out of the hash | `app/Libraries/EInvoicing/InvoiceHash.php` | `InvoiceHashTest` | **DETECTED** |
| H03 | the code is left out of the hash | `app/Libraries/EInvoicing/InvoiceHash.php` | `InvoiceHashTest` | **DETECTED** |
| H04 | a file with an attribute of the xml namespace is refused | `app/Libraries/EInvoicing/InvoiceHash.php` | `InvoiceHashTest` | **DETECTED** |
| H05 | a file that is not well formed is refused | `app/Libraries/EInvoicing/InvoiceHash.php` | `InvoiceHashTest` | **DETECTED** |
| CP01 | an identification scheme must be one of the eleven | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP02 | an identification number holds letters and digits only | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP03 | a scheme needs its number | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP04 | a number needs its scheme | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP05 | a country code has two letters | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP06 | a Saudi building number has four digits | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP07 | a Saudi postal code has five digits | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP08 | the additional number holds digits only | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP09 | an address field is not longer than the standard allows | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP10 | fields that did not change are not written again | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP11 | a change is audited | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| CP12 | a customer whose fields are all emptied keeps no row | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| Q01 | a code longer than seven hundred characters is refused | `app/Libraries/Documents/SimplifiedInvoiceQr.php` | `SimplifiedInvoiceQrTest` | **DETECTED** |
| Q02 | the signature of the certificate is written when it is given | `app/Libraries/Documents/SimplifiedInvoiceQr.php` | `SimplifiedInvoiceQrTest` | **DETECTED** |
| Q03 | the hash is written as bytes when the security standard is followed to the letter | `app/Libraries/Documents/SimplifiedInvoiceQr.php` | `SimplifiedInvoiceQrTest` | **DETECTED** |
| X01 | the screens are not found while accounting is off | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| X02 | the settings form is offered to a manager of every branch only | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| X03 | the files are offered to who holds the permission only | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| X04 | the file is sent as an attachment | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| X06 | the file is refused with not found when the service refuses | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| Y01 | the electronic invoice fields are read only while the layer is on | `app/Controllers/Customers.php` | `CustomersControllerTest` | **DETECTED** |
| Y02 | a customer with identification the standard refuses is not saved | `app/Controllers/Customers.php` | `CustomersControllerTest` | **DETECTED** |
| Y03 | the identification of the form is saved with the customer | `app/Controllers/Customers.php` | `CustomersControllerTest` | **DETECTED** |
| Y04 | the section is offered only while the layer is on | `app/Controllers/Customers.php` | `CustomersControllerTest` | **DETECTED** |
| A01 | the permission is sensitive | `app/Libraries/Accounting/AccountingGuard.php` | `SensitivePermissionsTest` | **DETECTED** |
| M01 | a credit note or a debit note must refer to a document | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M02 | two documents cannot have the same counter | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M03 | a sale or a note has one document | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M04 | two documents cannot have the same identifier | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M05 | an identification scheme is one of the eleven | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M06 | an issued document cannot be deleted while another refers to it | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| M07 | the migration refuses to drop documents that were issued | `app/Database/Migrations/20273400000000_EInvoicing.php` | `EInvoicingMigrationTest` | **DETECTED** |
| N01 | the panel offers to issue only to who may | `app/Libraries/EInvoicing/EInvoicePanel.php` | `EInvoicePanelTest` | **DETECTED** |
| N02 | the panel says when the books moved | `app/Libraries/EInvoicing/EInvoicePanel.php` | `EInvoicePanelTest` | **DETECTED** |
| N03 | nothing is shown while the layer is off | `app/Libraries/EInvoicing/EInvoicePanel.php` | `EInvoicePanelTest` | **DETECTED** |

**حراس مرحلة الإصلاح بعد المراجعة** (رموزها تبدأ بـ`Z`؛ بعض طفراتها تُشغّل اختبارات مختارة بـ`--filter` لسرعة التشغيل، والقائمة الكاملة بمرشحاتها في `wp46-guards.json`):

| # | الحارس | الملف | الاختبارات | النتيجة |
|---|---|---|---|---|
| ZF01 | issuing the sale of another employee is refused to someone who sees their own records only | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF02 | issuing the debit note of another employee is refused to someone who sees their own records only | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF03 | the file of the document of another employee is refused to someone who sees their own records only | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF04 | the list of documents is cut to the records of someone who sees their own records only | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF05 | a debit note refers only to a document of a branch the employee works in | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF06 | a debit note refers only to a document out of the records the employee may see | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF07 | a debit note refers only to a document of its own customer | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZF08 | the list of documents starts at the offset of its page | `app/Libraries/EInvoicing/EInvoiceService.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZT01 | issuing never touches the payments of the sale | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZT02 | issuing never touches the stock movements of the sale | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZT03 | issuing never touches the number of the invoice | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZT04 | issuing never touches the stock books of the sale | `app/Libraries/EInvoicing/EInvoiceService.php` | `EInvoiceServiceTest` | **DETECTED** |
| ZCP13 | a write the database refused is not said to be saved | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| ZCP14 | a street is no longer than its column | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| ZCP15 | an identification number is no longer than its column | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| ZCP16 | the fields of the address are no longer than their columns | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| ZCP17 | a postal code is no longer than its column | `app/Libraries/EInvoicing/CustomerParties.php` | `CustomerPartiesTest` | **DETECTED** |
| ZE01 | the name of a field or of a figure is said in the language of the reader | `app/Libraries/EInvoicing/EInvoiceException.php` | `EInvoiceExceptionTest` | **DETECTED** |
| ZE02 | a value of a field that is not too long is left as it was typed | `app/Libraries/EInvoicing/EInvoiceException.php` | `EInvoiceExceptionTest` | **DETECTED** |
| ZE03 | the prefix of a field of the settings is read | `app/Libraries/EInvoicing/EInvoiceException.php` | `EInvoiceExceptionTest` | **DETECTED** |
| ZRD1 | a customer with an invalid tax number is listed although it has an identification | `app/Libraries/EInvoicing/EInvoiceReadiness.php` | `EInvoiceReadinessTest` | **DETECTED** |
| ZRD2 | a tax number is read trimmed, as the document reads it | `app/Libraries/EInvoicing/EInvoiceReadiness.php` | `EInvoiceReadinessTest` | **DETECTED** |
| ZCU1 | a customer whose identification was not saved is told so | `app/Controllers/Customers.php` | `CustomersControllerTest` | **DETECTED** |
| ZV01 | the paths of the key and of the certificate are not shown to who may not manage the settings | `app/Views/accounting_einvoicing/manage.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZV02 | each check says in words whether it is ready, a warning or not ready | `app/Views/accounting_einvoicing/manage.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZV03 | the detail of a check is said in words and not as a code | `app/Views/accounting_einvoicing/manage.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZV04 | a key that cannot be used is not reported as documents issued unsigned | `app/Views/accounting_einvoicing/manage.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZV05 | the form of a debit note says when no note waits | `app/Views/accounting_einvoicing/manage.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZP01 | the pager offers the next page | `app/Views/accounting_einvoicing/pager.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZP02 | the pager offers the previous page | `app/Views/accounting_einvoicing/pager.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZP03 | the pager shows only when the list does not fit one page | `app/Views/accounting_einvoicing/pager.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZP04 | an offset out of range falls back to a page of the list | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZP05 | a negative offset is read as the first page | `app/Controllers/AccountingEInvoicing.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZR01 | the status line is on the receipt | `app/Views/sales/receipt.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZR02 | the status line is on the tax invoice | `app/Views/sales/tax_invoice.php` | `AccountingEInvoicingControllerTest` | **DETECTED** |
| ZN04 | electronic invoicing is in the accounting group of the sidebar | `app/Helpers/navigation_helper.php` | `NavigationHelperTest` | **DETECTED** |
| ZC01 | a debit note that has its document is not offered again | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC02 | a debit note that was taken back is not offered | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC03 | the debit notes offered are those of the branches the employee works in | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC04 | the debit notes offered are those the employee made when they see their own records only | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC05 | the documents offered are those of the customers of the notes | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC06 | the documents offered are those of the branches the employee works in | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC07 | the documents offered are those of the records the employee may see | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC08 | a debit note is named by its number when the series is on | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |
| ZC09 | a customer that is a company is named by its company | `app/Libraries/EInvoicing/EInvoiceChoices.php` | `EInvoiceChoicesTest` | **DETECTED** |

**ما كشفته أول جولة وأُصلح:** من 130 حارسًا لم يُكتشف أربعة في الجولة الأولى: `B16` (ضريبة المستند مقابل ضريبة البيع حين يتطابق الإجمالي) و`S15` (ربط الإشعار المدين بقيده) و`CP11` (فعل التدقيق «updated» لم يكن الاختبار يتحقق منه): أُضيف لكل منها اختبار يفشل بدونه (`testFromSale_RefusesATaxTheBooksDoNotHoldEvenWhenTheTotalTies` و`testIssueDebitNote_RefusesANoteWhoseTaxTheJournalOfTheNoteDoesNotHoldAndStoresNothing` وشرط الفعل في `testSave_AuditsWhatWasReplacedAndWhatItBecame`)، **وأُعيد تشغيل الثلاثة فاكتُشفت كلها**. **ورابعها `X05` (ترويسة `nosniff` في تنزيل XML) طفرة مكافئة:** المرشح العام `secureheaders` في `app/Config/Filters.php` يضيف الترويسة نفسها إلى كل استجابة، فتعطيل سطر المتحكم لا يغيّر شيئًا يُرى، **فحُذف من القائمة** (والسطر باقٍ حتى لا يعتمد التنزيل على المرشح العام) ولا يُعدّ حارسًا مثبتًا.

**ما كشفته جولة مرحلة الإصلاح وأُصلح:** من الحراس الخمسة والأربعين الجدد لم يُكتشف واحد في الجولة الأولى: `ZP05` (قراءة الإزاحة السالبة كأول صفحة): الاختبار كان يجرّب `-5` وحده وهي تُقرَّب إلى صفر بالقسمة الصحيحة حتى بلا الحارس؛ أُضيف `-60` (يصير `-50` بلا الحارس فيفشل الاستعلام) **فاكتُشف**. **واستُبدلت حمايتان مكرّرتان لمسارَي المفتاح** (التفريغ في المتحكم وإخفاء الحقلين في القالب) بحارس واحد في القالب، لأن الطفرة المنفردة على أيٍّ منهما كانت مكافئة.

## 8. تحديثات الوثائق المشتركة للمُدمِج

> لم أعدّل `decisions.md` ولا `conformance-matrix.md` ولا `gap-report.md` ولا `backlog.md` ولا أي README ولا `docs/ui-redesign/deploy-checklist.md`. هذه اقتراحات للمُدمِج.

### 8.1 نص قرار مقترح (بلا رقم E)

> **الفوترة الإلكترونية (WP46) طبقة مستند مرشّحة لا تكاملًا.** الإعداد الرئيسي مطفأ افتراضيًا ولا يصدق إلا والمحاسبة مفعّلة، والصلاحية `acc_einvoicing_manage` حساسة وغير ممنوحة لأحد. المستند يُبنى من بيع **مرحَّل** (فاتورة أو مرتجع مكتمل أو إشعار مدين) بأرقام دفاتره نفسها عبر `SaleAmounts` و`SalePostingBuilder` ولا حساب ثانٍ، ويُرفض عند أي فرق عن القيد. المستند المُصدَر **لا يُعدَّل ولا يُحذف** وتُصحَّح فواتيره بإشعار (D7). العدّاد ICV وسلسلة PIH لكل شركة، ويُقرأ الصف بـ`SELECT ... FOR UPDATE` أولًا داخل معاملة (ولا يُنشأ إلا إن غاب). **من يرى سجلاته فقط (WP30) لا يُصدر ولا يقرأ مستند ما صنعه غيره ويُقال له ما يُقال عن غير الموجود؛ والإشعار المدين يشير إلى مستند من فرع الموظف ونطاقه ومن عميل الإشعار نفسه.** التوقيع ECDSA على secp256k1 بمفتاح **خارج شجرة التطبيق** (يُرفض داخلها) يُسمّى بمساره فقط ولا يُخزَّن. النقل واجهة بتنفيذ فارغ يسجّل «لم يُرسل»؛ **لا CSID ولا إبلاغ ولا تخليص ولا اتصال بجهة خارجية** إلى أن يقرّر المالك. كل ما في الطبقة **يُتحقق منه مقابل المواصفة الرسمية والبيئة التجريبية الرسمية قبل أي استعمال فعلي**، ولا يُدّعى به امتثال ولا اعتماد.

### 8.2 مصفوفة المطابقة (`conformance-matrix.md`)

| الصف | التحديث المقترح |
|---|---|
| `SET-10` (الفوترة الإلكترونية) | من «مفتوح» إلى **«جزئي: طبقة المستند مرشّحة»**: المبني المستند والتجزئة والـQR والتوقيع والإعدادات والتجهيز والتنزيل؛ **غير المبني** الإرسال والتخليص وCSID وتقرير الإرسال وPDF/A-3 (القسم 5). |
| `CUS-11` (جهة حكومية ونوع المعرّف) | **مبني**: الرموز الأحد عشر ورقم المعرّف والعنوان الوطني على بطاقة العميل (الجدول `customer_einvoice_parties`)، والعلَم هو `is_government_entity` القائم. التسميات من رصد دفترة (FRM-R26)، **والتحقق من الرقم الضريبي 15 رقمًا 3...3**. |
| `SAL-45` (رمز QR) | يُضاف: **المرحلة الثانية (الوسوم 6 إلى 9) مبنية مرشّحة** بتمديد `SimplifiedInvoiceQr` في المستندات الموقَّعة؛ بقيت صورة الرمز على المطبوع خارج النطاق (WP40). |
| `G-226` | **ما لم يُبنَ من WP40 والمرحلة الثانية (WP46)** يُحدَّث: المرحلة الثانية بُنيت **مرشّحة بلا إرسال**؛ بقي ملف التثبيت `T-25` وصورة الرمز. |
| `G-227` | بلا تغيير (لم يُرصد في دفترة شيء جديد). |
| `RSC-ZAT-01` | **لم يُبنَ** (تقرير الإرسال بأعمدته وتجميعاته)؛ المبني حالة المستند وجدول المستندات. يبقى مفتوحًا لما بعد الإرسال. |
| `FRM-R26` و`RP-SET-05` | **مبنيان** (الرموز الأحد عشر وتسمياتها بلغة النظام: `Accounting_einvoicing.identification_<code>`). الاختبار الفعلي `PartyIdentificationTypeTest` وليس الاسمين المقترحين في الرصد. |

### 8.3 تقرير الفجوات (`gap-report.md`)

- **فجوة جديدة (مقترحة):** عدم تحقق أي ملف من ملفات الطبقة بأدوات الهيئة (SDK وSchematron وعينات)؛ تُغلق بتحصيلها وتشغيلها.
- **فجوة جديدة:** بيع صدر له مستند **ما زال قابلًا للتعديل أو الإلغاء** (OD-1).
- **فجوة جديدة:** شحن وتسوية رأس الفاتورة والعملة الأجنبية والبنود متعددة الضريبة غير مدعومة في المستند.
- **فجوة جديدة (WP34):** قائمة الإشعارات المدينة للمبيعات (`SalesDebitNotes::search`) وعكسها **لا تطبّقان نطاق «سجلاتي فقط»** كما تطبّقه بقية عائلة المبيعات؛ طبقة المستند هنا تطبّقه على نفسها فقط ولم تُعدَّل WP34.
- `G-226`: يُحدَّث كما في 8.2.

### 8.4 قائمة النشر (`docs/ui-redesign/deploy-checklist.md`)

- شغّل ترحيل `20273400000000_EInvoicing` (يُعاد تشغيله بأمان؛ يبذر صف عدّاد لكل شركة).
- **لا تمنح `acc_einvoicing_manage` لأحد** قبل قرار المالك، **ولا تفعّل `einvoice_enabled`**.
- المفتاح الخاص والشهادة **خارج شجرة التطبيق** بمسار مطلق، وصلاحيات القراءة لحساب الخدمة وحده.
- **لا تفرّغ ولا تستعد** جداول `einvoice_*` بمعزل عن بقية القاعدة (السلسلة).
- كل فاتورة صادرة من الطبقة **مرشّحة** حتى يُتحقق منها بأدوات الهيئة.

### 8.5 ملاحظات لرصد دفترة (إن أُعيد الرصد)

- أعمدة تقرير الإرسال `RSC-ZAT-01` وكيف يُعرض إشعار دائن بإجمالي سالب، **وحالات «صالح» وغيرها** في عمود حالة الفاتورة الإلكترونية.
- تسميات رموز المعرّف: هل يُرسل `700` رقمًا أم نصًا، وهل تُستعمل `TIN` عند غياب الرقم الضريبي.
- هل يُنشئ الإشعار الدائن في دفترة مستندًا مستقلًا برقم ICV خاص أم يتبع الفاتورة.

### 8.6 الملفات المشتركة التي تعدّلها هذه الحزمة (قائمة مراجعة للدمج)

كلها **إضافات صغيرة**؛ لم أجلب فروع الحزم الأخرى فلم أتحقق من تعارض فعلي معها، والقائمة لتُراجَع عند الدمج (فروع مثل WP41 وWP47 وWP48 وWP49 قد تلمس بعضها):

| الملف | ما تغيّر فيه |
|---|---|
| `app/Config/Routes.php` | مجموعة `accounting_einvoicing` (مسارات: الصفحة والتنزيل وحفظ الإعدادات والإصدار وإصدار الإشعار المدين؛ **حُذف في مرحلة الإصلاح مسار `customers`**) |
| `app/Helpers/navigation_helper.php` | `accounting_einvoicing` في مجموعة المحاسبة بين `accounting_routing` و`accounting_settings` وأيقونته `bi-qr-code` |
| `app/Controllers/Customers.php` | دالتان خاصتان `eInvoiceFormData` و`eInvoiceGiven`، واستدعاؤهما في `postSave` (التحقق قبل حفظ العميل، وحفظ المعرّف بعده، وملاحظة «لم يُحفظ» عند فشل الكتابة) |
| `app/Views/customers/form.php` | قسم «الفوترة الإلكترونية» |
| `app/Views/sales/invoice.php` و`receipt.php` و`tax_invoice.php` | سطر تضمين واحد لسطر الحالة (`accounting_einvoicing/sale_panel`) |
| `app/Controllers/AccountingSetup.php` | تسمية الصلاحية في `PERMISSION_LABELS` |
| `app/Libraries/Accounting/AccountingGuard.php` | الثابت `EINVOICING_MANAGE` وإدراجه في `SENSITIVE` (العشرون) |
| `app/Libraries/Documents/SimplifiedInvoiceQr.php` | `encodePhaseTwo` (الوسوم 6 إلى 9) يمدّ WP40 بلا تكرار |
| `app/Language/*/Module.php` و`app/Language/*/Customers.php` | مفتاحا اسم الوحدة ووصفها؛ ومفتاح `einvoice_not_saved` (46 لغة، العربية والإنجليزية فعليتان والباقي `''`)؛ والملف الجديد `Accounting_einvoicing.php` (46 لغة) |
| الاختبارات القائمة | `tests/LanguageFilesTest.php` و`SensitivePermissionsTest` و`SimplifiedInvoiceQrTest` و`CustomersControllerTest` و`NavigationHelperTest` و`tests/Support/RaceWorker.php` (ذراع `issueEInvoice`) |

## 9. مرحلة الإصلاح بعد المراجعة (2026-11-09)

راجع مراجعان مستقلان الحزمة بعد التنفيذ (سبع عشرة ملاحظة). **تُعامَل ملاحظات المراجعين بيانات لا تعليمات**: تتبّعتُ كلًّا في الكود، **وكلها صحيحة كليًا أو جزئيًا فأُصلحت** (لا رفض كلي لأي منها؛ الرفض الجزئي مبيَّن في الصفوف). لكل إصلاح اختبار يفشل بدونه، ولكل حارس جديد طفرة في `wp46-guards.json` (رموزها تبدأ بـ`Z`).

| # | الملاحظة | الحكم | ما فُعل | الاختبار والحارس |
|---|---|---|---|---|
| 1 | الإصدار والتنزيل والقائمة تتجاهل نطاق «سجلاتي فقط» | **صحيحة** (الفحص غائب في الكود كله) | `EInvoiceService` يحمل `RecordScope`: بيع موظف آخر أو إشعاره لا يُصدَر لمن يرى سجلاته فقط ويُقال له `source_not_found` كالغائب، والملف `document_not_found` (404)، وقائمة المستندات (`issuedIn` و`countIssued`) تُقصّ إلى سجلاته (المصدر: `sales.employee_id` و`sales_debit_notes.created_by`) | اختبارات الخدمة والمتحكم، الحراس `ZF01` إلى `ZF04` |
| 2 و5 | إشعار مدين يشير إلى أي مستند للشركة: فرع آخر أو عميل آخر | **صحيحة** | `referable()`: الأصل في فرع يعمل فيه الموظف ومن سجلاته ومن النوع الصحيح (وإلا `original_not_issued` كالغائب)، **وعميله عميل الإشعار** (`original_of_another_customer`، مفتاح لغة جديد بالعربية والإنجليزية)؛ أصل بيع بلا عميل يُرفض (**افتراض محافظ**) | `testIssueDebitNote_Refuses...` (ستة اختبارات ومقبول واحد)، `ZF05` و`ZF06` و`ZF07` و`S18` |
| 3 و4 | حقول العميل أطول من أعمدة الجدول، ونتيجة الحفظ لا تُفحص | **صحيحة** (STRICT_TRANS_TABLES يرمي، وبلا تصحيح يضيع المعرّف) | `CustomerParties::LIMITS` = عرض الأعمدة (واختبار يقارنها بـ`information_schema`) ويرفض `clean()` قبل حفظ العميل؛ `save()` يرمي `WriteFailed` إن رفضت القاعدة، والمتحكم يقول إن المعرّف لم يُحفظ ويبقى الرد باسم العميل المحفوظ (بدل تكرار عميل جديد عند الإعادة). **تغيّر مثال اختبار:** الرمز البريدي الأجنبي `P.O. Box 55` (11 حرفًا) صار `SW1A 1AA`، والشارع 1000 صار 255 | `CustomerPartiesTest` و`CustomersControllerTest` (زناد قاعدة بيانات يرفض الكتابة)، `ZCP13` إلى `ZCP17` و`ZCU1` |
| 6 | النموذج يسأل «رقم الإشعار» والمتحكم يعامله معرّفًا داخليًا | **صحيحة** (شاشة الإشعارات لا تُظهر المعرّف، والرقم المنسَّق لا يُكتب في حقل رقمي) | **حقل الرقم أُلغي**: قائمتان (`EInvoiceChoices`) بالإشعارات القائمة بلا مستند باسم رقمها المنسَّق وبالأصول المرشَّحة؛ صنف جديد بملف اختبار خاص (`EInvoiceChoicesTest`) | `ZC01` إلى `ZC09` و`ZV05` |
| 7 | التجهيز يجيز عميلًا برقم ضريبي غير صالح ما دام له معرّف | **صحيحة** (المشتري يرفضه في كل نوع) | الاستعلام يعدّ الرقم غير الصالح مشكلة **ولو وُجد معرّف**، ويقصّه من الفراغات كما يفعل المستند | `EInvoiceReadinessTest` (3 اختبارات)، `ZRD1` و`ZRD2` |
| 8 | اختبار «التشغيل والإطفاء» لا يقارن الأرقام والمخزون والمجاميع | **صحيحة** | `bookOf` صار يأخذ **بصمة** القيود والرأس والبنود وضرائبها والدفعات وأرقام المستندات (**بسلسلة ترقيم مفعّلة**، منسوبةً إلى آخر رقم قبل التشغيل لأن السلسلة تتابع) وحركات `inventory` ودفتر المخزون والمجاميع، وتُعاد قراءتها بعد الإصدار | **أربع طفرات حقن** `ZT01` إلى `ZT04` (تعديل دفعة وحركة مخزون ورقم فاتورة ودفتر مخزون عند الإصدار) كلها مكتشَفة |
| 9 | لا اختبار لسطر الحالة في الفاتورة الضريبية والإيصال ولا للقائمة الجانبية ولا لحدّ نقطة العملاء | **صحيحة** | اختبارات للإيصال وللفاتورة الضريبية (بضبط `invoice_type`) وللقائمة الجانبية (`NavigationHelperTest`)؛ **حدّ نقطة العملاء غير مطلوب بعد الآن:** النقطة (`getCustomers`) **حُذفت مع مسارها** لأنها بلا مستهلك والترقيم صار في الصفحة نفسها (الإزاحة المقيَّدة مختبَرة) | `ZR01` و`ZR02` و`ZN04` و`ZP01` إلى `ZP05` و`ZF08` |
| 10 | الشاشة تعرض 50 مستندًا و25 عميلًا بلا ترقيم | **صحيحة** | ترقيم للقائمتين (القسم 1.5)، وقائمة الأصول لا تتقيد بصفحة المستندات | `testGetIndex_Pages...` (3 اختبارات) |
| 11 | قارئ الوحدة يرى مسار المفتاح والشهادة | **صحيحة** (منخفضة) | الحقلان وقيمتاهما لا يُعرضان لمن لا يدير الإعدادات؛ **حارس واحد في القالب** (الطبقتان المكرَّرتان لا تُثبتان بطفرة منفردة فحُذفت إحداهما) | `testGetIndex_ShowsThePathsOfTheKey...`، `ZV01` |
| 12 | نص التقرير قديم أو مبالَغ | **صحيحة** | القفل (القسم 1.2) وأعداد الاختبارات وشرط التفعيل (القسم 6) صُحّحت، وأُضيفت قائمة **الملفات المشتركة** (8.6) | هذا التقرير |
| 13 | كلمات إنجليزية داخل رسائل عربية | **صحيحة** | `EInvoiceException::describe` يترجم أسماء الحقول والأرقام (`street` و`journal tax`...) بمفاتيح `word_*`، **ولا يمسّ قيمة كتبها المستخدم** حتى لو طابقت كلمة؛ وتفاصيل التجهيز بمفاتيح `detail_*` | `EInvoiceExceptionTest`، `ZEX1` إلى `ZEX3` و`ZV03` و`ZV04` |
| 14 | اتفاق العدد في ملخص العملاء | **صحيحة** | الصياغة لا تحتاج اتفاقًا: «العملاء الذين يُرفض لهم المستند: {0}، والتجاريون منهم: {1}» (والإنجليزية كذلك؛ لغات أخرى بلا ترجمة فترجع إلى الإنجليزية) | `LanguageFilesTest` |
| 15 | حالة البنود في التجهيز بأيقونة مخفية فقط | **صحيحة** | نص مخفي للقارئ الشاشي (`status_pass` و`status_warn` و`status_fail`) | `testGetIndex_SaysInWords...`، `ZV02` |
| 16 | اسم صنف كامل داخل اختبار بدل `use` | **صحيحة** | استيراد `ReturnDocument` | — |
| 17 | اختبار الفرع يحمل موظفًا غير مستعمَل وتوكيدًا شكليًا | **صحيحة** | أُعيدت كتابته بشاهد إيجابي: المحدود يُرفض والموظف في الفرع يُصدَر له | `testIssueSale_RefusesASaleOfABranchTheEmployeeDoesNotWorkInAndIssuesItToSomeoneWhoDoes` |

**ما رُفض أو لم يُنفَّذ كما اقتُرح:** (أ) في 9: لم يُختبر حدّ نقطة العملاء لأن النقطة حُذفت (الترقيم بدلها). (ب) في 3: لم تُوسَّع الأعمدة لتقبل الأطوال الواسعة (ترحيل غير منشور لكن تغييره بعد الدفع أخطر من تضييق الحدّ). (ج) في 2: لم يُجعل أصل بيع بلا عميل مقبولًا لإشعار مدين لعميل مسمّى (افتراض محافظ يُراجع).

**درس تنفيذي:** شغّلتُ `php-cs-fixer` على ملفات اختبار الحزمة فأعاد تسمية دوالها (`testFoo_Bar` صارت `testFooBar`) وجعل الأصناف `final`؛ **ما يخالف تسمية `test{الطريقة}_{السلوك}`**. أُعيدت الأسماء والأصناف في الاختبارات كلها، **والمصلح يُشغَّل على ملفات التطبيق الجديدة فقط**.

