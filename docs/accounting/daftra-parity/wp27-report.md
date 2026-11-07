# تقرير WP27: مستندات المخزون (أذون الإضافة والصرف والتحويل، الجرد، وطلبات المخزون)

> الحالة (2026-11-01): **نُفِّذ على الفرع `wp/WP27`** بتفويض المالك التنفيذي الشامل (E47): ثلاثة مستندات مخزنية مرقّمة. **أذون المخزن** (إضافة، وصرف، وتحويل بين موقعين) تغيّر الأرفف وتكتب سجل الحركة، وحين تتبعها الدفاتر ترحّل حركات مقيَّمة وقيدًا من البناة القائمة نفسها (`StockLedger` و`ReceivingPostingBuilder`). و**الجرد** يجمّد كمية النظام عند البدء ويُرحَّل عند الاعتماد تسويةً بالمتوسط الحالي. و**طلبات المخزون** بين الموقعين لا ترحّل شيئًا وإتمامها يُنشئ إذن التحويل. **الإلغاء عكس لا حذف، والتعديل عكس وإعادة ترحيل (E15)**، ويُرفض كل تغيير يترك موقعًا بأقل من صفر كما في تصحيح الاستلام (WP17). يعمل ذلك **والمحاسبة مفعّلة ومطفأة** (مع الإطفاء تتحرك الأرفف ويُرقَّم المستند ولا يُرحَّل شيء). إذن التحويل والطلبات **وراء إعدادين مطفأين افتراضيًا** وهما افتراض مُوثَّق لا ادعاء تطابق. **لا كتابة في دفترة، ولا دمج في `master`، ولا نشر.** لم يُجرَ فحص المتصفح ولا تشغيل المجموعة الكاملة (انظر القسم 5).

> **مراجعة المرحلة الثالثة (2026-11-07):** راجع مراجعان مستقلان العمل فتحققتُ من ستة عشر ادعاءً وأصلحتُ كلها (القسم 8). أهمها **عيب حقيقي**: إلغاء إذن تحويل بين فرعين وتعديله كانا يفشلان حين يكون رقم الفرع المستلِم أقل من رقم المُرسِل، وجردان قائمان لموقع واحد كانا يطبّقان الفرق نفسه مرتين.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الترحيل** `20272200000000_StockDocuments` (إضافي وآمن للتكرار) | ستة جداول: `stock_permits` و`stock_permit_lines` و`stocktakes` و`stocktake_lines` و`stock_requests` و`stock_request_lines`، بقيود `CHECK` (نوع الإذن وحالته، وأن للتحويل موقعًا ثانيًا ولغيره لا موقع، وكمية البند أكبر من صفر، والكمية المعدودة غير سالبة) ومفاتيح أجنبية بـ`RESTRICT` فلا يُحذف شيء مما يشير إليه مستند. ووحدة `stock_documents` وخمس صلاحيات (`stk_permits_manage` و`stk_stocktake_manage` و`stk_stocktake_approve` و`stk_requests_manage` و`stk_requests_approve`) **لا تُمنح لأحد**. `down()` يحذف الجداول والوحدة والصلاحيات ومنحها |
| **أذون المخزن** (`StockPermit`) | إذن **إضافة** (النوع 12 في دفترة): الوحدات تدخل موقعًا بتكلفة مكتوبة وتوزن تكلفة الصنف (`Item::applyPurchaseCost`)، ومدين مخزون الموقع ودائن الحساب الآلي `2213` (أو الحساب الفرعي المختار). إذن **صرف** (13): الوحدات تخرج بمتوسط الموقع، ومدين `12042` (أو المختار) ودائن مخزون الموقع. إذن **تحويل** (15): من موقع إلى موقع بما يستحقه المُخرِج بمتوسطه (لا ربح ولا خسارة)، ومدين المستلِم ودائن المُخرِج، وبين فرعين قيد في كل فرع يلتقيان في حسابَي ما بين الفروع (`InterBranchLegs`) ويُعكسان معًا. لكل إذن: رقم (ترقيم WP8، له تسلسل لكل نوع)، وتاريخ (حتى اليوم)، وملاحظة حتى 500 حرف، وموقع (وموقعان للتحويل)، وبنود (صنف وكمية، والتكلفة في الإضافة فقط) من 1 إلى 200، ومورّد (إضافة) أو عميل (صرف) اختياري **يُكتب على المستند فقط**، وحساب فرعي اختياري، وحالة `posted`/`cancelled` ورقم مراجعة |
| **الإلغاء والتعديل** | **الإلغاء** بسبب مكتوب: عكس القيد وحركات المخزون وإعادة الأرفف والتكلفة، ويبقى الإذن وبنوده. **التعديل** يعكس ما رُحِّل ويرحّل البنود الجديدة مراجعةً تالية (الرقم باقٍ، ومعه سجل المراجعة بالبنود قبل وبعد). يُرفض الإلغاء أو التعديل الذي يترك موقعًا بأقل من صفر (صافي التغيير لا كل بند وحده)، وهي قاعدة تصحيح الاستلام (WP17) نفسها. إن كانت فترة الإذن مغلقة **يُرفض التعديل** ويُسمح بنقله إلى يوم مفتوح دون إعادة كتابة المغلق، و**يُرحَّل عكس الإلغاء بتاريخ اليوم** والقيد الأصلي يبقى حيث هو، ويُرفض إن كان اليوم نفسه في فترة مغلقة |
| **الجرد** (`Stocktake`) | مستند لموقع واحد في يوم واحد: **كمية النظام تُجمَّد لكل صنف حين يُدرج في الجرد** (عند البدء أو عند إضافته لاحقًا)، ويُدخل العدّ ويُحسب الفرق. جرد جزئي بأصناف مختارة. **جرد واحد قائم لكل موقع ويوم** (مفتاح فريد `slot` يُفرَّغ عند الإلغاء، ويُحوَّل تعارض الإدراج إلى رسالة). **الاعتماد صلاحية مستقلة** (`stk_stocktake_approve`) ويُرفض ما فيه صنف لم يُعدّ بعد، وما لا أصناف فيه. عند الاعتماد: كل فرق يُطبَّق على الرف الحالي (`فرق = معدود − مجمَّد`، فما بيع أو شُري بعد البدء يبقى محسوبًا) ويُقوَّم بالمتوسط الحالي للموقع عبر `StockAdjustment::valued` نفسه الذي تقوّم به تسويات الكمية، ويُرحَّل **قيد واحد** بتاريخ يوم الاعتماد: مدين مخزون ودائن «جرد وارد» (`511`) للزيادة، ومدين «جرد منصرف» (`511`) ودائن مخزون للنقص. الإلغاء قبل الاعتماد بلا أثر، وبعده عكس القيد والحركات (يحتاج صلاحية الاعتماد) ويُرفض إن كسر حركة لاحقة. ورقة عدّ للطباعة (`stocktakes/sheet` و`print`) بكمية النظام المجمَّدة وعمود فارغ للعدّ. **بعد المراجعة:** الصنف على جرد قائم واحد لموقعه في وقت واحد (فلا يُطبَّق فرقه مرتين)، وكل عدّ وإدراج وحذف يُكتب في سجل التدقيق، والاعتماد يحمل **بصمة البنود** التي راجعها المعتمِد فيُرفض إن تغيّرت، وحفظ الورقة لا يمسح عدّ شخص آخر (كل صف يحمل ما عُرض عنه) |
| **طلبات المخزون** (`StockRequest`) | موقع يطلب من موقع آخر: `requested` ← `approved` ← `fulfilled` أو `cancelled`. **لا ترحيل ولا حركة.** يُنشئ الطلب من يعمل في فرع الموقع الطالب، ويعتمده من يعمل في فرع الموقع المانح (`stk_requests_approve`). **الإتمام يُنشئ إذن التحويل** (اليوم، `request_id`) بكل قواعده وفحوصه، في معاملة واحدة تقفل الأصناف أولًا، ويحتاج حامله صلاحيتَي اعتماد الطلبات وإدارة الأذون وصلاحية الأصناف للموقعين. الإلغاء وهو `requested` أو `approved`، وما أُتمّ يبقى كذلك وإلغاء تحويله من إذنه |
| **الإعدادان** (`StockDocumentSettings`) | `stock_transfer_permit_enabled` و`stock_requests_enabled`، **الاثنان مطفآن افتراضيًا**. تغييرهما لمن يملك صلاحية إعدادات المحاسبة ويُكتب في سجل التدقيق بقيمته قبل. أذونا الإضافة والصرف والجرد لا يحتاجان إعدادًا. الطلبات لا تُتمّ والتحويل مطفأ |
| **مكتبات مساندة** | `StockPermitBuilder` (بناء مسودة القيد وحركاتها للأذون الثلاثة من البناة القائمة)، `StockShelves` (قفل الأصناف بترتيب `item_id`، وصافي التغيير لكل (صنف، موقع)، ورفض ما يهبط بالرف دون صفر، وسجل الحركة)، `StockAccess` (صلاحية الأصناف للموقع وفرعه، وللتحويل بين فرعين الاثنان)، و`StockPermitType` و`StockPermitLine` و`StockPermitDocument` و`StockPermitSources` و`StockPermissions` |
| **الشاشات** (`/stock_documents`) | ثلاث تبويبات: الأذون (`StockPermits`) والجرد (`Stocktakes`) والطلبات (`StockRequests`) بالبحث والترشيح بالنوع والحالة والتاريخ والترقيم، وبنموذج لكل مستند، وعرض بقيوده ومسار التدقيق، ونموذج الإلغاء بالسبب، وإعدادات تُعرض لمن يملك صلاحيتها. **كل مسار يغيّر حالة POST** (14 مسارًا) بحماية CSRF. تعمل الوحدة والمحاسبة مطفأة. **عمودا «المتاح حاليًا» و«الرصيد بعد الحركة»** في بنود الإذن (شكل عمودَي «رصيد المخزن قبل/بعد» المرصودين، `INV-10`) يُقرآن من بحث الأصناف بموقع يملك الموظف صلاحيته فقط (لا رقم لموقع لا صلاحية له عليه)، و«الرصيد بعد الحركة» في الإذن الجديد وحده لا في تعديل إذن مرحَّل |
| **اللغة والترقيم** | `Stock_documents.php` جديد في كل اللغات (عربي مصري ولبناني حقيقيان، وإنجليزيتان، والباقي `''` فتسقط إلى الإنجليزية) ومفاتيح في `Accounting.php` (الإجراءات والكيانات والأخطاء ومصادر القيد) و`Numbering.php` و`Module.php`. خمسة أنواع ترقيم جديدة في `DocumentType` بست خانات: `permit_in` و`permit_out` و`permit_transfer` و`stocktake` و`stock_request`، وتسمية مؤقتة قبل تفعيل التسلسل (`STK-IN` و`STK-OUT` و`STK-TRF` و`STKT` و`STK-REQ` مع المعرّف) |
| **تعديلات صغيرة على ملفات مشتركة** | `Routes.php` (مجموعة `stock_documents`)، و`navigation_helper.php` (الوحدة تحت «المخزون»)، و`AccountingError` (حالات الخطأ الجديدة)، و`JournalService` (مصادر القيود الآلية الأربعة التي لا تُعكس يدويًا: `stock_permit` و`stock_permit_transfer` و`stock_permit_transfer_receipt` و`stocktake`)، و`JournalDraft::withSource`، و`StockAdjustment::valued` (صارت عامة، و`post()` يستعملها فلا يتغير سلوك تسوية الكمية القائمة) |
| **ما لم يتغير** | التحويل القديم عبر استلام بوضع `requisition` وتسويات الكمية في شاشة الصنف تعمل بلا تغيير وبقيودها نفسها (اختبار يثبت ذلك). المحاسبة المطفأة = OSPOS كما هو (D1): لا ترحيل ولا مصادر قيود ولا تغيير في شاشة قائمة |

