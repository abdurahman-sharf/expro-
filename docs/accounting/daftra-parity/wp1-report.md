# تقرير WP1: إغلاق ثغرات الضبط (الصلاحيات الحساسة، فروع شاشات المخزون والتقارير القديمة، وجهة التحويل، أقفال العملاء والموردين)

> الحالة (2026-10-27): **نُفِّذ على الفرع `wp/WP1`** (نسخة عمل مستقلة من `f5d4927`) بالتفويض التنفيذي الشامل (E47). خمسة بنود ضبط في الكود تحقّقتُ من كلٍّ منها بقراءة الشيفرة قبل تعديلها، ثم أثبتُّ كل حارس باختبار يفشل حين يُعطَّل (القسم 2). **وعولجت بعد ذلك ملاحظات مراجعتين مستقلتين** (القسم 1ب، خمس عشرة ملاحظة بينها متطابقتان): عشر صحّت وأُصلحت بحارس واختبار وقياس تعطيل، وثلاث صحّت جزئيًا وعولج ما صحّ منها، وواحدة قُبلت في الصياغة فقط بلا تغيير في الشيفرة، ورُفض النصف الثاني من أولاها بمسار موثَّق. **لا شيكات، ولا `E33` ولا `G-57`، ولا كتابة في دفترة، ولا ترحيل جديد في قاعدة البيانات (لا ملف في النطاق `202611100000xx`)، ولا دمج في `master`، ولا نشر.** لا قيد ولا رصيد ولا مستند مرحَّل ولا قفل فترة يُعدَّل بهذه الحزمة؛ والمحاسبة معطَّلة أو مفعَّلة تعملان كما كانتا (D1) ما عدا الرفض الجديد في الحالات الموصوفة. ولم أكتب عبارة «يطابق دفترة» لأي بند: هذه ضوابط في الأول برو نفسه ولا مقابل لها في المصفوفة المرصودة.

## 1. ما نُفِّذ

