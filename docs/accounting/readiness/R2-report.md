# تقرير المُقيِّم R2: الدورات الكاملة في التطبيق الحقيقي، وما ينكسر عند المستخدم الصعب

**التاريخ:** 2026-11-14. **الفرع:** `wp/R2` (من رأس التطوير `c0a9bb6`). **الشريحة:** المعيار 1 (اكتمال الدورات) والمعيار 2 (صحة القيود والأرصدة والتقارير) والمعيار 7 (غياب العيوب التي تمنع التشغيل). **لم يُدمج شيء ولم يُنشر شيء ولم تُلمس قاعدة إنتاج ولا نسخة التطوير الرئيسية.**

## 0. الحكم بصراحة

- **هل تُشغَّل تجربة (pilot) بعد هذه المراجعة؟ نعم، بشرطين:** دمج إصلاحات هذا الفرع الثلاثة (المسارات `save/-1` وصفوف ضريبة الصنف وأرقام المستندات اللاتينية) قبل أي استخدام، وقراءة قسم «الفجوات المهمة» لأن بينها ما يعطي رقمًا خاطئًا في ظروف عادية (الجرد النقدي، الدفع الجزئي في الاستلام، حذف عميل عليه رصيد).
- **هل يُشغَّل الإنتاج؟ ليس قبل** الإصلاحات المذكورة وقرار المالك في بنود «الفجوات المهمة» R2-04 إلى R2-09، وإعادة كتابة فحص الجاهزية الذي يُظهر «فشل» لثلاث حالات سليمة (R2-05).
- **الأرقام نفسها سليمة:** كل دورة شغّلتُها عبر HTTP والجلسات ونماذج المتصفح الحقيقية طابقت حسابي المستقل باليد قرشًا بقرش (المشتريات والمبيعات والمخزون والخزينة والقيود)، وكل تقرير مالي طابق SQL على القيود وعلى المستندات، والميزانية موزونة وصافي الربح يساوي حركة حقوق الملكية، وقفل الفترة يرفض كل ما جُرِّب داخله بلا تسرّب. **لم أجد خطأً في المال المرحَّل نفسه** (الجرد النقدي والدفع الجزئي في الاستلام قراران في قاعدة الحساب لا خطأ في الترحيل، وهما في الفجوات).
- **ما كشفته المراجعة كان في الواجهة والمسارات:** عشرة نماذج «جديد» تُرسل إلى مسار لا يوجد فيه (404)، منها **كل نماذج الشيكات وسند القبض**، وهذا هو العيب الوحيد الذي يمنع دورة كاملة. أُصلح باختبار أولًا.
- **كل ما سبق على جهاز مشترك** (متوسط الحمل من 10 إلى 19 على 4 أنوية)، فلا أقول شيئًا عن الأداء.

## 1. ما نُفِّذ وكيف

| البند | القيمة |
|---|---|
| التطبيق | `php -S` على المنفذ **8082** مع سكربت توجيه يصدّر البيئة (الوصفة في تقرير W96A القسم 7)، `CI_ENVIRONMENT=development`، `logger.threshold=9` لقراءة السجل |
| القاعدة | `ospos_test_r2_ui` (تُعاد من الصفر بـ`tools/dev/r2-setup.sh`: ترحيلات عبر شاشة الدخول، أشخاص وأصناف `tools/ui-smoke/seed.sql` بلا مخزون وبلا قيود، تفعيل المحاسبة بالأمر الرسمي، ثم الموردان والعملاء والأصناف والكاشير عبر **النماذج الحقيقية**) |
| المحرك | `tools/dev/r2-client.mjs` (عميل HTTP بجلسة وCSRF ونماذج تُقرأ كما يقرؤها المتصفح) و`r2-lib.mjs` (SQL مستقل، لقطات أرصدة، مسجِّل نتائج) |
| الحساب المستقل | في كل سيناريو: لقطة أرصدة كل الحسابات قبل وبعد، ثم **مقارنة الفرق بما حسبتُه باليد** وفحص أن **كل حساب لم أذكره لم يتحرك** (`deltaCheck`)؛ وللتقارير: SQL مستقل على سطور القيود (شجرة الحسابات بـ`parent_id`) وعلى المستندات (`sales_items` و`sales_items_taxes` و`sales_payments` و`customer_advances` و`cheques`) |
| بعد كل سيناريو | `php spark accounting:readiness --json --no-header`؛ ما ليس «نجاحًا» مدوَّن في 2.3 وR2-05 |
| المتصفح الحقيقي | Playwright/Chromium (`tools/dev/r2-browser.cjs`): 40 شاشة بحثًا عن أخطاء الـconsole، والنقر المزدوج على «إرسال» والنقر المزدوج على إنهاء البيع، وزر الرجوع بعد الإيصال، وحقن النصوص الخبيثة |
| الفحص الشامل للمسارات | `tools/dev/r2-sweep.mjs` يمشي كل مسارات `php spark routes` (1181 مسارًا): بلا جلسة، وككاشير، وكمدير بمعرّفات فاسدة |

الأدلة (ملفات JSON وسجلات): `/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/readiness/R2/` (مجلد `run4` للتشغيل النهائي من قاعدة جديدة؛ `run2` و`run3` للتشغيلين السابقين؛ `crawl3.mjs` و`pw1.cjs` و`pw2.cjs` و`probe*.mjs` للاستقصاء؛ لقطات: `item-after.png` و`cust-after.png`).

## 2. المرحلة أ: الدورات الكاملة وما طابق الحساب المستقل

الرقم في الجدول **ما قرأتُه من القاعدة بعد تنفيذ الخطوات عبر HTTP** مقابل **ما حسبتُه باليد** قبل التنفيذ. «لا شيء آخر يتحرك» تعني أن كل حساب غير مذكور في توقعي لم يتغير في لقطة الأرصدة.

| الدورة | ما شُغِّل (عبر الشاشات والنماذج) | النتيجة |
|---|---|---|
| **المشتريات** (P1 إلى P7) | استلام آجل لصنفين؛ استلام نقدي بتكلفة أخرى فتحدّث المتوسط المتحرك (11 ثم 10.6154 ثم 18.6667...)؛ دفعة مورد جزئية (80 من 200) ورصيده 120 على شاشة كشف المورد؛ مرتجع استلام (إشعار مدين) مقابل أصل؛ خصم نسبة وخصم ثابت لكل وحدة؛ ضريبة مشتريات 5% | **كلها طابقت**: المخزون والمورد والصندوق وضريبة المدخلات والكمية والمتوسط المتحرك لكل صنف، لا حساب آخر تحرك. المرتجع **يتطلب تسمية الاستلام الأصلي** (رسالة بكلمات) وهذا سلوك سليم |
| **المبيعات** (S0 إلى S15) | بيع نقدي من دون عميل؛ ضريبة مضافة 15%؛ **ضريبة مشمولة بالسعر** (3 وحدات بـ20 و5%: صافي 57.14 وضريبة 2.86)؛ دفع مختلط نقد+بطاقة؛ بيع آجل لعميل؛ نقد جزئي + آجل؛ خصم نسبة وخصم ثابت على السطر؛ خصم نسبة على الفاتورة؛ فاتورة بدفعة جزئية؛ إشعار دائن لجزء؛ رفض مرتجع أكبر من المباع؛ فاتورة استرداد نقدية؛ إلغاء فاتورة صادرة؛ رفض بيع بلا رصيد | **كلها طابقت** (الإيراد والضريبة وCOGS والمخزون والصندوق والعميل). إلغاء الفاتورة الصادرة **يُرفض بكلمات** («تُصحَّح بإشعار دائن») وبيع POS له مرتجع **يُرفض** إلغاؤه. تكلفة البضاعة المباعة تؤخذ من المتوسط الجاري |
| **ائتمان العملاء** (C1 إلى C3) | الحد الائتماني: الكاشير يُرفض بيعه بكلمات («سيدين 190.00 وحدّه 100») والمدير ذو الصلاحية يتجاوز ويسجَّل في سجل التدقيق؛ **قائمة أسعار** تُنسخ إلى السطر وتسعّر السجل مباشرة بعد اختيار العميل؛ **دفعة مقدمة 500** ثم فاتورة 50 | **طابقت** |
| **المخزون** (I0 إلى I7) | موقعان، وموقع في فرع آخر؛ **نقل بين موقعين** (قيمة بين حسابي الموقعين بالمتوسط)؛ **نقل بين فرعين** (قيدان متقابلان بحسابَي ما بين الفروع يتعادلان)؛ إذن إضافة وإذن صرف؛ **جرد** وموافقة؛ رفض المخزون السالب للبيع والإذن؛ تقرير التقييم | **طابقت** إلا نقطتين: النقل لا يمنعه خيار «ارفض» (R2-04) وفائض الجرد يُقيَّم بمتوسط الموقع (R2-09). تقرير التقييم يساوي مجموع حسابات المخزون بالقرش |
| **الخزينة والنقد** (T1 إلى T10) | خزينة نقدية وحساب بنكي؛ بيع ببطاقة إلى البنك؛ **تحويل بين خزينتين وإلغاؤه**؛ مصروف نقدي؛ سند قبض؛ **شيك مستلَم** (استلام ثم إيداع ثم تحصيل برسوم 5 فصافي البنك 295) وشيك مرفوض بعد الإيداع؛ دفتر شيكات وشيك مدفوع وتسليم وتحصيل؛ جرد نقدي؛ **استيراد كشف بنك** (3 سطور) | **طابقت** بعد إصلاح R2-01 (قبله كانت النماذج تعيد 404)؛ العيوب: R2-05 وR2-07. **لم أُجرِ مطابقة كشف البنك مع القيود** (استُوردت السطور فقط) |
| **المحاسبة** (E1 إلى E27) | ميزان المراجعة؛ قيد يدوي وعكسه ورفض عكسه مرتين؛ رفض قيد غير متوازن؛ **قفل الفترة** وكل ما حاولتُ داخله؛ شاشة الإقفال السنوي | انظر الجدول أدناه |