## 2. الأدلة

كل ملف شُغِّل وحده على قاعدة الاختبار `ospos_test_wp27`: `MYSQL_DB_NAME=ospos_test_wp27 vendor/bin/phpunit --no-coverage <الملف>`، بعد آخر تعديل على الكود (2026-11-01). **كلها نجحت: 4420 اختبارًا و18038 تأكيدًا في 39 ملفًا.**

**ملفات الحزمة الجديدة** (225 اختبارًا جديدًا في اثني عشر ملفًا، ملف واحد لكل صنف تحت الاختبار):

| الملف | الاختبارات | التأكيدات | النتيجة |
|---|---|---|---|
| `tests/Libraries/Stock/StockAccessTest.php` | 5 | 37 | نجح |
| `tests/Libraries/Stock/StockDocumentSettingsTest.php` | 5 | 46 | نجح |
| `tests/Libraries/Stock/StockPermitBuilderTest.php` | 12 | 105 | نجح |
| `tests/Libraries/Stock/StockPermitTest.php` | 66 | 685 | نجح |
| `tests/Libraries/Stock/StockPermitTypeTest.php` | 4 | 12 | نجح |
| `tests/Libraries/Stock/StockRequestTest.php` | 19 | 220 | نجح |
| `tests/Libraries/Stock/StockShelvesTest.php` | 12 | 93 | نجح |
| `tests/Libraries/Stock/StocktakeTest.php` | 44 | 449 | نجح |
| `tests/Controllers/StockPermitsControllerTest.php` | 21 | 310 | نجح |
| `tests/Controllers/StocktakesControllerTest.php` | 15 | 198 | نجح |
| `tests/Controllers/StockRequestsControllerTest.php` | 12 | 165 | نجح |
| `tests/Database/StockDocumentsMigrationTest.php` | 10 | 44 | نجح |

**ملفات موجودة عُدِّلت** (الأنواع الخمسة الجديدة في `DocumentType`، وحالات الخطأ والمفاتيح، و`StockAdjustment::valued`):

| الملف | الاختبارات | التأكيدات | النتيجة |
|---|---|---|---|
| `tests/Libraries/Accounting/StockAdjustmentTest.php` | 18 | 171 | نجح |
| `tests/Libraries/Numbering/DocumentTypeTest.php` | 10 | 111 | نجح |
| `tests/LanguageFilesTest.php` | 2450 | 5760 | نجح |

**انحدار على ما يلمسه الكود المشترك** (المصادر والقيود والاستلام والتحويل والمخزون والتنقل والصلاحيات والترقيم والإعدادات):

| الملف | الاختبارات | التأكيدات | النتيجة |
|---|---|---|---|
| `tests/Libraries/Accounting/JournalServiceTest.php` | 164 | 823 | نجح |
| `tests/Libraries/Accounting/StockLedgerTest.php` | 19 | 107 | نجح |
| `tests/Libraries/Accounting/InterBranchLegsTest.php` | 7 | 14 | نجح |
| `tests/Libraries/Accounting/ReceivingPostingBuilderTest.php` | 41 | 89 | نجح |
| `tests/Libraries/Accounting/ReceivingPostingTest.php` | 289 | 2721 | نجح |
| `tests/Libraries/Accounting/OpeningStockTest.php` | 78 | 826 | نجح |
| `tests/Libraries/Accounting/AccountingMessageTest.php` | 376 | 376 | نجح |
| `tests/Libraries/Accounting/Readiness/LedgerChecksTest.php` | 43 | 79 | نجح |
| `tests/Libraries/Accounting/Readiness/TieChecksTest.php` | 34 | 161 | نجح |
| `tests/Libraries/Numbering/DocumentNumberingTest.php` | 90 | 422 | نجح |
| `tests/Libraries/Roles/PermissionCatalogTest.php` | 6 | 76 | نجح |
| `tests/Models/EmployeeTest.php` | 33 | 341 | نجح |
| `tests/Models/ReceivingTest.php` | 75 | 654 | نجح |
| `tests/Models/ItemQuantityTest.php` | 4 | 13 | نجح |
| `tests/Models/InventoryTest.php` | 4 | 4 | نجح |
| `tests/helpers/NavigationHelperTest.php` | 16 | 22 | نجح |
| `tests/Controllers/AccountingJournalsControllerTest.php` | 124 | 634 | نجح |
| `tests/Controllers/ConfigTest.php` | 40 | 160 | نجح |
| `tests/Controllers/RolesControllerTest.php` | 20 | 122 | نجح |
| `tests/Controllers/ReceivingsControllerTest.php` | 221 | 1812 | نجح |
| `tests/Database/AccountingModulesMigrationTest.php` | 8 | 41 | نجح |
| `tests/Database/DocumentSequencesMigrationTest.php` | 12 | 70 | نجح |
| `tests/Database/RolesPermissionsMigrationTest.php` | 4 | 18 | نجح |
| `tests/Database/BranchesFoundationMigrationTest.php` | 9 | 47 | نجح |

لم تُشغَّل المجموعة الكاملة للاختبارات (القيد: قاعدة اختبار واحدة وحاسوب مشترك وتشغيل الملفات واحدًا بعد آخر).

**ما يثبته الاختبار (من أسماء الاختبارات في الملفات أعلاه):** أثر كل إذن في الأرفف والقيد وسجل الحركة وحساب الموقع الفرعي (`StockLedger::reconcile` عبر `assertBooksTie`)؛ إضافة بتكلفة صفر تُنزل المتوسط ولا ترحّل قيدًا بعد (كما رُصد في D2)؛ صرف بالمتوسط وتحويل بقيمة المُخرِج؛ التحويل بين فرعين بقيد في كل فرع يلتقيان في حسابَي ما بين الفروع وعكسهما معًا وبترتيب أقفال الفترات بحسب رقم الفرع مهما كان اتجاه المخزون؛ رفض الصرف والتحويل بأكثر من الرف (ولو والمحاسبة مطفأة) دون أثر؛ الإلغاء يعيد الأرفف والتكلفة؛ إلغاء إضافة وُحداتها مباعة **يُرفض**؛ التعديل عكس وإعادة ترحيل مراجعةً تالية وسجل تدقيق بالبنود قبل وبعد؛ فترة مغلقة (رفض التعديل، وعكس الإلغاء بتاريخ اليوم، ورفض الكل إن أُغلق اليوم)؛ الفروع (E45) في الإنشاء والإلغاء والبحث وعرض إذن التحويل للفرعين؛ سباق عمليتين على الرف نفسه (`raceTwoProcesses`) وإلغاءين معًا يعكسان مرة واحدة؛ ترتيب الأقفال بقراءة الاستعلامات (الأصناف تُقفل قبل أي قراءة تتبعها) وفحص القراءة بعد القفل (إن غيّر طلب آخر أصناف الإذن بين القراءة والقفل طُلب إعادة المحاولة)؛ لا عكس يدوي لقيد إذن (يُترك لإلغائه)؛ الإذن قبل تاريخ بدء الدفاتر يحرّك الأرفف ولا يرحّل؛ الجرد: التجميد والفرق على الرف الحالي والقيد الواحد والجرد الجزئي ومنع جردين لموقع ويوم والاعتماد بصلاحية مستقلة والإلغاء قبل الاعتماد وبعده ورفضه إن كسر حركة لاحقة؛ الطلبات: الحالات والفروع والإتمام بإذن التحويل وحده (لا يُنشأ إذن عند أي رفض) ولا ترحيل قبله؛ الشاشات: لا ثغرة صلاحية ولا فرع ولا إعدادات، وكل تغيير POST بحماية CSRF (المسارات تُقرأ من `Routes.php`)، وتعمل والمحاسبة مطفأة.

