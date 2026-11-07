# تقرير WP28: تعدد العملات (عملة المستند وسعره، قيود فروق الصرف، عملة الخزينة، المنازل غير العشرية الثنائية)

> الحالة (2026-11-07): **نُفِّذ على الفرع `wp/WP28`** (من الالتزام `7c10859`) بتفويض المالك التنفيذي الشامل (E47): جدول عملات وأسعار بصلاحية حساسة `acc_currencies_manage`، وعملة للمستند (بيع وشراء وردّ ودفعات) تُرحَّل قيودها **بالعملة الأساسية** مع المبلغ الأجنبي والعملة على كل سطر، وقيد **فرق صرف** منفصل لكل دفعة بسعر يخالف سعر المستند مربوط بالدفعة بمفتاح المصدر ويُعكس معها، وعملة للخزينة، وتحويل بين خزينتين بعملتين بالمبلغين، وما لا يُدعم بعد يُرفض باسم صريح في الكود. **لا دمج في `master` ولا نشر ولا طلب سحب.** سلوك دفترة غير المرصود **افتراض** يُقال عنه ذلك في موضعه (القسم 4). **جولة المراجعة (القسم 9)** فحصت 24 ادعاءً (بينها مكرَّران) من قارئين مستقلين: **كلها صحيحة وأُصلحت باختبار يفشل بلا الإصلاح، عدا جزء واحد من الادعاء 19** (أسماء الاختبارات الطويلة) **رُفض** بمسار موثَّق.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الجداول** (ترحيل `20272300000000_CurrenciesAndRates`) | `accounting_currencies` (الرمز، الاسم بالعربية والإنجليزية، المنازل 0 أو 2 أو 3 بقيد `CHECK`، التفعيل) و`accounting_currency_rates` (العملة، السعر `DECIMAL(18,8)` أكبر من صفر، تاريخ السريان، من أدخله، ملاحظة؛ **مفتاح فريد لكل عملة ويوم**). الدليل (76 عملة) يُزرع **معطَّلًا** وبمنازل ISO 4217 (لا جدول دفترة المرصود `FRM-R56`: انظر القسم 4)، والعملة الأساسية من إعداد المحاسبة **موجودة دائمًا ومفعَّلة وسعرها واحد** (`Currencies::ensureBase`). الوحدة `accounting_currencies` والصلاحية `acc_currencies_manage` تُسجَّلان **ولا يُمنح شيء** |
| **الخدمات** (`Currencies` و`CurrencyRates` و`CurrencyCatalog` و`Money` و`Currency` و`DocumentCurrency`) | إنشاء عملة وتعديل أسمائها ومنازلها (**المنازل تثبت بعد أول استعمال** في مستند أو قيد أو خزينة)، وتفعيل وتعطيل (**لا تُعطَّل الأساسية، ولا تُحذف عملة أبدًا**)، وسعر لكل يوم (إضافة وتعديل وحذف، كلٌّ مكتوب في سجل التدقيق بما كان عليه). المستند يأخذ **آخر سعر في تاريخه أو قبله**، ولا يأخذ سعرًا إن لم يوجد (`RateMissing`)، والسعر اليدوي (الذي يُخفى خلف «تعديل العملة» كما رُصد) **يحتاج الصلاحية الحساسة** |
| **الشاشات** (`AccountingCurrencies`، مسارات `accounting_currencies/*`) | قائمة العملات (مع آخر سعر والتفعيل) وإضافة وتعديل في نافذة، وشاشة أسعار لكل عملة (قائمة وإضافة وتعديل وحذف)، ولا شاشة أسعار للأساسية. القارئ بلا صلاحية لا يرى زر التعديل ولا يفتح النموذج (404). الوحدة في مجموعة المحاسبة في القائمة الجانبية، وتسمية الصلاحية في شاشة الإعدادات (الحساسة) وفي نموذج الموظف |
| **قيد بعملة أجنبية** (`PostingService` و`JournalLineDraft` و`JournalDraft`) | كل سطر قد يحمل `foreignAmount` و`currencyCode` (عمود جديد `accounting_journal_lines.currency_code`، والعمود `foreign_amount` كان محجوزًا). **قيد بعملة غير الأساسية** يُشترط فيه: مبلغ أجنبي على كل سطر بعملة القيد، ومجموع أجنبي متساوٍ للمدين والدائن، وكل مبلغ أساسي = المبلغ الأجنبي × سعر القيد مقرَّبًا بمنازل الأساسية **إلا بضع وحدات يأخذها سطر الموازنة**، وعملة معروفة ومفعَّلة (**وعكس قيد قديم يجوز بعملة عُطِّلت بعده**). قيد بالأساسية: سعره واحد ولا مبلغ أجنبي إلا لسطر خزينة بعملة أخرى. **القيد بالأساسية لم يتغير بايت** (الأعمدة الجديدة `NULL`، وكل اختبارات الترحيل والتطابق الذهبي القائمة تمر) |
| **التحويل** (`ForeignConversion`) | مستند بعملة أجنبية يُبنى بمبالغ عملته ثم يُحوَّل **سطرًا سطرًا** إلى الأساسية مقرَّبًا، و**فرق التقريب كله على سطر واحد مسمّى** (إيراد البضاعة في الفاتورة، وأكبر تكلفة في الشراء)، على نمط E14. فرق أكبر من بضع وحدات (نصف وحدة لكل سطر) أو سطر يصير صفرًا يُرفض `ForeignRoundingTooBig`. **فاتورة دفترة المرصودة** (268.33 دولارًا بسعر 3.75: العميل 1,006.24 والضريبة 131.25 والمبيعات 874.99) تخرج بالأرقام نفسها بلا تصحيح، وهي اختبار دائم |
| **البيع** (`SaleDocument.currency` و`SaleLoader` و`SalePostingBuilder` و`SalePosting` و`Sale`) | أعمدة `sales.currency_code/currency_rate`. **الفاتورة** تُبنى بمبالغ العملة (الإجمالي يُقرَّب بمنازلها؛ خصم السطر بمنازلها) ثم تُحوَّل. **التكلفة** (قيد `cogs`) **تبقى بالأساسية** بلا مبلغ أجنبي. **المرتجع وإشعار الدائن** يأخذان **عملة البيع الأصلي وسعره** من الصف المخزَّن (مرآة، لا إعادة تسعير أبدًا)، واختيار عملة أخرى لمرتجع (أو أجنبية لمرتجع أساسي) يُرفض `DocumentCurrencyLocked`. **عملة المستند المرحَّل وسعره لا يتغيران** بتعديل `Sale::update` (يُرفض `DocumentCurrencyLocked`، والعكس وإعادة الإدخال هما الطريق). المسودة تحتفظ بعملتها وتأخذ سعر يوم إصدارها |
| **الدفعة وفرق الصرف** | **الدفعة بعملة المستند دائمًا** (كما رُصد: نموذج الدفعة بلا حقل عملة أو سعر). سعرها يُؤخذ **عند ترحيلها** من جدول الأسعار بتاريخ **الدفعة** ويُكتب على صفها (`sales_payments.currency_rate`) فلا يُؤخذ مرة ثانية. قيد الدفعة: مدين الخزينة ودائن العميل **بسعر الدفعة** (والمبلغ الأجنبي على السطرين). ثم **قيد منفصل `sale_payment_fx`** بمفتاح مصدر هو رقم الدفعة: مدين **خسارة فروق العملة** ودائن العميل عندما ينقص رصيد العميل أكثر مما قُبض (سعر أقل)، ومدين العميل ودائن **أرباح فروق العملة** في العكس، بالشكل المرصود (دفعة 100 دولار بـ3.60 على فاتورة بـ3.75: خسارة 15.00؛ بـ3.90: ربح 15.00). **ما يسوّيه كل قيد** = قيمة مجموع الدفعات حتى هذه بسعر المستند مقرَّبة − ما سبقها مقرَّبًا، فـ**مجموع ما يسوّيه كل الدفعات = إجمالي الفاتورة بالأساسية بالسنت** (المرصود: 375.00 + 375.00 + 256.24 = 1,006.24)، ودفعة تتغير بحذف سابقتها يُعاد كتابة فرقها بنسخة جديدة |
| **العكس والإلغاء** | قيد الفرق **يُعكس مع دفعته** في حذف الدفعة وفي إلغاء البيع (وفي عكس الدفعة المعدَّلة)، ولا يُعكس يدويًا: **عكس قيد بعملة أجنبية وقيد فرق صرف من شاشة القيود يُرفض** `FxJournalLocked` (يُعكسان بتعديل المستند أو الدفعة). المرآة تنسخ المبلغ الأجنبي والعملة والسعر |
| **الأرصدة والأعمار** | دفتر العميل (`CustomerLedger`) **يقرأ من القيود** فهو بالأساسية أصلًا: أُضيف مصدر `sale_payment_fx` إلى «ما دُفع» (وإلى عمود «المدفوعات» في تقرير أرصدة العملاء) فيطابق **رصيد العميل في الدفتر وحسابه والمستندات والأعمار بالأساسية** (اختبار `assertTies` للفاتورة الأجنبية والأساسية معًا) |
| **الشراء** (`ReceivingDocument.currency` و`ReceivingLoader` و`ReceivingPostingBuilder` و`ReceivingPosting` و`Receiving`) | الشراء الأجنبي يُحوَّل **وقيمة مخزون كل موقع بالأساسية = قيمة سطره** (الحركات تُوزَّع بالتقريب على أكبرها فيطابق `StockLedger::reconcile`)، ومتوسط تكلفة الصنف **بالأساسية** (تكلفة الوحدة × سعر المستند) لا بعملة الشراء. **الدفعة عند الشراء** بسعر المستند (بلا فرق)، ودفعات المورد لاحقًا بسعر يومها مع قيد `supplier_payment_fx` بالنمط نفسه (المرصود: 200 دولار بسعر 3.60 على شراء بـ3.75: ربح 30.00 وصافي ما يُسوّى 750)، ومرتجع الشراء الأجنبي قيده بالأساسية (البضاعة تخرج بقيمتها في المخزون) وسطرا المورد والضريبة يحملان المبلغ الأجنبي بسعر الشراء الأصلي. **التحويل بين المواقع بعملة أجنبية يُرفض** |
| **عملة الخزينة** (`TreasuryService` و`TreasuryTransfers`) | عمود `treasuries.currency_code`. **يُحدَّد عند الإنشاء ويثبت بعد أول استعمال، والرئيسية بالأساسية**. `TreasuryService::position` يزيد `currencyCode` و`foreignBalance` (رصيدها بعملتها من أسطر القيود) **ورصيدها الأساسي هو رصيد حسابها كما كان** (اختبار). **حارس الترحيل:** سطر على خزينة بعملة لا يُقبل إلا بمبلغ بعملتها (`TreasuryCurrency`)، فدفعة بالريال إلى خزينة دولار تُرفض، ودفعة بالدولار إلى خزينة ريال تمر. **التحويل بين خزينتين:** بعملة واحدة أجنبية قيدٌ بتلك العملة؛ **بعملتين مختلفتين يحتاج المبلغين** (الخارج والواصل، `TransferAmountRequired`) والفرق بينهما بأسعار اليوم **سطر ربح أو خسارة صرف في القيد نفسه**؛ وبين فرعين بعملة أجنبية يُرفض. النموذج يُظهر حقل «المبلغ الواصل» وحده إن اختلفت العملتان |
| **التقريب والعرض** | `Money` (تقريب نصف بعيدًا عن الصفر، تحويل من الأساسية وإليها، تنسيق بمنازل العملة)، ومنازل **0 و3** مختبرة (ين ياباني ودينار كويتي) في المُنشئ والترحيل. شاشة القيد تُظهر عمود «المبلغ بالعملة الأجنبية» لقيد فيه سطر أجنبي **فقط**، وقائمة دفعات العملاء تُظهر مبلغ الدفعة بعملتها وتطابق **ما رُحِّل بالأساسية**، ومجاميعها بالأساسية. **سجل البيع (الشاشة):** رابط «تعديل العملة» يفتح الاختيار، والاختيار قبل أول صنف (يُرفض بعده وفي المرتجع)، وسعر الصنف من الكتالوج **وسعر مكوّنات الطقم وبلاطات الأصناف الأكثر مبيعًا** يُحوَّل بسعر اليوم إلى منازل العملة، وما يُكتب باليد يبقى. **أرقام السجل كلها (السطور والمجاميع والمدفوع والمتبقي والبلاطات) تُعرض برمز العملة ومنازلها** (`CurrencyDisplay`) لا برمز الأساسية. شاشة الخزائن تُظهر **رصيد الخزينة بعملتها** بجانب الأساسي (القائمة وكشف الخزينة) |
| **التعليق والاستئناف والمرتجع في السجل** (جولة المراجعة) | **تعليق** بيع أجنبي (أو مسودة فاتورة أو عرض أو أمر عمل) يحفظ عملته وسعره، و**استئنافه يعيد العملة إلى الجلسة** فيُكمَل بها لا بالأساسية، وإعادة حفظ مسودة أجنبية لا تكتب `NULL` فوق عملتها. **المرتجع لا يأخذ عملة السجل** (يرث عملة بيعه الأصلي): تبديل الوضع إلى «مرتجع» والسلة فيها أصناف بعملة يُرفض، وعلى سلة فارغة يُنسى اختيار العملة |
| **قيمة مخزنة** (جولة المراجعة) | بطاقة الهدايا ونقاط المكافآت **لا تسدّدان فاتورة بعملة أجنبية** (قيمتهما محفوظة بالأساسية فلا يصحّ أن تنقص بمبلغ الدولار): يُرفض باسم `StoredValueInForeignCurrency` في السجل وفي نموذج `Sale`. **نقاط المكافآت المكتسبة** تُحسب على قيمة البيع بالأساسية بسعر المستند |
| **ضوابط الأسعار وسجل البيع** (جولة المراجعة) | **الحد الأدنى للسعر** (بالأساسية) يُقارَن بسعر السطر الأجنبي **محوَّلًا بسعر المستند** في السجل وفي الحفظ، والرسالة تذكر الأرقام بالأساسية؛ فشل جلب سعر اليوم (حُذف أو عُطِّلت العملة بعد اختيارها) يظهر **رسالة في السجل** لا خطأ 500 |
| **الإغلاق اليومي** (جولة المراجعة) | `CashupExpectation` يعدّ دفعة النقد الأجنبية **بما دخل الخزينة بالأساسية** (مبلغها بسعر الدفعة مقرَّبًا بمنازل الأساسية؛ سعر البيع إن لم يكن للدفعة سعر) فلا يُرحَّل فرق نقد وهمي |
| **تصحيح سطور شراء أجنبي** (جولة المراجعة) | `Receiving::correctTheCost` يحوّل قيم السطور بسعر الشراء قبل أن يضبط متوسط تكلفة الصنف **بالأساسية** |
| **التحويل بين عملتين** (جولة المراجعة) | المبلغ الواصل يُقارَن بسعر اليوم: فرق أكثر من **10%** من الخارج بالأساسية **يُرفض** (`TransferRateOutOfRange`) بدل أن يُقيَّد ربحًا أو خسارة صرف بلا حد (قيمة 10% **افتراض** وبند قرار للمالك) |
| **رموز العملات** (جولة المراجعة) | رمز العملة يُقرأ بحروف كبيرة وبلا مسافات في كل مدخل (`isBase` و`find` و`update` و`setActive` و`inUse`) فلا يتجاوز `sar` حارس العملة الأساسية، وعملة **استُعملت** يمكن تعديل اسمها من النموذج المقفل (لا يُرسل المنازل) |
| **الرفض بالاسم** | مصروف أو سند قبض بعملة أجنبية يُرفض عند الترحيل `FxNotSupported` (الأعمدة موجودة والشاشات بلا حقل). قيد فرق الصرف بلا حساب في التوجيه `FxAccountsMissing`. تقريب النقد (`cash_adjustment`) على مستند أجنبي، ودفعة إلى خزينة فرع آخر، كلاهما `FxNotSupported`. العملة ذات **ثلاث منازل على مستند** `ForeignDecimalsUnsupported` (انظر القسم 4) |
| **التوجيه والحسابات** (ترحيل `20272300000002_FxDifferenceAccounts`، `RoutingKey::FxGain/FxLoss`، `DefaultChart`) | مفتاحا التوجيه `fx.gain` و`fx.loss` وحسابان افتراضيان `4209` «أرباح فروق العملة» (إيراد) و`5410` «خسائر فروق العملة» (مصروف) **يُفتحان في دليل قائم دون أن يلمسا قيدًا** (نمط `ChequesAndRevenues`: أول رمز حرّ، ومن وُجّه توجيهه لحساب آخر لا يُغيَّر). يمكن توجيههما إلى حساب واحد (مثل `1209` عند دفترة) من شاشة التوجيه |
| **الملفات** | `app/Libraries/Accounting/` (جديدة: `Currency` و`Currencies` و`CurrencyCatalog` و`CurrencyRates` و`DocumentCurrency` و`ForeignConversion` و`Money`)، `app/Controllers/AccountingCurrencies.php` و`app/Views/accounting_currencies/`، ثلاثة ترحيلات `20272300000000` إلى `…02`، لغات `Accounting_currencies` وتعديلات إضافية في `Accounting` و`Accounting_routing` و`Accounting_treasuries` و`Accounting_journals` و`Sales` و`Module` (**أحرف عربية حقيقية لـar-EG وar-LB، والباقي فارغ يرجع للإنجليزية**) |

