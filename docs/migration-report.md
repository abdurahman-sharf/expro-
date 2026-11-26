# تقرير الهجرة والمطابقة

يُحدَّث بعد كل مرحلة من خطة `docs/gap-analysis.md`.

## المرحلة 0 — خط الأساس (2026-11-26)

لم يتغير أي سلوك ولا أي جدول في هذه المرحلة.

### ما أُضيف
| الملف | الغرض |
|---|---|
| `app/Libraries/Accounting/BooksSnapshot.php` | لقطة: مدين ودائن كل حساب على القيود المرحّلة، المجاميع، ولكل موقع مخزون رصيد حسابه مقابل قيمة حركاته. ومقارنة لقطتين حساباً حساباً بمعرّف الحساب لا برمزه |
| `app/Commands/AccountingBooksSnapshot.php` | `php spark accounting:books_snapshot FILE` |
| `app/Commands/AccountingBooksCompare.php` | `php spark accounting:books_compare BEFORE.json AFTER.json` (يخرج 1 عند أي فرق) |
| اختبارات الثلاثة | `tests/Libraries/Accounting/BooksSnapshotTest.php`، `tests/Commands/AccountingBooksSnapshotTest.php`، `tests/Commands/AccountingBooksCompareTest.php` |
| اختباران لخط الأساس | في `AccountServiceTest` و`DefaultChartTest` (AN-01) |
| `.gitignore` | يستثني `ledger-x-spec/`؛ الحزمة تُقرأ من `ACS_SPEC_DIR` |

### سلوك النظام الحالي في AN-01..AN-09

كل سطر مثبت باختبار يمر على الكود الحالي. الاختبارات التي تثبّت سلوكاً سيُصحَّح تُقلب في المرحلة A أو B أو C مع التصحيح.

| # | السلوك الحالي | يقع فيه؟ | الاختبار المثبِت |
|---|---|---|---|
| AN-01 | حساب برمز `9990` يُقبل تحت الأب `11`. «أرصدة افتتاحية» `214` خصم متداول تحت `21`. الموردون `2211` تحت `211` | نعم | `AccountServiceTest::testCreate_AcceptsACodeThatDoesNotStartWithTheCodeOfItsParent` · `DefaultChartTest::testInstall_HoldsTheOpeningBalancesAmongTheCurrentLiabilitiesAndTheSuppliersUnderACodeTheirsDoesNotStartWith` |
| AN-02 | بند غير مخزني 80: مدين تكلفة المبيعات 80 / دائن المورد 80 | نعم | `ReceivingPostingBuilderTest::testPurchase_PostsAServiceAsACostAndMovesNoStock` |
| AN-03 | المخزون الافتتاحي: مدين الموقع / دائن حساب الأرصدة الافتتاحية (صحيح). إذن إضافة بلا حساب: دائن «أطراف دائنة أخرى» | جزئياً | `OpeningStockTest::testPost_PostsADebitToTheLocationAndACreditToTheOpeningEquityAndAValuedMovementForEachItem` · `StockPermitBuilderTest::testPlan_PutsEachLineOfAnAddPermitInAtItsCostAndCreditsTheAutomaticAccountOnce` |
| AN-04 | فاتورة شراء 300 بطريقة الدفع الافتراضية والمبلغ المدفوع فارغ: المورد 0.00 والنقد −300.00 | نعم | `ReceivingsControllerTest::testPostComplete_BooksTheWholePurchaseAsPaidWhenNoAmountIsTyped` |
| AN-05 | قيد التكلفة يُبنى مع الفاتورة ويؤجَّل إلى التسليم. إلغاء صف التكلفة في التوجيه: لا قيد ولا حركة | لا، عدا حالة الإلغاء | `SalePostingBuilderTest::testCost_DebitsCostOfSalesAndCreditsTheStockOfTheLocation` · `::testCost_WaitsForTheDelivery` · `::testCost_PostsNothingWhenTheCostOfSalesRowIsCancelled` |
| AN-06 | التسوية الموجبة والسالبة على حساب تسويات مستقل، لا على المبيعات | لا | `SalePostingBuilderTest::testInvoice_PostsShippingAndANegativeAdjustmentToTheirOwnAccounts` · `::testInvoice_CreditsAPositiveAdjustmentToTheAdjustmentAccount` |
| AN-07 | مصروف 230 من خزينة رصيدها صفر يُقبل، والرصيد −230.00 | نعم | `ExpensePostingTest::testSaveValue_TakesTheMoneyOutOfTheTreasuryAndItsBalanceEqualsItsAccount` |
| AN-08 | عمر سنتين من 2026-01-01: تاريخ الانتهاء 2028-01-01 محسوب | لا | `AssetInputTest::testPrepare_SpreadsWhatIsToBeDepreciatedOverTheLifeWhenTheFormGivesTheLifeAndNoAmount` |
| AN-09 | لا إهلاك يدوي لأصل مفرد. جدول الإهلاك مسقوف بالتكلفة ناقص الخردة | لا ينطبق | `DepreciationScheduleTest::testEvents_FixedEndsWithAPeriodCutToCostLessSalvage` · `::testEvents_DecliningNeverPassesCostLessSalvage` |

