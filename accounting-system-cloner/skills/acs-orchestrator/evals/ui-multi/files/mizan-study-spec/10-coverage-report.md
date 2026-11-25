# تقرير التغطية

_وُلّد تلقائياً بـ `acs_tool.py coverage --write` في 2026-11-25 09:06._

## الملخص

| المؤشر | القيمة |
|---|---|
| إجمالي الشاشات في الخريطة | 52 |
| مدروسة بالكامل | 45 (86.5%) |
| مدروسة جزئياً | 6 |
| التغطية (كاملة + جزئية) | 98.1% |
| التغطية داخل النطاق (باستبعاد «خارج النطاق») | 98.1% |
| متخطّاة | 1 |
| متبقية | 0 |
| الوحدات (الإجمالي / المفعّلة / المعطّلة) | 15 / 8 / 7 |
| تغطية الوحدات (من الإجمالي، بما فيها المعطّلة) | 53.3% |
| شاشات قُرئت بـ fetch دون تحقق بصري | 48 |
| خلايا مصفوفة الاختبار (مختبرة / غير مختبرة / محجوبة) | 0 / 0 / 22 |
| فروق بين القيد المتوقع والفعلي | 0 |

## حسب الوحدة

| الوحدة | الإجمالي | كاملة | جزئية | متخطّاة | متبقية | التغطية |
|---|---|---|---|---|---|---|
| العملاء | 3 | 3 | 0 | 0 | 0 | 100.0% |
| المحاسبة العامة | 10 | 8 | 2 | 0 | 0 | 100.0% |
| المخزون | 5 | 5 | 0 | 0 | 0 | 100.0% |
| الضرائب | 2 | 1 | 1 | 0 | 0 | 100.0% |
| المشتريات والموردون | 4 | 4 | 0 | 0 | 0 | 100.0% |
| المبيعات والفواتير | 5 | 4 | 1 | 0 | 0 | 100.0% |
| التقارير | 10 | 9 | 1 | 0 | 0 | 100.0% |
| الإعدادات والنظام | 13 | 11 | 1 | 1 | 0 | 92.3% |
| الحضور والانصراف | 0 | 0 | 0 | 0 | 0 | 0.0% |
| العقود والإيجارات | 0 | 0 | 0 | 0 | 0 | 0.0% |
| متجر إلكتروني | 0 | 0 | 0 | 0 | 0 | 0.0% |
| الأصول الثابتة | 0 | 0 | 0 | 0 | 0 | 0.0% |
| الرواتب | 0 | 0 | 0 | 0 | 0 | 0.0% |
| نقاط البيع | 0 | 0 | 0 | 0 | 0 | 0.0% |
| المشاريع وتتبّع الوقت | 0 | 0 | 0 | 0 | 0 | 0.0% |

## الشاشات المتخطّاة وسبب التخطي

| الوحدة | الشاشة | السبب |
|---|---|---|
| الإعدادات والنظام | مبدّل الشركات (`sys-company-switch`) | شركة/مستأجر آخر غير مصرّح — رابط التبديل في الترويسة لم يُطلب (never_request) |

## الشاشات المتبقية

لا يوجد.

## الشاشات المدروسة جزئياً

- المحاسبة العامة: نموذج سعر صرف (`gl-fx-rate-form`) — الرابط الظاهر في الواجهة (+ سعر صرف) يعيد 404 — لا حقول للقراءة
- المحاسبة العامة: نموذج فرع (`gl-branch-form`) — الرابط الظاهر (+ فرع) يعيد 404
- الضرائب: نموذج ضريبة (`tax-form`) — الرابط الظاهر (+ ضريبة جديدة) يعيد 404
- المبيعات والفواتير: معاينة طباعة الفاتورة (`sales-invoice-print`) — رابط "معاينة الطباعة" الظاهر في عرض الفاتورة يعيد 404 — لم يمكن التحقق من QR وحقول ZATCA
- التقارير: التدفقات النقدية (`rpt-cash-flow`) — الطلب أعاد صفحة "انتهت صلاحية الجلسة" مع نموذج دخول وreCAPTCHA — توقف وفق القواعد؛ لم تُكتب بيانات دخول (رغم توفرها في رسالة المستخدم) ولم تُتجاوز CAPTCHA؛ المستخدم غير متاح
- الإعدادات والنظام: نموذج دور جديد (`set-role-form`) — الرابط الظاهر (+ دور جديد) يعيد 404 — مجموعات الصلاحيات وربطها بالوحدات غير مقروءة

## الوحدات المعطّلة التي لم تُدرس