## 3. الحراس

أُضيف هنا كل حارس في الكود وأُثبت بـ`tools/dev/guard_mutations.py`: يُعطَّل الحارس (أو تُعكس شرطيته) ويُشغَّل الاختبار المسمّى، فإن لم يفشل فالحارس غير مثبت. قائمة التعطيلات في `wp27-guards.json` (197 تعطيلًا). **النتيجة: 197 من 197 كُشفت**

**العائلات:** `P` أذون المخزن (54، `StockPermit`)، و`S` الأرفف (10)، و`A` صلاحية الأصناف والفرع (4)، و`C` الإعدادان (5)، و`T` الجرد (51)، و`R` الطلبات (26)، و`B` بناء القيد (11)، و`J` مصادر القيود الآلية (3 في `JournalService`)، و`D` أنواع الترقيم (3)، و`K` تقويم تسوية الكمية (2)، و`Q` الشاشات (28: الصلاحية والفرع والإعدادات و«غير موجود» لمستند فرع آخر).

**التشغيل الأول** (198 تعطيلًا) كشف 175 ولم يكشف 23: حراس لا يثبتها اختبار. عولجت كلها: أُضيفت اختبارات (سقف 200 بندًا وسقف الكمية، وسبب الإلغاء حين لا ترحيل، وأن الإضافة لا تُرفض لرف تحت الصفر، وقراءة الرف تحت قفل عند الحكم، وصلاحية إدراج صنف وإزالته وإدخال العدّ في الجرد، وأن اليوم قبل بدء الدفاتر لا يُرحَّل، وفترة اعتماد مغلقة لجرد لا قيمة له، وسجل التدقيق لبدء الجرد وإلغائه، وسقف 5000 صنف، واعتماد الطلب والإعداد مطفأ، وإذن صرف لا قيمة له بلا قيد، وجواب «غير موجود» لمستند فرع آخر في المسارات الستة المتبقية)، وحُذف إدخال ظنّنتُه ميتًا (`StockPermitSources::TRANSFER_RECEIPT` في `JournalService::TRANSFER_SOURCES`) فسقط تعطيله، **وكان ذلك خطأ**: كشفت المراجعة أن `reverseAutomatic` يرفض قيد الاستلام الذي مصدره ليس في القوائم، وهو الأول في الترتيب حين يكون رقم فرعه أقل (الاقتران لا يحميه في هذا المسار). أُعيد الإدخال وأُضيف اختبار الإلغاء والتعديل لتحويل من الفرع الأعلى إلى الأدنى فعاد الحارس `J04` مثبتًا (القسم 8). وأُعيد تعريف تعطيل `S05` لأن القفل في نص الاستعلام لا في `LockedRead`. ثم أُعيد تشغيل تلك التعطيلات و`J01` إلى `J03` بعد تعديل `JournalService`، فكُشفت كلها.