### 2.1 التقارير مقابل الحساب المستقل (E1 إلى E9)

| التقرير | ما طابقه | النتيجة |
|---|---|---|
| ميزان المراجعة | مجموع المدين = مجموع الدائن؛ **رصيد كل حساب** = SQL على السطور بشجرة `parent_id` | مطابق |
| قائمة الدخل / الأرباح والخسائر الشهرية | الإيراد − المصروف من SQL | مطابق (707.95؛ التشغيل `run4`) |
| الميزانية | الأصول = الخصوم + حقوق الملكية **بما فيها نتيجة الفترة**؛ الأصول = SQL على حسابات الأصول؛ نتيجة الفترة = صافي الدخل | مطابق |
| التدفق النقدي | صافي التغير في النقد = حركة حسابات الخزينة والبنك؛ التشغيلي + الاستثماري + التمويلي + «تغيرات أخرى» = التغير | مطابق (الإجمالي −560.00). **ملاحظة تصنيف:** بند «تغيرات أخرى» (−472.99) يحمل أرصدة كبيرة (ضريبة مستحقة، فرق الصندوق −735) خارج الأقسام الثلاثة المعتادة |
| تقرير ضريبة القيمة المضافة | «الضريبة المحصَّلة» = ضريبة **مستندات** المبيعات (من `sales_items_taxes`) وحساب ضريبة المخرجات؛ ضريبة المدخلات (4.00) = حسابها | مطابق (47.86 و4.00) |
| دفتر العميل | رصيد كل عميل = المبيعات + الضريبة + المرتجعات − الدفعات − المقدمات − الشيكات **من المستندات** | مطابق للعملاء الثلاثة |
| الأعمار والأرصدة (عملاء وموردين) | أرصدة الشاشتين تساوي أرصدة حسابات الدفتر | مطابق (عميل −650 دائن، مورد 270 و176) |

### 2.2 قفل الفترة (E14 إلى E26)

أُغلقت الفترة حتى اليوم، ثم حاولتُ داخلها: قيد يدوي، بيع نقدي، استلام، دفعة مورد، مقدمة عميل، مصروف، سند قبض، تحويل خزينة، عكس قيد، إلغاء بيع. **كل المحاولات رُفضت بالرسالة «The date 2026-11-14 is in a period closed through 2026-11-14» (والبيع سُجّل في `accounting_posting_failures` ولم يُترك شيء)**، ولم يتسرب أي قيد إلى الفترة بعد إقفالها (E25 وE25b)، وأعاد «إعادة الفتح» الأمور (E26). وفي المرحلة ب رُفض تعديل أسطر استلام وتعديل رأس فاتورة وإلغاء مقدمة وتعديل مصروف وحذفه داخل الفترة المغلقة.

### 2.3 «جاهزية» بعد كل سيناريو

بعد كل ملف سيناريو شغّلتُ `accounting:readiness --json --no-header`. **ما لم يكن «نجاحًا»:** `sensitive_grants` (مراجعة: منح المدير 25 صلاحية حساسة، متوقع)، و`clock_zones` (مراجعة: سطر الأوامر بتوقيت UTC لا بتوقيت المتجر)، و`activation` (ملاحظة: حساب ضريبة المخرجات مشترك بين الضرائب)، و`return_document` (مراجعة: إشعار استرداد منذ الترحيل)، و**ثلاثة إخفاقات سليمة** شرحتُها في R2-05 (`payment_stamping` و`source_keys` و`treasury_ties`). **لم تفشل `customer_ties` ولا `supplier_ties` ولا `stock_ties` ولا `journals_unbalanced` ولا `interbranch_ties` في أي لحظة.**

## 2ب. المرحلة ب: المستخدم الصعب (ملخص النتائج؛ التفاصيل في الجدول الشامل)

| الفحص | ما فُعل | النتيجة |
|---|---|---|
| رمز CSRF ناقص أو قديم (B1) | POST بلا رمز وبرمز خاطئ | 403 برسالة «الإجراء غير مسموح» بلا أثر؛ لا 500 |
| جلسة منتهية في منتصف نموذج (B2) | جلسة غير موجودة | الصفحة تحوّل إلى الدخول (302)، وطلب Ajax يردّ 401 «Session expired. Please log in again.» (الصفحة تحوّله إلى شاشة الدخول)؛ لا شيء حُفظ |
| النقر المزدوج / طلبان متوازيان (B3) | إنهاء البيع مرتين معًا؛ الاستلام؛ مقدمة عميل بثلاثة طلبات بمفتاح واحد | **بيع واحد وحركة نقد واحدة (25)**؛ استلام واحد؛ **مقدمة واحدة** (مفتاح الطلب). **دفعة المورد والمصروف تُسجَّلان مرتين** على مستوى الخادم (R2-15) |
| النقر المزدوج في المتصفح (BR) | زر «إرسال» لنموذج المصروف والعميل، وزر إنهاء البيع | **سجل واحد في كل حالة** |
| زر الرجوع بعد الإيصال | رجوع ثم إعادة الإنهاء | الخادم آمن (لا بيع ثانٍ)، لكن الصفحة تُظهر «تأكيد إعادة الإرسال/ERR_CACHE_MISS» (R2-13) |
| بيع آخر وحدة من جلستين (B4) | كاشيران يبيعان آخر وحدات صنف معًا والمنع مفعّل | **بيع واحد والمخزون 0 لا أقل** (القفل يعمل) |
| مدخلات غريبة في السجل (B5) | كمية صفر وسالبة و`999999999999` و`0.001` و`abc` و`٣` (هندي) و`١٫٥` و`1,5` و`1e3` وفراغ وأربعين رقمًا؛ سعر سالب ونصي وهندي وضخم؛ خصم >100% وسالب وثابت يفوق السطر | **لا صفحة خطأ ولا NaN**؛ السالب والخصم الزائد يُرفضان بكلمات؛ الرقم الهندي `٣` و`٢٥` يُقرآن 3 و25؛ **كمية ضخمة (999,999,999,999) تُقبل** وتعطي إجماليًا 49,999,999,999,950.00 (لا حد أعلى)؛ رسالة خام `Validation.decimal_locale` (R2-12) |
| نصوص غريبة (B6) | اسم من 400 حرف، إيموجي وعربي، علامات اقتباس، `<script>` و`<img onerror>`، حقن SQL | يُحفظ كله؛ **لا تنفيذ ولا وسوم حية** (قوائم الأصناف والعملاء والدفتر والإكمال التلقائي)؛ الاسم الطويل يُقطع إلى 255 بصمت (R2-22) |
| رقم الفاتورة مرتين (B7) | `DUP-100` لفاتورتين | الثانية تُرفض: «Invoice Number DUP-100 must be unique.» |
| تاريخ فاسد في نموذج التعديل (B8) | `not a date` | **500** (R2-11) |
| تحرير سجل واحد من نافذتين (CC1 وCC2) | سعر صنف وهاتف عميل | **آخر حفظ يمحو الأول بصمت** (R2-21) |
| مقدمتان من نافذتين معًا (CC3) | عشرة لكل منهما | مقدمتان والدفاتر متماسكة |
| تعديلات في أوقات حرجة (AW) | رفع تكلفة استلام بعد بيع جزء منه (R2-10)؛ إلغاء مقدمة بعد تطبيقها على فاتورة (الرصيد يعود 50 مدينًا والروابط متماسكة)؛ إلغاء بيع له مرتجع (يُرفض بكلمات)؛ رفع ضريبة صنف 15 إلى 20% في منتصف السنة (القديم 15 والجديد 20 وحساب المخرجات = مستندات المبيعات)؛ تغيير خصم المتجر الافتراضي مع عرض سعر مفتوح (الفاتورة تبقى 100)؛ تعديل مستندات في فترة مغلقة (كلها تُرفض)؛ حذف عميل ومورد عليهما رصيد (R2-06) | كما بين الأقواس |
| صلاحيات الوصول المباشر (sweep) | 486 مسار GET: بلا جلسة، وككاشير لا يملك المحاسبة | **0 مسارًا مفتوحًا بلا جلسة**؛ الكاشير لا يصل إلا إلى علامات الأصناف وتصنيفات العملاء (تتبع وحدتيه)؛ 6 شاشات `view/abc` كانت 500 صارت 404 |
| 40 شاشة في Chromium | فتح كل شاشة وقراءة الـconsole والطلبات الفاشلة | **لا خطأ console ولا طلب فاشل ولا تمرير أفقي** (بعد استبعاد ضجيج شريط التطوير) |
| السجل `writable/logs` | قراءة كل المدخلات بعد المرحلة | لا `Warning` ولا `Notice` ولا `Deprecated` من التطبيق؛ `CRITICAL`: معرّفات فاسدة (أُصلحت ست منها) وبقية R2-16 |