| البند | ما كان في الكود | ما صار |
|---|---|---|
| **`P6-SENSITIVE-PERMS`** صلاحيتا الخزائن ودفعات الموردين | `AccountingGuard::SENSITIVE` يسرد خمس صلاحيات فقط (الإعدادات وإقفال الفترات وإعادة فتحها والأرصدة الافتتاحية والتوجيه)، فتبقى `acc_treasuries_manage` (إنشاء الخزائن والتحويل بينها) و`acc_payables_manage` (دفعات الموردين والأرصدة الافتتاحية) خارج القائمة: يمنحهما أو يسحبهما محرر موظفين غير مدير، وتدخلان في «تحديد الكل»، ولا يُسجَّلان في سجل المنح الحساس | **القائمة سبع صلاحيات بعد الإضافة** (`TREASURIES_MANAGE` و`PAYABLES_MANAGE`). فلا يمنحهما ولا يسحبهما إلا المدير، **ولا يحفظ محرر غير مدير نموذجًا يُسقط ما يحمله الموظف منهما** (يحتفظ الحفظ بما يحمله الموظف من الحساسة)، ولا «تحديد الكل» يمنحهما، ويُسجَّل كل منح وسحب في سجل التدقيق (`SensitivePermissions::recordChange`). **لم أمسّ `Employees.php`**: منطقه كان صحيحًا ويقرأ القائمة نفسها، فالإصلاح مصدره واحد. أُضيف اسما الصلاحيتين إلى أسماء شاشة الإعداد (`AccountingSetup::PERMISSION_LABELS`) ونصّ `sensitive_note` في نموذج الموظف (إنجليزي وإنجليزي بريطاني وعربي مصري وعربي لبناني) |
| **`A2A-STOCK-ACCESS`** شاشات الأصناف وتسوية المخزون | شاشات الأصناف (تغيير الكمية بالجرد السريع، حفظ نموذج الصنف، استيراد CSV، حذف الصنف) تقبل أي موقع مخزون بصرف النظر عن فروع الموظف، فيستطيع موظف فرع «أ» تحريك مخزون موقع في فرع «ب» وترحيل قيد تسوية في فرع لا يعمل فيه (قاعدة الفروع مطبّقة في البيع والاستلام وحدهما) | **القاعدة نفسها في البيع والاستلام (منحة الموقع على وحدة الأصناف **و**موقع في فرع يعمل فيه الموظف):** (1) `Items::itemLocations()` تعيد `get_allowed_locations('items', true)` وتُستعمل في قائمة الأصناف وقالب الاستيراد وفحص ترويسة CSV وفحص كل صف (عمود موقع فرع آخر لا يُقبل فيُرفض الصف ولا يُحفظ شيء). (2) `postSaveInventory` يرفض الموقع غير المسموح برسالة `Items.stock_location_not_allowed` **ولا يكتب صف جرد ولا قيدًا**، مع المحاسبة مفعّلة أو معطّلة. (3) `postSave` يتجاهل كمية موقع غير مسموح **ولا يصفّر مخزون فرع آخر** حين لا يعرضه النموذج. (4) نماذج الصنف والجرد والتفاصيل تعرض مواقع الموظف المسموحة وحدها، وتعود الشاشة إلى موقع مسموح لو كان الموقع المحفوظ في الجلسة خارج فروعه، ومن لا موقع له في فروعه يُحوَّل إلى صفحة «لا صلاحية». (5) **حذف الصنف** (`delete` و`delete_list`) يُرفض بلا أثر إن كان للصنف مخزون غير صفري في فرع لا يعمل فيه الموظف، لأن الحذف يُخرج المخزون من كل رف؛ وموظف كل الفروع بلا قيد، والحارس نفسه لا يقيّد النظام بلا موظف مسجَّل (لكن حذف صنف له مخزون ولا موظف مسجَّل يفشل قبل ذلك في `Inventory::resetRows` كما كان قبل الحزمة، انظر القسم 4). (6) **حارس في الخدمة لا في الشاشة وحدها:** `StockAdjustment::post` يطلب `BranchGuard::requireBranch` لفرع الموقع، فيُرفض قيد التسوية (`BranchException`) أيًّا كان المنادي، والقيد يحمل فرع الموقع ويطابق `StockLedger::reconcile` حساب المخزون الفرعي `1203xxx`. (7) **نموذج الصنف لا يُعلِّم صنفًا محذوفًا (`is_deleted`)** إن كان له مخزون غير صفري في فرع لا يعمل فيه الموظف، بالرفض نفسه الذي يعطيه الحذف (لا تغيير في الصنف ولا في المخزون، والرسالة `Items.error_adding_updating` بسبب `Branches.error_not_allowed`)؛ ولا يُرفض تحرير صنف محذوف أصلًا ولا موظف كل الفروع. (8) **الصنف الجديد يأخذ رفًّا فارغًا** (كمية صفر بلا حركة ولا قيد) في كل موقع قائم لا في مواقع فروع الموظف وحدها (`Item_quantity::createMissingRows` من حفظ النموذج ومن استيراد CSV)، لأن قوائم الأصناف تقرأ رف الموقع الذي تعرضه فكان الصنف يغيب عن موظفي الفروع الأخرى. (9) **سجل حركة الصنف في شاشة التفاصيل** يقتصر في الخادم على مواقع الموظف المسموحة (`Inventory::get_inventory_data_for_item` تقبل قائمة مواقع، والقائمة الفارغة لا تطابق شيئًا)، لا في الجافاسكربت وحده. (10) **كل أفعال الأصناف** (البحث والصف والباركود والتعديل الجماعي) تحوّل من لا موقع له في فروعه إلى «لا صلاحية» (`Item_lib::get_item_location`) لا الفهرس وحده |
| **`G-48`** التقارير القديمة والفروع | نماذج `app/Models/Reports` وشاشات `Reports` تقرأ كل الفروع لأي موظف يحمل صلاحية التقرير؛ ولوحة البداية تخبّئ أرقامها بمفتاح واحد للجميع | **كل نموذج تقرير يقرأ المبيعات أو الاستلامات أو المخزون يقيّد صفوفه بفروع الموظف المسجَّل** (`Report::branchIds` من `BranchGuard::allowedBranchIds`): لا فرع يعني لا شيء (`1 = 0`)، وكل الفروع والنظام (بلا موظف) بلا قيد. **المغطّى:** ملخصات المبيعات والفئات والعملاء والموردين والأصناف والموظفين والمدفوعات والخصومات والضرائب وضرائب المبيعات (عبر `Summary_report` وحدها أو مباشرة)، والتفصيلي للمبيعات وللأصناف وللاستلامات، وتقارير العميل والخصم والموظف والمورد، ومخزون الأصناف والأصناف تحت حد الطلب (بفرع موقع المخزون). والجدولان المؤقتان `Sale::create_temp_table` و`Receiving::create_temp_table` يقبلان `branch_ids` (استلام التحويل بين فرعين يُرى لموظفي أيٍّ من الفرعين كما في G-41). **صفوف الفرع الافتراضي القديمة** (مبيعات سبقت وجود الفروع) تبقى ظاهرة لموظف الفرع الافتراضي. **تقرير المصروفات للشركة كلها فقط** (لا فرع للمصروف): يُرفض (حالة 403) برسالة «هذا الإجراء يخص الشركة كلها ويحتاج وصولًا إلى كل الفروع» (`Branches.error_company_scope_needed`) لمن لا يعمل في كل الفروع ويعيد نموذجه فارغًا، وتُخفى بطاقة المصروفات من لوحة البداية **ورابطا التقرير من قائمة التقارير ومن بحث التنقل** لمن لا يفتحه (`openableReportPermissions`). **والإقفال النقدي (`Cashups`) يبقى للشركة كلها** (`Report::readingTheCompany`): سجل الإقفال بلا فرع وقائمته للشركة كلها ومصروفاته المطروحة منه بلا فرع، فتقييد المقبوضات وحدها كان يخلط رقم فروع برقم شركة. **التفاصيل بالمعرّف** (`detail_sales` و`detail_receivings`) تعيد 404 لمستند فرع آخر. **القوائم المنسدلة** لمواقع المخزون في شاشات التقارير تعرض مواقع فروع الموظف وحدها. **الوسم:** رأس التقرير (`reports/_head.php`) يقول **«فروع محدودة (…)»** تحذيرًا لمن يقرأ بعض الفروع أكثر من فرع، و«الشركة كلها» لمن يقرأ كلها، ولا يظهر لمن يقرأ فرعًا واحدًا، بالصياغة نفسها التي اعتمدتها P8 (E45) لميزان المراجعة (مفاتيح `Accounting_reports.scope_*` أعيد استعمالها ولم يُضَف مفتاح). **ذاكرة لوحة البداية** صار مفتاحها يحمل فروع الموظف فلا يرى موظف رقمَ فرع آخر |
| **`G41-A5`** وجهة التحويل | `postChangeMode` يفحص منحة موقع **المصدر** وحده ويحفظ موقع **الوجهة** كما جاء (التحويل أو طلب التوريد داخل الفرع يرسل مخزونًا إلى موقع لا يملك الموظف منحته) | الوجهة تُفحص بمنحة وحدة الاستلام **وبفرع الموظف** (`Receivings::destinationAllowed`) عند تغيير الوضع، فيُرفض بلا تغيير في السلة برسالة `Receivings.location_not_allowed`، ويُحفظ رقمها وحده (`(int)`) لا النص الوارد؛ وتُفحص **المنحتان مرة أخرى عند «إنهاء طلب التوريد»** (`postRequisitionComplete`) فمن سُحبت منحته بين الخطوتين لا يمرّ (ولكلٍّ من المنحتين اختبار وقياس تعطيل خاصان به بعد المراجعة). (أُنجز هذا البند أولًا في الإيداع `45861e3` من جلسة موازية، وراجعتُه وأعدتُ تشغيل اختباراته وعطّلتُ حرّاسه الخمسة) |
| **`P5-LOCK-POOLING`** أقفال العميل والمورد | `CustomerLock` و`SupplierLock` يطلبان `GET_LOCK` ويعدّان `1` نجاحًا. خلف مجمّع اتصالات أو وكيل (`pgbouncer` بنمط المعاملة أو ProxySQL) قد يُمنح القفل لاتصال غير الذي ينفّذ العمل، أو يتشارك عميلان اتصالًا واحدًا، **فتعمل عمليتان معًا ويُنفَق الرصيد الدائن مرتين بصمت** | صنف جديد `NamedLock` يعدّ القفل **مأخوذًا فقط إذا قال الخادم إن هذا الاتصال نفسه يملكه** (`IS_USED_LOCK(name) = CONNECTION_ID()`) بعد `GET_LOCK` ولا يكون الاتصال قد حمله قبل أن يطلبه (قفل «محتل» يعني اتصالًا مشتركًا). والنتيجة `LockOutcome` (`Taken` أو `Busy` أو `NotHeld` أو `Occupied`)؛ **ما عدا `Taken` يرفض العملية** (`CustomerBusy` أو `SupplierBusy` بسياق `lock`) فتُغلق على الفشل بدل أن تعمل بلا حماية، ويُسجَّل `critical` في السجل. ما أُخذ دون أن يمسكه الاتصال **يُطلب فكّه فورًا** على الاتصال نفسه فلا يبقى مثبّتًا، إلا إن وجّه المجمّع أمر الفك نفسه إلى اتصال آخر فيبقى القفل في جلسة الخادم التي أخذته حتى تنتهي (انظر القسم 4). وإعادة أخذ الاسم نفسه من العملية نفسها (طلبات متداخلة) تُعدّ بسجل ثابت فلا تُحسب «اتصالًا مشتركًا»، ويفك آخر `release` القفل. **وأمر تشغيل جديد `php spark accounting:check_locks`** يجرّب قفلًا لا يطلبه أحد ويقول هل يحفظ الاتصال الأقفال المسماة (يخرج بـ `EXIT_ERROR` وبرسالة صريحة إن لم يحفظ) ليُشغَّل قبل النشر وبعده في بيئة فيها مجمّع |

## 1ب. ملاحظات المراجعة المستقلة

راجع الحزمةَ مراجعان مستقلان (اعتُبرت ملاحظاتهما بيانات لا تعليمات)، وتتبّعتُ كل ملاحظة في الكود قبل أن أقبلها أو أرفضها. خمس عشرة ملاحظة، اثنتان منها متطابقتان (سجل حركة الصنف). **ما صحّ منها أُصلح باختبار يفشل بدون الإصلاح وحارس مقيس بتعطيله** (القسم 2 والملحق، الدفعات 12 إلى 14 مع الحراس `FIX-*`).

