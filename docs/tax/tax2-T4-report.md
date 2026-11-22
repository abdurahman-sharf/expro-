# TAX2 — تقرير المرحلة T4: الشحن والعملة الأجنبية والدفعات المقدمة ووحدة القياس

| البند | القيمة |
|---|---|
| التاريخ | 2026-11-21 |
| الفرع | `claude/tender-rubin-r5z9a4`. **لم يُدمج في `master` ولم يُنشر.** |
| الموافقة | «أوافق على T4 ثم واصل بعد ذلك العمل حتى تنتهي من الخطة بشكل كامل» |
| التصميم | [tax2-T4-design.md](tax2-T4-design.md) |
| الإيداعات | `1818a6bc1` (T4)، ثم إصلاحات المراجعة المستقلة ضمن `9204f856a` (مع T6) |
| **الأثر خارج الوضع السعودي** | **لا تغيير** في السلوك. أُضيف حساب في الدليل (215010 «ضريبة الدفعات المقدمة من العملاء»، بلا قيود)، وحقل اختياري لرمز الوحدة في قوالب الوحدات |

---

## 1. ما نُفّذ

| # | البند | التنفيذ | الدليل |
|---|---|---|---|
| 1 | الشحن رسمًا على مستوى المستند (BG-21) | في بيع اللقطة يُضم صافي الشحن إلى خاضع فئته (`sales_vat_totals.charges`)، وتُقرَّب ضريبة الفئة مرة واحدة على الأسطر والرسوم (BR-CO-17). المستند يكتب `AllowanceCharge` (`FC`) و`ChargeTotalAmount` | `VatCalculatorTest` و`UblInvoiceXmlTest` و`EInvoiceBuilderTest` و`SalePostingTest::testSaveValue_PutsTheShippingOfASaudiSaleInTheCategoryOfItsRate` |
| 2 | الشحن بلا ضريبة | يُرفض عند الإتمام في الوضع (`ShippingUntaxedInSaudiMode`) | `testSaveValue_RefusesShippingWithoutTaxUnderTheSaudiRules` |
| 3 | القيد والتقرير | الضريبة = ضريبة الفئات، والإيراد = خاضع الأسطر، والشحن = صافيه، والفرق سطر التقريب. التقرير الضريبي يقرأ اللقطة فلا يُضاف الشحن مرتين | `TaxDocumentsTest::testSales_CountsTheShippingOfASaudiSaleOnceThroughItsCategory` |
| 4 | العملة الأجنبية | بيع اللقطة بعملة أجنبية يُصدر: `TaxTotal` الأول بالريال (BT-111) = ضريبة المستند × سعر البيع، والثاني بعملة المستند. `JournalTie` يقارن بمبالغ القيد بعملة المستند | `EInvoiceBuilderTest` (USD) |
| 5 | الدفعة المقدمة (R-04 §9.5) | الدفعة في الوضع تحمل ضريبة على **ما لم تخصمه فاتورة فورًا**، بنسبة S السارية، والقيد من حساب الانتظار 215010 إلى ضريبة المخرجات. **فاتورة الدفعة (386) تصدر مع الاستلام** (T6). الفاتورة النهائية تكتب سطر تعديل لكل دفعة مخصومة (KSA-26 إلى 34) و`PrepaidAmount` | `CustomerAdvancesTest` و`EInvoiceServiceTest` و`AdvanceVatMigrationTest` |
| 6 | وحدة القياس | رمز UN/ECE اختياري لقالب الوحدة (`^[A-Z0-9]{1,3}$`) يُكتب في `unitCode`، وإلا `PCE` | `UnitTemplatesTest` و`EInvoiceBuilderTest` (KGM) |

## 2. المراجعة المستقلة وإصلاحاتها

راجع وكيل مستقل الإيداع `1818a6bc1` وحده (قراءة فقط). وجد سبعة عيوب مهمة وأربع ملاحظات، **وكلها أُصلحت** في `9204f856a`:

| # | العيب | الإصلاح | الاختبار |
|---|---|---|---|
| 1 | المرتجع الكامل لبيع فيه شحن يرد الشحن مع الأصناف (حد المرتجع كان على الخاضع شاملًا الرسوم) | `VatSnapshot::leftOf` يحسب من **خاضع الأسطر** (`lines()`) للأصل وللمرتجعات | `VatSnapshotTest::testLeftOf_LeavesTheShippingACategoryTookInOutOfWhatAReturnGivesBack` |
| 2 | ضم الشحن قبل وجود عمود `charges` (قبل الترحيل) يضاعفه في القيد | الضم مشروط بوجود العمود | (حارس، بلا اختبار مستقل: يحتاج قاعدة بلا الترحيل) |
| 3 | التقرير الضريبي يُسقط ضريبة شحن بيع لقطة حُفظ قبل T4 | يُتخطى الشحن فقط حين تحمل الفئات رسومًا | `TaxDocumentsTest` (الحالات القائمة) |
| 4 | فاتورة الدفعة لا تُطابق دفعة خُصم جزء منها فورًا، ولا مُستدعٍ لها، والفاتورة النهائية تشير إلى فاتورة دفعة غير موجودة | المطابقة بالجزء الخاضع (`gross` في سجل الضريبة)، و**الإصدار مع الاستلام**، و**رفض فاتورة نهائية لدفعة بلا 386** (`PrepaymentNotIssued`، BR-KSA-73) | `CustomerAdvancesTest::testReceive_ChargesVatOnAnAdvanceReceivedUnderTheSaudiRules` |
| 5 | استرداد الدفعة لا يرد ضريبتها | الاسترداد يرد ضريبة الجزء المردود (`customer_advance_vat_returns`)، ويُصدر **إشعار دائن** يشير إلى 386. والإلغاء بعد الإصدار كذلك | `CreditRefundsTest::testRefund_GivesBackTheVatOfAnAdvanceByACreditNoteOfItsPrepaymentInvoice` و`CustomerAdvancesTest::testCancel_GivesBackTheVatOfTheAdvanceByACreditNoteOfItsPrepaymentInvoice` |
| 6 | ضريبة الدفعات لا تظهر مستحقة في التقرير الضريبي | تُحسب في قسم المبيعات بخاضعها ونسبتها، وبإشارة ما تفعله بالمستحق | `TaxReportTest::testBuild_CountsTheVatOfAnAdvanceReceivedUnderTheSaudiRulesAsTaxOfTheSales` |
| 7 | تقسيم الدفعة يخالف «الضريبة = الخاضع × النسبة» (مثل 100.01) | `AdvanceVat::split`: الضريبة = الخاضع × النسبة مقرّبة، وإن لم ينقسم المبلغ بدقة تبقى سنته تقريبًا للمستند (BT-114) | `AdvanceVatTest` (منها 1,000 مبلغ) و`testReceive_IssuesThePrepaymentInvoiceOfAnAdvanceThatDoesNotSplitExactlyWithItsRounding` |

والملاحظات: قيد ضريبة الاستعمال في فترة مقفلة يُؤرَّخ اليوم، وفحص الشحن الخام لا يرمي `ValueError`، وتعليق `storeHeaderCharges` عاد إلى مكانه، و`VatSnapshotCharges::down()` يرفض بعد ضم رسوم.

**قرارات استجدت** (في [سجل القرارات](tax2-decisions.md) §4ج): رفض إلغاء دفعة خصمتها فاتورة صدر مستندها، ورفض إلغاء استرداد صدر إشعاره.

## 3. الاختبارات

كل ملفات T4 وما عُدّل بسببها نجحت (تشغيل تسلسلي على قاعدة اختبار مستقلة):

| الملف | النتيجة |
|---|---|
| `CustomerAdvancesTest` | 60 ناجحة |
| `CreditRefundsTest` | 123 ناجحة |
| `EInvoiceServiceTest` | 67 ناجحة |
| `EInvoiceBuilderTest` | 46 ناجحة |
| `UblInvoiceXmlTest` | 21 ناجحة |
| `VatCalculatorTest` | 21 ناجحة |
| `VatSnapshotTest` | 6 ناجحة |
| `AdvanceVatTest` | 6 ناجحة |
| `TaxDocumentsTest` / `TaxReportTest` | 31 / 63 ناجحة |
| `SalePostingTest` | 485 ناجحة (قبل T6)، واختبارات الوضع السعودي بعد T6 ناجحة |
| `UnitTemplatesTest` / `StockUnitsControllerTest` | 25 / 28 ناجحة |
| `DefaultChartTest` / `RoutingServiceTest` / `MigrationChecksTest` | 78 / 62 / 20 ناجحة |
| ترحيلات T4 الخمسة | كلها ناجحة |
| `LanguageFilesTest` | 4,136 ناجحة |

الحزمة الكاملة تعمل على الإيداع `9204f856a` في شجرة عمل منفصلة، ونتيجتها في [تقرير T6](tax2-T6-report.md) §4.

## 4. ما لم يُنفذ أو لم يُتحقق منه

| البند | السبب |
|---|---|
| التحقق بالـ SDK الرسمي | كان معطلًا وقت هذا التقرير. **شُغّل في 2026-11-22** (تقرير T5) |
| تقسيم الاستعمال الجزئي المتكرر للدفعة | الاستعمال الذي يستنفد الدفعة يأخذ الباقي بالهللة، وقد يخالف BR-KSA-79 بهللة في حالات نادرة. يُتحقق منه بالـ SDK |
| التقرير الضريبي بعملة أجنبية | قيد قائم قبل TAX2، يُعالج في T13 |

## 5. ملاحظة النشر

ترحيلات T4: `VatSnapshotCharges` (عمود على جدول جديد)، و`AdvanceVat` (جدولان جديدان)، و`AdvanceVatAccount` (بيانات: حساب جديد وتوجيه)، و`EInvoicePrepayments` (توسيع قيود CHECK)، و`UnitCodes` (عمود اختياري). كلها آمنة لإعادة التشغيل، و`down()` يرفض بعد وجود بيانات.