| # | الحارس (اسمه في `wp27-guards.json`) | الملف الذي يحرسه | الاختبار الذي يكشفه | النتيجة |
|---|---|---|---|---|
| P01 | create requires the permission to manage permits | `StockPermit.php` | `StockPermitTest` | كُشف |
| P02 | update requires the permission to manage permits | `StockPermit.php` | `StockPermitTest` | كُشف |
| P03 | cancel requires the permission to manage permits | `StockPermit.php` | `StockPermitTest` | كُشف |
| P04 | the transfer permit works only while its setting is on | `StockPermit.php` | `StockPermitTest` | كُشف |
| P05 | a permit is not dated after today | `StockPermit.php` | `StockPermitTest` | كُشف |
| P06 | the employee needs the items permission and the branch of the location | `StockPermit.php` | `StockPermitTest` | كُشف |
| P07 | the employee needs the items permission and the branch of the destination too | `StockPermit.php` | `StockPermitTest` | كُشف |
| P08 | a transfer names two different locations | `StockPermit.php` | `StockPermitTest` | كُشف |
| P09 | only a transfer names a second location | `StockPermit.php` | `StockPermitTest` | كُشف |
| P10 | a supplier only on an add and a customer only on an issue | `StockPermit.php` | `StockPermitTest` | كُشف |
| P11 | the party must exist | `StockPermit.php` | `StockPermitTest` | كُشف |
| P12 | the account of a permit must be one the permit may use | `StockPermit.php` | `StockPermitTest` | كُشف |
| P13 | the note is at most 500 characters | `StockPermit.php` | `StockPermitTest` | كُشف |
| P14 | a permit has at least one line | `StockPermit.php` | `StockPermitTest` | كُشف |
| P15 | a permit has at most 200 lines | `StockPermit.php` | `StockPermitTest` | كُشف |
| P16 | a quantity is more than zero | `StockPermit.php` | `StockPermitTest` | كُشف |
| P17 | a quantity fits the column | `StockPermit.php` | `StockPermitTest` | كُشف |
| P18 | the cost is a number of zero or more | `StockPermit.php` | `StockPermitTest` | كُشف |
| P19 | a line is an item that keeps stock | `StockPermit.php` | `StockPermitTest` | كُشف |
| P20 | saving a permit never leaves a location below zero | `StockPermit.php` | `StockPermitTest` | كُشف |
| P21 | the period of the permit must be open in every branch | `StockPermit.php` | `StockPermitTest` | كُشف |
| P22 | nothing is posted while accounting is off | `StockPermit.php` | `StockPermitTest` | كُشف |
| P23 | nothing is posted for a day before the books start | `StockPermit.php` | `StockPermitTest` | كُشف |
| P24 | an add permit brings the cost of the item to the weighted average | `StockPermit.php` | `StockPermitTest` | كُشف |
| P25 | taking back an add permit takes its units out of the cost | `StockPermit.php` | `StockPermitTest` | كُشف |
| P26 | a cancellation never leaves a location below zero | `StockPermit.php` | `StockPermitTest` | كُشف |
| P27 | a cancellation needs the items permission of the locations | `StockPermit.php` | `StockPermitTest` | كُشف |
| P28 | taking back reverses the journals of the permit | `StockPermit.php` | `StockPermitTest` | كُشف |
| P29 | taking back reverses the stock movements of the permit | `StockPermit.php` | `StockPermitTest` | كُشف |
| P30 | taking back puts the shelves back | `StockPermit.php` | `StockPermitTest` | كُشف |
| P31 | a correction never leaves a location below zero by its net change | `StockPermit.php` | `StockPermitTest` | كُشف |
| P32 | a permit that is cancelled does not stand | `StockPermit.php` | `StockPermitTest` | كُشف |
| P33 | a cancellation has a reason | `StockPermit.php` | `StockPermitTest` | كُشف |
| P34 | the journals of a transfer between branches are recorded as a pair | `StockPermit.php` | `StockPermitTest` | كُشف |
| P35 | the journals of a transfer between branches are written in the order of the branch ids | `StockPermit.php` | `StockPermitTest` | كُشف |
| P36 | the items are locked first when a permit is saved | `StockPermit.php` | `StockPermitTest` | كُشف |
| P37 | the items are locked first when a permit is corrected | `StockPermit.php` | `StockPermitTest` | كُشف |
| P38 | the items are locked first when a permit is cancelled | `StockPermit.php` | `StockPermitTest` | كُشف |
| P39 | a correction prepared on stale items is asked to be made again | `StockPermit.php` | `StockPermitTest` | كُشف |
| P40 | a cancellation prepared on stale items is asked to be made again | `StockPermit.php` | `StockPermitTest` | كُشف |
| P41 | the check of the items locked compares what the document holds with what was locked | `StockPermit.php` | `StockPermitTest` | كُشف |
| P42 | a person limited to branches sees only the permits of those branches | `StockPermit.php` | `StockPermitTest` | كُشف |
| P43 | a refused posting is written to the failure log | `StockPermit.php` | `StockPermitTest` | كُشف |
| P44 | creating a permit is written to the audit log | `StockPermit.php` | `StockPermitTest` | كُشف |
| P45 | correcting a permit is written to the audit log | `StockPermit.php` | `StockPermitTest` | كُشف |
| P46 | cancelling a permit is written to the audit log | `StockPermit.php` | `StockPermitTest` | كُشف |
| P47 | a permit takes the number of its kind | `StockPermit.php` | `StockPermitTest` | كُشف |
| P48 | whether the books followed a permit is kept on it | `StockPermit.php` | `StockPermitTest` | كُشف |
| P49 | an issue permit keeps what its units left at | `StockPermit.php` | `StockPermitTest` | كُشف |
| P50 | saving a permit moves the shelves | `StockPermit.php` | `StockPermitTest` | كُشف |
| P51 | a journal already reversed with its partner is not reversed again | `StockPermit.php` | `StockPermitTest` | كُشف |
| P52 | a reversal in a closed period is made on the first open day | `StockPermit.php` | `StockPermitTest` | كُشف |
| P53 | an edit takes back before it posts the corrected lines | `StockPermit.php` | `StockPermitTest` | كُشف |
| P54 | an edit raises the revision | `StockPermit.php` | `StockPermitTest` | كُشف |
| S01 | changes of one item and location are added together | `StockShelves.php` | `StockShelvesTest`، `StockPermitTest` | كُشف |
| S02 | a change that cancels out is dropped | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S03 | only a change that takes units out is checked | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S04 | a location is left with nothing, never less | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S05 | the shelf is read under a lock when it is judged | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S06 | every change of the shelves is written in the inventory record | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S07 | the shelves change by the net change | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S08 | the items are locked in the order of their ids | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S09 | the items are locked for update | `StockShelves.php` | `StockShelvesTest` | كُشف |
| S10 | an item is locked once | `StockShelves.php` | `StockShelvesTest` | كُشف |
| A01 | the employee must work in the branch of the location | `StockAccess.php` | `StockAccessTest` | كُشف |
| A02 | the location must have an items permission | `StockAccess.php` | `StockAccessTest` | كُشف |
| A03 | the employee must hold the items permission of the location | `StockAccess.php` | `StockAccessTest` | كُشف |
| A04 | a location that is deleted is not one | `StockAccess.php` | `StockAccessTest` | كُشف |
| C01 | the settings need the permission to change the settings | `StockDocumentSettings.php` | `StockDocumentSettingsTest` | كُشف |
| C02 | a change of the settings is written to the audit log | `StockDocumentSettings.php` | `StockDocumentSettingsTest` | كُشف |
| C03 | nothing is written when nothing changes | `StockDocumentSettings.php` | `StockDocumentSettingsTest` | كُشف |
| C04 | both settings are off until turned on | `StockDocumentSettings.php` | `StockDocumentSettingsTest` | كُشف |
| C05 | the requests are off until turned on | `StockDocumentSettings.php` | `StockDocumentSettingsTest` | كُشف |
| T01 | starting a stocktake requires the permission to manage stocktakes | `Stocktake.php` | `StocktakeTest` | كُشف |
| T02 | listing more items requires the permission to manage stocktakes | `Stocktake.php` | `StocktakeTest`، `StocktakesControllerTest` | كُشف |
| T03 | taking an item off requires the permission to manage stocktakes | `Stocktake.php` | `StocktakeTest`، `StocktakesControllerTest` | كُشف |
| T04 | entering counts requires the permission to manage stocktakes | `Stocktake.php` | `StocktakeTest`، `StocktakesControllerTest` | كُشف |
| T05 | approving requires the permission to approve | `Stocktake.php` | `StocktakeTest` | كُشف |
| T06 | cancelling an approved stocktake requires the permission to approve | `Stocktake.php` | `StocktakeTest` | كُشف |
| T07 | starting a stocktake needs the items permission and the branch of the location | `Stocktake.php` | `StocktakeTest` | كُشف |
| T08 | a second stocktake of a location and a day is told so in words | `Stocktake.php` | `StocktakeTest` | كُشف |
| T09 | a stocktake that could not be written is not carried on with | `Stocktake.php` | `StocktakeTest` | كُشف |
| T10 | a stocktake keeps its slot of location and day | `Stocktake.php` | `StocktakeTest` | كُشف |
| T11 | a listed item freezes what the system holds | `Stocktake.php` | `StocktakeTest` | كُشف |
| T12 | a counted quantity is a number of zero or more | `Stocktake.php` | `StocktakeTest` | كُشف |
| T13 | a count is for an item on the sheet | `Stocktake.php` | `StocktakeTest` | كُشف |
| T14 | every listed item is counted before approving | `Stocktake.php` | `StocktakeTest` | كُشف |
| T15 | a stocktake with no items has nothing to approve | `Stocktake.php` | `StocktakeTest` | كُشف |
| T16 | the items are locked first when a stocktake is approved | `Stocktake.php` | `StocktakeTest` | كُشف |
| T17 | the items are locked first when a stocktake is cancelled | `Stocktake.php` | `StocktakeTest` | كُشف |
| T18 | an approval prepared on stale items is asked to be made again | `Stocktake.php` | `StocktakeTest` | كُشف |
| T19 | a cancellation prepared on stale items is asked to be made again | `Stocktake.php` | `StocktakeTest` | كُشف |
| T20 | an approval never leaves a location below zero | `Stocktake.php` | `StocktakeTest` | كُشف |
| T21 | a cancellation never leaves a location below zero | `Stocktake.php` | `StocktakeTest` | كُشف |
| T22 | nothing is posted while accounting is off | `Stocktake.php` | `StocktakeTest` | كُشف |
| T23 | nothing is posted for a day before the books start | `Stocktake.php` | `StocktakeTest` | كُشف |
| T24 | the period of the approval must be open | `Stocktake.php` | `StocktakeTest` | كُشف |
| T25 | an approval moves the shelves by the difference | `Stocktake.php` | `StocktakeTest` | كُشف |
| T26 | a cancellation reverses the journal | `Stocktake.php` | `StocktakeTest` | كُشف |
| T27 | a cancellation reverses the stock movements | `Stocktake.php` | `StocktakeTest` | كُشف |
| T28 | a cancellation puts the shelves back | `Stocktake.php` | `StocktakeTest` | كُشف |
| T29 | a reversal in a closed period is made on the first open day | `Stocktake.php` | `StocktakeTest` | كُشف |
| T30 | a stocktake that is cancelled does not stand | `Stocktake.php` | `StocktakeTest` | كُشف |
| T31 | a count is changed only while it is being counted | `Stocktake.php` | `StocktakeTest` | كُشف |
| T32 | a count is changed only by who may change the stock of its location | `Stocktake.php` | `StocktakeTest` | كُشف |
| T33 | a cancellation needs the items permission of the location | `Stocktake.php` | `StocktakeTest` | كُشف |
| T34 | a cancelled stocktake frees the day of its location | `Stocktake.php` | `StocktakeTest` | كُشف |
| T35 | starting a stocktake is written to the audit log | `Stocktake.php` | `StocktakeTest` | كُشف |
| T36 | approving a stocktake is written to the audit log | `Stocktake.php` | `StocktakeTest`، `StocktakesControllerTest` | كُشف |
| T37 | cancelling a stocktake is written to the audit log | `Stocktake.php` | `StocktakeTest` | كُشف |
| T38 | a stocktake takes the number of its kind | `Stocktake.php` | `StocktakeTest` | كُشف |
| T39 | whether the books followed an approval is kept | `Stocktake.php` | `StocktakeTest` | كُشف |
| T40 | what a unit was worth is kept on the line | `Stocktake.php` | `StocktakeTest` | كُشف |
| T41 | the difference is kept on the line | `Stocktake.php` | `StocktakeTest` | كُشف |
| T42 | a person limited to branches sees only the stocktakes of those branches | `Stocktake.php` | `StocktakeTest` | كُشف |
| T43 | a refused approval is written to the failure log | `Stocktake.php` | `StocktakeTest` | كُشف |
| T44 | what was found over is added to the gains | `Stocktake.php` | `StocktakeTest` | كُشف |
| T45 | what was found short is added to the losses | `Stocktake.php` | `StocktakeTest` | كُشف |
| T46 | counting every item lists only items that keep stock | `Stocktake.php` | `StocktakeTest` | كُشف |
| T47 | the items listed keep stock | `Stocktake.php` | `StocktakeTest` | كُشف |
| T48 | a stocktake is not dated after today | `Stocktake.php` | `StocktakeTest` | كُشف |
| T49 | the note of a stocktake is at most 500 characters | `Stocktake.php` | `StocktakeTest` | كُشف |
| T50 | a cancellation has a reason | `Stocktake.php` | `StocktakeTest` | كُشف |
| T51 | a stocktake lists at most 5000 items | `Stocktake.php` | `StocktakeTest` | كُشف |
| R01 | asking requires the permission to manage requests | `StockRequest.php` | `StockRequestTest` | كُشف |
| R02 | approving requires the permission to approve | `StockRequest.php` | `StockRequestTest` | كُشف |
| R03 | fulfilling requires the permission to approve | `StockRequest.php` | `StockRequestTest` | كُشف |
| R04 | cancelling requires a permission of requests | `StockRequest.php` | `StockRequestTest` | كُشف |
| R05 | asking works only while the setting is on | `StockRequest.php` | `StockRequestTest` | كُشف |
| R06 | approving works only while the setting is on | `StockRequest.php` | `StockRequestTest` | كُشف |
| R07 | fulfilling works only while the setting is on | `StockRequest.php` | `StockRequestTest` | كُشف |
| R08 | a request goes from one location to another | `StockRequest.php` | `StockRequestTest` | كُشف |
| R09 | asking needs the branch of the location that asks | `StockRequest.php` | `StockRequestTest` | كُشف |
| R10 | approving needs the branch of the location that gives | `StockRequest.php` | `StockRequestTest` | كُشف |
| R11 | cancelling needs a branch of the request | `StockRequest.php` | `StockRequestTest` | كُشف |
| R12 | a step is taken only from the state it needs | `StockRequest.php` | `StockRequestTest` | كُشف |
| R13 | only a request that was made or approved is cancelled | `StockRequest.php` | `StockRequestTest` | كُشف |
| R14 | the items are locked first when a request is fulfilled | `StockRequest.php` | `StockRequestTest` | كُشف |
| R15 | asking is written to the audit log | `StockRequest.php` | `StockRequestTest` | كُشف |
| R16 | approving is written to the audit log | `StockRequest.php` | `StockRequestTest` | كُشف |
| R17 | fulfilling is written to the audit log | `StockRequest.php` | `StockRequestTest` | كُشف |
| R18 | cancelling is written to the audit log | `StockRequest.php` | `StockRequestTest` | كُشف |
| R19 | a request takes the number of its kind | `StockRequest.php` | `StockRequestTest` | كُشف |
| R20 | a fulfilled request keeps its transfer permit | `StockRequest.php` | `StockRequestTest` | كُشف |
| R21 | a quantity is more than zero | `StockRequest.php` | `StockRequestTest` | كُشف |
| R22 | a line is an item that keeps stock | `StockRequest.php` | `StockRequestTest` | كُشف |
| R23 | a request has from one to 200 lines | `StockRequest.php` | `StockRequestTest` | كُشف |
| R24 | a person limited to branches sees the requests of the branch that asks and of the branch that gives | `StockRequest.php` | `StockRequestTest` | كُشف |
| R25 | a request is not dated after today | `StockRequest.php` | `StockRequestTest` | كُشف |
| R26 | a cancellation has a reason | `StockRequest.php` | `StockRequestTest` | كُشف |
| B01 | an add permit credits the account chosen | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B02 | an issue permit debits the account chosen | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B03 | an add permit rounds the value of a line | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B04 | an add permit worth nothing has no journal | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B05 | an issue permit worth nothing has no journal | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B06 | an issue permit takes the units out at what a unit is worth where they leave | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B07 | a transfer names the location the stock goes to | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B08 | the sending journal of a transfer carries the source of the permit | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B09 | the receipt of a transfer carries the receipt source of the permit | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B10 | the description of a leg names the other branch | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| B11 | a transfer between branches names the branch it goes to | `StockPermitBuilder.php` | `StockPermitBuilderTest` | كُشف |
| J01 | the journal of a permit is an automatic one of a document | `JournalService.php` | `StockPermitTest` | كُشف |
| J02 | the journal of a stocktake is an automatic one of a document | `JournalService.php` | `StocktakeTest` | كُشف |
| J03 | the sending journal of a transfer permit is that of a transfer | `JournalService.php` | `StockPermitTest` | كُشف |
| D01 | the three permits share one table | `DocumentType.php` | `DocumentTypeTest` | كُشف |
| D02 | the stock documents are numbered with six digits | `DocumentType.php` | `DocumentTypeTest` | كُشف |
| D03 | a stock permit shows its kind and id until it has a number | `DocumentType.php` | `DocumentTypeTest` | كُشف |
| K01 | a change of quantity is valued with the sign of the change | `StockAdjustment.php` | `StockAdjustmentTest` | كُشف |
| K02 | what is pending is not counted as stock the movements miss | `StockAdjustment.php` | `StockAdjustmentTest` | كُشف |
| Q01 | the lookup of items offers only items that keep stock | `StockDocumentController.php` | `StockPermitsControllerTest` | كُشف |
| Q02 | a row left blank on a form is no line | `StockDocumentController.php` | `StockPermitsControllerTest` | كُشف |
| Q03 | the forms of permits need the permission to manage permits | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q04 | the transfer form needs the setting | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q05 | the form to correct a permit needs the permission and a permit that stands | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q06 | the form to cancel a permit needs the permission and a permit that stands | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q07 | a permit is shown only to the branches it belongs to | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q08 | correcting a permit is asked of a permit the employee may see | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q09 | cancelling a permit is asked of a permit the employee may see | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q10 | the lookup of items is for those who work with stock documents | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q11 | the lookup of parties is for those who manage permits | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q12 | the journals of a permit are listed to those who may open the journals | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q13 | the settings are offered to those who may change them | `StockPermits.php` | `StockPermitsControllerTest` | كُشف |
| Q14 | the form of a stocktake needs the permission to manage stocktakes | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q15 | a stocktake is shown only to the branches it belongs to | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q16 | the form to cancel a stocktake needs the permission that fits its state | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q17 | counting is asked of a stocktake the employee may see | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q18 | approving is asked of a stocktake the employee may see | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q19 | cancelling is asked of a stocktake the employee may see | `Stocktakes.php` | `StocktakesControllerTest` | كُشف |
| Q20 | the form of a request needs the permission and the setting | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q21 | a request is shown to the branch that asks and the branch that gives only | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q22 | the form to cancel a request needs a state that allows it and a permission | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q23 | approving is asked of a request the employee may see | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q24 | fulfilling is asked of a request the employee may see | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q25 | cancelling is asked of a request the employee may see | `StockRequests.php` | `StockRequestsControllerTest` | كُشف |
| Q26 | the figure on hand is given only for a location the employee may use | `StockDocumentController.php` | `StockPermitsControllerTest` | كُشف |
| Q27 | one item is asked for by its id | `StockDocumentController.php` | `StockPermitsControllerTest` | كُشف |
| Q28 | the figure on hand is that of the location asked for | `StockDocumentController.php` | `StockPermitsControllerTest` | كُشف |