| # | الملاحظة | الحكم | ما فُعل أو لماذا رُفضت |
|---|---|---|---|
| 1 | نموذج الصنف يحذف صنفًا له مخزون في فرع آخر (`is_deleted`) متجاوزًا حارس الحذف | **حقيقية، أُصلحت** (نصفها الثاني مرفوض) | حارس في `Items::postSave` عند الانتقال إلى «محذوف» فقط (`FIX-1a` و`FIX-1b` و`FIX-1c`) بخمسة اختبارات (يرفض مع المحاسبة مفعّلة ومعطّلة، ويقبل ما كل مخزونه في فروعه وموظف كل الفروع وتحرير صنف محذوف أصلًا). **مرفوض:** أن «مخزون الأستاذ والفرعي يختلفان لموظف كل الفروع لأنه لا يُرحَّل قيد إزالة»: مسار الخانة لا يغيّر إلا `items.deleted` ويترك `item_quantities` وسجل المخزون والقيد كما هي، فيتطابقان (`StockLedger::reconcile` لا يستثني الأصناف المحذوفة)، وهذه دلالة الخانة قبل الحزمة (يبقى المخزون ويعيده إلغاء الحذف) |
| 2 | الإقفال النقدي يخلط مقبوضات مقيَّدة بالفروع بمصروفات للشركة كلها | **حقيقية، أُصلحت بالاختيار المحافظ** | سجل `cash_up` بلا فرع وقائمته وإقفالاته للشركة كلها ومصروفاته بلا فرع، فالمقبوضات المسبقة الملء عادت للشركة كلها كما قبل الحزمة بقراءة صريحة (`Report::readingTheCompany`) لا بحالة عامة تتسرب (`FIX-5a` و`FIX-5b` و`FIX-5c`، واختبار في `CashupControllerTest` واثنان في `ReportTest`). **قرار للمالك:** إقفال لكل فرع يحتاج فرعًا في `cash_up` (يُضاف إلى WP3) |
| 3 و6 | شاشة تفاصيل الجرد تضمّن سجل حركة كل المواقع (وأسماء الموظفين) في الصفحة | **حقيقية، أُصلحت** | التصفية في الخادم بقائمة مواقع الموظف (`FIX-2a` و`FIX-2b` و`FIX-2c`)، باختبار الشاشة (يرى موظف الفرع سجل مواقعه وحدها وموظف كل الفروع الكل) و`InventoryTest` الجديد (4) |
| 4 | الصنف الجديد بلا رف في مواقع الفروع الأخرى فلا يظهر في شاشتها | **حقيقية، أُصلحت** | `Item_quantity::createMissingRows` من الحفظ ومن استيراد CSV (`FIX-3a` إلى `FIX-3d`)؛ والاختبار يبحث بموظف الفرع الآخر فيجد الصنف |
| 5 | فكّ القفل بعد `NotHeld` قد لا يصل إلى الاتصال الذي أخذه خلف مجمّع يوزّع الأوامر | **مقبولة في الصياغة، بلا تغيير في الشيفرة** | تتبّعتُ `NamedLock::take`: الفك يُطلب على الاتصال نفسه فيصل حيث يحمل المجمّع الأوامر كلها إلى خادم واحد، وإن وزّعها فقد لا يصل ويبقى القفل في جلسة الخادم حتى تنتهي؛ والنتيجة تبقى إغلاقًا على الفشل (رفض لا تلف بيانات). لا شيء في التطبيق يبلغ جلسة خادم أخرى، فلا إصلاح في الكود يُجدي؛ فصحّحتُ العبارة التي بالغت («يُفك فورًا») وأضفتُ حدًّا في القسم 4، والعلاج التشغيلي بحاله (`accounting:check_locks` واتصال مباشر أو مجمّع بنمط الجلسة) |
| 7 | شرط منحة المصدر في إنهاء طلب التوريد بلا اختبار ولا تعطيل | **حقيقية، أُصلحت** | اختبار موقع مصدر بلا منحة والوجهة بمنحة (`FIX-7`) |
| 8 | رفض تقرير المصروفات يقول «لا صلاحية الوحدة» والقائمة تعرض الرابط | **حقيقية، أُصلحت** | 403 برسالة `Branches.error_company_scope_needed` في صفحة قائمة التقارير، وتخفي القائمة وبحث التنقل الرابطين لمن لا يفتحهما (`FIX-6a` إلى `FIX-6g`، اختبارات في `ReportsControllerTest` و`HomeTest` و`report_helperTest`) |
| 9 | مقارنة القائمة الحساسة كلها بالترتيب تنكسر مع حزمة تضيف صلاحية إليها وإعادة كتابة اختبار موجود تتصادم معها | **حقيقية، أُصلحت** | أعدتُ الاختبار الموجود كما كان حرفيًا، ونقلتُ اختباري الصلاحيتين إلى آخر الملف بلا مقارنة بكل القائمة (المنتمية تثبتها حلقة العضوية، ويثبتها تعطيل `P6-1` و`P6-2`) |
| 10 | `/items/search` يعطي 500 لموظف لا موقع له في فروعه، والفهرس وحده محمي | **حقيقية، أُصلحت** | `Item_lib::get_item_location` يحوّل إلى «لا صلاحية» (`FIX-4`)، باختبار البحث و`Item_libTest` الجديد (3). وصار فحص الفهرس المكرَّر زائدًا فحذفتُه، **وقد أظهر القياس بعد الإصلاح أن حارسه `A2A-7` لم يعد يُكتشف** (`UNDETECTED`) فسحبتُه بدل أن أُبقي حارسًا ميتًا، وحلّ محلّه `FIX-4` |
| 11 | أسماء snake_case في أسطر عدّلتها الحزمة وأسطر لم يمرّ بها المنسّق | **حقيقية جزئيًا، أُصلحت على أسطر الحزمة** | حوّلتُ إلى camelCase متغيرات الأسطر التي أضافتها الحزمة أو عدّلتها في الدوال التي لمستها (Items وReports وReceivings وCashups وItem وInventory وDetailed_receivings وSummary_taxes)، وطبّقتُ على تلك الأسطر ما أراه المنسّق (استيراد، محاذاة، فاصلة، سطر فارغ). **لم أطبّق** ما يغيّر سلوكًا (`==` إلى `===` في الأسطر المجاورة) ولا إعادة كتابة دوال لم تمسّها الحزمة (استيراد CSV، نصّ استعلام `Inventory_low`)؛ والملفات الجديدة غير القديمة نظيفة |
| 12 | أسماء اختبارات مخالفة، وملفان لصنف واحد، وتغطية الدالتين المساعدتين بالمتحكمات | **حقيقية جزئيًا** | أعدتُ تسمية 26 دالة (`Refuses` إلى `Rejects`، وغياب الشرطة أو شرطتان) على صيغة `test{الدالة}_{السلوك}`، واختبرتُ `reportScope()` و`openableReportPermissions()` في `report_helperTest` (5) و`Item_lib` في `Item_libTest` (3). **بقي** `Summary_discounts_test.php` و`Summary_taxes_test.php` بجانب `Summary_discountsTest.php` و`Summary_taxesTest.php`: ملفان قديمان لا يجمعهما PHPUnit (لاحقة `_test.php`) ويفشلان على الأساس لسببين لا علاقة لهما بالحزمة (مفتاح أجنبي في بذرة الأصناف وعدم صحة اختبار هروب رمز العملة)، ودمجهما يعني إصلاح اختبارين معطوبين في موضوع آخر وقرار في معناهما؛ تركتُهما للمُدمِج (القسم 4) |
| 13 | تأكيدان لا يمكن أن يفشلا | **حقيقية، أُصلحت** | حُذف `assertNotSame` على معرّفين متباينين دائمًا، واستُبدل تأكيد `'stock'` (موجود في كل نموذج) بعلامة الموقع في كل شاشة (`id="quantity_1"` و`<option value="1"`) |
| 14 | إعادة ترتيب مفتاحي `tax_percent_*` في 46 ملف لغة بلا حاجة | **حقيقية، أُصلحت** | أعدتُ الترتيب الأصلي في الملفات الستة والأربعين؛ فلا يضيف كل `Items.php` إلا مفتاح `stock_location_not_allowed` |
| 15 | فرع «النظام بلا قيد» في حذف الصنف مدّعى وغير مختبر ولا معطَّل | **حقيقية جزئيًا** | للعبارة الآن اختبار وقياس تعطيل (`FIX-8`). **لكن التتبع أظهر** أن حذف صنف له مخزون ولا موظف مسجَّل يفشل قبل ذلك في `Inventory::resetRows` (يقرأ رقم الموظف المسجَّل لسجل الحركة) كما قبل الحزمة، فصحّحتُ ادعاء «النظام بلا قيد» ليكون على الحارس لا على الحذف كله، وحدٌّ في القسم 4 |

