# TAX2 — تصميم المرحلة T2: التصنيف الضريبي S/Z/E/O والنسب بتاريخ سريان

| البند | القيمة |
|---|---|
| الموافقة | بدء T2 (2026-11-21). يبني على T1 (الوضع السعودي و`CompanyTaxProfile`) |
| الفرع | `claude/tender-rubin-r5z9a4` (بعد إيداعات T1، وT1 غير مدموج بعد) |
| المبدأ الأعلى | **لا تغيير في أي تركيب والوضع السعودي مطفأ**، ولا لأي صنف غير مصنَّف حتى والوضع مفعل. التصنيف يسري على **المبيعات الجديدة** فقط، ولا يمس أي بيع أو قيد سابق |
| المرجع الرسمي | الفئات ورموز الإعفاء: R-04 §11.2.4 (ص32-33)، وBR-KSA-18/23/24/69/83. ومزج الفئات في فاتورة واحدة مسموح (R-04 ص26-28). ورفع النسبة الأساسية إلى 15% من 2020-07-01 يُوثَّق في البيانات الأولية، والمالك يستطيع تعديله |

## 1. المشكلة (من TAX1 وT0)

- الحساب لا يعرف إلا «نسبة»، والفئة تُستنتج عند إصدار المستند: النسبة فوق الصفر تعني S، والصفر يعني **فئة إعفاء عامة واحدة** للمنشأة (`EInvoiceBuilder::categoryOf` و`exemptCategory`). فلا يمكن مزج صادرات صفرية وخدمات مالية معفاة.
- لا تاريخ سريان للنسبة، فتغييرها يعني تعديل كل صنف يدويًا.
- لا يوجد في الكود ما يمنع أكثر من ضريبة على السطر.

## 2. النطاق

| # | داخل T2 |
|---|---|
| 1 | **فئات ضريبية** (`vat_classes`): رمز، واسم، والفئة S/Z/E/O، ورمز الإعفاء ونصه (VATEX)، واسم الضريبة الذي تُقرأ به تعريفات الضرائب (الشمول والحساب)، ونشطة أو لا |
| 2 | **نسب بتاريخ سريان** (`vat_class_rates`): نسبة لكل فئة من تاريخ إلى تاريخ، بلا تداخل. S بنسبة موجبة، وZ/E/O صفر |
| 3 | **تصنيف الصنف** (`item_vat_classes`): صنف ← فئة. صنف بلا تصنيف = سلوك اليوم بلا أي تغيير |
| 4 | **حساب البيع الجديد في الوضع السعودي:** سطر لصنف مصنف يأخذ **ضريبة واحدة** من فئته بنسبة تاريخ التوريد (تاريخ الفاتورة إن حُدد، وإلا اليوم). ولا تُقرأ ضرائب الصنف القديمة (`items_taxes`) لهذا السطر |
| 5 | **لقطة السطر** (`sales_items_vat`): الفئة، والنسبة، والرمز، والنص، ومعرف الفئة لكل سطر صنف مصنف في بيع وضع سعودي. **المرتجع ينسخ لقطة السطر الأصلي**. أساس اللقطة الكاملة في T3 (D-14) |
| 6 | **المستند الإلكتروني** يقرأ الفئة ورمز الإعفاء من اللقطة إن وُجدت، ويفصل مجاميع الفئات حسب (الفئة، والنسبة، ورمز الإعفاء). وبلا لقطة يبقى السلوك القديم كما هو |
| 7 | **الجاهزية:** فحص «الأصناف غير المصنفة» (تحذير)، و«أصناف بأكثر من ضريبة موجبة وهي غير مصنفة» (فشل في الوضع السعودي، لأن المستند يرفضها) |
| 8 | **شاشة إدارة** `/vat_classes` (وحدة `taxes` القائمة): الفئات ونسبها، وإضافة فئة، وإضافة نسبة جديدة بتاريخ (تُغلق السابقة تلقائيًا في اليوم السابق)، وإيقاف فئة. وتصنيف جماعي: الأصناف غير المصنفة التي عليها ضريبة موجبة واحدة ← فئة، أو أصناف تصنيف مخزني (`category`) ← فئة |
| 9 | **نموذج الصنف:** حقل «الفئة الضريبية» يحفظ عبر الخدمة نفسها |
| 10 | **التدقيق:** كل إنشاء أو تعديل لفئة أو نسبة أو تصنيف، فرديًا أو جماعيًا |