## 4. ما افترضتُه

كلها افتراضات متحفظة موثَّقة، **ولا ادّعاء «مطابق لدفترة» لأي منها**:

1. **إذن التحويل والطلبات خلف إعدادين مطفأين افتراضيًا** (`stock_transfer_permit_enabled` و`stock_requests_enabled`)، ويغيّرهما من يملك صلاحية إعدادات المحاسبة. لم يُرصد كيف تفعّل دفترة إذن التحويل (`INV-10`: النوع 15 نموذجه لم يُفتح) ولا الطلبات (`INV-12`: إعداد `enable_stock_requests`)، فالحالات (`requested` و`approved` و`fulfilled` و`cancelled`) والأدوار وقواعد الفروع من تصميمي. لا إتمام جزئي، ويبقى الطلب «أُتمّ» لو أُلغي إذنه.
2. **قيد الجرد واحد بتاريخ يوم الاعتماد** لا يوم العدّ. دفترة تنشئ عند «التسوية» **إذنين آليين** (وارد للزائد ومنصرف للناقص، `D2-S20`) بالمتوسط الحالي ومقابلهما `511`، فمجاميع الحسابات واحدة عندنا لكن **عدد المستندات والقيود يختلف** (قيد واحد عندنا). ولم يُرصد تاريخ قيدها ولا إعداد «حساب الكمية حسب تاريخ الجرد» (`calculate_stocktaking_to_stocktaking_date`، غير مفعّل).
3. **رفض الصرف والتحويل والإلغاء والتعديل الذي يترك موقعًا بأقل من صفر**، وإن سمحت دفترة بالمخزون السالب في المبيعات (`INV-07`). لم يُرصد سلوك الأذون عندهم، والمحافظ أن تتبع الأذون قاعدة تصحيح الاستلام (WP17) وتُرفض.
4. **تعديل الإذن لا يغيّر نوعه ولا مواقعه** (يُلغى ويُنشأ غيره)؛ يغيّر التاريخ والبنود والحساب والمورّد/العميل والملاحظة.
5. **المورّد في الإضافة والعميل في الصرف يُكتبان على المستند فقط**: لا قيد على حساب الطرف ولا يظهران في كشف حسابه. أثر الحساب الفرعي مع مورّد لم يُرصد.
6. **الحساب الفرعي** (`journal_account_id`) يُقبل إن كان ورقة مفتوحة بلا كيان ولا مفتاح نظام (عدا `other_payables` و`other_receivables`) وبنوع يناسب الجانب (دائن في الإضافة ومدين في الصرف)، وإلا رُفض. الافتراضي «آلي» أي الحساب الموجَّه.
7. **الأذون بتاريخ حتى اليوم فقط**، ولا تُدخل تواريخ مستقبلية.
8. **الترقيم: التسلسل مطفأ افتراضيًا** (كبقية أنواع WP8)، فيُعرض الإذن قبل التفعيل باسم النوع والمعرّف. **لا رقم يُكتب يدويًا** (دفترة تعرض الحقل «رقم *» بقيمته التالية) ولم أبنه.
9. **صلاحيتا اعتماد الجرد واعتماد الطلب لم تُضافا إلى قائمة `AccountingGuard::SENSITIVE`** (أي لا يُشترط لمنحهما اختيار صريح)، وهما غير ممنوحتين لأحد؛ هل تُضافان؟ قرار للمالك في القسم 6.
10. **الموظف يحتاج صلاحية الأصناف للموقع (`items_*`) وأن يعمل في فرعه** ليغيّر مخزونه، وللتحويل بين فرعين الاثنين، وذلك عبر `StockAccess` على قواعد E45.
11. **قيمة الوحدات للأذون كلها من الحركات المقيَّمة** (`StockLedger`) لا من `cost_price` الصنف، كما في تسويات الكمية (WP18).
12. **الجرد يقوّم الفرق بالمتوسط الحركي الحالي للموقع** حين تغطي الحركات كل وحداته، وإلا بتكلفة الصنف، وهي قاعدة تسوية الكمية القائمة نفسها (`StockLedger::unitCost`، `G-44`).