## 2. الأدلة

**ما شُغّل** (من `/home/user/wt/WP28`، قاعدة `ospos_test_wp28`، كل مرة بمعالج واحد، **لم تُشغَّل المجموعة الكاملة**):

| التشغيل | الملفات | النتيجة |
|---|---|---|
| ملفات الحزمة الجديدة والمتغيرة | `CurrenciesTest` و`CurrencyRatesTest` و`SalePostingBuilderTest` و`ReceivingPostingBuilderTest` (بعد آخر إضافة) | **OK: 238 اختبارًا** |
| الاختبارات المضافة في آخر جولة بالتصفية | `ReceivingPostingTest` و`PostingServiceTest` و`TreasuryTransfersTest` و`SalePostingTest` | **OK: 6 اختبارات** |
| الجولة الأولى بعد المنطق | `CreditSettlementTest` و`CustomerBalanceReportTest` و`SupplierBalanceReportTest` و`AccountingCurrenciesControllerTest` و`MultiCurrencyMigrationTest` و`PostingServiceTest` | **OK: 231 اختبارًا** |
| مكتبات المحاسبة (12 ملفًا أولها) | `CurrenciesTest` إلى `CrossBranchPaymentsTest` | 787 اختبارًا: **فشل واحد** (ترتيب أسطر قيد خسارة الصرف في اختبار جديد لي) فصُحِّح |
| مكتبات المحاسبة والترحيل والجاهزية والبنك واللغات | `TreasuryTransfersTest` إلى `AccountingCurrenciesControllerTest` (31 ملفًا و`Readiness` و`Bank` و7 ملفات ترحيل و`ParityFixtureTest` و`LanguageFilesTest`) | 4,411 اختبارًا: **5 أخطاء** كلها قوائم قائمة تحتاج الحسابين الجديدين والتوجيه والصلاحية (القسم أدناه) فصُحِّحت وأُعيد تشغيلها |
| `SalePostingTest` و`ReceivingPostingTest` | الكبيران | **OK: 721 اختبارًا** (6,073 تأكيدًا) |
| دفعة ثانية أوسع (47 ملف مكتبات ومنها الخمسة المصحَّحة + 10 متحكمات + `RolesPermissionsMigrationTest` + `Libraries/Roles`) | سجلات ومخزون وإشعارات وشيكات وتقارير وكشوف | 2,166 اختبارًا: **فشل واحد** (قائمة أكواد في `AccountingStatementsControllerTest`) فصُحِّح وأُعيد: **OK: 67 اختبارًا** |
| المتحكمات والنماذج | الخزائن والذمم والإعدادات والتوجيه والقيود والموظفين والأدوار والمبيعات والمشتريات + `SaleTest` و`ReceivingTest` و`Sale_libTest` و`Sale_libPaymentTest` | 1,436 اختبارًا: كل شيء ناجح **عدا `ReceivingTest` (75 خطأ)** لأن `$refresh = true` يرجع الترحيلات و`QuoteLifecycle::down()` يرفض ما دام في القاعدة عروض بقيت من ملفات سبقته في **التشغيل نفسه**؛ أُعيد ومعه ملف يزرع القاعدة نظيفة: **OK: 94 اختبارًا** (انظر الملاحظات) |