## 2. الأدلة

- **اختبارات جديدة (193 دالة اختبار مضافة في 41 ملف اختبار: 24 ملفًا جديدًا و17 موجودًا؛ العدد مقيس بعدّ دوال `test*` في الرأس مطروحًا منها ما كان عند `f5d4927`؛ ملف واحد لكل صنف تحت الاختبار):** `SensitivePermissionsTest` (2: كل منهما في اختبار مستقل ملحق بآخر الملف) و`EmployeesControllerTest` (4: المدير يمنح الصلاحيتين ويسحبهما ويُسجَّل الأمران، ولا يمنحهما غير المدير ولا يسحبهما، وحفظ غير المدير يبقي ما يحمله الموظف منهما، و«تحديد الكل» لا يمنحهما) و`AccountingSetupControllerTest` (1)؛ `ItemsControllerTest` (27: الجرد السريع والنموذج والاستيراد والحذف وقوائم المواقع وصفحة «لا صلاحية» (الفهرس والبحث) ومسار `stock_location` في العنوان لموقع فرع آخر وتسجيل قيد في فرع لا يعمل فيه الموظف دون استثناء وخانة الحذف في النموذج وسجل شاشة التفاصيل والرف الفارغ للصنف الجديد، مع المحاسبة معطّلة ومفعّلة، وموظف فرع واحد وكل الفروع) و`ItemTest` (15: حذف صنف وقائمة أصناف لموظف فرع واحد ولمن لا فرع له ولكل الفروع، ولا يمنع الحذفَ رفٌّ فارغ ولا موقع محذوف في فرع آخر، ورفض سابق لا يمتد إلى الحذف التالي، والنظام بلا موظف) و`StockAdjustmentTest` (5: رفض موظف خارج الفرع ولا يُرحَّل شيء، ورفض من لا فرع له، وقيد الفرع الصحيح مع مطابقة المخزون، والفرع الافتراضي، والنظام نفسه بلا قيد) و`InventoryTest` (4) و`ItemQuantityTest` (1) و`Item_libTest` (3)؛ `ReportsControllerTest` (21: صفحات التقارير والوسم وتفاصيل المستند بالمعرّف وشاشات الإدخال الست لمواقع فروع الموظف وحدها وتقرير المصروفات للشركة كلها وقائمة التقارير، كل تقرير منه في اختبار مستقل) و21 ملفًا تحت `tests/Models/Reports` (72 دالة: نموذج لكل تقرير: يرى موظف فرع «أ» صفوفه وحدها، وموظف «ب» صفوفه، وموظف الفرعين وكل الفروع والنظام الكل، ومن لا فرع له **لا شيء**، وصف الفرع الافتراضي القديم؛ و`ReportTest` فيه قراءة الشركة كلها) و`ReportScopeTest` (5: الوسم) و`Dashboard_libTest` (3: مفتاح الذاكرة والمصروفات) و`report_helperTest` (5) و`HomeTest` (1) و`CashupControllerTest` (1: مقبوضات الإقفال من كل الفروع)؛ `ReceivingsControllerTest` (6: وجهة التحويل وطلب التوريد ومنحة المصدر عند الإنهاء) و`NamedLockTest` (8: يؤخذ ويُفك، «مشغول»، «محتل قبل الطلب»، «مأخوذ على اتصال آخر» بمحاكاة مجمّع، إعادة الأخذ مرتين، والمجسّ في الحالتين) و`CustomerLockTest` (3) و`SupplierLockTest` (4) و`AccountingCheckLocksTest` (2). **محاكي المجمّع** في `tests/Support/PooledConnection.php` و`PooledResult.php` يوجّه أمر القفل إلى اتصال والفحص إلى اتصال آخر.
- **اختبارات الحدود والتجاوز:** لا فرع (`[]`) ≠ كل الفروع (`null`) في كل نموذج؛ النظام بلا موظف غير مقيَّد؛ المحاسبة معطّلة لا تعطّل الرفض في شاشات الأصناف؛ وجهة بمنحة وبلا منحة وبمنحة في فرع آخر؛ ملف CSV بعمود موقع فرع آخر لا يحفظ شيئًا؛ حذف صنف بمخزون في فرعين لموظف فرع واحد لا يغيّر شيئًا؛ حذف صنف مخزونه كله في فرع الموظف يمرّ ويُرحَّل فيه.
- **تعطيل الحراس:** عطّلتُ **كل حارس على حدة** بسكربت `mutate_generic.py` وشغّلتُ اختبارات كلٍّ منها: **123 حارسًا** (8 للصلاحيات الحساسة، و37 لشاشات الأصناف وخدمة التسوية، و6 لوجهة التحويل ومصدره، و8 للأقفال، و64 للتقارير القديمة ولوحة البداية والإقفال النقدي؛ والتعداد مشتق من فروق `app/` بين `f5d4927` والرأس، ولم يبقَ ملف تغيّر فيه حارس بلا تعطيل له)، **فظهر فشل أو خطأ أو توقف في اختبارات كلٍّ منها (123 من 123)**، في 14 دفعة (عشرة حراس أو أقل في كل دفعة) تلخيص كلٍّ منها في الملحق أدناه كما طُبع. **وكل الدفعات شُغّلت من جديد على رأس الفرع الأخير** بعد إصلاحات المراجعة (الدفعات من 1 إلى 11 لحراس الجولة الأولى، و12 إلى 14 (23 حارسًا `FIX-*`) لحراس الإصلاحات)، ولا يبقى مما سبق تشغيلٌ قديم. وأظهر قياس الجولة الأولى في الدفعات من السادسة إلى التاسعة **اثني عشر حارسًا لم تلتقطه الاختبارات في أول تشغيل** (`G48-23` و`G48-27` و`G48-29` و`A2A-13` و`A2A-14` و`A2A-16` و`A2A-18` إلى `A2A-23`)، فأضفتُ لكلٍّ منها اختبارًا ثم أعدتُ تشغيل الدفعة كلها وصارت 10 من 10؛ وكان `G48-23` مخفيًّا بخلل في الاختبار نفسه (ترويسة `Location` باقية من طلب سابق تُرضي `assertRedirect()`)، فصار الاختبار يتحقق من رمز الحالة 302 (والآن 403). وفي جولة المراجعة **سُحب حارس واحد** هو `A2A-7` (فحص الفهرس لموظف بلا موقع في فروعه): صار زائدًا بعد أن صار `Item_lib::get_item_location` يرفض هذه الحالة لكل الأفعال، فقاس التشغيل بعد الإصلاح `UNDETECTED` لتعطيله فحذفتُ الفحص المكرَّر وحارسه وأبقيتُ `FIX-4`. ولم أُبقِ شيئًا معطّلًا.
- **ملفات الاختبار التي شغّلتُها (بلا المجموعة الكاملة بطلب التكليف)** على قاعدة `ospos_test_a` وحدها، بعد آخر تعديل في الشيفرة والاختبارات: **34 تشغيلًا (مجلد `tests/Models/Reports` كاملًا و33 ملفًا)، 2524 اختبارًا و8854 تأكيدًا، كلها ناجحة** (الصيغة `OK` من PHPUnit في كل سطر، ولا فشل ولا خطأ ولا اختبار متجاوَز). المجموعة الكاملة يشغّلها المُدمِج بعد الدمج. **تنبيه لبيئة التشغيل:** اختبارات السباق (`CustomerLockTest` و`SupplierLockTest` وغيرهما ممن يستعمل `raceTwoProcesses`) تُشغّل عملياتٍ فرعية بمُحمِّل composer المشترك (`vendor` رابط رمزي إلى النسخة الرئيسية) فتعمل بشيفرة النسخة الرئيسية لا الفرع. حين صار فيها كود دمج الموجة الأولى (`numbering_source_invalid` من `DocumentNumbering`) فشلت هذه الاختبارات على قاعدتي (التي لا تحمل ترحيلاته) فشلًا لا علاقة له بالحزمة؛ فشغّلتُها بملف ini يضيف `auto_prepend_file` يوجّه الفئات إلى الفرع (على غرار `wt-bootstrap.php`) فنجحت بالأعداد نفسها (10 اختبارات و55 تأكيدًا، و17 و122)، وبهذا شغّلتُ كل دفعات التعطيل. على المُدمِج أن يشغّل المجموعة الكاملة في نسخة واحدة موحّدة الشيفرة والترحيلات.