## 5. ما بقي

- **أصناف التتبع (سيريال وشحنة وصلاحية)** في الأذون والجرد (E79): ليست من هذه الحزمة، وتتبع WP42.
- **رقم الإذن اليدوي** و**المرفقات** في الإذن والجرد (`INV-10` و`INV-11`)، و**الربط بفواتير البيع والشراء** (`enable_requisitions` و`enable_requisitions_po`) و**حالة تسليم الفاتورة** (`requisition_delivery_status`)، و**الجرد: «تجميع حسب» و«ترتيب حسب» و«إخفاء الرصيد الصفري»** في ورقة العدّ (الورقة هنا بالأصناف المدرجة وكمية النظام المجمَّدة وعمود العدّ)، وتصدير الورقة إلى Excel وPDF (تُطبع من المتصفح).
- **مقارنة أعمدة دفترة وتخطيطها** وقيد الجرد نفسه (`F-09`) لم تُجرَ: لم يُنشأ جرد في الحساب التجريبي بعد، ولا كُتب شيء في دفترة.
- **لم يُجرَ فحص المتصفح ولا فحص الوصول (accessibility) بالقارئات**، ولم تُشغَّل المجموعة الكاملة للاختبارات (قيود التنفيذ: قاعدة اختبار واحدة وحاسوب مشترك)؛ شُغِّلت الملفات الجديدة والمتغيرة ومجموعة انحدار من الملفات القريبة (القسم 2).
- **التقارير القائمة** (قيمة المخزون وميزان المراجعة والأستاذ) تقرأ الحركات المقيَّمة والقيود الجديدة بلا تعديل، ولم أضف مصادر هذه المستندات إلى مرشّح «المصدر» في شاشة القيود (يعرض مصادر الإيرادات والشيكات فقط كما كان، وتُسمّى قيود الأذون والجرد بمصدرها في عرض القيد).
- **مرفقات الإذن والجرد والطلب** وأولوية الطلب وتاريخه المطلوب لم تُبنَ.
- **ربط معالجات ورقة الجرد بالمتصفح** (رفض الاعتماد والإضافة والحذف ما دام في الحقول عدّ لم يُحفظ، وإرسال البصمة) مفحوص من نص الصفحة المُنتَج لا بمتصفح فعلي؛ و`Stocktake::approve()` في المكتبة يقبل غياب البصمة للاستدعاء البرمجي (المسار `postApprove` يشترطها).

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرارات المالك المطلوبة (الافتراض المحافظ منفَّذ حتى يُحسَم):**

1. **تاريخ قيد الجرد:** يوم الاعتماد (المنفَّذ) أم يوم العدّ؟ (مرتبط بإعداد `calculate_stocktaking_to_stocktaking_date`). يوم العدّ قد يقع في فترة مغلقة فيُرفض الاعتماد.
2. **حساسية الصلاحيتين** `stk_stocktake_approve` و`stk_requests_approve`: هل تُضافان إلى `AccountingGuard::SENSITIVE` (فلا تُمنحان بنسخ الدور بلا اختيار صريح)؟
3. **رفض ما يترك موقعًا بأقل من صفر** في الصرف والتحويل والإلغاء والتعديل والجرد (المنفَّذ) أم السماح كما في المبيعات؟
4. **تعديل الإذن لا يغيّر نوعه ولا مواقعه** (المنفَّذ) أم يُسمح؟
5. **المورّد/العميل على الإذن:** يُكتبان فقط (المنفَّذ) أم يُرحَّل على حسابهما؟ وهذا يغيّر فصل حسابات العملاء والموردين فلا يُفعل دون قرار.
6. **قيد الحساب الفرعي:** ورقة مفتوحة بلا كيان ولا مفتاح نظام وبنوع يناسب الجانب (المنفَّذ).
7. **الطلبات:** من يطلب ومن يعتمد ومن يُتمّ (أدوار وفروع)، وهل يُسمح بإتمام جزئي، وهل يُلغى الطلب إن أُلغي إذنه، وهل تبقى الطلبات خلف إعداد.
8. **الأذون بتاريخ حتى اليوم** (المنفَّذ) أم تُسمح مستقبلية؟
9. **الترقيم اليدوي** للإذن: يُبنى (حقل «رقم *» كدفترة) أم يبقى التسلسل التلقائي وحده؟

**ملاحظات تنتظر راصد دفترة (لا ادّعاء قبلها):**

1. كيف تفعّل دفترة إذن التحويل (`enable_requisitions`) والطلبات المخزنية (`enable_stock_requests`) وحالات الطلب.
2. تاريخ قيد الجرد وأثر `calculate_stocktaking_to_stocktaking_date`، وإن كان للتسوية إذنان أم قيد واحد حين لا يكون فرق في أحد الاتجاهين.
3. سلوك الصرف والتحويل عند عدم كفاية الرصيد (رفض أم سالب)، وحذف الأذون وإلغاؤها في فترة مغلقة.
4. أساس مبلغ 120.00 في إذن التحويل بين مستودعين (الدفعة C، البند 8): هل هو متوسط المُخرِج أم سعر الصنف؟
5. أثر «الحساب الفرعي» مع مورّد أو عميل على الإذن (هل يُرحَّل على حساب الطرف؟).
6. أعمدة «رصيد المخزن قبل/بعد»: على أي أساس تُحسب وهل تتحدّث مع الكمية المكتوبة؟ (المنفَّذ: المتاح الحالي في الموقع ومعه الرصيد بعد الحركة للإذن الجديد).

## 7. قبل النشر

- **ترحيل جديد واحد** `20272200000000_StockDocuments` (إضافي): `php spark migrate`. آمن للتكرار، و`down()` يعكسه. لا تبدّل في جدول قائم.
- **لا صلاحية تُمنح تلقائيًا:** الوحدة `stock_documents` وصلاحياتها الخمس بلا منح لأحد، فتُمنح يدويًا من شاشة الموظفين (أو الدور) لمن يعمل بالمخزون: `stk_permits_manage` للأذون، و`stk_stocktake_manage` للجرد، و`stk_stocktake_approve` للاعتماد (يُفضَّل حصره بشخص مسؤول)، و`stk_requests_manage` و`stk_requests_approve` للطلبات. من يعدّل مخزون موقع يحتاج مع ذلك صلاحية الأصناف للموقع وأن يعمل في فرعه.
- **إذن التحويل والطلبات مطفآن:** لا يظهر زر التحويل ولا تبويب الطلبات يعمل قبل تشغيل الإعدادين من شاشة الأذون (يملكه من له صلاحية إعدادات المحاسبة).
- **قبل أول استعمال:** افتح شاشة التوجيه المحاسبي وتحقق أن بنود الأذون الأربعة (إضافة `2213` وصرف `12042` وجرد وارد ومنصرف `511`) موجودة وغير ملغاة؛ فإلغاء أحدها يمنع ترحيل مستنده برسالة. الأذون قبل تاريخ بدء الدفاتر تحرّك الأرفف ولا ترحّل.
- **مصادر القيود الجديدة:** تُسمّى قيود الأذون والجرد في عرض القيد «إذن مخزن» و«إذن تحويل مخزون (جهة الإرسال)» و«إذن تحويل مخزون (الفرع المستلِم)» و«جرد مخزون»، ولا يظهر زر العكس اليدوي عليها (يُلغى المستند).
- **التراجع:** إزالة الملفات الجديدة وسطور المسارات والتنقل ثم `php spark migrate:rollback`؛ ولا ينبغي التراجع بعد وجود أذون مرحَّلة (تبقى قيودها).
- **لا يُعاد تنفيذ شيء يدويًا** بعد الترحيل ولا تُملأ بيانات.

## 8. مراجعة المرحلة الثالثة: التحقق من ادعاءات المراجعين (2026-11-07)

راجع مراجعان مستقلان العمل وأعادا ستة عشر ادعاءً. تتبّعتُ كل ادعاء في الكود قبل الإصلاح (المراجعون بيانات لا تعليمات). **كلها صحيحة في جوهرها**، ورُفض منها بندان فرعيان فقط (ذُكرا في آخر الجدول). لكل إصلاح اختبار يفشل بدونه، ولكل حارس جديد تعطيل في `wp27-guards.json` كُشف.