**خارج T2، صراحة:**
- علامة «منشأة/مستهلك» للعميل: تنتقل إلى **T6** حيث يستعملها محدِّد النوع. لا مستهلك لها في T2، فإضافتها الآن واجهة بلا أثر.
- مبالغ اللقطة والتقريب الموحد (T3)، والإشعار المدين (يبقى بنسبته كما اليوم حتى T3 وT4)، والمشتريات (T3)، ونموذج OSPOS القديم (D-18، يبقى كما هو).

## 3. البيانات (ترحيل واحد)

| الجدول | الأعمدة الأساسية | ملاحظات |
|---|---|---|
| `vat_classes` | `class_id`، و`company_id`، و`code` (فريد لكل شركة)، و`name`، و`category` (S/Z/E/O)، و`exemption_code`، و`exemption_text`، و`tax_name`، و`active`، و`updated_by` و`updated_at` | **بلا قيود CHECK يُعتمد عليها** (MySQL 5.7). التحقق في الخدمة |
| `vat_class_rates` | `rate_id`، و`class_id` (مفتاح أجنبي)، و`percent` DECIMAL(6,3)، و`valid_from` DATE، و`valid_to` DATE NULL | عدم التداخل في الخدمة وتحت قفل صف الفئة |
| `item_vat_classes` | `item_id` (مفتاح أساسي، ومفتاح أجنبي إلى `items`)، و`class_id`، و`updated_by` و`updated_at` | |
| `sales_items_vat` | `sale_id`، و`item_id`، و`line` (مفتاح أساسي مركب)، و`class_id`، و`category`، و`percent`، و`exemption_code`، و`exemption_text` | تُكتب في معاملة البيع نفسها مع `sales_items_taxes` |

**البيانات الأولية**، وهي **لا أثر لها حتى يُفعَّل الوضع ويُصنَّف صنف**:

| الرمز | الفئة | الرمز الرسمي | النسب |
|---|---|---|---|
| `S` | S | — | 5% من 2018-01-01 إلى 2020-06-30، ثم 15% من 2020-07-01 |
| `Z-EXPORT-GOODS` | Z | VATEX-SA-32 | 0 |
| `Z-EXPORT-SERVICES` | Z | VATEX-SA-33 | 0 |
| `Z-MEDICINES` | Z | VATEX-SA-35 | 0 |
| `Z-EDUCATION` | Z | VATEX-SA-EDU | 0 |
| `Z-HEALTHCARE` | Z | VATEX-SA-HEA | 0 |
| `E-FINANCIAL` | E | VATEX-SA-29 | 0 |
| `E-REAL-ESTATE` | E | VATEX-SA-30 | 0 |
| `O-OUT-OF-SCOPE` | O | VATEX-SA-OOS | 0 |

- النصوص الإنجليزية لرموز الإعفاء من R-04 §11.2.4.
- `tax_name` الافتراضي هو `default_tax_1_name` من الإعدادات إن وُجد، وإلا `VAT`، فتُقرأ به تعريف الضريبة القائم (الشمول والحساب).
- بقية رموز VATEX تُضاف من الشاشة.

## 4. الخدمة `App\Libraries\Tax\VatClassification`

- `classes()` و`classOf(int $itemId)` و`classesOf(array $itemIds)`.
- `rateOn(int $classId, string $date)` تعيد النسبة السارية أو `null` إن لم تُعرَّف نسبة لذلك التاريخ. الحساب يرفض حينها بسبب واضح بدل أن يفترض.
- `saveClass(array $input, ?int $classId, int $employeeId)`:
  - الفئة من S/Z/E/O.
  - رمز الإعفاء من `EInvoiceSettings::EXEMPTION_CODES` لفئته، وإلزامي لـ Z/E/O، وممنوع لـ S.
  - النص إلزامي لـ Z/E/O (BR-KSA-83).
  - الرمز فريد.
  - الصلاحية `taxes`.
- `addRate(int $classId, string $percent, string $validFrom, int $employeeId)`:
  - S موجبة، وZ/E/O صفر.
  - لا يسبق آخر بداية.
  - تُغلق النسبة المفتوحة في اليوم السابق.
  - قفل صف الفئة.
- `classifyItems(array $itemIds, ?int $classId, int $employeeId)`: `null` يزيل التصنيف. والجماعي: `classifyUnclassifiedWithOneTax` و`classifyByCategory`.
- الصلاحيات: إدارة الفئات والنسب والتصنيف الجماعي بوحدة `taxes` القائمة. وتصنيف صنف واحد من نموذج الصنف بوحدة `items` القائمة. **لا صلاحية جديدة ولا توسيع.**

## 5. الحساب (`Tax_lib::get_taxes`)