```
tests/Models/Reports: 73 tests, 881 assertions
tests/Controllers/ItemsControllerTest.php: 92 tests, 680 assertions
tests/Controllers/ReceivingsControllerTest.php: 79 tests, 674 assertions
tests/Controllers/ReportsControllerTest.php: 26 tests, 302 assertions
tests/Controllers/EmployeesControllerTest.php: 42 tests, 214 assertions
tests/Controllers/AccountingSetupControllerTest.php: 23 tests, 129 assertions
tests/Libraries/Accounting/NamedLockTest.php: 8 tests, 18 assertions
tests/Libraries/Accounting/CustomerLockTest.php: 10 tests, 55 assertions
tests/Libraries/Accounting/SupplierLockTest.php: 17 tests, 122 assertions
tests/Libraries/Accounting/StockAdjustmentTest.php: 15 tests, 136 assertions
tests/Libraries/Accounting/SensitivePermissionsTest.php: 9 tests, 54 assertions
tests/Libraries/Accounting/ReportScopeTest.php: 17 tests, 36 assertions
tests/Libraries/Accounting/AccountingGuardTest.php: 4 tests, 12 assertions
tests/Libraries/Accounting/CreditSettlementTest.php: 18 tests, 169 assertions
tests/Libraries/Accounting/SupplierSettlementTest.php: 19 tests, 241 assertions
tests/Libraries/Accounting/SupplierAdvancesTest.php: 26 tests, 237 assertions
tests/Libraries/Dashboard_libTest.php: 9 tests, 58 assertions
tests/Commands/AccountingCheckLocksTest.php: 2 tests, 4 assertions
tests/Models/ItemTest.php: 31 tests, 294 assertions
tests/Models/ReceivingTest.php: 12 tests, 75 assertions
tests/Models/SaleTest.php: 18 tests, 87 assertions
tests/Models/Stock_locationTest.php: 11 tests, 35 assertions
tests/Models/ItemQuantityTest.php: 4 tests, 13 assertions
tests/Controllers/SalesControllerTest.php: 97 tests, 592 assertions
tests/Controllers/HomeTest.php: 23 tests, 58 assertions
tests/Controllers/AccountingReportsControllerTest.php: 49 tests, 278 assertions
tests/Controllers/AccountingTreasuriesControllerTest.php: 46 tests, 367 assertions
tests/Controllers/AccountingPayablesControllerTest.php: 30 tests, 250 assertions
tests/LanguageFilesTest.php: 1680 tests, 2692 assertions
tests/helpers/report_helperTest.php: 8 tests, 28 assertions
tests/helpers/NavigationHelperTest.php: 16 tests, 22 assertions
tests/Controllers/CashupControllerTest.php: 3 tests, 26 assertions
tests/Libraries/Item_libTest.php: 3 tests, 11 assertions
tests/Models/InventoryTest.php: 4 tests, 4 assertions
```

## 3. ما افترضتُه (بنود قرار للمالك)

1. **تقرير المصروفات للشركة كلها فقط.** المصروف لا يحمل فرعًا، فلا يصح أن يرى موظف بعض الفروع رقمًا عن الشركة كلها كأنه رقم فروعه. اخترتُ الرفض (صفحة «لا صلاحية») على عرض رقم جزئي مضلِّل. إن أردتَ تقرير مصروفات لكل فرع فيلزم أولًا فرع للمصروف (WP3).
2. **حذف الصنف ذي المخزون في فرع آخر يُرفض كله** على أن يُحذف ما في فروعه ويبقى الباقي، لأن الحذف عملية على الصنف لا على الموقع، وإبقاء الصنف محذوفًا مع مخزون حيّ في فرع آخر يكسر المخزون.
3. **موظف لا موقع له في فروعه يُحوَّل إلى «لا صلاحية»** في كل أفعال الأصناف (الفهرس والبحث والصف والباركود والتعديل الجماعي) بدل أن يرى قائمة فارغة أو خطأ خادم.
4. **رفض المجمّع يستعمل رسالتي «مشغول» نفسيهما** (`CustomerBusy` و`SupplierBusy`) والفرق في سياق الخطأ (`lock = not_held` أو `occupied`) والسجل `critical`، فلا أضيف نوع خطأ ولا تغيّر شاشة. إن أردتَ رسالة بمعنى «اتصال قاعدة البيانات لا يحفظ الأقفال» صريحة للمستخدم فهو مفتاح لغة وخطأ جديدان.
5. **وسم التقارير يعيد استعمال مفاتيح P8** (`Accounting_reports.scope_*`) ولا يضيف مفاتيح جديدة؛ ويظهر الوسم في رأس كل تقرير يستعمل `reports/_head.php`.
6. **كشوف العملاء والموردين والتقارير المالية (P8)** لم أمسّها: لها نطاقها الخاص من P8/E45.
7. **الإقفال النقدي للشركة كلها** (قرار للمالك): سجل `cash_up` بلا فرع، وقائمته وإقفالاته وطرحه للمصروفات للشركة كلها، فبقيت مقبوضاته المسبقة الملء من كل الفروع كما قبل الحزمة لئلا تُخلط بمصروفات الشركة كلها. فموظف فرع واحد يملك وحدة الإقفال النقدي يرى في النموذج مقبوضات كل الفروع (وفي قائمة الإقفالات مبالغ الإقفالات كلها كما كان). **إن أردتَ إقفالًا لكل فرع** فيلزم فرع في `cash_up` ومصروف بفرع (WP3).
8. **خانة «محذوف» في نموذج الصنف** لا تزيل المخزون (دلالتها قبل الحزمة)، وإنما يرفضها الحارس لموظف لا يعمل في فرع فيه مخزون الصنف لأن الصنف المحذوف يختفي من مبيعات ذلك الفرع واستلامه.

## 4. حدود معروفة وما لم يُنفَّذ