## 3. النتائج مصنَّفة (أربع فئات)

**الوقائع** ما شاهدته بنفسي (مخرجات وSQL ولقطات)؛ **الاستنتاجات** مذكورة صراحة في بند «استنتاج». كل «مُصلَح» أُصلح باختبار فاشل أولًا (القسم 4).

### 3.1 BLOCKER

#### R2-01. عشرة نماذج «جديد» ترسل إلى مسار غير موجود فتنتهي بـ404 (سند القبض وكل نماذج الشيكات بينها) — **مُصلَح في `wp/R2`**
- **الخطوات:** تسجيل الدخول بالمتصفح، فتح `/receipt_vouchers`، «سند قبض جديد»، تعبئة المبلغ 33، «إرسال» (`pw2.cjs`). الطلب الذي يخرج: `POST /receipt_vouchers/save/-1`. وبالمثل بوساطة `crawl3.mjs` لكل نماذج الحوارات.
- **المتوقع:** يُحفظ السند. **الفعلي (قبل الإصلاح):** `404` («Cannot access the controller in Defined Routes»). النماذج المتأثرة: سند القبض، تصنيفات الإيراد، قوائم الأسعار، الحقول المخصصة، تعريفات الضريبة، علامات الأصناف التجارية، تصنيفات العملاء، **الشيك المستلَم، الشيك المدفوع، دفتر الشيكات**.
- **السبب (حقيقة):** النموذج لمدخل جديد يرسل `save/-1` (`NEW_ENTRY`)، والمسار معرَّف `save/(:num)` و`(:num)` أرقام موجبة فقط. اختبارات المتحكمات تنادي `/save` بلا رقم فلم ترَ العيب.
- **الأثر:** لا يمكن إنشاء شيك ولا سند قبض ولا قائمة أسعار من الشاشة؛ دورة الشيكات (استلام، إيداع، تحصيل، رد، وكذلك المدفوع) معطلة للمستخدم الحقيقي. هذا يمنع دورة كاملة في المعيار 1.
- **الإصلاح:** محدِّد مسار `(:entry)` = `-?[0-9]{1,9}` على مسارات الحفظ والعرض لهذه الوحدات؛ `tests/Config/RoutesTest.php` (17 حالة فشلت كلها قبل الإصلاح ونجحت بعده). **الثقة:** عالية جدًا.

### 3.2 IMPORTANT GAP

#### R2-02. حفظ صنف جديد يفشل على قاعدة جديدة (صفّا الضريبة فارغان) برسالة مضللة — **مُصلَح**
- **الخطوات:** على قاعدة جديدة (`default_tax_1_name` فارغ): `/items` ثم «صنف جديد»، تعبئة الاسم والتكلفة والسعر، «إرسال» (`pw1.cjs`، لقطة `item-after.png`).
- **الفعلي:** «Tax Name contains disallowed characters.». يعمل الحفظ فقط إذا مُلئ **اسما** الضريبتين **ونسبتاهما** كلاهما (`probe4.mjs`، خمس صيغ).
- **السبب:** `validateItemFields` يطبق `required` على كل صفوف `tax_names[]` بما فيها الفارغ الذي يرسله النموذج افتراضيًا.
- **الحل البديل قبل الإصلاح:** كتابة اسمين ونسبتين 0. **الإصلاح:** `dropEmptyTaxRows` يُسقط الصف الذي لا اسم فيه ولا نسبة قبل الفحص، ويبقى الصف بنسبة بلا اسم مرفوضًا (اختباران في `ItemsControllerTest`). **الثقة:** عالية. **الأثر على المالك:** أول ما يصطدم به أي نشاط على قاعدة جديدة؛ إن كانت قاعدته فيها أسماء ضريبة افتراضية فلن يراه.

#### R2-03. رقم المستند يحمل أرقامًا هندية حسب لغة المتصفح (`Q٢٦000003` مقابل `Q26000002`) — **مُصلَح**
- **الخطوات:** عرض بيع بالرمز `quote` من جلستين بترويسة `Accept-Language: ar-SA` ثم `en-US` (`probe11.mjs`).
- **الفعلي:** `Q٢٦000003` للعربي و`Q26000002` للإنجليزي؛ النتيجة تُخزَّن في القاعدة كما هي. الرقم نفسه (`000003`) لاتيني فيختلط الخطّان.
- **الأثر:** البحث بالرقم من لوحة مفاتيح لاتينية لا يجد المستند، ومستندان من موظفين بلغتي متصفح مختلفتين يُرقَّمان بنمطين. جمهور المنتج عربي فالحالة هي الغالبة لا النادرة.
- **الإصلاح:** `Token_lib::applyDateFormats` يطلب `@numbers=latn`؛ `Token_libTest::testRender_KeepsLatinDigitsInDateTokensWhenTheDefaultLocaleIsArabic` (فشل بـ`Q٢٦١١١٤` ثم نجح). **الثقة:** عالية.

#### R2-04. خيار «منع المخزون السالب» لا يشمل نقل المخزون بين المواقع (الطلبية الداخلية)
- **الخطوات:** ضبط `negative_stock = refuse`، ثم بيع أكثر من الرصيد (رُفض بكلمات)، وإصدار إذن صرف أكثر من الرصيد (رُفض بكلمات)، ثم نقل 12 وحدة من موقع فيه 7 إلى موقع آخر عبر وضع «طلب داخلي» في الاستلام (`inventory.log` السيناريو I6).
- **الفعلي:** النقل **قُبل** والموقع المصدر صار **−5** (`SELECT quantity FROM ospos_item_quantities`).
- **حقيقة:** وثائق صنف `NegativeStockGuard` تقول إنه يغطي البيع وتسليم الفاتورة وإن الإذن والجرد «يحتفظان بقواعدهما»؛ النقل لم يُذكر. **استنتاج:** المالك الذي يختار «ارفض» يتوقع ألا ينزل أي موقع تحت الصفر. **الثقة:** عالية في الواقعة، متوسطة في تصنيف الأهمية. لم أُصلحه (قرار تصميم يحتاج المالك).

#### R2-05. أداة الجاهزية تُظهر «فشلًا» (رمز خروج 1) لثلاث حالات سليمة
- **أ.** `source_keys`: شيك مودَع ثم مرفوض يرحّل **قيدين** بمفتاح مصدر واحد (مراجعتان 1 و2، بتصميم `ChequePostingBuilder` الذي يبني زوجين لانتقال `DepositReject`)، فيعدّه الفحص مستندًا مكررًا (`cheque_in_dep_rejected 2 ... journals: 68,69`). الصافي في الدفاتر صحيح (حسابا القبض وتحت التحصيل يتعادلان).
- **ب.** `payment_stamping`: بعد إنشاء أول خزينة إضافية يصير لمدفوعات البيع السابقة `treasury_id = NULL` فيُبلَّغ «12 دفعة» بالفشل حتى يُنفَّذ `accounting:stamp_payments --apply`.
- **ج.** `treasury_ties`: قيد يدوي واحد على حساب الخزينة (مثل «أ: مصروف إيجار، د: الصندوق» بسبع وحدات) يجعل الفحص يفشل: «-7.0000 of it was put there by hand».
- **الأثر:** الأداة هي بوابة المالك قبل النشر وبعده؛ ثلاث عمليات عادية تُلوّنها بالأحمر فيفقد المالك الثقة بالأخضر. **الثقة:** عالية في الوقائع. لم أُصلحه (تغيير دلالة فحوص).

#### R2-06. يُسمح بحذف عميل أو مورد عليه رصيد دون تحذير
- **الخطوات:** حذف العميل `R2Cust One` (رصيده الدائن 650) والمورد `R2 Supplier A` (مستحق له 270) من شاشتيهما (`awkward.log` السيناريو AW7).
- **الفعلي:** «تم الحذف»؛ يُخفى الشخص ويُعطَّل حسابه الفرعي (`active = 0`) **ويبقى الرصيد** في الحساب المعطَّل (−650 و−270)؛ لا فحص جاهزية يكتشفه. **الأثر:** عميل دفع مقدمًا ثم حُذف: لا يظهر في شاشات التحصيل. **الثقة:** عالية.

#### R2-07. الجرد النقدي يرحّل «عجزًا» كاذبًا حين يُدفع نقدًا من الدرج لغير المصروفات
- **الخطوات:** حفظ جرد نقدي (فتح 100، إقفال 250) بعد دورة فيها شراء نقدي (800) ودفعة مورد وقسط من الدرج (`treasury.log` T9).
- **الفعلي:** المتوقع في الدرج 985 (`expected_amount_cash`) فيرحَّل **عجز 735** (`مدين 1208 / دائن الصندوق`، القيد 72)، والصندوق يهبط بذلك.
- **السبب (حقيقة من الشيفرة `CashupExpectation`):** المتوقع = الفتح + المنقول + مدفوعات المبيعات النقدية + سندات القبض − **مصروفات** نقدية فقط؛ **لا** يخصم مشتريات نقدية ولا دفعات الموردين ولا ردّ مقدمات العملاء ولا التحويلات من الدرج.
- **الأثر:** كل ما دُفع من الدرج لغير مصروف يظهر في دفتر الأستاذ عجزًا وهميًا. **الثقة:** عالية في الشيفرة والرقم، متوسطة في أنه مقصود.