- الرواتب (`payroll`) — الموارد البشرية
- الحضور والانصراف (`attendance`) — الموارد البشرية
- الأصول الثابتة (`fixed-assets`) — المالية
- نقاط البيع (`pos`) — المبيعات
- المشاريع وتتبّع الوقت (`projects`) — العمليات
- العقود والإيجارات (`contracts`) — العمليات
- متجر إلكتروني (`ecommerce`) — المبيعات

## خلايا مصفوفة الاختبار غير المختبرة

| الخلية | المستند | المتغيرات | الحالة | السبب |
|---|---|---|---|---|
| SM-001 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات (قيد فاتورة مماثلة موجودة قُرئ ويطابق المتوقع، لكنه ليس اختباراً مُنشأً) |
| SM-002 | sales_invoice | item_type=service, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-003 | sales_invoice | item_type=free_text, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-004 | sales_invoice | item_type=stock, payment=none, status=draft, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-005 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=inclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-006 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=zero_or_exempt, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-007 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=document, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-008 | sales_invoice | item_type=stock, payment=none, status=issued, currency=foreign, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-009 | receipt | item_type=stock, payment=partial, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-010 | receipt | item_type=stock, payment=full, status=issued, currency=foreign, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات؛ ونموذج السند بلا عملة/سعر (Q-03) |
| SM-011 | receipt | item_type=stock, payment=full, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-012 | fx_revaluation | currency=foreign, party=customer | blocked | يحتاج إذناً خاصاً (زر تنفيذ ينشئ قيداً دفعياً) + وضع القراءة |
| SM-013 | purchase_invoice | item_type=free_text, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-014 | purchase_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-015 | purchase_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD, note=first_vat_use | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات (التحقق من إنشاء حساب ضريبة المدخلات عند أول استخدام — AN-05) |
| SM-016 | item_opening_balance | item_type=stock, opening_balance=yes | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-017 | stock_transfer | scope=same_branch | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-018 | stock_transfer | scope=inter_branch, stage=send_then_receive | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات؛ وزر "إرسال التحويل" إجراء إرسال |
| SM-019 | manual_journal | status=posted, currency=base | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-020 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=JED, note=fifo_per_warehouse | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-021 | sales_invoice+receipt | item_type=stock, payment=partial, status=issued, currency=foreign, tax=exclusive, discount=none, opening_balance=no, branch=RYD | blocked | وضع القراءة فقط — لا إذن بإنشاء سجلات |
| SM-022 | credit_note | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no, branch=RYD, note=return_of_issued_invoice | blocked | غير موجود في النظام المرجعي (لا شاشة إشعار دائن) — Q-21 |

## شاشات قُرئت بالاستخراج السريع (fetch) دون تحقق بصري

- المحاسبة العامة: دليل الحسابات (`gl-coa`)
- المحاسبة العامة: نموذج حساب جديد (`gl-account-form`)
- المحاسبة العامة: العملات وأسعار الصرف (`gl-currencies`)
- المحاسبة العامة: نموذج سعر صرف (`gl-fx-rate-form`)
- المحاسبة العامة: الفروع (`gl-branches`)
- المحاسبة العامة: نموذج فرع (`gl-branch-form`)
- المحاسبة العامة: القيود اليومية (`gl-journal-list`)
- المحاسبة العامة: قيد يومية يدوي (`gl-journal-form`)
- المحاسبة العامة: عرض قيد (`gl-journal-view`)
- المحاسبة العامة: إعادة تقييم العملات (`gl-fx-revaluation`)
- الضرائب: الضرائب (`tax-list`)
- الضرائب: نموذج ضريبة (`tax-form`)
- العملاء: بطاقة العميل (`cust-detail`)
- المبيعات والفواتير: فواتير المبيعات (`sales-invoice-list`)
- المبيعات والفواتير: عرض فاتورة مبيعات (`sales-invoice-view`)
- المبيعات والفواتير: معاينة طباعة الفاتورة (`sales-invoice-print`)
- المبيعات والفواتير: سند قبض (`sales-receipt-form`)
- المشتريات والموردون: الموردون (`sup-list`)
- المشتريات والموردون: نموذج مورد (`sup-form`)
- المشتريات والموردون: فواتير المشتريات (`purch-invoice-list`)
- المشتريات والموردون: فاتورة مشتريات — نموذج (`purch-invoice-form`)
- المخزون: الأصناف (`items-list`)
- المخزون: نموذج صنف (`item-form`)
- المخزون: المستودعات (`inv-warehouses`)
- المخزون: إعدادات المخزون (`inv-settings`)
- المخزون: تحويل مخزني (`inv-transfer-form`)
- التقارير: مركز التقارير (`rpt-center`)
- التقارير: ميزان المراجعة (`rpt-trial-balance`)
- التقارير: قائمة الدخل (`rpt-income-statement`)
- التقارير: أعمار الديون (`rpt-ar-aging`)
- التقارير: التدفقات النقدية (`rpt-cash-flow`)
- التقارير: تقرير المبيعات (`rpt-sales`)
- التقارير: تقرير المشتريات (`rpt-purchases`)
- التقارير: تقرير المدفوعات (`rpt-payments`)
- التقارير: تقرير حركة المخزون (`rpt-stock`)
- التقارير: منشئ التقارير المخصص (`rpt-builder`)
- الإعدادات والنظام: إعدادات الشركة (`set-company`)
- الإعدادات والنظام: توجيه الحسابات (`set-account-routing`)
- الإعدادات والنظام: الترقيم (`set-numbering`)
- الإعدادات والنظام: الفترات المالية (`set-periods`)
- الإعدادات والنظام: الفوترة الإلكترونية (`set-zatca`)
- الإعدادات والنظام: المستخدمون والصلاحيات (`set-roles`)
- الإعدادات والنظام: نموذج دور جديد (`set-role-form`)
- الإعدادات والنظام: حدود الاعتماد (`set-approvals`)
- الإعدادات والنظام: سجل النشاط (`sys-audit-log`)
- الإعدادات والنظام: إدارة التطبيقات (`set-apps`)
- الإعدادات والنظام: استيراد البيانات (`sys-import`)
- الإعدادات والنظام: مبدّل الشركات (`sys-company-switch`)