- **ملفا اختبار قديمان لا يعملان أصلًا:** `tests/Models/Reports/Summary_discounts_test.php` و`Summary_taxes_test.php` (لاحقة `_test.php` لا يجمعها PHPUnit، وإن شُغّلا صراحةً فشلا على الأساس: الأول يفترض أن رمز العملة يُهرَّب بدالة غير موجودة، والثاني يفشل بمفتاح أجنبي في بذرته). **لم أصلحهما ولم أحذفهما** (خارج الحزمة)، وجعلتُ اختباراتي في `Summary_discountsTest.php` و`Summary_taxesTest.php` المجموعين. وهذا يخالف «ملف واحد لكل صنف» ظاهرًا لا فعلًا، لأن القديمين ميتان؛ ودمجهما في الجديدين يعني إصلاح بذرة الأول (مفتاح أجنبي لمورد رقمه 1) وقرارًا في معنى اختبار هروب رمز العملة في الثاني. للمُدمِج أن يدمجهما أو يحذف القديمين.
- **شاشات المبيعات والاستلام وسجل البيع** لم تتغير (قاعدة الفروع مطبّقة فيها منذ A3a وP4).
- **تقارير الأصناف (`Summary_items`) تتقيد ببيع الفرع** (مبيعاتها)، أما **كمية الصنف في الرفوف** فتقيّدها تقارير المخزون بفرع الموقع.
- **تنبيه للاختبارات الموجودة:** في بيئة `FeatureTestTrait` تبقى ترويسة `Location` من طلب سابق في العملية نفسها، فيُرضي `assertRedirect()` طلبًا لم يُحوَّل أصلًا (هكذا اختفى غياب الحارس `G48-23`). اختباراتي تتحقق من رمز الحالة 302 حيث يلزم إثبات الرفض. ثلاثة اختبارات أقدم في `ReportsControllerTest` (`testNonAdminWithoutReportGrantIsDeniedSummaryCustomers` وأختاها) تستعمل `assertRedirect()` وحدها ولم أغيّرها لأنها خارج الحزمة؛ وقد تُرضيها الترويسة الباقية إن زال الحارس الذي تحميه بعد اختبار سبقها بتحويل في العملية نفسها (لم أقس ذلك لها، ولم تدخل في الحراس المعطَّلة).
- **لا يمنع القفل المسمّى بحد ذاته الوصول لقاعدة بيانات بلا قفل** (مجمّع يوزّع الاتصالات): الحارس يكشف ويرفض، ولا يصلح التوزيع نفسه. الحل التشغيلي أن يتصل التطبيق بقاعدة البيانات مباشرة أو بمجمّع بنمط الجلسة.
- **فكّ القفل خلف مجمّع يوزّع الأوامر:** حين يأخذ `GET_LOCK` اتصالًا ويقول `IS_USED_LOCK` إنه ليس اتصال العمل (`NotHeld`) يُطلب `RELEASE_LOCK` على اتصال العمل نفسه؛ فإن وجّه المجمّع هذا الأمر أيضًا إلى اتصال ثالث لم يفكّ شيئًا (يعيد 0، ولا يُفحص ناتجه) وبقي القفل في جلسة الخادم التي أخذته حتى تنتهي، فتُرفض تعديلات ذلك العميل أو المورد (`Busy` أو `Occupied`) حتى تدور الجلسة. الحالة إغلاق على الفشل بلا تلف بيانات، ولا علاج لها في التطبيق (لا سبيل إلى جلسة خادم أخرى)؛ علاجها تشغيلي (`accounting:check_locks` واتصال مباشر أو مجمّع بنمط الجلسة).
- **حذف صنف له مخزون ولا موظف مسجَّل** (أمر سطر أوامر أو مهمة) يفشل في `Inventory::resetRows` لأنه يقرأ رقم الموظف المسجَّل لسجل الحركة، كما كان قبل الحزمة؛ فحارس الحذف لا يقيّد النظام بلا موظف لكن الحذف نفسه لا يكتمل بلا موظف. لم أُغيّره (خارج الحزمة).
- **سجل الصلاحيات الحساسة للصلاحيتين الجديدتين** يبدأ من الحفظ التالي لكل موظف؛ من يحملهما اليوم لا يُسجَّل لهما منح سابق، ويبقيان له (لا يُسحب شيء).

## 5. قبل النشر

**لا ترحيل في هذه الحزمة** ولا جدول ولا عمود. أثرها عند التشغيل: (1) موظف غير مدير كان يمنح صلاحيتي الخزائن أو دفعات الموردين لا يستطيع بعد الآن، ويبقى ما مُنح لغيره كما هو. (2) موظف مقيَّد بفرع يفقد رؤية مواقع المخزون وبيانات التقارير القديمة لبقية الفروع (وسجل حركة الصنف في شاشة التفاصيل)، ومن لا مواقع له في فروعه يرى «لا صلاحية» في كل أفعال الأصناف، ويفقد رابطي تقرير المصروفات من القائمة وبحث التنقل، ويبقى الإقفال النقدي للشركة كلها. وكل صنف جديد يأخذ رفًّا فارغًا في كل مواقع المخزون. (3) في بيئة فيها مجمّع اتصالات شغّل `php spark accounting:check_locks`: إن أعاد خطأ فتعديلات العملاء والموردين تُرفض إلى أن يتصل التطبيق بقاعدة البيانات دون مجمّع يشارك الاتصالات.

## 6. تحديثات الوثائق المشتركة للمُدمِج

لم أعدّل أيًّا من `decisions.md` و`conformance-matrix.md` و`gap-report.md` و`backlog.md` ولا أي `README` ولا `deploy-checklist.md`. فيما يلي النصوص جاهزة للصق.

### 6.1 `decisions.md` (نص مقترح بلا رقم E)

> **ضوابط الصلاحيات والفروع والأقفال (WP1، 2026-10-27):** صلاحيتا `acc_treasuries_manage` و`acc_payables_manage` **حساستان** كالخمس التي سبقتهما (فالقائمة سبع): لا تُمنحان تلقائيًا ولا بـ«تحديد الكل»، ولا يمنحهما أو يسحبهما إلا المدير، وحفظ غير المدير يُبقي ما يحمله الموظف منهما، ويُسجَّل كل منح وسحب. **شاشات الأصناف (الجرد السريع، حفظ النموذج، استيراد CSV، حذف الصنف) وخدمة تسوية المخزون** تطبّق قاعدة البيع والاستلام: لا يغيّر الموظف مخزون موقع إلا بمنحة وحدة الأصناف على الموقع **وبأن يعمل في فرعه**، ولا يُرحَّل قيد تسوية في فرع لا يعمل فيه (حارس في الخدمة لا في الشاشة وحدها)، ولا يُحذف صنف له مخزون في فرع لا يعمل فيه. **التقارير التشغيلية القديمة** (المبيعات والاستلام والمخزون والخصومات والضرائب ولوحة البداية) تُقيَّد بفروع الموظف: لا فرع = لا شيء، كل الفروع والنظام بلا قيد، ويقول رأس التقرير «فروع محدودة» أو «الشركة كلها» بحسب القاعدة نفسها لميزان المراجعة (E24/E45)؛ **وتقرير المصروفات للشركة كلها فقط** (لا فرع للمصروف) فيُرفض لمن لا يعمل في كل الفروع، **والإقفال النقدي للشركة كلها** (سجله بلا فرع). **وجهة طلب التوريد والتحويل** تُفحص بمنحة الاستلام وبفرع الموظف عند تغيير الوضع وعند الإنهاء. **أقفال العميل والمورد المسماة** لا تُعدّ مأخوذة إلا إذا أكّد الخادم أن الاتصال الذي ينفّذ العمل هو الذي يملكها، وإلا **رُفضت العملية** (إغلاق على الفشل) بدل العمل بلا حماية من الإنفاق المزدوج، ويفحص `accounting:check_locks` بيئة التشغيل. لا ترحيل ولا قيد ولا رصيد يتغير.

### 6.2 `conformance-matrix.md` (صفوف مقترحة)

يُغيَّر صفّا `ACC-28` و`INV-09` ويُضاف صفان في الصيغة نفسها (المعرّف | الموضوع | دفترة | الأول برو | الحالة | ملاحظة):