**التصحيحات التي طلبها الخط الأساسي القائم** (قوائم مكتوبة باليد لا خلل في المنطق): `DefaultChartTest` (الحسابان `4209` و`5410`)، `StatementFiguresTest` و`AccountingStatementsControllerTest` (القائمتان تعرضان `4209` و`5410`)، `RoutingServiceTest` (`fx.gain` و`fx.loss`)، `SensitivePermissionsTest` (`acc_currencies_manage`)، `YearEndClosingTest` (استعمل الرمز `5410` لحساب «إيجار» فصار محجوزًا فأخذ `5411`).

**الاختبارات المضافة:** 6 ملفات جديدة (`CurrenciesTest` 24، `CurrencyRatesTest` 16، `MoneyTest` 6، `ForeignConversionTest` 10، `AccountingCurrenciesControllerTest` 23، `MultiCurrencyMigrationTest` 14) و**129 اختبارًا مضافًا** إلى ملفات قائمة (`PostingServiceTest` 17 و`JournalServiceTest` 1 و`SalePostingBuilderTest` 23 و`SalePostingTest` 26 و`ReceivingPostingBuilderTest` 17 و`ReceivingPostingTest` 18 و`TreasuryServiceTest` 9 و`TreasuryTransfersTest` 11 و`ExpensePostingTest` 1 و`RevenueVouchersTest` 1 و`CreditSettlementTest` 3 و`CustomerBalanceReportTest` 1 و`SupplierBalanceReportTest` 1). **ملف اختبار واحد لكل صنف**؛ التسمية `test{الدالة}_{السلوك}`.

**ما تغطيه الاختبارات** (بحسب تعريف الإنجاز): الحالة العادية والحدّية (المنازل 0 و2 و3 بين `Money` والمُنشئ والخزينة، والتقريب نصف بعيدًا عن الصفر، والتقريب التراكمي للدفعات الجزئية، وأصغر سعر وأدقه)؛ **الأثر المحاسبي** (قيد البيع والشراء والمرتجع والدفعة وفرق الصرف سطرًا سطرًا، والمخزون `StockLedger::reconcile`، ورصيد الخزينة = حسابها، وتطابق دفتر العميل والمورد والأعمار)؛ **الصلاحيات** (`acc_currencies_manage` حساسة ومرفوضة لغير حامليها، ونطاق الشركة، والقارئ بلا زر)؛ **العكس والتعديل** (فرق الصرف يُعكس مع دفعته وبإلغاء المستند، لا عكس يدوي، ولا تعديل لعملة المستند المرحَّل وسعره)؛ **الفروع** (دفعة بعملة أجنبية إلى خزينة فرع آخر مرفوضة، وتحويل بين فرعين بعملة أجنبية مرفوض، ومستند أجنبي في فرع مخزونه)؛ **الفترات المقفلة** (بيع أجنبي في فترة مقفلة يُرفض ولا يُحفظ شيء)؛ **بيانات قديمة** (قيد بالأساسية لم يتغير بايت: أعمدة جديدة `NULL`، واختبار فاتورة دفترة 268.33 يخرج بالأرقام نفسها، ومرحلة `down()` ترفض ما دام صف يحمل عملة، والترحيلات آمنة عند التشغيل ثانية)؛ **المحاسبة مطفأة** (عملة أجنبية تُرفض باسم `ForeignCurrencyUnsupported` ولا يتغير سلوك OSPOS).

## 3. الحراس

كل حارس أُضيف في هذه الحزمة له **طفرة** في `docs/accounting/daftra-parity/wp28-guards.json` (`find` يقع **مرة واحدة** في الملف، و`replace` يعطّل الحارس)، ويُثبت `tools/dev/guard_mutations.py` أن الاختبارات **تفشل** حين يُعطَّل. الأمر:

```
MYSQL_DB_NAME=ospos_test_wp28 python3 tools/dev/guard_mutations.py docs/accounting/daftra-parity/wp28-guards.json [بادئات الأسماء]
```

| المجموعة | عدد الحراس |
|---|---|
| `Currencies` (العملات) | 18 |
| `CurrencyRates` (الأسعار) | 17 |
| `PostingService` (قيد بعملة) | 12 |
| `ForeignConversion` (التحويل) | 3 |
| `JournalService` (العكس) | 3 |
| `SalePostingBuilder` (بناء قيود البيع) | 10 |
| `SalePosting` (ترحيل البيع) | 7 |
| `Sale` و`Sale_lib` (اختيار العملة) | 5 |
| `Receiving` (نموذج الشراء) | 5 |
| `ReceivingPostingBuilder` (بناء قيود الشراء) | 11 |
| `ReceivingPosting` (ترحيل الشراء) | 5 |
| `TreasuryService` و`TreasuryTransfers` (الخزينة والتحويل) | 11 |
| المصروف وسند القبض | 2 |
| الدفاتر وتقارير الأرصدة | 4 |
| شاشة العملات (`AccountingCurrencies`) | 5 |
| ترحيل `DocumentCurrencyColumns` | 1 |
| **المجموع** | **119** |

**نتيجة التشغيل:** **119 من 119 مكتشفة (`DETECTED`)، صفر `UNDETECTED`، صفر `INVALID`.**

- **التشغيل الأول** (117 طفرة يومئذٍ): قُطع بعد 104 طفرة بمقاطعة (`KeyboardInterrupt`) لا أعرف مصدرها، والأداة تعيد الملف المعدَّل دائمًا فبقيت الشجرة نظيفة (تحقّقتُ بـ`git status`)، فكانت **92 مكتشفة و12 غير مكتشفة**. غير المكتشفة قادت إلى اختبارات جديدة: نطاق الشركة لتعديل العملة وتفعيلها وتعديل السعر وحذفه (`C04` و`C06` و`R05` و`R06`)؛ قيد بالأساسية ومعه سطر بعملة أخرى وسعر غير واحد (`P05`)؛ **التقريب التراكمي لدفعات جزئية** عند العميل والمورد، وبعد دفعة عند الشراء (`S03` و`B09` و`B07`: دفعتان من 0.10 على 0.20 تُسوّيان 0.75 لا 0.76)؛ إجمالي شراء بالين بلا كسور (`B10`)؛ دفعة مورد في يوم بلا سعر (`Q05`)؛ نسيان العملة بعد حفظ واحد في الشراء (`N05`).
- **الحارس `S06` كان فرعًا ميتًا** (`$account === null` بعد التوجيه: مفتاحا `fx.gain` و`fx.loss` يقبلان النوع «تلقائي» وحده فيرمي المُحلِّل قبل أن يعيد `null`). **استُبدلت طفرته** بالحارس الحقيقي (تغليف رفض التوجيه باسم `FxAccountsMissing`)، وأُضيفت الطفرتان المناظرتان للشراء (`B11`) والتحويل (`T11`) مع اختبارَيهما. **الفرع الميت نفسه بقي حماية دفاعية ولا يُدَّعى حارسًا** (القسم 5).
- **التشغيل الثاني** (27 طفرة: الـ12 + `B11` و`T11` + 13 لم تبلغها المقاطعة): **27 من 27 مكتشفة**.
- الطفرتان `K03` و`K04` (تقارير أرصدة العميل والمورد تحسب فرق الصرف مدفوعات) أُضيفتا مع اختباريهما بعد الإصلاح.

الحراس بأسمائها:

- `C01` create asks the permission to manage currencies (`Currencies.php`)
- `C02` create asks for the whole company (`Currencies.php`)
- `C03` update asks the permission (`Currencies.php`)
- `C04` update asks for the whole company (`Currencies.php`)
- `C05` switching on or off asks the permission (`Currencies.php`)
- `C06` switching on or off asks for the whole company (`Currencies.php`)
- `C07` the code of a currency is three capital letters (`Currencies.php`)
- `C08` a code is taken once (`Currencies.php`)
- `C09` decimals are zero, two or three (`Currencies.php`)
- `C10` decimals are fixed once the currency was used (`Currencies.php`)
- `C11` the base currency is never switched off (`Currencies.php`)
- `C12` a switched-off currency is not offered on a document (`Currencies.php`)
- `C13` the base currency is not a foreign currency (`Currencies.php`)
- `C14` a rate typed by hand asks the permission to manage currencies (`Currencies.php`)
- `C15` a document needs a rate on or before its date (`Currencies.php`)
- `C16` a currency of three decimals is refused on a document (`Currencies.php`)
- `C17` the row of the base currency is put back switched on (`Currencies.php`)
- `C18` a currency created is written to the audit log (`Currencies.php`)
- `R01` adding a rate asks the permission (`CurrencyRates.php`)
- `R02` changing a rate asks the permission (`CurrencyRates.php`)
- `R03` deleting a rate asks the permission (`CurrencyRates.php`)
- `R04` adding a rate asks for the whole company (`CurrencyRates.php`)
- `R05` changing a rate asks for the whole company (`CurrencyRates.php`)
- `R06` deleting a rate asks for the whole company (`CurrencyRates.php`)
- `R07` the base currency has no rate (`CurrencyRates.php`)
- `R08` a rate is above zero (`CurrencyRates.php`)
- `R09` a rate has at most eight decimals and is a number (`CurrencyRates.php`)
- `R10` one rate for a currency and a day (`CurrencyRates.php`)
- `R11` a document takes the latest rate on or before its date (`CurrencyRates.php`)
- `R12` the base currency is worth one (`CurrencyRates.php`)
- `R13` a rate added is written to the audit log (`CurrencyRates.php`)
- `R14` a rate changed is written to the audit log with what it was (`CurrencyRates.php`)
- `R15` a rate deleted is written to the audit log (`CurrencyRates.php`)
- `R16` a change that changes nothing writes nothing (`CurrencyRates.php`)
- `R17` a rate of a day cannot move onto a day that has one (`CurrencyRates.php`)
- `P01` a line of a foreign journal carries a foreign amount (`PostingService.php`)
- `P02` a foreign journal balances in its own currency (`PostingService.php`)
- `P03` a base amount is what the foreign amount comes to at the rate (`PostingService.php`)
- `P04` a journal in the base has the rate one (without foreign lines) (`PostingService.php`)
- `P05` a journal in the base has the rate one (with a line in another currency) (`PostingService.php`)
- `P06` a currency the books do not use is refused (`PostingService.php`)
- `P07` the reversal of a journal may use a currency switched off since (`PostingService.php`)
- `P08` a foreign amount and its currency go together (`PostingService.php`)
- `P09` a foreign amount is above zero (`PostingService.php`)
- `P10` a line of a foreign journal is in the currency of the journal (`PostingService.php`)
- `P11` a line of a base journal carries no foreign amount in the base (`PostingService.php`)
- `P12` a treasury that holds a currency takes only amounts in it (`PostingService.php`)
- `F01` a difference that is more than rounding is refused (`ForeignConversion.php`)
- `F02` the line that balances never comes to nothing (`ForeignConversion.php`)
- `F03` the line that balances is one of the lines (`ForeignConversion.php`)
- `J01` the reversal mirrors the foreign amount and the currency of every line (`JournalService.php`)
- `J02` a journal in a foreign currency and an exchange difference are not taken back by hand (`JournalService.php`)
- `J03` the books reverse an exchange difference with its payment (`JournalService.php`)
- `S01` the rounding of cash is refused on a foreign sale (`SalePostingBuilder.php`)
- `S02` a payment of a foreign sale has a rate (`SalePostingBuilder.php`)
- `S03` the earlier payments count in what a payment settles (`SalePostingBuilder.php`)
- `S04` only a completed payment books anything (`SalePostingBuilder.php`)
- `S05` a loss is posted when the customer's balance falls by more than the money received (`SalePostingBuilder.php`)
- `S06` a routing of the exchange difference that has no usable account is refused with a name (`SalePostingBuilder.php`)
- `S07` a payment of a foreign sale is converted at its own rate (`SalePostingBuilder.php`)
- `S08` the invoice of a foreign sale is converted line by line (`SalePostingBuilder.php`)
- `S09` the total of a foreign document is kept in the decimals of its currency (`InvoiceRounding.php`)
- `S10` the discount of a foreign line is rounded to the decimals of its currency (`SaleAmounts.php`)
- `L01` a payment of a foreign sale takes the rate of its day, or is refused (`SalePosting.php`)
- `L02` a foreign payment does not go into a treasury of another branch (`SalePosting.php`)
- `L03` removing a payment takes back its exchange difference (`SalePosting.php`)
- `L04` cancelling a sale takes back the exchange differences of its payments (`SalePosting.php`)
- `L05` an exchange difference that an earlier payment changed is posted again (`SalePosting.php`)
- `L06` the currency and the rate of a posted sale do not change (`SalePosting.php`)
- `L07` a change of the rate alone is a change (`DocumentCurrency.php`)
- `M01` a return takes the currency and the rate of its sale (`Sale.php`)
- `M02` a return is in the currency of its sale (`Sale.php`)
- `M03` a foreign currency needs the books on (`Sale.php`)
- `M04` the currency chosen is for one save only (`Sale.php`)
- `M05` an issued draft keeps its currency (`Sale.php`)
- `N01` a purchase return takes the currency and the rate of its purchase (`Receiving.php`)
- `N02` a purchase return is in the currency of its purchase (`Receiving.php`)
- `N03` a purchase in a foreign currency needs the books on and is never a transfer (`Receiving.php`)
- `N04` the cost of an item is averaged in the base (`Receiving.php`)
- `N05` the currency chosen is for one save only (`Receiving.php`)
- `B01` a transfer of stock is never in a foreign currency (`ReceivingPostingBuilder.php`)
- `B02` the stock of a foreign purchase is worth what its line is worth in the base (`ReceivingPostingBuilder.php`)
- `B03` the movements of a location add up to its line (`ReceivingPostingBuilder.php`)
- `B04` the payment made at once is converted at the rate of the purchase (`ReceivingPostingBuilder.php`)
- `B05` a payment to the supplier is converted at its own rate (`ReceivingPostingBuilder.php`)
- `B06` a gain is posted when the supplier's balance falls by more than the money paid (`ReceivingPostingBuilder.php`)
- `B07` what was paid at once counts among the payments of the purchase (`ReceivingPostingBuilder.php`)
- `B08` a payment of a foreign purchase has a rate (`ReceivingPostingBuilder.php`)
- `B09` the earlier payments count in what a payment to the supplier settles (`ReceivingPostingBuilder.php`)
- `B10` the total of a foreign purchase is kept in the decimals of its currency (`ReceivingAmounts.php`)
- `B11` a routing of the exchange difference that has no usable account is refused with a name (`ReceivingPostingBuilder.php`)
- `Q01` a foreign payment does not come out of a treasury of another branch (`ReceivingPosting.php`)
- `Q02` removing a payment takes back its exchange difference (`ReceivingPosting.php`)
- `Q03` deleting a purchase takes back the exchange differences of its payments (`ReceivingPosting.php`)
- `Q04` the currency and the rate of a posted purchase do not change (`ReceivingPosting.php`)
- `Q05` a payment of a foreign purchase takes the rate of its day, or is refused (`ReceivingPosting.php`)
- `T01` the currency of a used treasury and of the main treasury is fixed (`TreasuryService.php`)
- `T02` the main treasury is in the base currency (`TreasuryService.php`)
- `T03` a treasury holds a currency that exists and is switched on (`TreasuryService.php`)
- `T04` the foreign balance counts a credit against the treasury (`TreasuryService.php`)
- `T05` a transfer between currencies needs the amount that arrives (`TreasuryTransfers.php`)
- `T06` two treasuries of the base take the same amount out and in (`TreasuryTransfers.php`)
- `T07` two treasuries of one foreign currency take the same amount out and in (`TreasuryTransfers.php`)
- `T08` a transfer between branches is never in a foreign currency (`TreasuryTransfers.php`)
- `T09` an exchange gain is posted when more arrives than leaves (`TreasuryTransfers.php`)
- `T10` a transfer needs the rate of its day (`TreasuryTransfers.php`)
- `T11` a routing of the exchange difference that has no usable account is refused with a name (`TreasuryTransfers.php`)
- `E01` an expense in a foreign currency is refused with a name (`ExpensePosting.php`)
- `E02` a receipt voucher in a foreign currency is refused with a name (`RevenueVouchers.php`)
- `K01` the customer ledger counts the exchange difference in what was paid (`CustomerLedger.php`)
- `K02` the supplier ledger counts the exchange difference in what was paid (`SupplierLedger.php`)
- `K03` the customer balance report counts the exchange difference under payments (`CustomerBalanceReport.php`)
- `K04` the supplier balance report counts the exchange difference under payments (`SupplierBalanceReport.php`)
- `U01` the form of a currency is for who manages currencies (`AccountingCurrencies.php`)
- `U02` the form of a rate is for who manages currencies (`AccountingCurrencies.php`)
- `U03` the base currency has no screen of rates (`AccountingCurrencies.php`)
- `U04` a rate is changed only through its own currency (`AccountingCurrencies.php`)
- `U05` a reader is offered no edit (`AccountingCurrencies.php`)
- `D01` the columns are not dropped while a row carries a currency (`20272300000001_DocumentCurrencyColumns.php`)


## 4. ما افترضتُه