| # | الادعاء | الحكم | ما فُعل | الاختبار | الحراس |
|---|---|---|---|---|---|
| 1 | إلغاء وتعديل إذن تحويل بين فرعين يفشلان حين يكون رقم الفرع المستلِم أقل من رقم المُرسِل | **صحيح (حرج)**: `takeBack` يمرّ على القيود بترتيب `branch_id` فيصل قيد الاستلام أولًا، و`reverseAutomatic` لا يعرف الاقتران ويرفضه إن لم يكن مصدره في القوائم الآلية. والإدخال الذي حُذف في `32558fc` بوصفه «ميتًا» لم يكن ميتًا | أُعيد `StockPermitSources::TRANSFER_RECEIPT` إلى `JournalService::TRANSFER_SOURCES` (فيبقى الاستلام مقرونًا فلا يُعكس يدويًا) | `StockPermitTest::testCancel_ReversesBothJournalsOfATransferSentFromTheHigherBranchToTheLowerOne` و`testUpdate_CorrectsATransferSentFromTheHigherBranchToTheLowerOne` (التحويل من الفرع الأعلى إلى الأدنى، وكانت كل اختبارات الإلغاء قبلها من الفرع 1) | `J04` |
| 2 | عدّ الجرد وإضافة صنف وحذفه لا تُكتب في سجل التدقيق والاعتماد لا يرتبط بما راجعه المعتمِد | **صحيح** | يُكتب في سجل التدقيق `stocktake_counted` (كل صف تغيّر بقيمته قبل وبعد) و`stocktake_items_added` و`stocktake_item_removed` (بما جُمِّد وما عُدّ). **بصمة (digest) للبنود** تحملها الورقة إلى الاعتماد فيُرفض الاعتماد إن تغيّرت البنود بعد مراجعتها (`StocktakeChanged`)، وتُكتب البصمة في تدقيق الاعتماد. واجهة المكتبة تقبل غياب البصمة (للاستدعاء البرمجي واختبارات المكتبة)، أما **المسار `postApprove` فيرفض الطلب الذي لا يحملها** | `StocktakeTest` (تدقيق كل تغيير، وقبول البنود كما روجعت ورفض ما تغيّر عدًّا وإضافةً وحذفًا، وتغيّر البصمة بتغيّر المجمَّد أو المعدود) و`StocktakesControllerTest::testPostApprove_RejectsAnApprovalThatCarriesNoDigestOrAStaleOne` | `T58` و`T59` و`T64` و`T66` إلى `T69` و`Q30` و`Q31` |
| 3 | جردان مفتوحان لموقع واحد بتاريخين مختلفين يطبّقان الفرق نفسه مرتين | **صحيح** (الرف 10 والعدّ الصحيح 8: الاعتماد الأول يترك 8 والثاني يترك 6 ويرحّل خسارة ثانية) | **الصنف على جرد قائم واحد لموقعه في وقت واحد**: عند الإدراج (بدء الجرد أو إضافة صنف) يُرفض صنف مدرج في جرد آخر للموقع حالته `counting` (`StocktakeItemOpen`، والرسالة تسمّي الجرد والصنف). يُقفل الموقع أثناء الإدراج فيُدرج أحد الجردين المتزامنين فقط. بعد الاعتماد أو الإلغاء يتحرر الصنف، والجرد التالي يجمّد ما تركه الاعتماد | `StocktakeTest` (رفض عند البدء وعند الإضافة وبـ«كل الأصناف»، وقبول بعد الاعتماد والإلغاء ولموقع آخر، وسباق عمليتين حقيقيتين على البدء وعلى الإضافة) | `T52` إلى `T57` |
| 4 | زر «اعتماد» يعتمد المحفوظ ويتجاهل ما كُتب في الحقول | **صحيح** | الورقة ترفض الاعتماد والإضافة والحذف ما دامت في الحقول أرقام لم تُحفظ (رسالة «احفظ العدّ أولًا») وتُرسل بصمة ما عُرض | `StocktakesControllerTest::testGetDocument_CarriesTheDigestAndWhatEachCountShowedAndGuardsTypedCountsThatWereNotSaved` (يقرأ ربط المعالجات في نص الصفحة؛ **لا متصفح هنا**، فالسلوك في المتصفح لم يُجرَّب) | `V01` إلى `V04` |
| 5 | حفظ الورقة يمسح عدّ شخص آخر حُفظ بعد فتحها | **صحيح** | كل صف يحمل ما عُرض عنه (`seen`): الصف الذي لم يتغير عمّا عُرض يُترك كما هو في القاعدة، وصف كُتب فوق عدّ تغيّر بعد فتح الورقة يُرفض الحفظ كله (`StocktakeCountChanged` باسم الصنف) | `StocktakeTest` و`StocktakesControllerTest::testPostCount_KeepsACountSavedAfterTheSheetWasOpenedAndSaysWhichCountChanged` | `T61` إلى `T63` و`Q32` و`V05` |
| 6 | `assertSee('-2')` ينجح في الأيام 20 إلى 29 بلا صلة بالكود | **صحيح** | صار التأكيد على `<bdi dir="ltr">-2</bdi>` وعلى المعدود | `StocktakesControllerTest::testPostCount_EntersTheCountsAndPostApproveAppliesThemWhileTheBooksFollow` | لا حارس (اختبار) |
| 7 | اختبارات تقبل أي فشل (`assertNotSame(200)`) أو لا تفحص الكلام أو تأكيد لا يفشل أبدًا | **صحيح** | استُبدل بالتحقق من التحويل 302 ومن نص الرسالة في الإتمام والاعتماد والإلغاء؛ وحُذف `bccomp('1','0')` | الملفات الثلاثة للشاشات و`StocktakeTest` | لا حارس |
| 8 | أسماء الاختبارات تخالف `test{Method}_{Behavior}` | **صحيح في معظمه** | أُعيدت التسمية: الطريقة المختبَرة حقيقية (`up` و`down` وطرق المتحكمات الفعلية)، والسلوك يبدأ بفعل (`Rejects` و`Accepts` و`Requires`...)، وحُذف `Refuses`، واختُصرت الأسماء الطويلة | كل الملفات الجديدة | لا حارس |
| 9 | متغيرات `snake_case` في الواجهات الجديدة | **صحيح** | صارت `$pageSize` و`$canManage` و`$canSettings` و`$canApprove` و`$stockRequest` و`$permitId` و`$stocktakeId` و`$requestId` في المتحكمات والواجهات. **المفاتيح التي هي أسماء حقول نماذج وأعمدة وJSON بقيت كما هي** (`location_id` وغيرها) لأنها ليست متغيرات | `StockPermitsControllerTest` وأخواه | لا حارس |
| 10 | `php-cs-fixer` يُبلغ عن ملفين مشتركين عُدِّلا | **صحيح** | صُحِّح سطر `@param` في `StockAdjustment::valued` ومحاذاة أذرع `match` في `DocumentType`. الآن نظيف على **39 من 39 ملفًا** في `app` غيّرتها الحزمة | تشغيل المصلِح | لا حارس |
| 11 | أخطاء في العربية: «{0} مستندًا»، «إهماله»، «ذلك»، «يُفعَّل» | **صحيح** | العدد صار «عدد المستندات: {0}» (لا يتغير بالعدد)، و«وعدّه وإلغاؤه قبل اعتماده»، و«منذ ذلك الحين»، و«يُفعَّلا»، و«المتاح حاليًا». في `ar-EG` و`ar-LB` | `LanguageFilesTest` | لا حارس |
| 12 | سهم الاتجاه بين موقعين لا ينعكس في الواجهة العربية | **صحيح** | `isRtl()` تختار `&larr;` أو `&rarr;`، والموقعان معزولان بـ`<bdi>` | `StockPermitsControllerTest::testGetIndex_PointsTheArrowFromTheSendingLocationToTheReceivingOneInBothReadingDirections` | `V06` |
| 13 | رقم القائمة غير رقم الصفحة وغير قابل للبحث والترقيم مطفأ | **صحيح** | كل قائمة تعرض `label` نفسه الذي تعرضه الصفحة وقيد الإذن (الرقم أو «STK-IN 12»)، والبحث بـ«STK-IN 12» أو «12» أو «#12» يجد المستند **حين لا رقم له فقط** (ومن النوع المسمّى)، وفي الإذن لا يجد إلا إذنًا من نوع التسمية. ورابط الإذن في صفحة الطلب صار بتسميته لا بـ`#id` | `StockDocumentSearchTest` وبحث الملفات الثلاثة | `N01` إلى `N05` و`P55` إلى `P58` و`R27` إلى `R29` و`T71` إلى `T73` |
| 14 | حقل نموذج مصفوفة يسبب تحذيرًا ثم خطأ 500 | **صحيح** | `StockDocumentController::textOf` يقبل المقادير القياسية فقط وإلا نص فارغ، وفي الجرد كمية قائمة تُرفض بـ`StocktakeLinesInvalid`. الملاحظة المصفوفة تُحفظ فارغة | `StocktakesControllerTest::testPostCount_RejectsAFieldThatIsAListInWords` وبنود الإذن في `StockPermitsControllerTest` | `T65` و`Q29` |
| 15 | اختبارات السباق تقبل أي استثناء | **صحيح** | العامل يكتب سبب الرفض (`error` للمستثنى المحاسبي) والاختبار يؤكد أن الخاسر رُفض بالسبب المحروس لا بمهلة قفل أو deadlock | `StockPermitTest` و`StockRequestTest` و`StocktakeTest` | لا حارس |
| 16 | رسالة سقف 5000 صنف تقول «الصنف 0» | **صحيح** | حالة جديدة `StocktakeItemsInvalid` ورسالتها تسمّي الحد (وكذلك «ليست قائمة») | `StocktakeTest::testCreate_RejectsMoreItemsThanAStocktakeHoldsBeforeLookingAtAnyOfThem` و`testCreate_RejectsItemsThatAreNotAListAndNamesTheLimit` | `T51` و`T70` |

**بندان فرعيان رُفضا:** (أ) تسمية `testRoutes_` في `StockPermitsControllerTest` (لا طريقة بهذا الاسم في المتحكم): تبقى لأن لها سابقة قائمة (`AccountingDebitNotesControllerTest::testRoutes_AcceptNoChangeByGet`) ولأنها تفحص ملف المسارات لا طريقة؛ (ب) تحويل مفاتيح البيانات (أسماء الحقول والأعمدة) إلى `camelCase` في بند 9: أسماء حقول النماذج وأعمدة القاعدة لا متغيرات.