#### R2-08. الاستلام: «المبلغ المدفوع» الأقل من الإجمالي يُتجاهل فيُرحَّل الشراء مدفوعًا بكامله
- **الخطوات:** استلام قيمته 300، طريقة الدفع «نقدًا»، المبلغ المدفوع 100 (`probe3.mjs`).
- **الفعلي:** قيد `purchase_payment` بـ300 (الصندوق −300)، لا دفعة جزئية. التسمية في الشاشة «Amount tendered» (للباقي لا للدفعة). **الأثر:** من يكتب 100 يقصد دفعة جزئية فيخرج من الصندوق 300. **الحل العملي الحالي:** اختيار «آجل» ثم دفعة مورد. **الثقة:** عالية.

### 3.3 POST-LAUNCH

| المعرّف | العنوان | الواقعة والدليل |
|---|---|---|
| R2-09 | تقييم فائض الجرد بمتوسط الموقع لا بتكلفة بطاقة الصنف، وبقايا قيمة لصنف كميته صفر | فائض وحدة من صنف Q قُيِّم 22.8889 (متوسط الموقع) وبطاقة الصنف 22.0 (`inventory.log` I5)؛ وبعد أن نزل صنف P تحت الصفر وعاد إلى الصفر بقي في حساب المخزون **−1.0258 لكمية صفر** (`SELECT ... FROM ospos_accounting_stock_entries GROUP BY item_id, location_id`)؛ تقرير التقييم يساوي حسابات المخزون بالقرش لكنه يعرض قيمة لصنف بلا كمية. الأثر صغير ومنتظم حين يُباع تحت الصفر (الخيار «اسمح») |
| R2-10 | تعديل تكلفة استلام بعد بيع جزء منه يضع فرق التكلفة كله على الباقي | رفع تكلفة 10 وحدات من 10 إلى 12 بعد بيع 4: صارت تكلفة الصنف 13.33 لا 12 (الفرق 20 كله على 6 وحدات) ولم يُعدَّل COGS للمباع (`awkward.log` AW1)؛ الدفاتر متماسكة |
| R2-11 | حفظ بيع أو استلام أو مصروف أو جرد بتاريخ غير صالح = 500 | `Call to a member function format() on false` من `date_create_from_format` في `Sales::postSave` وما يماثله (`adversarial.log` B8) |
| R2-12 | رسالة خام «Validation.decimal_locale» للكمية غير الرقمية في سجل البيع | كمية `abc` أو `١٫٥` أو `1,5` تعطي مفتاح اللغة بدل الجملة (`adversarial.log` B5) |
| R2-13 | زر الرجوع بعد الإيصال يعيد صفحة POST (`ERR_CACHE_MISS`/«تأكيد إعادة الإرسال») | الإيصال يُعاد مباشرة من POST بلا إعادة توجيه؛ الخادم آمن: إعادة إنهاء البيع لا تنشئ بيعًا ثانيًا (B3b) |
| R2-14 | أول فاتورة بيع رقمها `0` | الرمز `{CO}` يعدّ الفواتير السابقة فيبدأ من صفر (`probe9.mjs`) |
| R2-15 | لا مفتاح تكرار على دفعة المورد والمصروف | طلبان متوازيان يسجلان دفعتين ومصروفين (B3f وB3g)؛ نماذج المتصفح محمية (النقر المزدوج سجّل واحدًا: BR-dblclick-expense)، ودفعة العميل المقدمة محمية بمفتاح طلب (B3e) |
| R2-16 | 500 على مدخلات فاسدة في الروابط والنماذج القديمة | `taxes/save/-1` بلا تصنيف (FK)، `customers/importCsvFile` بلا ملف، `items/generateBarcodes` بلا أرقام، `items/search` و`customers/search` بلا `search`، `sales/delete/abc` (السجل: `TypeError`). المعرّفات الفاسدة في ست شاشات `view/abc` أُصلحت مع R2-01 |
| R2-17 | تقرير أرصدة الموردين يضع إشعار المدين تحت «تسويات» ويترك «المرتجعات» 0 | مورد A: مرتجعات 0.00 وتسويات −44.00 (`probe33.mjs`)؛ الإجمالي صحيح |
| R2-18 | أمر `accounting:enable --currency=SAR` في الدليل لا يعمل (spark لا يقرأ `=`) | الأمر يتجاهل الخيارات ويقول «base_currency_missing»؛ ظاهر في دليل الجاهزية الخطوة 7 وقائمة النشر وأداة الجاهزية نفسها — **صُحِّحت النصوص** (لا شيفرة) |
| R2-19 | بقايا العلامة الأصلية في الواجهة | عنوان الصفحة «Open Source Point of Sale | الاول برو»، تذييل «opensourcepos.org · 3.4.3 - dev»، بريد افتراضي `changeme@example.com` |
| R2-20 | حد الدخول 5 في الدقيقة لكل عنوان IP | مكاتب خلف عنوان واحد قد تحجب موظفين عند بدء الدوام (429)؛ قابل للضبط `throttle.capacity` |
| R2-21 | لا حماية من «التحديث الضائع» حين تحرر نافذتان السجل نفسه | صنف وعميل: حفظت النافذة B سعرًا (77) وهاتفًا ثم حفظت A نموذجها الأقدم فمحت التغيير بلا تنبيه (`concurrent.log` CC1 وCC2)؛ القيود اليومية فيها رقم إصدار أما الأصناف والعملاء فلا |
| R2-22 | اسم صنف من 400 حرف يُحفظ مقطوعًا إلى 255 بلا رسالة | `SELECT LENGTH(name) FROM ospos_items WHERE item_number='ODD-verylong'` = 255 والاستجابة `success: true` (B6) |

### 3.4 NO ACTION

- **بطاقة الدفع تذهب إلى حساب الصندوق الرئيسي ما لم تُختر خزينة بنكية في سجل البيع** (يُسأل عنها فعلًا عند وجود أكثر من خزينة)، تصميم لا عيب (T2).
- **بيع أكثر من الرصيد يمرّ بتحذير** حين يكون الخيار «اسمح» (الافتراضي القديم) (S15).
- **حقل الإجمالي في الجرد النقدي مشتق** (إقفال − فتح + بطاقة)، وليس ما يكتبه المستخدم (T9).
- **JSON قوائم الأصناف والعملاء يحمل الوسوم الخام لكن واجهة الجداول تهرّبها**: جُرِّبت `<script>` و`<img onerror>` وعلامة `<B>` في اسم صنف وعميل فلم يُنفَّذ شيء ولم تظهر وسوم حية في 6 شاشات ولا في الإكمال التلقائي (`pw4.cjs`).
- **500 ورسالة Debug Toolbar في وضع التطوير** عند أول ثلاثة طلبات (`unlink debugbar_*.json`): أثر خادم التطوير لا الإنتاج.
- **مسارات الاستيراد والتصدير والتحميل** لم أنفِّذها.
- **كل مسارات التطبيق (1181) محجوبة بلا جلسة (0 مسارًا يُجيب 200)، والكاشير لا يصل إلى أي شاشة لم تُمنح له** سوى علامات الأصناف وتصنيفات العملاء التي تتبع وحدتي الأصناف والعملاء اللتين يملكهما.

## 4. ما أُصلح (اختبار فاشل أولًا ثم إصلاح ثم نجاح)

| الإيداع | العيب | الاختبار الذي فشل أولًا | النتيجة بعد الإصلاح |
|---|---|---|---|
| `fb147a0` | R2-03 رقم المستند بأرقام هندية | `Token_libTest::testRender_KeepsLatinDigitsInDateTokensWhenTheDefaultLocaleIsArabic` فشل بـ`Q٢٦١١١٤` | `Token_libTest`: 44 اختبارًا و72 تأكيدًا نجحت |
| `f130df3` | R2-01 مسارات `save/-1` (عشرة نماذج) ومعرّفات فاسدة في ست شاشات | `tests/Config/RoutesTest.php` جديد: **10 أخطاء 404 و7 إخفاقات** قبل الإصلاح | 17 من 17 نجحت |
| `f130df3` | R2-02 صفّا الضريبة الفارغان في نموذج الصنف | `ItemsControllerTest::testPostSave_AcceptsTheEmptyTaxRowsOfAFormLeftAsItWasOpened` فشل بـ«Tax Name contains disallowed characters» (واختبار ثانٍ لرفض نسبة بلا اسم كان ناجحًا ويبقى) | الاختبار الجديد وبقية اختبارات الضريبة في الملف (98 اختبارًا و619 تأكيدًا) نجحت |
| `af1de82` | R2-18 نص الأمر `accounting:enable --currency=SAR` في الدليل وقائمة النشر وأداة الجاهزية | لا شيفرة تتغير (نصوص فقط) | اختبارات الأمر وأداة الجاهزية قبل/بعد: انظر الصف الأخير |

**ما شغّلتُه بعد الإصلاح (قاعدة `ospos_test_r2*`، عملية واحدة لكل قاعدة):** `tests/Config/RoutesTest.php` و`tests/Libraries/Token_libTest.php` و14 ملف متحكمات للوحدات المتأثرة (الشيكات وقوائم الأسعار وسند القبض وتصنيفات الإيراد والحقول المخصصة وتعريفات الضريبة والعلامات والتصنيفات والفروع والأدوار والخزائن والفترات والشجرة ومراكز التكلفة)، و`ItemsControllerTest` كله، و`ReadinessRunbookTest` و`AccountingEnableTest` و`AccountingReadinessTest`. النتائج في 4.1 أدناه.