1. **سلوك دفترة بعد ما رُصد افتراض** (E66، الدفعة B): المرصود قيد فرق منفصل مربوط بالمستند، ومنازل USD 2 وKWD 3 وJPY 0، وسعر الدفعة بتاريخ **الدفعة**، وفاتورة 268.33. **ما لم يُرصد وأفترضه:** أن الدفعة عند إنشاء الفاتورة تُسوّى بسعر الفاتورة (بلا فرق)؛ وأن ما يُسوّى كل دفعة يُحسب بالفرق التراكمي المقرَّب (لضمان التطابق بالسنت)؛ وأن المرتجع بسعر المستند الأصلي (الوصف في المهمة، ودفترة لم تُرصد فيه)؛ وأن قيد فرق الصرف لا يحمل مبلغًا أجنبيًا.
2. **حسابان منفصلان لأرباح الصرف وخسائره** (توصية E66 البند 5، `G-132` قرار للمالك): دفترة تُرحّل الاثنين إلى `1209` وقد رُصد أنه يحمل الربح والخسارة معًا ويُسرّب صافيًا إلى عمود الريال. الافتراضي هنا حسابان (`4209` و`5410`) ويبقى للمالك توجيههما إلى حساب واحد.
3. **المنازل من جدول ISO 4217 لا من جدول دفترة** (`FRM-R56`): جدول دفترة المرصود يعطي 0 منازل لعملات مثل PLN وRUB وSEK وHUF وIDR وCOP وهي ISO بمنزلتين، فلا يُعتمد مرجعًا. الاختبار `testDecimalsOf_...` يقول: الين 0 والدينار الكويتي 3 والريال 2 وPLN 2.
4. **عملة بثلاث منازل لا تُقبل على مستند بعد** (`G-25` جزئي): أعمدة `item_unit_price` و`discount` في بنود البيع والشراء و`payment_amount` و`cash_refund` في الدفعات `DECIMAL(15,2)`، فسعر 10.125 دينارًا يُخزَّن 10.13 والدفعة 30.375 تُخزَّن 30.38 فيبقى 0.005 مفتوحًا للأبد. **توسيع هذه الأعمدة ترحيل ثقيل يغيّر نصّ كل سعر ودفعة مخزَّنة** (`'90.00'` تصير `'90.000'`) وتنكسر به اختبارات حزم أخرى تقارن النصوص الدقيقة، فتُرك بند قرار (القسم 6). **المبنى والترحيل والقيد والخزينة تدعم الثلاث منازل** (اختبارات الدينار الكويتي على مستوى المُنشئ والترحيل والخزينة)، والرفض على المستند باسم `ForeignDecimalsUnsupported` فلا شيء يُقبل ناقصًا.
5. **الدفعة عند الشراء بسعر الشراء نفسه**: تُرحَّل في اليوم الذي حُفظ فيه الشراء، وسعر دفعة بتاريخ مختلف عن تاريخ الفاتورة المكتوب لا يُؤخذ لها (تُسوّى بسعر المستند). دفعات المورد اللاحقة بسعر يوم الدفعة.
6. **الأرصدة أساسية والمقابلة بالأساسية**: التسوية الآلية للرصيد الدائن (`CreditSettlement` و`SupplierSettlement`) تعمل بالأساسية لأن دفتر العميل والمورد يقرأ من القيود. **افتراض:** دفعة مقدمة بالأساسية تُطبَّق على فاتورة بعملة أجنبية **بمقدارها الأساسي** (قيمة الفاتورة المفتوحة بالأساسية تنقص بها) ولا يُحسب فرق صرف لأن التطبيق لا يكتب قيدًا، وإشعار دائن لمرتجع فاتورة أجنبية يسدّد فاتورته بسعرها لأنه يرث سعرها. **لم أمنع** تطبيق رصيد على فاتورة بعملة أخرى: المنع يترك رصيد العميل المقدّم معلقًا بلا طريق لتطبيقه (لا تطبيق يدوي في النظام)، وكل الأرصدة تتطابق بالأساسية (اختبارات `CreditSettlementTest`). المنظور الأجنبي للفاتورة (كم بقي بالدولار) **لا يُعاد حسابه** بعد تطبيق رصيد أساسي عليها: بند `WP28-CREDIT-CURRENCY` بقرار المالك.
7. **سعر مستند الشراء المرتجع لا يُعاد** حتى لو تغيّر جدول الأسعار؛ وسعر «تعديل العملة» اليدوي ملزم بالصلاحية الحساسة ولا يظهر في شاشة السجل إلا الاختيار.
8. **إعدادات دفترة `update_journal_entries_currency_rates`** (رُصد ظهورها في صفحتين بحالتين) لم يُرصد أثرها ولم يُبنَ لها مقابل.
9. **نقاط المكافآت على بيع أجنبي** (جولة المراجعة) تُحسب على قيمة البيع بالأساسية **بسعر المستند** لا بسعر كل دفعة: برنامج ولاء لا قيد محاسبي، وسعر المستند هو ما كُتب على الفاتورة. **افتراض** (دفترة لا تُرصد فيها).
10. **بطاقة الهدايا ونقاط المكافآت على مستند أجنبي مرفوضة** (جولة المراجعة): قيمتهما محفوظة بالعملة الأساسية، فخصمهما بمبلغ الدولار يُحدث فرقًا بين حساب الالتزام ورصيد البطاقات. **الرفض هو الاختيار الأكثر أمانًا وهو افتراض**: لا سلوك مرصود لدفترة.
11. **عدّ النقد الأجنبي في الإغلاق اليومي** (جولة المراجعة) بسعر **الدفعة** مقرَّبًا بمنازل الأساسية (ما دخل الخزينة فعلًا). **افتراض**: لم يُرصد إغلاق يومي بعملة.
12. **حد 10% للتحويل بين عملتين** (جولة المراجعة): الفرق بين المبلغ الواصل وقيمة الخارج بأسعار اليوم يُقيَّد ربحًا أو خسارة صرف حتى 10% من الخارج بالأساسية وما فوقه يُرفض. **النسبة افتراض** (دفترة تقيّم الساق الأجنبية بالجدول ولا تقبل سعرًا يكتبه المستخدم): بند قرار للمالك (القسم 6).
13. **الثابت `purchase_payment_fx` حُذف** (جولة المراجعة): لم يكن يُنتجه أي مُنشئ (فرق الصرف عند الدفع الفوري بلا فرق أصلًا).

## 5. ما بقي

| البند | الحالة |
|---|---|
| **إعادة تقييم الأرصدة الأجنبية المفتوحة آخر الفترة** (فروق غير محققة) | **غير مبنية**: فرق الصرف المحقق وحده يُرحَّل عند الدفع. الرصيد الأجنبي المفتوح يبقى بسعره حين رُحِّل (القيود لا تُعاد تسعيرها أبدًا، وهي الثابتة الأولى للحزمة). `YearEndClosing` لا يغيّر لها شيئًا. سلوك دفترة (تعيد تسعير قيودها القديمة، رُصد `#000197`) **لا يُحاكى**. بند جديد `WP28-REVALUATION` بقرار المالك |
| **مصروف وسند قبض وشيك وسلفة بعملة أجنبية** | **مرفوضة باسم** (`FxNotSupported`) في ترحيل المصروف وسند القبض؛ لا حقل عملة في شاشاتها، ولم يُرصد سلوك دفترة فيها. البند `G-12` يبقى جزئيًا. الشيك بعملة أجنبية (وفرق صرف تحصيله) يبقى لـWP26/WP28 كما كتب تقرير WP26 |
| **شاشة سجل المشتريات** | الاختيار في **سجل البيع** وحده. النموذج `Receiving::useCurrency` والترحيل جاهزان **ولم تُوصَل شاشة سجل المشتريات** (`Receivings`/`Receiving_lib`). بند `WP28-RECEIVINGS-REGISTER` |
| **ثلاث منازل على مستند** | مرفوضة بالاسم (القسم 4، البند 4). بند `WP28-WIDE-AMOUNT-COLUMNS` بقرار المالك |
| **مرشح العملة في التقارير** | **غير مبنى**: كل التقارير بالأساسية (ميزان المراجعة وكشف الحساب والأرصدة والأعمار)، ويظهر المبلغ الأجنبي في **شاشة القيد وكشف الحساب بعمود العملة على السطر**. المرشح «كل عملة على حدة» (`currency=USD` بوحدات الدولار) يحتاج تعديل استعلامات `TrialBalance` و`AccountLedger` والإصدار الأول من العمود `currency_code` على السطر موجود له. بند `WP28-REPORT-CURRENCY-FILTER` |
| **تقارير OSPOS القديمة** (ملخص المبيعات وملخص المدفوعات) | تجمع بنود البيع **بعملة المستند** كما هي (ريال وبضعة دولارات في مجموع واحد)، **لا تحوّل**. حتى تُحوَّل (جدول مؤقت `create_temp_table`) فالمبيعات الأجنبية تظهر في تقارير المحاسبة بالأساسية وفي تقارير OSPOS القديمة بعملتها. بند `WP28-LEGACY-REPORTS` وهو **خلل معروف**، لا نعدّه مطابقة لدفترة |
| **قرار المالك على الحسابين** | `4209` و`5410` (القسم 6) |
| **التقرير والإيصال** | العرض بمنازل العملة ورمزها في **سجل البيع كله** (السطور والمجاميع والمدفوع والمتبقي وبلاطات الأصناف) وقائمة الدفعات وشاشة القيد وشاشة الخزائن؛ **إيصال البيع والفاتورة المطبوعة ما زالا يطبعان بمنازل الأساسية** (`to_currency`). بند `WP28-RECEIPT-DISPLAY` |
| **ربط الأرصدة الدائنة بالمستند الأجنبي** (`G-138`، `WP28-CREDIT-CURRENCY`) | تطبيق الرصيد الدائن يعمل **بالأساسية** على الفاتورة الأجنبية (بلا فرق صرف ولا إعادة حساب للمنظور الأجنبي)؛ تسوية بالعملة نفسها وبين عملتين وبين الفروع لم تُبنَ |
| **فرع دفاعي ميت** (`$account === null`) | في `SalePostingBuilder::fxAccount` و`ReceivingPostingBuilder::fxAccount` و`TreasuryTransfers::exchangeAccount`: المُحلِّل يعيد `null` للنوع «ملغى» وحده، ومفتاحا `fx.gain` و`fx.loss` يقبلان «تلقائي» وحده (`RoutingKey::allowedTypes`)، فلا يبلغه شيء اليوم. تُرك حماية دفاعية إن فُتح «ملغى» لهما يومًا (يخرج رفضًا باسم لا خطأ نوع)، **ولا يُدَّعى حارسًا**: حارسها الفعلي تغليف رفض المُحلِّل (`S06` و`B11` و`T11`) |
| **المتصفح** | لم يُجرَ تمرير في المتصفح (شاشة العملات والأسعار ونموذج الخزينة والتحويل واختيار العملة في السجل): الاختبارات من الخادم |

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

- **`G-132` حسابا فروق الصرف:** الافتراضي حسابان (`4209` و`5410`) أم واحد (`1209`)؟ وهل الصافي يظهر في الأرصدة؟ (دفترة تسرّب صافي الفرق إلى عمود الريال: رُصد 4.75).
- **صلاحية `acc_currencies_manage`:** **حساسة** (E47 البند 6 من المهمة: تغيير سعر يغيّر مالًا)، **لا يمنحها أحد**، تُمنح من شاشة الموظف لمن يملك صلاحية الإدارة العليا وكل الفروع (الخدمة تطلب أيضًا كل الفروع كالأصناف التي تخص الشركة).
- **حد 10% للتحويل بين عملتين** (جولة المراجعة): هل تبقى النسبة أم تُجعل إعدادًا أم يُمنع كتابة المبلغ الواصل ويُحسب من جدول الأسعار؟ **القيمة الحالية افتراض.**
- **بطاقة الهدايا ونقاط المكافآت على مستند أجنبي**: مرفوضة الآن (الأكثر أمانًا). هل تُحوَّل قيمتها بسعر يوم الاستعمال ويُسجَّل فرق صرف، أم تبقى مرفوضة؟ **بقرار المالك** ولم يُرصد سلوك دفترة.
- **المنازل الثلاث على المستند** (`WP28-WIDE-AMOUNT-COLUMNS`): توسيع الأعمدة إلى `DECIMAL(15,3)` أو `(15,4)` بترحيل ثقيل يحتاج نافذة صيانة وتحديث اختبارات النصوص الدقيقة في الحزم الأخرى.
- **إعادة تقييم آخر الفترة** (`WP28-REVALUATION`): تُبنى أم تبقى فروق الصرف المحققة وحدها.
- **ما يحتاج رصدًا في دفترة** (لم يُرصد): مصروف وسند قبض بعملة أجنبية (OO-15)، وإشعار دائن بعملة، والإعداد `update_journal_entries_currency_rates`، وسعر الدفعة عند إنشاء الفاتورة، وتحويل بين خزينتين بعملتين مختلفتين غير الريال (رُصد ريال ودولار فقط).

## 7. قبل النشر