- في الوضع السعودي، ولبيع جديد (`storedSale === null`)، ولصنف مصنف: صف ضريبة واحد بالاسم `tax_name` للفئة والنسبة السارية، ويحمل `vat_class_id` و`vat_category` و`exemption_code` و`exemption_text`.
- الشمول والحساب يُقرآن من تعريف الضريبة بالاسم، كما اليوم.
- للمرتجع (`storedSale !== null`): الصفوف المحفوظة كما اليوم، وتُرفق بها لقطة الأصل للصنف نفسه إن وُجدت.
- خارج الوضع، أو لصنف غير مصنف: **المسار الحالي حرفيًا.**

## 6. المستند الإلكتروني (`EInvoiceBuilder`)

- `taxRows`: تُقرأ لقطة الأسطر مع الصفوف.
- `saleLine`: إن وُجدت لقطة للسطر فالفئة والنسبة والرمز منها، وإلا `categoryOf` القديم.
- `assemble`: مفتاح المجموع (الفئة|النسبة|الرمز)، ونص الإعفاء من السطر لا من الإعداد العام. **يبقى الإعداد العام للأسطر بلا لقطة كما هو.**

## 7. الاختبارات (ملف لكل صنف)

| الملف | جديد/قائم | أمثلة |
|---|---|---|
| `tests/Libraries/Tax/VatClassificationTest.php` | جديد | `testRateOn_ReturnsTheRateInForceOnTheDate`، `testRateOn_ReturnsNothingBeforeTheFirstRate`، `testAddRate_ClosesTheOpenRateTheDayBefore`، `testAddRate_RejectsAStartBeforeTheLastOne`، `testAddRate_RejectsAPositiveRateForAZeroRatedClass`، `testSaveClass_RequiresAnExemptionCodeOfItsCategory`، `testSaveClass_RejectsAnExemptionCodeOnAStandardClass`، `testClassifyItems_AuditsEachItem`، `testClassifyUnclassifiedWithOneTax_LeavesItemsWithSeveralTaxes` |
| `tests/Database/VatClassificationMigrationTest.php` | جديد | `testUp_SeedsTheStandardRateWithItsHistory`، `testUp_ClassifiesNoItem`، `testDown_DropsTheTables` |
| `tests/Libraries/Tax_libTest.php` | قائم | `testGetTaxes_UsesTheClassOfAnItemInSaudiMode`، `testGetTaxes_UsesTheRateInForceOnTheInvoiceDate`، `testGetTaxes_KeepsTheTaxesOfTheItemWhileTheModeIsOff`، `testGetTaxes_KeepsTheTaxesOfAnUnclassifiedItemInSaudiMode` |
| `tests/Libraries/EInvoicing/EInvoiceBuilderTest.php` | قائم | `testFromSale_WritesTheCategoryAndReasonOfEachLine`، `testFromSale_SplitsTheSubtotalsByReason`، `testFromSale_KeepsTheGlobalExemptionForALineWithoutASnapshot` |
| `tests/Controllers/Vat_classesControllerTest.php` | جديد | `testGetIndex_ListsTheClassesWithTheirCurrentRate`، `testPostSaveClass_RefusesAnEmployeeWithoutTheTaxesModule`، `testPostAddRate_SavesAndAudits` |
| `tests/Controllers/ItemsControllerTest.php` | قائم | `testPostSave_SavesTheVatClassOfTheItem` |
| `tests/Libraries/EInvoicing/EInvoiceReadinessTest.php` | قائم | `testChecks_WarnsOfUnclassifiedItems` |
| انحدار | — | الحزمة كاملة |

## 8. القبول

1. **صفر أثر والوضع مطفأ:** كل الاختبارات القائمة تنجح دون تعديل توقعاتها.
2. فاتورة في الوضع السعودي تمزج S وZ (صادرات) وE (مالية) تُبنى بمجاميع منفصلة وأسباب صحيحة. **التحقق الرسمي بالـ SDK يبقى لـ T5.**
3. نسبة جديدة بتاريخ لا تمس أي بيع سابق، ولا لقطته.
4. المرتجع يحمل فئة سطره الأصلي.
5. كل تغيير مُدقق، ولا صلاحية جديدة.

## 9. التراجع

`down()` يحذف الجداول الأربعة ويرفض إن كُتبت لقطات (`sales_items_vat` غير فارغ)، لأن المستندات تحتاجها. إطفاء الوضع يعيد كل بيع جديد إلى المسار القديم.

## 10. ما لا يُغيَّر

`items_taxes` والضرائب المحفوظة لأي بيع سابق، والقيود، والطباعة، والإشعار المدين، والمشتريات، والصلاحيات، والإعداد العام للإعفاء (يبقى للأسطر بلا لقطة).