| المعرّف | الموضوع | دفترة | الأول برو | الحالة | ملاحظة |
|---|---|---|---|---|---|
| ACC-28 (تعديل خانة الأول برو) | صلاحيات الحسابات | 13 صلاحية: القيود (كل/الخاصة، مسودة، حذف)، حسابات القيود، مراكز التكلفة، الفترات المقفلة، الأصول | ثماني صلاحيات `acc_*` وحساسة **وصلاحيتا الخزائن ودفعات الموردين حساستان أيضًا (WP1)**، فالحساسة سبع: لا يمنحها ولا يسحبها غير المدير، وحفظ غير المدير يُبقي ما يحمله الموظف منها، ويُسجَّل كل تغيير. ضابط في الأول برو لا مقابل مرصودًا له في دفترة | مختلف ويحتاج تعديلًا | addI |
| INV-09 (إضافة إلى ملاحظة الصف) | مستودع وخزينة افتراضيان للموظف | (دون تغيير) | مواقع الموظف بالصلاحيات **وبفروعه** (WP1): الأصناف والتقارير والاستلام تقبل موقعًا بمنحة الوحدة وفي فرع يعمل فيه الموظف وحده | مختلف ويحتاج تعديلًا | setC. القاعدة ضابط محلي؛ سلوك الموظف المقيَّد في دفترة (`S-32`، مصفوفة الأدوار `R-04`) غير مرصود، فلا ادّعاء تطابق |
| REP-WP1 (صف جديد، معرّف مؤقت يضع المُدمِج غيره) | تقييد التقارير التشغيلية بفرع الموظف | لم يُرصد | **تقارير الأول برو القديمة تقرأ فروع الموظف وحدها** (WP1): لا فرع = لا شيء، كل الفروع بلا قيد، والتفاصيل بالمعرّف 404 لمستند فرع آخر، ووسم «فروع محدودة/الشركة كلها»، ولوحة البداية بمفتاح ذاكرة لكل مجموعة فروع، والمصروفات والإقفال النقدي للشركة كلها فقط | غير قابل للتحقق بعد | ضابط محلي لا مقابل مرصودًا له، فلا يُحتسب في «مطابق» ولا «مختلف» ولا يغيّر الإجمالي (149)؛ يحتاج WP20 لقراءة سلوك الموظف المقيَّد |
| LOCK-WP1 (صف جديد، معرّف مؤقت) | الحماية من الإنفاق المزدوج لرصيد العميل والمورد | لم يُرصد | **القفل المسمّى لا يُعدّ مأخوذًا إلا إذا ثبت أن الاتصال الذي ينفّذ يملكه، وإلا رُفضت العملية** (WP1)، وأمر `accounting:check_locks` للتشغيل | غير قابل للتحقق بعد | ضابط هندسي لا يخص مقارنة دفترة، ولا يغيّر الإجمالي (149) |

### 6.3 `gap-report.md` (تحديث صفوف)

يُحذف الصف `G-48` من جدول المفتوح ويُنقل إلى المُنجَز بهذا النص، ويُضاف إلى قسم ما أُغلق:

| المعرّف | الوصف | الأدلة | متى | الحجم |
|---|---|---|---|---|
| `G-48` | **تقارير الاستلام وفرع الموظف — أُغلقت 2026-10-27 (WP1):** شمل تقييد الفرع الآن **كل نماذج `app/Models/Reports` وشاشات `Reports`** لا تقارير الاستلام وحدها: المبيعات والاستلام والمخزون والخصومات والضرائب وضرائب المبيعات وتفاصيل المستند بالمعرّف ولوحة البداية، بالقاعدة نفسها (لا فرع = لا شيء، كل الفروع بلا قيد) ووسم «فروع محدودة/الشركة كلها». **والمصروفات والإقفال النقدي للشركة كلها فقط** (لا فرع للمصروف ولا لسجل الإقفال) | `Models/Reports/*Test` و`ReportsControllerTest` و`Dashboard_libTest` | WP1 | — |
| `A2A-STOCK-ACCESS` و`G41-A5` و`P5-LOCK-POOLING` و`P6-SENSITIVE-PERMS` | أُغلقت في WP1 بالنصوص في القسم 1 من [wp1-report.md](wp1-report.md) | الاختبارات في القسم 2 | WP1 | — |

### 6.4 `backlog.md` (حالات)

صفوف قسم البنود: `P6-SENSITIVE-PERMS` و`A2A-STOCK-ACCESS` و`G-48` و`G41-A5` و`P5-LOCK-POOLING` تصير الحالة **`done (WP1)`**، ويُعلَّم `WP1` منجزًا في جدول الحزم ويُسقط من اعتماد `WP2` و`WP3` و`WP7` و`WP11` و`WP17` و`WP18` و`WP30` و`WP32` شرطُه. ويُضاف إلى `WP3` (المصروفات) ملاحظة: **المصروف بلا فرع اليوم فتقريره للشركة كلها فقط**؛ إن رُحِّل المصروف بفرع فيُفتح للموظف المقيَّد.

### 6.5 `docs/ui-redesign/deploy-checklist.md` (بند مقترح)

- [ ] **ضوابط الصلاحيات والفروع والأقفال (WP1):** **بلا ترحيل ولا جدول ولا عمود**، فلا يتغير قيد ولا رصيد ولا مستند. بعد النشر: (1) صلاحيتا `acc_treasuries_manage` و`acc_payables_manage` صارتا حساستين: من يحملهما يبقيهما، ولا يمنحهما أو يسحبهما بعد الآن إلا المدير (راجع من يحملهما في شاشة الإعداد). (2) **موظف مقيَّد بفرع** لا يغيّر مخزون موقع فرع آخر من شاشات الأصناف ولا يحذف صنفًا له مخزون هناك، ويرى في التقارير القديمة ولوحة البداية أرقام فروعه وحدها، **وتقرير المصروفات ولوحة المصروفات للشركة كلها فقط (موظف كل الفروع) والإقفال النقدي للشركة كلها**؛ تأكد أن الموظفين الذين تتوقع أن يروا الشركة كلها يعملون في كل الفروع. (3) **شغّل `php spark accounting:check_locks` على كل بيئة**: يجب أن يقول «The connection to the database keeps named locks». إن أخطأ فبيئتك فيها مجمّع/وكيل اتصالات يشارك الاتصالات، وتُرفض تعديلات العملاء والموردين (إغلاق على الفشل) إلى أن تُصحَّح.

## ملحق: نتائج تعطيل الحراس (قياس فعلي بـ mutate_generic.py)

كل سطر حارس عُطِّل وحده وشُغِّلت اختباراته (`DETECTED` تعني أن اختبارًا فشل أو توقف). النتائج كما طُبعت في آخر تشغيل كامل على رأس الفرع، مقسومة على دفعات من عشرة حراس أو أقل (الدفعات 1 إلى 11 لحراس الجولة الأولى، و12 إلى 14 لحراس إصلاحات المراجعة).

### الدفعة 1