1. **ثلاثة ترحيلات إضافية** (`20272300000000` و`…01` و`…02`): الثاني **ثقيل** (يضيف أعمدة قابلة لـ`NULL` إلى `sales` و`sales_payments` و`receivings` و`receivings_payments` و`expenses` و`revenues` و`treasuries` و`treasury_transfers` و`accounting_journal_lines`: **أكبر جدول هو `accounting_journal_lines`**، فيُجرى في ساعة هادئة بعد نسخ احتياطي)، و`down()` فيه **يرفض** ما دام صف يحمل عملة. الأول والثالث يضيفان جداول وصفوفًا فقط. كلها **آمنة عند التشغيل ثانية** ولا تلمس قيدًا ولا مستندًا ولا رصيدًا.
2. **الصلاحية** `acc_currencies_manage` **لا يحملها أحد بعد الترحيل**: تُمنح لمن يدير العملات وحده.
3. **الحسابان** `4209` و`5410` يُفتحان في الدليل القائم بأول رمز حرّ؛ يُراجَع توجيه `fx.gain` و`fx.loss` في شاشة التوجيه (واختبار الجاهزية).
4. **الأسعار:** لا مستند بعملة أجنبية قبل **تفعيل العملة وإدخال سعر قبل تاريخ المستند**؛ وإلا يُرفض بـ`RateMissing`.
5. **عملة الخزينة تُحدَّد قبل أول حركة** (وتثبت بعدها)، ولا يُرحَّل رصيد افتتاحي إلى خزينة بعملة أجنبية (يُرفض `TreasuryCurrency`).
6. **أمر `accounting:readiness`:** يُشغَّل بعد الترحيل، وتُراجَع قائمة الترحيلات المعلَّقة (الثاني `heavy`).
7. **اختبار `ReceivingTest` يعتمد على قاعدة نظيفة** (علّة قائمة قبل هذه الحزمة لا بسببها): `$refresh = true` يرجع الترحيلات و`QuoteLifecycle::down()` يرفض ما دام في القاعدة عروض أسعار بقيت من ملف سبقه في التشغيل نفسه، فيفشل بـ75 خطأً إن شُغِّل بعد `SalesControllerTest` مثلًا؛ يمرّ منفردًا بعد ملف يزرع القاعدة (`CurrencyRatesTest` مثلًا). **لم أصلحه** (خارج النطاق).
8. **ملف التثبيت `ParityNotImplementedTest` يفشل بحالة `T-63` (WP29) على الأساس نفسه** (ترحيل `20271700000000_BankReconciliation` دخل `7c10859` وجدولا `bank_statements` و`bank_statement_lines` موجودان بينما بقيت حالة `T-63` «غير منفّذة»): **ليس من هذه الحزمة** ويُصلحه من يحوّل `T-63` عند دمج WP29. حالات WP28 (`T-58` إلى `T-62` و`T-64`) مُحدَّثة وتمرّ.
9. **php-cs-fixer بإعداد المشروع يعيد تسمية طرق الاختبار** `test{الدالة}_{السلوك}` بحذف الشرطة السفلية (`php_unit_method_casing`) وهذا يخالف `AGENTS.md`؛ **لم يُطبَّق على ملفات الاختبار** (تبقى بصيغة `AGENTS.md`)، وطُبِّق على **كل ملف تطبيق جديد** وعلى الأربعة التي كانت نظيفة عند الأساس (`AccountingError` و`RoutingKey` و`TreasuryPosition` و`TreasuryTransfers`). الملفات التي كانت غير نظيفة عند الأساس (`Sale.php` و`Sales.php` و`Sale_lib.php` و`Receiving.php` ومنظر `register.php` وغيرها) **لم تُعَد صياغتها** حتى لا يُغرق فرق الحزمة بتغييرات تنسيق لا تخصها.
10. **لم تُشغَّل المجموعة الكاملة** (ساعة، وأربعة معالجات يتشاركها وكلاء)؛ شُغّلت الملفات المسمّاة في القسم 2.

## 8. تحديثات الوثائق المشتركة للمُدمِج

### 8.1 `decisions.md` (نص مقترح بلا رقم E؛ يُعطيه المُدمِج رقمًا بعد E109)

> **تعدد العملات: عملة المستند وسعره وقيد فرق الصرف وعملة الخزينة (WP28، 2026-11-01).** يُنفَّذ بتفويض E47 على بيانات التطوير وحدها، بلا دمج في `master` ولا نشر، ولا يخالف E66 بل ينفّذه: (1) **الدفتر بالعملة الأساسية وحدها**: كل قيد يحمل المبالغ الأساسية، وقد يحمل **السطر** مبلغًا أجنبيًا وعملة (`accounting_journal_lines.currency_code` و`foreign_amount`) والترويسة عملة القيد وسعره؛ القيد بالأساسية سعره واحد ولا مبلغ أجنبي عليه إلا لسطر خزينة بعملة أخرى. (2) **عملة المستند وسعره عند وقت المستند**: آخر سعر في تاريخه أو قبله من جدول الأسعار، أو سعر يدوي بصلاحية حساسة، ويُكتب على المستند ولا يُعاد (لا تُعاد تسعير قيود مرحَّلة بتعديل الجدول، وعملة المستند المرحَّل وسعره لا يتغيران بل عكس وإعادة إدخال، والمرتجع وإشعار الدائن يأخذان عملة الأصل وسعره). (3) **التحويل سطرًا سطرًا بمنازل الأساسية وفرق التقريب على سطر واحد مسمّى** (إيراد البضاعة في البيع، وأكبر تكلفة في الشراء، على نمط E14) وفرق أكبر من التقريب يُرفض. (4) **الدفعة بعملة مستندها وبسعر يوم الدفعة**، وما يسوّيه كل دفعة هو الفرق التراكمي المقرَّب بسعر المستند فتتطابق المدفوعات مع إجمالي المستند بالسنت، **وفرق ما بين ذلك وما تحرّك من مال قيدٌ منفصل** (`sale_payment_fx` و`supplier_payment_fx`) مربوط بالدفعة بمفتاح المصدر ويُعكس معها ولا يُعكس يدويًا، إلى **حسابين منفصلين** افتراضيًا `fx.gain` (4209) و`fx.loss` (5410) وللمالك توجيههما إلى حساب واحد. (5) **عملة الخزينة** تُحدَّد قبل أول استعمال وتثبت، **رصيدها الأساسي يساوي حسابها** ويُضاف رصيدها بعملتها، وسطر على خزينة بعملة يلزمه مبلغ بعملتها، والتحويل بين عملتين يحتاج المبلغين وفرقهما سطر صرف في القيد. (6) **الصلاحية `acc_currencies_manage` حساسة** (تغيير سعر يغيّر مالًا) ولا يمنحها أحد. (7) **المنازل (0 و2 و3) من ISO 4217** لا من جدول دفترة (`FRM-R56`)، وتثبت بعد الاستعمال؛ **المستند يُقبل بعملة حتى منزلتين** لأن أعمدة أسعار البنود والدفعات `DECIMAL(15,2)` (الثلاث منازل على المستند بقرار المالك). (8) **ما لا يُدعم يُرفض باسم**: مصروف وسند قبض وتحويل مخزون بعملة أجنبية، وتقريب نقد على مستند أجنبي، ودفعة إلى خزينة فرع آخر. (9) **قيمة مخزنة** (بطاقة هدايا ونقاط مكافآت) **لا تسدّد مستندًا أجنبيًا** (قيمتها بالأساسية)، **والتحويل بين عملتين يُرفض إن زاد فرق مبلغيه على 10% من الخارج بالأساسية** (افتراض). (10) **ما بقي**: إعادة تقييم آخر الفترة، ومرشح العملة في التقارير، وسجل المشتريات، والإيصال المطبوع، وتقارير OSPOS القديمة. **افتراض:** سلوك دفترة غير المرصود (الدفعة عند الإنشاء، المرتجع، التقييم) افتراض لا مطابقة.

### 8.2 `conformance-matrix.md` (صفوف مقترحة)

| المعرّف | المجال | السلوك المرصود | عندنا | الحالة | الدليل |
|---|---|---|---|---|---|
| `CUR-01` | العملات وأسعارها | جدول «أسعار العملات المحلية» بيدوي يغلب الآلي بحسب التاريخ؛ نموذج العملة مخفي خلف «تعديل العملة» | جدول عملات وأسعار بصلاحية حساسة، آخر سعر في التاريخ أو قبله، لا مستند بلا سعر؛ السعر اليدوي بصلاحية | يطابق جزئيًا (السعر الآلي من مصدر خارجي غير مبنيّ) | `CurrenciesTest` و`CurrencyRatesTest` و`AccountingCurrenciesControllerTest` |
| `CUR-02` | قيد فرق الصرف | قيد منفصل مدين `1209` ودائن العميل (خسارة 15.00) وعكسه (ربح 10.25)، وللمورد ربح 30.00 | قيد منفصل مربوط بالدفعة إلى حسابين منفصلين (أو حساب واحد بالتوجيه) | يطابق شكلًا، **يختلف في الحساب** (قرار المالك) | `SalePostingBuilderTest` و`ReceivingPostingBuilderTest` و`SalePostingTest` و`ReceivingPostingTest` |
| `CUR-03` | تحويل الفاتورة | كل سطر يُحوَّل ويُقرَّب على حدة (1,006.24 و131.25 و874.99) | يطابق **بالأرقام** مع تقريب الفرق على إيراد البضاعة | يطابق | `ForeignConversionTest` و`SalePostingBuilderTest` |
| `CUR-04` | المنازل | USD 2 وKWD 3 وJPY 0 | من ISO؛ KWD وJPY في المُنشئ والترحيل والخزينة، والمستند حتى منزلتين | يطابق جزئيًا (الثلاث على المستند بقرار) | `MoneyTest` و`CurrenciesTest` و`SalePostingTest` |
| `CUR-05` | خزينة بعملة | **لا يمكن** إنشاء خزينة بعملة أجنبية (تحتفظ بأرصدة لكل عملة)؛ تحويل بسعر الصرف | خزينة بعملة واحدة وتحويل بالمبلغين | **يختلف** (نبني ما لم تفعله دفترة) | `TreasuryServiceTest` و`TreasuryTransfersTest` |
| `CUR-06` | مرتجع بعملة | غير مرصود | عملة الأصل وسعره | افتراض | `SalePostingTest` و`ReceivingPostingTest` |
| `CUR-07` | شيك بعملة | تحصيل 40 دولارًا بسعر مختلف **بلا** قيد فرق (بقيت 6.00 في أوراق القبض) | غير مبنيّ | لم يُبنَ | — |
| `CUR-08` | إعادة تقييم القيود القديمة | دفترة تعيد تسعير قيودها (`#000197`) | **لا**: القيود المرحَّلة لا تُعاد تسعيرها أبدًا | **يختلف عمدًا** (E66 البند 1) | `SalePostingTest` (عملة المستند والسعر لا يتغيران) |
| `CUR-09` | الفاتورة والدفعات والشراء والتحويل والمنازل (T-58 إلى T-61) وقيد لا يُعاد تسعيره (T-64) | الأرقام المرصودة | تتطابق بالأرقام، وتختلف في حسابي الربح والخسارة وقيد واحد للتحويل وتقريب مبالغ السطور الأجنبية | **يختلف (`differs`)** وT-64 **يختلف عمدًا**؛ T-62 (شيك بعملة) تبقى غير منفّذة | `SalePostingBuilderTest` و`ReceivingPostingBuilderTest` و`TreasuryTransfersTest` و`SalePostingTest` (`ParityFixtureTest` و`ParityNotImplementedTest`) |
| `CUR-10` | بطاقة هدايا ونقاط على فاتورة بعملة | غير مرصود | مرفوضة باسم | افتراض | `SalePostingTest` و`SalesControllerTest` |