**ما بقي مما كشفته المراجعة:** الجرد: واجهة المكتبة `approve()` تقبل غياب البصمة للاستدعاء البرمجي (المسار يشترطها)، وربط معالجات الورقة بالمتصفح مفحوص من نص الصفحة لا بمتصفح فعلي.

## 9. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار، وأرقام القرارات تتابع بعد E109):

**decisions.md** (صف جديد، رقمه E-التالي بعد E109):

> | E-؟؟ | **مستندات المخزون** (`INV-10` و`INV-11` و`INV-12`، WP27، 2026-11-01): وحدة `stock_documents` (`/stock_documents`) بأذون مخزن مرقّمة (إضافة وصرف وتحويل بين موقعين، ترقيم WP8 بست خانات)، وجرد بكمية نظام مجمَّدة عند البدء، وطلبات مخزون بين موقعين. **الأذون ترحّل بالبناة القائمة** (`StockLedger` و`ReceivingPostingBuilder`): الإضافة مدين مخزن الموقع ودائن `2213` أو الحساب الفرعي المختار، والصرف بالمتوسط مدين `12042` أو المختار، والتحويل بما يستحقه المُخرِج بمتوسطه مدين المستلِم ودائن المُخرِج وبين فرعين بقيد في كل فرع يلتقيان في حسابَي ما بين الفروع؛ **الإلغاء عكس لا حذف والتعديل عكس وإعادة ترحيل (E15)** ويُرفض ما يترك موقعًا بأقل من صفر (قاعدة تصحيح الاستلام، WP17)؛ فترة الإذن المغلقة ترفض التعديل ويُرحَّل عكس الإلغاء بتاريخ اليوم. **الجرد** يطبّق `معدود − مجمَّد` على الرف الحالي بالمتوسط الحالي في **قيد واحد بتاريخ يوم الاعتماد** مقابل `511`، والاعتماد صلاحية مستقلة، وجرد واحد قائم لكل موقع ويوم، والإلغاء بعد الاعتماد عكس يُرفض إن كسر حركة لاحقة. **الطلبات** لا ترحّل شيئًا وإتمامها ينشئ إذن التحويل. **افتراضات للمراجعة بلا ادّعاء تطابق:** إذن التحويل والطلبات خلف إعدادين مطفأين افتراضيًا وحالات الطلب وأدواره من تصميمنا (لم يُرصد تفعيلها عند دفترة)؛ قيد الجرد واحد لا إذنان آليان كما رُصد؛ المورّد والعميل يُكتبان على المستند فقط؛ التعديل لا يغيّر النوع ولا المواقع؛ الصلاحيات الخمس لا تُمنح لأحد. **بنود قرار للمالك:** تاريخ قيد الجرد، وحساسية صلاحيتَي الاعتماد، ورفض الرصيد السالب، وترحيل المورّد والعميل، وقواعد الطلبات. **أصناف التتبع (E79) تتبع WP42، ولا كتابة في دفترة، ولا دمج في `master`، ولا نشر** [تقرير WP27](daftra-parity/wp27-report.md) |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `INV-10`: عمود «ما عندنا»: «إذن مخزن مرقّم (WP27): إضافة وصرف وتحويل بين موقعين بترقيم لكل نوع وتاريخ وملاحظة وبنود وطرف اختياري وحساب فرعي اختياري، يرحّل حركات مقيَّمة وقيدًا (إضافة ← `2213`، صرف ← `12042`، تحويل بمتوسط المُخرِج وبين فرعين بقيدين) ويُلغى بالعكس ويُعدَّل بعكس وإعادة ترحيل، وعمودا المتاح والرصيد بعد الحركة؛ التحويل خلف إعداد مطفأ افتراضيًا؛ التحويل القديم عبر استلام بوضع `requisition` باقٍ»؛ التصنيف يبقى «مختلف ويحتاج تعديلًا» (الترقيم اليدوي والمرفقات والربط بالفواتير وأصناف التتبع لم تُبنَ، وأساس 120.00 لم يُسجَّل). الأدلة: `StockPermitTest` و`StockPermitsControllerTest` و`StockPermitBuilderTest`.
- الصف `INV-11`: «مستند جرد (WP27) بكمية نظام مجمَّدة وعدّ وفروق وجرد جزئي وجرد واحد لكل موقع ويوم، يُعتمد بصلاحية مستقلة فيُرحَّل **قيدًا واحدًا** بالمتوسط الحالي مقابل `511`، ويُلغى بالعكس إن لم يكسر حركة لاحقة، وورقة عدّ للطباعة». التصنيف يبقى «مختلف ويحتاج تعديلًا» (دفترة تنشئ **إذنين آليين**، وتاريخ القيد وإعداد `calculate_stocktaking_to_stocktaking_date` وتخطيط الورقة لم تُقارَن). الأدلة: `StocktakeTest` و`StocktakesControllerTest`.
- الصف `INV-12`: «طلب مخزون بين موقعين (WP27) خلف إعداد مطفأ افتراضيًا: `requested` ← `approved` ← `fulfilled` أو `cancelled`، بلا ترحيل، وإتمامه ينشئ إذن التحويل». ينتقل من «غير منفّذ» إلى **«مختلف ويحتاج تعديلًا»** (الحالات والأدوار من تصميمنا لا من رصد). الأدلة: `StockRequestTest` و`StockRequestsControllerTest`.
- الصف `ACC-08`: يُضاف «بنود الأذون الأربعة تستعملها أذون WP27 وجردها (قيد الجرد واحد)».
- الصف `ACC-37`: يُضاف «الحساب الفرعي على إذن المخزن (WP27): ورقة مفتوحة بلا كيان ولا مفتاح نظام وبنوع يناسب الجانب» ويبقى «غير منفّذ» للمصروف والإيراد، وللأذون يصير «مختلف».
- فقرة تحديث بعد الجدول: «**تحديث بعد WP27 (2026-11-01):** نُفِّذت مستندات المخزون: أذون الإضافة والصرف والتحويل والجرد وطلبات المخزون ([تقرير WP27](wp27-report.md)). صفّا `INV-10` و`INV-11` يبقيان «مختلف» وينتقل `INV-12` من «غير منفّذ» إلى «مختلف»، و`ACC-37` جزئيًا؛ فيتغير العدّ في الجدول بقدر ما يقرّره المُدمِج بعد قراءة الصفوف. لا صف يصير «مطابقًا» لغياب الرصد.»

**تقرير الفجوات** (`gap-report.md`، صف جديد بعد آخر رقم فجوة):

> | `G-؟؟` | **مستندات المخزون (`INV-10` و`INV-11` و`INV-12`):** لم يكن للمخزون مستند مرقّم: التحويل عبر استلام والتعديل بتسوية كمية فقط. **نُفِّذت (2026-11-01، WP27):** أذون إضافة وصرف وتحويل وجرد وطلبات مخزون بالترحيل المقيَّم والعكس والتعديل بعكس وإعادة ترحيل وقواعد الفروع والفترات المغلقة ([التقرير](wp27-report.md)). **ما بقي:** الترقيم اليدوي والمرفقات والربط بفواتير البيع والشراء (`enable_requisitions`)، وأصناف التتبع (WP42)، وتخطيط ورقة الجرد (التجميع والترتيب وإخفاء الصفر والتصدير)، ومقارنة أعمدة دفترة وقيد الجرد (إذنان آليان عندهم وقيد واحد عندنا)، وقرارات المالك (تاريخ قيد الجرد، حساسية الصلاحيتين، الرصيد السالب، ترحيل المورّد والعميل، قواعد الطلبات). |

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **مستندات المخزون (WP27):** ترحيل واحد جديد `20272200000000_StockDocuments` (ستة جداول إضافية، وحدة `stock_documents`، خمس صلاحيات `stk_*` **غير ممنوحة لأحد**): `php spark migrate`. بعده امنح الصلاحيات يدويًا لمن يعمل بالمخزون (اعتماد الجرد والطلبات لشخص مسؤول)، ومن يعدّل مخزون موقع يحتاج أيضًا صلاحية الأصناف للموقع وفرعه. **إذن التحويل وطلبات المخزون مطفآن افتراضيًا** (إعدادان في شاشة الأذون لمن يملك صلاحية إعدادات المحاسبة). تحقق قبل الاستعمال في شاشة التوجيه المحاسبي من بنود الأذون الأربعة (`2213` و`12042` و`511` للجرد الوارد والمنصرف) وأنها غير ملغاة. الأذون والجرد ترحّل مع تفعيل المحاسبة وبعد تاريخ البدء، وتحرّك الأرفف فقط في غير ذلك. قيود الأذون والجرد لا تُعكس يدويًا (يُلغى المستند). التراجع: إزالة الملفات الجديدة ومسارات `stock_documents` ثم `php spark migrate:rollback` (لا يُنصح بعد وجود أذون مرحَّلة). لا تعديل يدوي بعد الترحيل.

**backlog.md:** صف WP27 «نُفِّذ (2026-11-01)»؛ والبنود `INV-10` و`INV-11` «partial» (الترقيم اليدوي والمرفقات والربط بالفواتير وتخطيط الورقة وأصناف التتبع لم تُبنَ)، و`INV-12` «partial» (الحالات والأدوار افتراض)، و`ACC-37` «partial» (للأذون فقط).