### 4.1 نتائج PHPUnit بعد الإصلاح

| التشغيل | النتيجة |
|---|---|
| `tests/Config/RoutesTest.php` + `tests/Libraries/Token_libTest.php` + 14 ملف متحكمات للوحدات المتأثرة (على `ospos_test_r2`، عملية واحدة، 30 دقيقة على جهاز مشترك) | **OK (421 اختبارًا و2518 تأكيدًا)** |
| `ReadinessRunbookTest` + `AccountingEnableTest` + `AccountingReadinessTest` (على `ospos_test_r2_c`) | **OK (54 اختبارًا و260 تأكيدًا)** |
| `ItemsControllerTest` بترشيح الاختبارات الضريبية (على `ospos_test_r2_b`) | **OK (98 اختبارًا و619 تأكيدًا)** |
| `ItemsControllerTest` كاملًا، بنسخة واحدة من الملف | **OK (188 اختبارًا و1245 تأكيدًا)** |

**تنبيه على أثر جانبي لخطئي في الأمر:** مرّة شغّلتُ `ItemsControllerTest.php` مرتين في عملية واحدة (بتكرار الملف في سطر الأوامر)، فظهر «فشلان» (`testGetSearch_ShowsThePurchaseTaxesOfEachItem...` و`testImportItemQuantityMultipleLocations`) **في النسخة الثانية فقط** بعد أن مرّت الـ188 اختبارًا في النسخة الأولى كلها؛ هذا أثر تشغيل الملف مرتين على قاعدة واحدة لا فشل حقيقي، وأعدتُ التشغيل بنسخة واحدة فنجح كله (السطر أعلاه). لم أشغّل المجموعة الكاملة للمشروع.

**لم أُصلح (وسبب كل منها):** R2-04 قرار تصميم؛ R2-05 يغيّر دلالة فحوص؛ R2-06 وR2-07 وR2-08 قرارات مالك؛ R2-11 يحتاج مفتاح لغة جديدًا في 46 لغة؛ وبقية البنود تحسينات.
## 5. ما لم أتحقق منه (بصراحة)

- **الأداء والحجم:** لم أقِس شيئًا؛ الجهاز مشترك (حمل 10 إلى 12) والقاعدة صغيرة (عشرات المستندات).
- **الإقفال السنوي:** فتحتُ شاشته فقط (E27)؛ **لم أنفِّذ إقفال السنة ولا عكسه**، ولم أرَ معاينته بأرقام.
- **مطابقة كشف البنك:** استوردتُ كشفًا من 3 سطور (T10)؛ لم أنفِّذ المطابقة ولا الإقفال ولا تقرير المطابقة.
- **أعمار الذمم:** كل مستنداتي بتاريخ اليوم فلم تُختبر شرائح 1 إلى 30 وما بعدها.
- **إشعارات الدائن لأصناف خاضعة للضريبة:** إشعاراتي وفواتير الاسترداد كانت لصنف بنسبة 0%؛ **أثر المرتجع على تقرير الضريبة لم يُختبر برقم**.
- **سلسلة طلبات الشراء:** جرّبتُ أمر الشراء ثم الاستلام فقط؛ لم أجرِّب طلب الشراء ولا طلب العرض ولا عرض المورد ولا الاستلام الجزئي.
- **الفروع:** النقل بين فرعين فقط؛ لم أجرِّب موظفًا مقيّدًا بفرع ولا تسوية ما بين الفروع.
- **غير المشغَّل:** تعدد العملات، الأصول الثابتة، الفواتير الدورية، الفوترة الإلكترونية، بوابة العميل، واجهة `api/v1`، الوكيل، قوائم الأسعار بخصم أو بحد أدنى، الإنتاج بدفعات وأرقام تسلسلية.
- **الواجهة بالعربية والطباعة:** شغّلتُ الواجهة بالإنجليزية (لغة المتجر الافتراضية في القاعدة الجديدة)؛ لم أفتح PDF الفواتير ولا الإيصالات الحرارية ولا أرسلتُ بريدًا.
- **التزامن الحقيقي:** جلستان على خادم PHP المدمج بثلاثة عمال؛ **لم أجرّب PHP-FPM ولا أكثر من جلستين**. نتيجة B4 (بيع آخر وحدة) تدل على القفل لكنها لا تثبت سلوكه تحت حمل حقيقي.
- **قراءة السجل:** سجل التطبيق (`writable/logs`) فُحص بعد المرحلة ب؛ لم يظهر `Warning` ولا `Notice` ولا `Deprecated` من شيفرة التطبيق، والمُسجَّل `CRITICAL` كلها من R2-16 والمعرّفات الفاسدة وإخفاقات `unlink` لأداة التطوير. (`logger.threshold` في `.env` الفرع 0 فلا سجل بلا تفعيله؛ **تأكد منه في الإنتاج**.)

## 6. إعادة التشغيل

```
# من جذر الفرع، والخادم يعمل على 8082 (انظر القسم 1)
R2_EVIDENCE=/tmp/r2-evidence tools/dev/r2-run-all.sh purchases sales sales2 inventory treasury accounting sales3 adversarial awkward
NODE_PATH=$(npm root -g) node tools/dev/r2-browser.cjs        # المتصفح الحقيقي
node tools/dev/r2-sweep.mjs                                   # فحص المسارات كلها
MYSQL_DB_NAME=ospos_test_r2 vendor/bin/phpunit --no-coverage tests/Config/RoutesTest.php tests/Libraries/Token_libTest.php
```

كلمة مرور القاعدة تُقرأ من `.env` وقت التشغيل ولا تُكتب في أي ملف.

## 7. الجدول الشامل للسيناريوهات (المعرّف، الخطوات، المتوقع، الفعلي، النتيجة)

**263 سيناريو: 251 نجح و12 فشل.** كل «فشل» مُفسَّر بالنتائج أعلاه ولا يخفي أي فشل آخر: `I5-inventory` ← R2-09؛ `I6-transfer` ← R2-04؛ `B3f` و`B3g` ← R2-15؛ `B8` ← R2-11؛ `CC1` و`CC2` ← R2-21؛ `BR-back-finish` ← R2-13؛ وأسطر `AW1-ties` و`AW2-ties` و`AW3-ties` و`AW7-ties` **ليست فشل السيناريو نفسه** بل فشل فحص `treasury_ties` الذي سببه القيد اليدوي على حساب الخزينة (R2-05 ج)؛ فحوص `customer_ties` و`supplier_ties` و`stock_ties` و`journals_unbalanced` و`interbranch_ties` نجحت في الأسطر الأربعة (عدّلتُ السكربت بعدها ليستثني `treasury_ties` من حكم هذه الأسطر). المصدر: ملفات `run4/*.json` وأسطر `run4/*.log` في مجلد الأدلة؛ والتشغيل من قاعدة جديدة كاملة (`r2-run-all.sh`).