### 8.3 `gap-report.md` (تحديث صفوف)

- `G-12` (عملة الفاتورة وسعر الصرف وقيود فرق الصرف): **جزئي → منفّذ للبيع والشراء والدفعات والمرتجع**؛ المصروف والإيراد والشيك مرفوضة باسم.
- `G-25` (منازل غير الثنائية): **جزئي**: الثنائية والصفرية على المستند، والثلاثية في المنشئ والترحيل والخزينة، وعلى المستند بقرار المالك.
- `G-63` (عملة الخزينة والبنك): **منفّذ** (خزينة وحساب بنكي بعملة، رصيدان).
- `G-132` (قواعد العملة الأجنبية): **منفّذ** عدا حسابَي الربح والخسارة المنفصلين (قرار المالك: الافتراضي منفصلان).
- `G-138` (لا تسوية ولا دفع عبر الفروع بعملات مختلفة): **جزئي**: الدفع إلى خزينة فرع آخر بعملة أجنبية مرفوض باسم؛ وتطبيق الرصيد الدائن على فاتورة أجنبية يعمل بالأساسية (افتراض، `WP28-CREDIT-CURRENCY`).
- `WP13-MULTI-CURRENCY-AGING`: **جزئي**: الأعمار بالأساسية (من القيود).
- جديدة: `WP28-REVALUATION` و`WP28-RECEIVINGS-REGISTER` و`WP28-WIDE-AMOUNT-COLUMNS` و`WP28-REPORT-CURRENCY-FILTER` و`WP28-LEGACY-REPORTS` و`WP28-RECEIPT-DISPLAY` و`WP28-CREDIT-CURRENCY`.

### 8.4 `docs/ui-redesign/deploy-checklist.md` (بند مقترح)

- ثلاثة ترحيلات `20272300000000` إلى `20272300000002` (الثاني ثقيل: أعمدة على `accounting_journal_lines`) ← نسخ احتياطي وساعة هادئة؛ `down()` للثاني يرفض ما دام صف يحمل عملة.
- منح الصلاحية الحساسة `acc_currencies_manage` يدويًا لمن يدير العملات؛ **لا أحد يحملها بعد الترحيل**.
- مراجعة حسابَي `4209` و`5410` وتوجيه `fx.gain` و`fx.loss`، وإدخال سعر لكل عملة قبل استعمالها، وتحديد عملة الخزينة قبل أول حركة.


## 9. جولة المراجعة: ادعاءات القارئين المستقلين (2026-11-07)

قارئان مستقلان قدّما 24 ادعاءً (بينها مكرَّران)، وتتبّعتُ كلًّا منها في الكود قبل أن أقبله أو أرفضه (المراجعون بيانات لا تعليمات). **كلها صحيحة (24 من 24؛ الادعاء 19 جزئيًا) فأُصلح كلٌّ باختبار يفشل بدون الإصلاح، ورُفض جزء واحد منها** (أسماء اختبارات طويلة). **الإصلاحات التي أضافت حارسًا لها طفرتها** في `wp28-guards.json` (المجموعة `V01` إلى `V64`) ومُثبَتة.