**النتيجة:** 79 حالة اختبار (منها صفوف جدول الدليل الافتراضي) و331 تحققاً، كلها ناجحة، على MariaDB 10.11 وPHP 8.3.

**ما تغيّر عن تقرير الفجوات:** AN-04 كان غير محسوم وتبيّن أن النظام يقع فيه. AN-08 كان غير محسوم وتبيّن أنه لا يقع فيه حين يُعطى العمر. الباقي كما قُرئ من الكود.

**ما لم يُثبَت باختبار واحد من طرف إلى طرف:** ذرّية فاتورة البيع مع قيد تكلفتها وحركة المخزون في معاملة واحدة (AN-05) مثبتة بقراءة الكود وباختبارات `SalePostingTest` القائمة، لا باختبار مسمّى لهذا التناقض. يُضاف T-AN-05 في بند A8.

### اختبارات أداة اللقطة
`BooksSnapshotTest` و`AccountingBooksSnapshotTest` و`AccountingBooksCompareTest`: 14 اختباراً و48 تحققاً، ناجحة. مع `AccountServiceTest` و`DefaultChartTest` كاملين: 176 اختباراً و1029 تحققاً، ناجحة.

### لقطة ميزان المراجعة ومطابقة المخزون

**لم تؤخذ بعد على بياناتك.** بيئة العمل لا تحوي قاعدة بياناتك؛ فيها قاعدة اختبار فارغة فقط. الأداة جاهزة ومختبرة، وتؤخذ اللقطة على نسخة من قاعدتك هكذا:

```bash
php spark accounting:books_snapshot writable/books-before.json
```

السطر المطبوع يذكر عدد الحسابات ذات الحركة ومجموعي المدين والدائن وعدد مواقع المخزون وكم منها لا يطابق حسابُه قيمةَ حركاته. بعد كل هجرة:

```bash
php spark accounting:books_snapshot writable/books-after.json
```

```bash
php spark accounting:books_compare writable/books-before.json writable/books-after.json
```

اللقطة تقرأ فقط ولا تكتب إلا الملف. الفحص الأشمل القائم `accounting:readiness --json` (توازن كل قيد، روابط العملاء والموردين والخزائن والمخزون) يبقى مكمّلاً لها.

### ما أُجّل من المرحلة 0
اختبارات T-01..T-11 كانت في خطة المرحلة 0 ونُقلت إلى المرحلة A: كل واحد منها يُكتب مع البند الذي يخصه، لأن أغلبها مغطى باختبارات قائمة بأسماء أخرى وحصرها يتبع تصحيحات A4–A8.