| المعرّف | الخطوات | المتوقع | الفعلي | النتيجة |
|---|---|---|---|---|
| P1 | receive 10 P @10 and 5 Q @20 from supplier A, payment Due | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P2 | receive 10 P @12 from supplier A, payment Cash (the amount tendered is 120) | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P3-http | pay 80 to supplier A in cash from the payment dialog | success | {"success":true,"message":"The payment was entered.","id":1} | PASS |
| P3 | pay 80 to supplier A in cash from the payment dialog | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P3b | supplier A balance after P1..P3 | 120 | 120 | PASS |
| P3b-screen | open the supplier statement screen | shows 120.00 | shows 120.00 | PASS |
| P4 | return mode: 4 P to supplier A (cost typed 11), no payment | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P5 | receive 10 Q @20 with a 10% line discount from supplier B, Due | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P6 | receive 10 P @12 with a fixed discount of 2 per unit from supplier A, Due | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| P7 | receive 10 U @8 with tax 5% from supplier A, Due | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S0 | receive 20 T @40 for cash | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S1 | POS: 2 P @25 + 1 Q @50, Cash 100 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S2 | POS: 2 T @100 (15% added), Cash 230 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S3 | cash 100 + card 65 on 165 (150 net + 15 tax) | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S4 | sale on Due to customer 1: 3 P @25 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S6 | sale to customer 2: 4 P @25 = 100, Cash 40 + Due 60 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S7-cash | sale: 2 Q @50 with 10% off + 1 P @25 with a fixed 5 off; Cash 110 | 110 | 110 | PASS |
| S7-net-revenue | sale: 2 Q @50 with 10% off + 1 P @25 with a fixed 5 off; Cash 110 (revenue net of discount account) | -110 | -110 | PASS |
| S7-cogs | sale: 2 Q @50 with 10% off + 1 P @25 with a fixed 5 off; Cash 110 | 47.9487 | 47.9487 | PASS |
| S8 | sale: 2 P @25 with a 10% invoice discount, Cash 45 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S9-config | switch tax included on | saved | {"success":true,"message":"Configuration save successful."} | PASS |
| S9 | tax included: 3 U @20 (5% inside the price), Cash 60 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S10 | invoice to customer 3: 2 Q @50, Cash 30 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S11 | credit note of 1 Q (@50) of invoice 9, credited to the account | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S12 | credit note of 5 Q when 2 were invoiced and 1 already returned | refused | A return cannot give back more than the sale brought. What is left to return: 1.000. | PASS |
| S13 | refund invoice of 1 Q (@50) paid back in cash | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| S14-http | cancel an issued invoice with a payment | a clear answer (cancelled or refused in words) | 200 {"success":false,"message":"An issued invoice or credit note is not cancelled. Correct it with a credit note."} | PASS |
| S15 | sell 1000 U with 7 in stock | refused in words | Payments do not cover the amount due. [warning: Warning: Desired Quantity is insufficient. You can still process the sale, but audit your inventory.] | PASS |
| I0 | create Warehouse and JED store, assign JED store to the Jeddah branch | saved | {"success":true,"message":"The branch was updated.","id":2} | PASS |
| I1-http | requisition 5 P from stock to Warehouse | no refusal | ok | PASS |
| I1-qty-source | stock of P at the source falls by 5 | 7 | 7 | PASS |
| I1-qty-dest | stock of P at the destination rises by 5 | 5 | 5 | PASS |
| I1 | transfer inside a branch moves value between the two location accounts at the average cost | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| I2-http | requisition 4 Q from stock (HQ) to JED store (Jeddah) | no refusal | ok | PASS |
| I2-qty-source | Q at source | 5 | 5 | PASS |
| I2-qty-dest | Q at destination | 4 | 4 | PASS |
| I2-books | between branches: inventory out of HQ, inventory into Jeddah, inter-branch accounts offset | inventory -74.6667 / 1203003 74.6667 / inter-branch legs sum to 0 | inventory -74.6667 / 1203003 74.6667 / others 2214:-74.6667 12044:74.6667 | PASS |
| I3-http | add permit: 10 Q @25 against the automatic account (other payables) | saved | {"success":true,"message":"The permit was saved.","id":1} | PASS |
| I3 | stock add permit moves inventory up and the chosen account down, and re-averages the cost | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| I4-http | issue permit: 3 P | saved | {"success":true,"message":"The permit was saved.","id":2} | PASS |
| I4-inventory | inventory falls by 3 x average cost | -31.8462 | -31.8461 | PASS |
| I4-qty | P falls by 3 | 9 | 9 | PASS |
| I4-offset | the other side of the journal equals the inventory fall | 31.8462 | 31.8461 | PASS |
| I5-create | start a stocktake of the main location | saved | {"success":true,"message":"The stocktake was started.","id":1} | PASS |
| I5-count | enter counts | saved | {"success":true,"message":"The counts were saved.","id":1} | PASS |
| I5-approve | approve the stocktake | approved | {"success":true,"message":"The stocktake was approved.","id":1} | PASS |
| I5-P | P at the main location equals the count | 2 | 2 | PASS |
| I5-Q | Q at the main location equals the count | 16 | 16 | PASS |
| I5-inventory | inventory value moves by -2 P + 1 Q at their costs | 0.7692 | 1.6581 | FAIL |
| I6-set | set negative stock to refuse | saved | {"success":true,"message":"The settings were saved.","id":1} | PASS |
| I6-sale | sell 12 U with 7 in stock | refused in words | There is not enough stock of R2 Taxed item 5: the setting refuses a sale that would take the stock below nothing. | PASS |
| I6-permit | issue permit above the stock | refused in words | {"success":false,"message":"This would leave less than nothing of R2 Taxed item 5 in stock: the goods of the receiving have been sold or moved.","id":… | PASS |
| I6-transfer | transfer above the stock | refused in words | not refused | FAIL |
| I7 | valuation report total against the stock accounts (1203*) | equal | report 1251.2 vs accounts 1251.1966 | PASS |
| T1 | create a cash treasury and a bank account | both saved | {"success":true,"message":"The treasury was added.","id":2} {"success":true,"message":"The treasury was added.","id":3} | PASS |
| T2-reached | sale of 2 P paid by card into Bank Alpha | saved | 16  | PASS |
| T2-bank | bank account rises by the card payment | 50 | 50 | PASS |
| T2-cash | the main cash account does not move | 0 | 0 | PASS |
| T3-http | transfer 100 from the main cash to Bank Alpha | saved | {"success":true,"message":"The transfer was made.","id":1} | PASS |
| T3 | transfer moves 100 from cash to bank | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| T3c-http | cancel the transfer | cancelled | {"success":true,"message":"The transfer was cancelled.","id":1} | PASS |
| T3c | cancelling puts both accounts back | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| T4-http | cash expense 100 under the rent category | saved | {"success":true,"message":"Expense add successful","id":1} | PASS |
| T4-cash | cash falls by 100 | -100 | -100 | PASS |
| T4-expense | expense accounts rise by 100 in total | 100 | 100 | PASS |
| T5-http | receipt voucher 200 cash, automatic revenue account | saved | {"success":true,"message":"The receipt voucher was saved.","id":1} | PASS |
| T5 | voucher: cash up 200, other revenue up 200 | books move by the hand-worked amounts, nothing else moves | as expected | PASS |
| T6-save | receive a cheque of 300 from customer 1 | saved | {"success":true,"message":"The cheque was entered.","id":1} | PASS |
| T6-received | receiving the cheque: customer credited 300, notes receivable debited 300 | 12041005:-300 120601:300 | 12041005:-300 120601:300 | PASS |
| T6-deposit | deposit the cheque | done | {"success":true,"message":"The cheque was deposited.","id":1} | PASS |
| T6-deposit-books | deposit moves 300 from notes receivable to under collection | one account -300, another +300 | {"120601":-300,"120602":300} | PASS |
| T6-collect | collect the cheque with 5 bank fees | done | {"success":true,"message":"The cheque was collected.","id":1} | PASS |
| T6-collect-books | collect: bank +295 (300 less fees 5), fees expense +5, under collection -300 | bank +295 | bank 295; {"546":5,"120602":-300,"1202001":295} | PASS |
| T7-reject | receive, deposit and reject a cheque of 150 from customer 2 | rejected | {"success":true,"message":"The cheque was rejected.","id":2} | PASS |
| T7-customer | after the rejection the customer owes what he owed before (net 0 movement) | 0 | 0 | PASS |
| T7-bank | the bank does not move | 0 | 0 | PASS |
| T8-book | open a cheque book 1000-1020 on Bank Alpha | saved | {"success":true,"message":"The cheque book was added.","id":1} | PASS |
| T8-issue | issue paid cheque 1000 of 120 to supplier A | saved | {"success":true,"message":"The cheque was entered.","id":3} | PASS |
| T8-deliver | deliver it | done | {"success":true,"message":"The cheque was delivered.","id":3} | PASS |
| T8-clear | the bank clears it | done | {"success":true,"message":"The cheque was collected.","id":3} | PASS |
| T8-supplier | supplier A owed less by 120 | 120 | 120 | PASS |
| T8-bank | bank down by 120 | -120 | -120 | PASS |
| T9 | save a cash-up (opening 100, closing 250 cash + 50 card = 300) | saved | {"success":true,"message":"Cashup add successful","id":1} | PASS |
| T9-row | the stored cash-up carries the figures typed | 100/250/200 (the total is closing cash less opening cash plus card) | ["1","100.00","250.00","200.00"] | PASS |
| T10-import | import a 3-line statement | imported | {"success":true,"created":true,"status":"imported","message":"Imported 3 line(s); 0 already known.","id":1,"imported":3,"duplicates":0}  | PASS |
| T10-lines | three lines stored | 3 | [["3"]] | PASS |
| E1 | trial balance: total debit equals total credit | equal | 8,912.89 / 8,912.89 | PASS |
| E2 | trial balance: every account closing balance equals the SQL sum of its lines (with its children) | no difference | 39 rows agree | PASS |
| E3 | income statement net income equals revenue less expenses by SQL | 707.95 | 707.95 | PASS |
| E4 | profit and loss (monthly) total profit equals the same figure | 707.95 | 707.95 | PASS |
| E5 | balance sheet: assets equal liabilities plus equity including the result of the period | 1396.48 | 1396.48 | PASS |
| E5b | balance sheet assets equal the SQL sum of asset accounts | 1396.48 | 1396.48 | PASS |
| E6 | balance sheet result of the period equals the net income (the movement on the equity side) | 707.95 | 707.95 | PASS |
| E7 | cash flow: total change in cash equals the movement of the treasury and bank accounts | -560 | -560 | PASS |
| E7b | cash flow: operating + investing + financing + other changes equals the total change | -560 | -560 | PASS |
| E8 | VAT report: tax collected equals the tax of the sales documents | 47.86 | 47.86 | PASS |
| E8b | VAT report: tax collected equals the output tax account | 47.86 | 47.86 | PASS |
| E8d | VAT report: the input tax of the purchases (80 x 5% = 4) equals the input tax account | 4 | 4 | PASS |
| E9-11 | customer 1: balance by its documents (sales + tax + returns - payments - advances - cheques) against its ledger account | -225 | -225 | PASS |
| E9-12 | customer 2: balance by its documents (sales + tax + returns - payments - advances - cheques) against its ledger account | 90 | 90 | PASS |
| E9-13 | customer 3: balance by its documents (sales + tax + returns - payments - advances - cheques) against its ledger account | 20 | 20 | PASS |
| E10 | post a manual journal: Dr rent 500, Cr cash 500 | posted | {"success":true,"message":"Journal 000002 was posted.","id":73,"redirect":"http://127.0.0.1:8082/accounting_journals/view/73","new_url":"http://127.0.… | PASS |
| E10b | cash account falls by 500 | -1285 | -1285 | PASS |
| E11 | an unbalanced journal (500 / 400) is refused in words | refused | {"success":false,"message":"The journal is not balanced: debit 500.0000, credit 400.0000.","id":-1} | PASS |
| E12 | reverse the manual journal | reversed | {"success":true,"message":"Reversal journal 000003 was posted and the original journal is now reversed.","id":74,"redirect":"http://127.0.0.1:8082/acc… | PASS |
| E12b | cash is back after the reversal | -785 | -785 | PASS |
| E13 | reversing the same journal twice is refused | refused | {"success":false,"message":"The journal is already reversed.","id":-1} | PASS |
| E14 | close the period through today | closed | {"success":true,"message":"The period was closed.","id":1} | PASS |
| E15 | a manual journal dated inside the closed period | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E16 | a sale dated today inside the lock | refused, nothing saved | not saved The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale). | PASS |
| E17 | a purchase dated today inside the lock | refused in words | The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale). | PASS |
| E18 | a supplier payment inside the lock | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E19 | a customer advance inside the lock | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E20 | an expense inside the lock | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E21 | a receipt voucher inside the lock | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E22 | a treasury transfer inside the lock | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E23 | reverse a journal into the closed period | refused | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| E24 | cancel a POS sale of the closed period | refused in words | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale)."} | PASS |
| E25 | nothing leaked into the books while the period was closed | -792 | -792 | PASS |
| E25b | no journal posted after the closing falls inside it | 0 | 0 | PASS |
| E26 | reopen the period | reopened | {"success":true,"message":"The closing was reopened.","id":1} | PASS |
| E27 | the year-end screen opens | 200 without an error page | 200 | PASS |
| C1-on | turn the credit limit control on | saved | {"success":true,"message":"The controls were saved.","id":1} | PASS |
| C1-limit | set the credit limit of customer 2 to 100 | saved | {"success":true,"message":"You have successfully updated customer R2Cust Two","id":12} | PASS |
| C1-refused | cashier sells 100 on credit to customer 2 who already owes 90 (limit 100) | refused in words, nothing saved | not saved The credit limit of R2Cust Two is 100.00. With this sale they would owe 190.00. Only an employee who may override the credit limit can issue… | PASS |
| C1-within | a sale of 25 that stays under the limit is accepted when the customer owes 90 less... (checked against the rule) | refused (90+25 > 100) | not saved The credit limit of R2Cust Two is 100.00. With this sale they would owe 115.00. Only an employee who may override the credit limit can issue… | PASS |
| C1-override | the administrator, who may override the credit limit, sells the same 100 on credit | accepted (override recorded) | 20  | PASS |
| C1-audit | the refusal and the override are in the audit log | 2 rows | 3 | PASS |
| C2-list | create a price list through its dialog form (the form posts save/-1) | saved | {"success":true,"message":"The price list was saved.","id":1} | PASS |
| C2-price | item P gets the price 20 in the list | saved | {"success":true,"message":"You have successfully updated item R2 Plain item","id":13} | PASS |
| C2-assign | assign the list to customer 3 | saved | {"success":true,"message":"You have successfully updated customer R2Cust Three","id":13} | PASS |
| C2-sale | sell 2 P to customer 3 at the list price 20 (the price is copied to the line) | saved | 21  | PASS |
| C2-line | the stored line price is the list price | 20 | 20.00 | PASS |
| C2-register | the register prices the line from the list as soon as the customer is chosen | 20 | 20.00 | PASS |
| C3-adv | customer 1 pays 500 in advance | saved | {"success":true,"message":"The advance payment was saved.","id":2} | PASS |
| C3-invoice | invoice of 50 to customer 1 who holds credit | saved | 22  | PASS |
| C3-balance | the customer balance rises by the invoice (50) whether or not the credit is applied | -675 | -675 | PASS |
| C4-on | turn the purchase order flow on | saved | {"success":true,"message":"The settings were saved.","id":-1} | PASS |
| C4-create | save a purchase order of 5 Q at 19 | saved, no stock or books effect | {"success":true,"message":"The document was saved.","id":1,"redirect":"http://127.0.0.1:8082/purchase_orders/view/1"} | PASS |
| C4-approve | approve it | status changed | {"success":true,"message":"The status was changed.","id":1} | PASS |
| C4-convert | convert it to a receiving | the receiving register is filled | {"success":true,"message":"The receiving register is filled in from the order. Nothing is saved until you complete the receiving.","id":1,"redirect":"… | PASS |
| C4-register | the register carries the quantity and price of the order | 5 @ 19 | 5 @ 19.00 | PASS |
| C4-receive | complete the receiving on credit | inventory +95, supplier B owed +95, stock +5 | inventory 95, 2211005 -95, qty 5 | PASS |
| C4-again | convert the same order again after it was received | refused or limited to what is left | {"success":false,"message":"An order that is \"Received\" is not received: it is cancelled or already received in full.","id":1} | PASS |
| B1a | POST without any token | 403/redirect, no 500, no state change | 403 {"title":"CodeIgniter\\Security\\Exceptions\\SecurityException","type":"CodeIgniter\\Security\\Exceptions\\SecurityExcep | PASS |
| B1b | POST with a wrong token | 403 in words | 403 {"title":"CodeIgniter\\Security\\Exceptions\\SecurityException","type":"CodeIgniter\\Security\\Exceptions\\SecurityException","code":403,"message"… | PASS |
| B2a | GET a screen with a session that does not exist | redirect to login | 302 -> http://127.0.0.1:8082/login | PASS |
| B2b | ajax POST from a form left open after the session expired | 401 (the page script sends the user to login) or a redirect to login, never a 500 | 401 {"success":false,"message":"Session expired. Please log in again."} | PASS |
| B2c | nothing saved by the refused posts | same number of sales | 0 | PASS |
| B3a | finish the same sale twice at the same moment | exactly one sale | 1 sale(s); statuses 200/200 | PASS |
| B3b | press back and finish again after the receipt | no second sale | 1 sale(s) in total | PASS |
| B3c | cash moved once | 25 | 25 | PASS |
| B3d | finish the same receiving twice at the same moment | exactly one receiving | 1 | PASS |
| B3e | customer advance posted three times with the same request key | one advance | 1 advance(s): true/true/true | PASS |
| B3f | supplier payment posted twice at the same moment (the form has no request key) | one payment | 2 payment(s) | FAIL |
| B3g | expense saved twice at the same moment | one expense (or the page blocks the second click) | 2 expense(s) | FAIL |
| B4 | two cashiers sell all 3 remaining units at the same moment while negative stock is refused | one sale, stock never below zero | 1 sale(s), stock left 0 | PASS |
| B5-qty-zero | quantity "0" on a line | refused in words or a sane number, never an error page | 200 qty=0 total=$0.00 err= | PASS |
| B5-qty-negative | quantity "-3" on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=Quantity cannot be negative. | PASS |
| B5-qty-huge | quantity "999999999999" on a line | refused in words or a sane number, never an error page | 200 qty=999,999,999,999 total=$49,999,999,999,950.00 err= | PASS |
| B5-qty-tiny | quantity "0.001" on a line | refused in words or a sane number, never an error page | 200 qty=0 total=$0.05 err= | PASS |
| B5-qty-text | quantity "abc" on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=Validation.decimal_locale | PASS |
| B5-qty-arabic digit | quantity "٣" on a line | refused in words or a sane number, never an error page | 200 qty=3 total=$150.00 err= | PASS |
| B5-qty-arabic decimal | quantity "١٫٥" on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=Validation.decimal_locale | PASS |
| B5-qty-comma decimal | quantity "1,5" on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=Validation.decimal_locale | PASS |
| B5-qty-exponent | quantity "1e3" on a line | refused in words or a sane number, never an error page | 200 qty=1,000 total=$50,000.00 err= | PASS |
| B5-qty-blank | quantity " " on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=The quantity field is required. | PASS |
| B5-qty-long digits | quantity "1111111111111111111111111111111111111111" on a line | refused in words or a sane number, never an error page | 200 qty=1 total=$50.00 err=Validation.decimal_locale | PASS |
| B5-negative-price | price "-5" on a line | refused or sane | 200 total=$50.00 err=Price cannot be negative. | PASS |
| B5-text-price | price "abc" on a line | refused or sane | 200 total=$50.00 err=Validation.decimal_locale | PASS |
| B5-arabic-price | price "٢٥" on a line | refused or sane | 200 total=$25.00 err= | PASS |
| B5-huge-price | price "99999999999999" on a line | refused or sane | 200 total=$99,999,999,999,999.00 err= | PASS |
| B5-disc-percent-over-100 | discount "150" (percent) | refused | 200 total=$50.00 err=Percentage discount cannot exceed 100%. | PASS |
| B5-disc-percent-negative | discount "-10" (percent) | refused | 200 total=$50.00 err=Discount cannot be negative. | PASS |
| B5-disc-fixed-above-the-line | discount "9999" (fixed) | refused | 200 total=$50.00 err=Discount cannot exceed the item total. | PASS |
| B5-disc-percent-text | discount "abc" (percent) | refused | 200 total=$50.00 err=Validation.decimal_locale | PASS |
| B6-item-very-long | item named "NNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNN" | saved and shown escaped, or refused in words | 200 {"success":true,"message":"You have successfully added item NNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNN | PASS |
| B6-item-emoji | item named "قهوة ☕🧋 مخبز" | saved and shown escaped, or refused in words | 200 {"success":true,"message":"You have successfully added item قهوة ☕🧋 مخبز","id":19} | PASS |
| B6-item-quotes | item named "O'Brien "Quoted" `tick`" | saved and shown escaped, or refused in words | 200 {"success":true,"message":"You have successfully added item O'Brien \"Quoted\" `tick`","id":20} | PASS |
| B6-item-angle-brackets | item named "<script>alert(1)</script><img src=x oner" | saved and shown escaped, or refused in words | 200 {"success":true,"message":"You have successfully added item <script>alert(1)</script><img src=x onerror=alert(2)>","id":21} | PASS |
| B6-item-sql | item named "x'; DROP TABLE ospos_items; --" | saved and shown escaped, or refused in words | 200 {"success":true,"message":"You have successfully added item x'; DROP TABLE ospos_items; --","id":22} | PASS |
| B6-escaping | the item list must carry no live script tag from a stored name | escaped | escaped | PASS |
| B6-customer | customer with markup and quotes in the name | saved or refused in words | {"success":true,"message":"You have successfully added customer <B>Bold</B> O'Neil \"Q\"","id":15} | PASS |
| B6-customer-escaping | customer list does not echo the tag raw | escaped | escaped | PASS |
| B7 | two invoices typed with the number DUP-100 | second refused in words | first 26 ; second refused Invoice Number DUP-100 must be unique. | PASS |
| B8 | edit form of a sale with the date "not a date" | refused in words, never a 500 | 500 {"title":"Error","type":"Error","code":500,"message":"Call to a member function format() on false","file":"/home/user/wt/R2/app/Controllers/Sales.… | FAIL |
| B9 | a cashier who holds the items and customers modules posts a new item brand | accepted (the lookup belongs to those modules) | 200 {"success":true,"message":"Added successfully.","id":1}; rows 1 | PASS |
| B9b | a cashier who holds the customers module posts a new customer classification | accepted (it belongs to that module) | 200 {"success":true,"message":"Added successfully.","id":1}; rows 1 | PASS |
| AW1-edit | raise the cost of a purchase of 10 units from 10 to 12 after 4 were sold at cost 10 | refused in words or corrected so the books stay whole | {"success":true,"message":"Lines successfully updated","id":15} / before {"qty":6,"cost":10,"cogs":587.4446,"inv":1286.7093,"payable":-250} after {"qt… | PASS |
| AW1-ties | the books tie after the edit | no tie fails | treasury_ties:fail | FAIL |
| AW2-cancel | advance 100, invoice 50 settled from it, then the advance is cancelled | cancelled with the application undone (balance back to the invoice 50) or refused in words | applied 50; balance -50 -> 50; {"success":true,"message":"It was cancelled.","id":4} | PASS |
| AW2-ties | the customer, books tie after the cancellation | no tie fails | treasury_ties:fail | FAIL |
| AW3-return | return one unit of the POS sale against its origin | saved | saved 30 | PASS |
| AW3-cancel | cancel the original POS sale that has a return | refused in words | {"success":false,"message":"A sale that goods have been returned from cannot be cancelled while its return stands."} | PASS |
| AW3-ties | the books tie | no tie fails | treasury_ties:fail | FAIL |
| AW4-edit | raise the VAT of item T from 15% to 20% | saved | {"success":true,"message":"You have successfully updated item R2 Taxed item 15","id":15} | PASS |
| AW4-new | a sale after the change carries 20 | 20 | 20 | PASS |
| AW4-old | the sale before the change keeps 15 | 15 | 15 | PASS |
| AW4-books | the output tax account equals the tax of the documents after the change | 82.86 | 82.86 | PASS |
| AW5 | a quote of 100 is converted after the default discount of the shop was raised to 10% | the invoice keeps the quoted 100 | config true; convert {"success":true,"message":"The invoice is ready in the register. The quote is converted when the invoice is saved.","redirect":"h… | PASS |
| AW6-close | close the period through today | closed | {"success":true,"message":"The period was closed.","id":1} | PASS |
| AW6-recv | edit the lines of a purchase of the closed period | refused | {"success":false,"message":"This would leave less than nothing of R2 awkward cost in stock: the goods of the receiving have been sold or moved.","id":… | PASS |
| AW6-sale | edit the header of an invoice of the closed period | refused or harmless to the books | {"success":false,"message":"The customer and the date of a sale that has been posted cannot change. Correct it with a return.","id":28} | PASS |
| AW6-adv | cancel an advance of the closed period | refused | {"success":false,"message":"This payment or opening balance is already cancelled, or has nothing posted.","id":-1} | PASS |
| AW6-expense | edit an expense of the closed period | refused | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","id":-1} | PASS |
| AW6-expense-del | delete an expense of the closed period | refused | {"success":false,"message":"The date 2026-11-14 is in a period closed through 2026-11-14 (Open Source Point of Sale).","ids":["4"]} | PASS |
| AW6-reopen | reopen | reopened | {"success":true,"message":"The closing was reopened.","id":1} | PASS |
| AW7-customer | delete customer 1 who has invoices and a balance | refused in words or hidden with history kept | {"success":true,"message":"You have successfully deleted 1 Customer(s)"} | PASS |
| AW7-supplier | delete supplier A who has purchases and a balance | refused in words or hidden with history kept | {"success":true,"message":"You have successfully deleted 1 Supplier(s)"} | PASS |
| AW7-account | delete the main cash account that holds movements | refused in words | {"success":false,"message":"This is a system account and cannot be changed this way.","id":-1} | PASS |
| AW7-treasury | delete the main treasury that holds movements | refused in words | {"success":false,"message":"The main treasury cannot be deactivated or deleted.","id":-1} | PASS |
| AW7-item | delete item P that has stock and history | refused in words or hidden with history kept | {"success":true,"message":"You have successfully deleted 1 item(s)"} | PASS |
| AW7-ties | the books tie after the deletions | no tie fails | treasury_ties:fail | FAIL |
| CC1 | two windows open the same item; B saves a new price 77, then A saves a new description with its older form | A is told the record changed, or B price kept | price now 50.00; A answered {"success":true,"message":"You have successfully updated item R2 Second item","id":14} | FAIL |
| CC2 | two windows open the same customer; B saves a phone, A then saves an address with its older form | A is told the record changed, or B phone kept | phone now null; A answered {"success":true,"message":"You have successfully updated customer R2Cust Three","id":13} | FAIL |
| CC3 | two windows record a customer advance of 10 at the same moment (different request keys) | two advances, books consistent | 2 advance(s) | PASS |
| BR-home | open /home and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-sales | open /sales and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-receivings | open /receivings and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-items | open /items and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-item_kits | open /item_kits and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-customers | open /customers and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-suppliers | open /suppliers and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-giftcards | open /giftcards and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-expenses | open /expenses and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-cashups | open /cashups and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-taxes | open /taxes and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-reports | open /reports and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-employees | open /employees and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-config | open /config and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-stock_documents | open /stock_documents and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-price_lists | open /price_lists and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-purchase_orders | open /purchase_orders and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-customer_orders | open /customer_orders and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_chart | open /accounting_chart and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_journals | open /accounting_journals and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_receivables | open /accounting_receivables and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_payables | open /accounting_payables and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_cheques | open /accounting_cheques and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_treasuries | open /accounting_treasuries and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_assets | open /accounting_assets and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_periods | open /accounting_periods and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_periods-year_end | open /accounting_periods/year_end and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_reports | open /accounting_reports and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_reports-tax | open /accounting_reports/tax and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_reports-inventory_valuation | open /accounting_reports/inventory_valuation and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_statements-balance_sheet | open /accounting_statements/balance_sheet and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_statements-profit_loss | open /accounting_statements/profit_loss and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_statements-cash_flow | open /accounting_statements/cash_flow and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_statements-income_statement | open /accounting_statements/income_statement and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_settings | open /accounting_settings and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-accounting_routing | open /accounting_routing and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-receipt_vouchers | open /receipt_vouchers and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-branches | open /branches and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-roles | open /roles and watch the console | 200, no console error, no failed request | 200  | PASS |
| BR-new-voucher | create a new voucher through its dialog in the browser | one row saved | 1 row(s) | PASS |
| BR-new-price-list | create a new price-list through its dialog in the browser | one row saved | 1 row(s) | PASS |
| BR-new-revenue-category | create a new revenue-category through its dialog in the browser | one row saved | 1 row(s) | PASS |
| BR-dblclick-expense | double click Submit on the new expense dialog | one expense | 1 expense(s) | PASS |
| BR-dblclick-customer | double click Submit on the new customer dialog | one customer | 1 customer(s) | PASS |
| BR-finish-visible | the finish button appears after the payment is added | present | true | PASS |
| BR-dblclick-finish | double click the finish sale button | one sale | 1 sale(s); events  | PASS |
| BR-back-finish | press the browser back button after the receipt and finish again | no second sale and the page comes back | 1 sale(s) in total; page after back: chrome-error://chromewebdata/ [page.goBack: net::ERR_CACHE_MISS] | FAIL |

<!-- total 263; pass 251; fail 12 -->