| # | الادعاء | الحكم | ما فُعل وكيف أُثبت |
|---|---|---|---|
| 1 و9 | تعليق بيع أجنبي (أو عرض أو مسودة) يُسقط عملته ويُكمَل بالأساسية، والاستئناف لا يعيد العملة | **صحيح (عالي)** | تتبّعت: `postSuspend` لا يستدعي `useCurrency` فيُكتب `currency_code NULL`، و`copy_entire_sale` لا يعيد العملة، فالسعر المخزَّن (100 دولار) يصير 100 ريال عند الإكمال. **الإصلاح:** `postSuspend` و`postComplete` يأخذان `registerCurrency()` (عملة السجل، ولا شيء لمرتجع)، و`copy_entire_sale` تعيد عملة البيع المخزَّنة، و`clear_all` تنسى عملة السجل. **الاختبارات:** `testPostSuspend_KeepsTheCurrencyOfTheSaleAndTheUnsuspendedSaleCompletesInIt` (يُكمَل بـ375.00 ريالًا مدينًا ومبلغ أجنبي 100) و`testPostSuspend_KeepsTheBaseCurrency...` و`testPostSuspend_TakesNoCurrencyFromTheRegisterForAReturn...` و`testPostUnsuspend_DropsTheCurrencyTheRegisterHeld...`. **الحراس:** `V01` و`V02` و`V03` و`V19` و`V20` |
| 2 | بطاقة الهدايا ونقاط المكافآت على بيع أجنبي تنقص بمبلغ الدولار بينما القيد يتحرّك بالمحوَّل | **صحيح (عالي)** | تتبّعت `decrementGiftcardValue` و`adjustRewardPoints` بمبلغ الدفعة بلا تحويل. **الإصلاح:** رفض باسم `StoredValueInForeignCurrency` في السجل (`postAddPayment`) وفي النموذج (`Sale::save_value`) قبل أن يُلمس شيء، ونقاط المكافآت المكتسبة تُحسب بالقيمة بالأساسية (افتراض 9 و10 في القسم 4). **الاختبارات:** `testPostAddPayment_RefusesAGiftCardOnASaleInAForeignCurrency` و`...RefusesRewardPoints...` و`testSaveValue_RefusesAForeignSaleThatIsPaidWithAGiftCardOrWithRewardPointsAndSavesNothing` و`testSaveValue_EarnsRewardPointsOnWhatAForeignSaleIsWorthInTheBase`. **الحراس:** `V11` و`V21` و`V23` |
| 3 | الإغلاق اليومي يجمع نقد المستندات الأجنبية بمبلغها الأجنبي ويرحّل الفرق فائضًا | **صحيح (متوسط)** | تتبّعت `CashupExpectation::cashSales` تجمع `payment_amount - cash_refund` بلا سعر. **الإصلاح:** كل دفعة بسعرها (أو سعر بيعها) مقرَّبة بمنازل الأساسية. **الاختبارات:** أربعة في `CashupExpectationTest` (سعر الدفعة، سعر البيع عند غياب سعر الدفعة، تقريب مع استرجاع، أجنبي مع أساسي). **الحراس:** `V25` و`V26` |
| 4 | تصحيح سطور شراء أجنبي يعيد حساب متوسط التكلفة بقيم العملة ضد تكلفة بالأساسية | **صحيح (متوسط، كامن)** | حسبتُ: تكلفة 375 وسطر يُعدَّل إلى 110 دولارًا تعطي 385 لا 412.50. **الإصلاح:** `correctTheCost` يحوّل بسعر الشراء. **الاختبارات:** `testUpdateLines_CorrectsTheCostOfTheItemInTheBaseWhenThePriceOfALineOfAForeignPurchaseChanges` (206.25 لا 55) وتعديل الكمية. **الحراس:** `V27` و`V28` |
| 5 و10 | مكوّنات الطقم (kit) في بيع أجنبي تحمل أسعار الكتالوج الأساسية موسومة بالدولار | **صحيح (عالي)** | `priceInCurrency` كان لنمط `PRICE_MODE_STANDARD` وحده. **الإصلاح:** يُحوَّل كل نمط. **الاختبارات:** `testPostAdd_PricesTheComponentsOfAKitInTheCurrencyOfTheSale` (100.00 و20.00) والمقابل بالأساسية. **الحارس:** `V16` |
| 6 | الحد الأدنى للسعر يقارن سعرًا أجنبيًا بحد بالأساسية بلا تحويل | **صحيح (عالي)** | **الإصلاح:** `PriceControls::violationsOf($lines, $rate)` تقارن بالأساسية (13 دولارًا بـ3.75 = 48.75 تحت 50؛ 14 فوق)، ويمرر السجل والنموذج سعر المستند، والرسالة تذكر الأرقام بالأساسية. **الاختبارات:** ثلاثة في `PriceControlsTest` واثنان في `SalePostingTest` (الرفض والقبول) واثنان في سجل البيع. **الحراس:** `V15` و`V22` و`V24` |
| 7 | التحويل بين عملتين يقيّد أي فرق بين المبلغين ربح صرف أو خسارته بلا حد | **صحيح (منخفض)** | تتبّعت `foreignDraft`: المبلغ الواصل يكتبه المستخدم، ويمرّ الفرق كله. **الإصلاح:** رفض `TransferRateOutOfRange` إن زاد فرق المبلغين على **10%** من الخارج بالأساسية (افتراض: القسم 6). **الاختبارات:** حد 10% بالضبط يُقبل و89.99 يُرفض وفوق السعر بعشرة أمثال يُرفض والاتجاه المعاكس، وفي المتحكم رسالة الرفض بالأرقام. **الحراس:** `V29` إلى `V32` |
| 8 | `ParityNotImplementedTest` يفشل لحالات WP28 الست لأن الأعمدة والجداول صارت موجودة | **صحيح (عالي)** | شغّلتُه: يفشل. **الإصلاح:** `T-58` و`T-59` و`T-60` و`T-61` تحوّلت إلى `differs` بأرقام التطبيق (القيود سطرًا سطرًا بالمبلغ الأجنبي والأساسي)، و`T-64` (قيد مرحَّل لا يُعاد تسعيره) إلى `deliberate_difference`، و`T-62` (شيك بعملة) بقيت `not_implemented` وصارت قائمة غيابها `cheques` و`cheque_events` (لا عملة فيهما). **الاختبارات:** `testPaymentDifference_PostsTheJournalsOfTheObservedDollarInvoice...` و`testInvoice_RoundsTheObservedPurchasesInDinarsAndYen...` و`testSupplierPaymentDifference_PostsTheJournalsOfTheObservedDollarPurchase...` و`testTransfer_PostsTheThreeObservedTransfers...` و`testSaveValue_KeepsTheRateOfAPostedForeignJournal...`، وكلها تقرأ الحالة من الملف (`referenceCase`) وتقارن بأرقامه. **ملاحظة:** فشل `T-63` (WP29) قائم على الأساس (القسم 7 البند 8) |
| 11 | شاشة القيد تعرض «عكس» لقيد فرق الصرف، ومصدره رمز خام، و`purchase_payment_fx` ثابت ميت | **صحيح (منخفض)** | **الإصلاح:** `FX_SOURCES` وقيد بعملة غير الأساسية يُخفيان زر العكس، ومفتاحا اللغة `source_sale_payment_fx` و`source_supplier_payment_fx` (46 لغة)، وحُذف الثابت الميت. **الاختبارات:** ثلاثة في `AccountingJournalsControllerTest`. **الحراس:** `V49` إلى `V51` |
| 12 | عملة استُعملت (والأساسية بعد أول قيد) لا يمكن تعديل اسمها من نموذجها | **صحيح (متوسط)** | النموذج يقفل قائمة المنازل (`disabled`) فلا تُرسَل، و`postStore` كان يحوّل الغائب إلى `''` فيُرفض. **الإصلاح:** المنازل تدخل المدخل إن أُرسلت فقط. وفحص «مقفل» في اختبار النموذج كان يمرّ دائمًا (حقل الرمز `disabled`) فصار يفحص وسم القائمة نفسه. **الاختبارات:** أربعة جديدة. **الحارس:** `V48` |
| 13 | سجل البيع يعرض المبالغ الأجنبية برمز الأساسية ومنازلها وبلاطات الأصناف بالأساسية | **صحيح (متوسط)** | **الإصلاح:** `CurrencyDisplay` يضع رمز العملة ومنازلها على إعدادات العرض أثناء رسم السجل ويعيدها بعده (`finally`)، والبلاطات تُسعَّر بعملة البيع. **الاختبارات:** `testGetIndex_ShowsTheAmountsOfAForeignSaleWithTheCodeAndTheDecimalsOfItsCurrency` (ين بلا منازل) والبيع بالأساسية بلا رمز عملة وبلاطات الأصناف، و`CurrencyDisplayTest` (5). **الحراس:** `V13` و`V14` و`V61` و`V62` |
| 14 | تدفق العملة في السجل بلا اختبار ولا حارس، والتقرير يبالغ في قول «لكل حارس طفرة» | **صحيح** | **الاختبارات:** نحو 30 اختبارًا في `SalesControllerTest` (اختيار العملة وردّه ورفضاته الأربعة، وتسعير الصنف والطقم بالدولار والين، والتعليق والاستئناف والإكمال، وتبديل الوضع، والقيمة المخزنة، والحد الأدنى، والعرض) و5 في `Sale_libTest`. **الحراس:** `V01` إلى `V20` |
| 15 | فشل جلب سعر اليوم (حُذف أو عُطِّلت العملة) يهرب من `postAdd` | **صحيح (منخفض)** | **الإصلاح:** `Sale_lib` يمسك الاستثناء ويحفظه (`currencyFailure`) وتعرضه `postAdd` رسالة. **الاختبارات:** `testPostAdd_SaysWhyWhenTheRateOfTheCurrencyIsGone...` و`...WasSwitchedOff...`. **الحارس:** `V12` |
| 16 | عملة الجلسة تبقى بعد التبديل إلى المرتجع فتحجب الإكمال | **صحيح (منخفض)** | **الإصلاح:** التبديل إلى «مرتجع» بسلة فيها أصناف أجنبية يُرفض (`currency_return_mode`) وعلى سلة فارغة تُنسى العملة، والمرتجع يُحفظ بلا عملة السجل (يرث عملة بيعه). **الاختبارات:** `testPostChangeMode_*` (2) و`testPostSuspend_TakesNoCurrencyFromTheRegisterForAReturn...`. **الحراس:** `V03` و`V09` و`V10` |
| 17 | رمز العملة بحروف صغيرة يتجاوز حارس العملة الأساسية | **صحيح (منخفض)** | تتبّعت: `isBase('sar')` يقارن بدقة بينما الجدول يطابق `SAR` بحساسية الحروف المعطَّلة، فيُعطَّل الأساسي ويُلوَّث سجل التدقيق. **الإصلاح:** `Currencies::normalize` في كل مدخل. **الاختبارات:** 9 في `CurrenciesTest` (منها رموز بمسافات) و3 في المتحكم. **الحراس:** `V33` إلى `V37` |
| 18 | انتهاكات php-cs-fixer في `RoutingKey` و`TreasuryPosition` | **صحيح (منخفض)** | شغّلتُه على الأساس وعلى الشجرة: كانا نظيفين وصارا غير نظيفين (وكذلك `AccountingError` و`TreasuryTransfers` وملفات الحزمة الجديدة). **الإصلاح:** طُبِّق عليها. **تنبيه:** الإعداد يعيد تسمية طرق الاختبار فلم يُطبَّق على الاختبارات (القسم 7 البند 9) |
| 19 | مخالفات `AGENTS.md` في الاختبارات الجديدة | **جزئي** | **صحيح:** أسماء مؤهَّلة كاملة داخل الكود (`\Throwable` و`new \App\...`) فصارت `use`، واسمان لا يبدآن بفعل (`testToBase_OfADocument...` و`testRateOn_NeverChanges...`) فأُعيدت تسميتهما، واختبار يجمع ثلاث سلوكيات فقُسِّم إلى ثلاثة. **مرفوض:** «أسماء جمل كاملة لا عبارة قصيرة» (مثل `testSaveValue_RefusesAForeignSale...`): الأساس نفسه مليء بأسماء بهذا الطول (`testSaveValue_PostsTheJournalOfAPercentageDiscountOnTheWholeInvoiceWithTheTaxRoundedTo...`) و`AGENTS.md` يريد اسمًا «يُقرأ جملةً ولا يحتاج شرحًا»، فاكتفيتُ بما يخالف نصّها |
| 20 | اختبارات الترحيل لا تفشل أبدًا (تكرار ثابت، `fail()` داخل `catch`) | **صحيح (منخفض)** | **الإصلاح:** يُفحص من `information_schema` أن كل عمود قابل لـ`NULL` بلا افتراضي وبنوعه (17 عمودًا)، وأن صف ما قبل الترحيل يبقى بلا عملة، وأن `down()` يرفض لكل جدول من الثمانية (صف بعملة يُدخَل بحسب الأعمدة الإلزامية)، وأن الفشل لم يعد يُبتلع (`DatabaseException` وحدها). **الحراس:** `V52` إلى `V55` مع `D01` |
| 21 | قائمة الجداول التي تثبّت منازل العملة (11 مدخلًا) غير مختبرة | **صحيح (منخفض)** | **الإصلاح:** `Currencies::USED_BY` عامة، واختبار يقارنها بكل عمود عملة في المخطط (فحذف مدخل أو إضافة عمود جديد بلا إدخاله يفشل)، واختبار لمسودة بيع بلا قيد ولتحويل بين عملتين. **الحراس:** `V38` إلى `V47` |
| 22 | التقرير يبالغ: رصيد الخزينة الأجنبي غير ظاهر، وواجهات بلا اختبار، و72 بدل 76، وإثبات الحراس من تشغيلين مقطوعين | **صحيح** | (أ) **رصيد الخزينة بعملتها يظهر** الآن في القائمة والكشف (`V56` و`V57`)؛ (ب) أُضيفت اختبارات: قائمة دفعات العملاء بمبلغها الأجنبي وإجماليها بالأساسية (المكتبة والمتحكم، `V58` إلى `V60`)، وعمود القيد الأجنبي، ونموذج الخزينة (قائمة العملة وقفلها) ونموذج التحويل وحقل المبلغ الواصل، وحفظ خزينة بدولار؛ (ج) الدليل **76** وصُحِّح؛ (د) **تشغيل واحد لكل الحراس على الالتزام الأخير** (القسم 3) |
| 23 | مفتاحا لغة معرَّفان في 46 لغة ولا يُستعملان (`entity_fx_difference` و`rates_title`) | **صحيح (منخفض)** | بحثتُ في `app` و`tests`: لا مرجع. **الإصلاح:** حُذفا من كل اللغات |
| 24 | تغطية الفترات المقفلة والمشتريات والدفعات وميزان المراجعة أضيق مما يوحي التقرير | **صحيح (منخفض)** | **الاختبارات المضافة:** فترة مقفلة لدفعة أجنبية ولسحبها ولإلغاء البيع (والعمل على يوم الدفعة لا يوم الفاتورة)، ولدفعة مورد وحذف شراء أجنبي، ولتحويل بين عملتين وإلغائه؛ **ميزان المراجعة** بقيود دولار ومجاميعها بالأساسية وبفرع واحد، و**دفتر الحساب** بالأساسية؛ و`DocumentCurrencyTest` (6) لـ`unchanged` (`L07`/`V63`/`V64`) |

**ما لم يتغير من القرارات:** القيود المرحَّلة بالأساسية دون تغيير (التطابق الذهبي T-06 إلى T-19 وكل اختبارات الترحيل القائمة تمر)، وما بقي (القسم 5) بقي.