```
DETECTED  P6-1 treasuries permission is sensitive: Tests: 9, Assertions: 49, Failures: 2.; failing: 2
DETECTED  P6-2 payables permission is sensitive: Tests: 9, Assertions: 50, Failures: 2.; failing: 2
DETECTED  P6-3 treasuries permission is sensitive (employee form): Tests: 42, Assertions: 187, Failures: 4.; failing: 4
DETECTED  P6-4 payables permission is sensitive (employee form): Tests: 42, Assertions: 203, Failures: 4.; failing: 4
DETECTED  P6-5 non-administrator cannot hand out a sensitive grant: Tests: 42, Assertions: 201, Failures: 4.; failing: 4
DETECTED  P6-6 non-administrator save keeps the sensitive grants held: Tests: 42, Assertions: 210, Failures: 2.; failing: 2
DETECTED  A2A-1 item locations are limited to the branches of the employee: Tests: 92, Assertions: 675, Failures: 2.; failing: 2
DETECTED  A2A-2 quantity change refused in a location outside the branches: Tests: 92, Assertions: 673, Failures: 3.; failing: 3
DETECTED  A2A-3 item form saves only the quantities of allowed locations: Tests: 92, Assertions: 657, Errors: 4, Failures: 1.; failing: 5
DETECTED  A2A-4 import header check uses the allowed locations: Tests: 92, Assertions: 669, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 2

```
DETECTED  A2A-5 import row check uses the allowed locations: Tests: 92, Assertions: 669, Failures: 1.; failing: 1
DETECTED  A2A-6 item list offers only the allowed locations: Tests: 92, Assertions: 679, Failures: 1.; failing: 1
DETECTED  A2A-8 item form lists only the allowed locations: Tests: 92, Assertions: 669, Failures: 1.; failing: 1
DETECTED  A2A-8b inventory form lists only the allowed locations: Tests: 92, Assertions: 673, Failures: 1.; failing: 1
DETECTED  A2A-8c count form lists only the allowed locations: Tests: 92, Assertions: 672, Failures: 2.; failing: 2
DETECTED  A2A-9 an item with stock outside the branches cannot be deleted: Tests: 31, Assertions: 291, Failures: 1.; failing: 1
DETECTED  A2A-10 a list of items with stock outside the branches cannot be deleted: Tests: 31, Assertions: 290, Failures: 1.; failing: 1
DETECTED  A2A-10b a list of items with stock outside the branches cannot be deleted (screen): Tests: 6, Assertions: 72, Failures: 1.; failing: 1
8 of 8 guards detected
```

### الدفعة 3

```
DETECTED  A2A-11 the saved location of the screen is checked against the branches: Tests: 92, Assertions: 680, Failures: 1.; failing: 1
DETECTED  A2A-12 the journal of a stock adjustment needs the branch of its location: Tests: 15, Assertions: 133, Failures: 2.; failing: 2
DETECTED  G41-A5-1 the destination of a requisition is checked when the mode changes: Tests: 79, Assertions: 671, Failures: 2.; failing: 2
DETECTED  G41-A5-2 the destination is checked against the grants and the branches: Tests: 79, Assertions: 671, Failures: 2.; failing: 2
DETECTED  G41-A5-3 the destination is checked again when the requisition is completed: Tests: 79, Assertions: 671, Failures: 1.; failing: 1
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
DETECTED  G48-1 limitToBranches limits the query: Tests: 10, Assertions: 46, Failures: 2.; failing: 2
DETECTED  G48-2 limitToBranches refuses everything to an employee of no branch: Tests: 10, Assertions: 46, Failures: 1.; failing: 1
DETECTED  G48-3 branchCondition limits a hand-written query: Tests: 10, Assertions: 40, Failures: 1.; failing: 1
DETECTED  G48-4 branchCondition refuses everything to an employee of no branch: Tests: 10, Assertions: 43, Failures: 1.; failing: 1
DETECTED  G48-5 limitedInputs carries the branches: Tests: 10, Assertions: 43, Failures: 1.; failing: 1
DETECTED  G48-6 the table aliases of the query are put back: Tests: 10, Assertions: 45, Failures: 1.; failing: 1
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
DETECTED  G48-22 the expenses report is refused to an employee of some branches (page): Tests: 26, Assertions: 300, Failures: 1.; failing: 1
DETECTED  G48-23 the expenses report is refused to an employee of some branches (graph): Tests: 26, Assertions: 300, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 7

```
DETECTED  G48-24 the company-wide check looks at the branches of the employee: Tests: 26, Assertions: 298, Failures: 2.; failing: 2
DETECTED  G48-25 a sale of another branch is not found: Tests: 26, Assertions: 302, Failures: 1.; failing: 1
DETECTED  G48-26 a receiving of another branch is not found: Tests: 26, Assertions: 302, Failures: 1.; failing: 1
DETECTED  G48-27 the inventory screen lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-28 the date screen of the summary reports lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-29 the screen of receivings by date lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-30 the report page says whose figures it holds: Tests: 26, Assertions: 294, Failures: 1.; failing: 1
DETECTED  G48-31 the report scope reads the branches of the employee: Tests: 26, Assertions: 295, Failures: 2.; failing: 2
DETECTED  G48-32 the label tells a partial scope from the company: Tests: 17, Assertions: 35, Failures: 1.; failing: 1
DETECTED  G48-33 the dashboard hides expenses from an employee of some branches: Tests: 9, Assertions: 54, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 8

```
DETECTED  G48-34 the dashboard cache is kept by branches: Tests: 9, Assertions: 57, Failures: 2.; failing: 2
DETECTED  P6-7 the setup screen names who holds the treasuries permission: Tests: 23, Assertions: 119, Errors: 2.; failing: 2
DETECTED  P6-8 the setup screen names who holds the payables permission: Tests: 23, Assertions: 119, Errors: 2.; failing: 2
DETECTED  A2A-13 the location of the URL is checked against the allowed locations: Tests: 92, Assertions: 679, Failures: 1.; failing: 1
DETECTED  A2A-14 a refused journal of the screen is reported, not thrown: Tests: 92, Assertions: 677, Errors: 1.; failing: 1
DETECTED  A2A-15 the refusal of a delete is explained to the employee: Tests: 92, Assertions: 675, Failures: 1.; failing: 1
DETECTED  A2A-16 a refused journal of a delete is reported, not thrown: Tests: 31, Assertions: 291, Errors: 1.; failing: 1
DETECTED  A2A-17 an employee of every branch is not limited when deleting: Tests: 31, Assertions: 263, Errors: 7.; failing: 7
DETECTED  A2A-18 stock inside the branches of the employee does not block a delete: Tests: 31, Assertions: 280, Failures: 6.; failing: 6
DETECTED  A2A-19 an employee of no branch cannot delete an item with stock: Tests: 31, Assertions: 286, Errors: 2.; failing: 2
10 of 10 guards detected
```

### الدفعة 9

```
DETECTED  A2A-20 an empty shelf of another branch does not block a delete: Tests: 31, Assertions: 292, Failures: 1.; failing: 1
DETECTED  A2A-21 a deleted location of another branch does not block a delete: Tests: 31, Assertions: 292, Failures: 1.; failing: 1
DETECTED  A2A-22 the refusal of an earlier delete is not carried to the next one: Tests: 31, Assertions: 294, Failures: 1.; failing: 1
DETECTED  A2A-23 the refusal of an earlier list delete is not carried to the next one: Tests: 31, Assertions: 294, Failures: 1.; failing: 1
DETECTED  G41-A5-4 the destination is kept as a number: Tests: 79, Assertions: 674, Failures: 1.; failing: 1
DETECTED  G41-A5-5 the refusal of a destination is told to the employee: Tests: 79, Assertions: 671, Failures: 2.; failing: 2
DETECTED  G48-35 the system with nobody logged in is not limited: Tests: 10, Assertions: 46, Failures: 1.; failing: 1
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
DETECTED  G48-28b the discounts screen lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-28c the screen of detailed sales lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-28d the screen of sales by item lists the locations of the branches: Tests: 26, Assertions: 298, Failures: 1.; failing: 1
DETECTED  G48-36 a single branch is not told whose figures a report holds: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
DETECTED  G48-37 the label of the whole company: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
DETECTED  G48-38 the label of the selected branches: Tests: 17, Assertions: 36, Failures: 1.; failing: 1
10 of 10 guards detected
```

### الدفعة 11

```
DETECTED  G48-39 a partial scope is shown as a warning: Tests: 26, Assertions: 295, Failures: 1.; failing: 1
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

### الدفعة 14

```
DETECTED  FIX-6g the system with nobody logged in keeps every report: Tests: 8, Assertions: 28, Failures: 1.; failing: 1
DETECTED  FIX-7 the source of a requisition is checked again when it is completed: Tests: 79, Assertions: 671, Failures: 1.; failing: 1
DETECTED  FIX-8 the system with nobody logged in is not limited when it deletes an item: Tests: 31, Assertions: 294, Failures: 1.; failing: 1
3 of 3 guards detected
```