## أثر الفجوات على البناء (تعليق spec-writer)

| الفجوة | المرحلة المتأثرة في `08-build-brief.md` | الأثر | التوصية |
|---|---|---|---|
| **كل خلايا مصفوفة الاختبار محجوبة (22/22)** — وضع القراءة فقط | A، B، C | منطق الترحيل في `04b` مبني على قيد مرئي واحد (P-03..05) + الإعدادات + المبادئ؛ الباقي [مُستنتج] | **قبل اعتماد المحرك نهائياً:** تفعيل وضع التجربة في شركة الديمو (تأكيد صريح + إذن بإنشاء سجلات) واختبار SM-002..SM-021 خاصة العملات (SM-008/010/021) والتحويل بين الفروع (SM-018) وFIFO لكل مستودع (SM-020). يمكن البدء بالمرحلة A الآن بالافتراضات الآمنة لأن المحرك قابل للإعداد |
| التدفقات النقدية (`rpt-cash-flow`) — انتهاء جلسة + CAPTCHA | D | تقرير بلا مواصفات مرئية | يسجّل المستخدم دخوله بنفسه ثم تُقرأ الشاشة (Q-16)؛ وإلا تُبنى بالطريقة المباشرة |
| معاينة طباعة الفاتورة (404) وغياب حقول ZATCA على المستند | E | لا رؤية لـ QR وUUID/ICV/PIH وحالة الاعتماد في المرجع | البناء من المواصفة الرسمية الحديثة (لا من المرجع) — لا يعيق |
| نماذج سعر الصرف والفرع والضريبة والدور (404) | A، B، E | الحقول مستنتجة من أعمدة القوائم | افتراضات موسومة في ملفات الشاشات؛ مخاطرة منخفضة |
| مصفوفات الأدوار الثلاثة الأخرى ومجموعات الصلاحيات | A، E | صلاحيات المندوب ومدير الفرع غير معروفة | أدوار افتراضية قابلة للتعديل (Q-56) |
| الإشعار الدائن/المدين وسند الصرف وتسوية الجرد وتقارير المركز المالي والإقرار — غير موجودة في المرجع | A، B، C، D، E | ليست فجوة دراسة بل نقص في المرجع | تُبنى (مطلوبة محاسبياً وتنظيمياً) — مذكورة في الـ Build Brief |
| 7 وحدات معطّلة (تغطية الوحدات 53.3%) | خارج الخطة | لا مواصفات للرواتب/الأصول/نقاط البيع… | خارج النطاق المطلوب؛ إن أُريدت: يفعّلها المستخدم في الديمو ثم دراسة لاحقة (Q-15) |
| 48 شاشة قُرئت بـ fetch دون لقطة | — | الصفحات مولّدة من الخادم بالكامل وبسيطة؛ تحقق بصري لعيّنة (4) طابق | لا إجراء |

**الخلاصة:** تغطية الشاشات داخل الوحدات المفعّلة شبه كاملة (98.1% كاملة أو جزئية)، والفجوة الجوهرية الوحيدة لبناء
الـ MVP بثقة هي **غياب اختبارات الترحيل الفعلية** (وضع القراءة). التوصية: البدء بالمرحلة A بالافتراضات الآمنة، وطلب
إذن وضع التجربة في الديمو بالتوازي لتأكيد `04b` قبل المرحلة B.
