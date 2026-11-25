# تقرير التغطية

_وُلّد تلقائياً بـ `acs_tool.py coverage --write` في 2026-11-25 09:10._

## الملخص

| المؤشر | القيمة |
|---|---|
| إجمالي الشاشات في الخريطة | 39 |
| مدروسة بالكامل | 36 (92.3%) |
| مدروسة جزئياً | 0 |
| التغطية (كاملة + جزئية) | 92.3% |
| التغطية داخل النطاق (باستبعاد «خارج النطاق») | 92.3% |
| متخطّاة | 3 |
| متبقية | 0 |
| الوحدات (الإجمالي / المفعّلة / المعطّلة) | 15 / 8 / 7 |
| تغطية الوحدات (من الإجمالي، بما فيها المعطّلة) | 53.3% |
| شاشات قُرئت بـ fetch دون تحقق بصري | 0 |
| خلايا مصفوفة الاختبار (مختبرة / غير مختبرة / محجوبة) | 0 / 0 / 15 |
| فروق بين القيد المتوقع والفعلي | 0 |

## حسب الوحدة

| الوحدة | الإجمالي | كاملة | جزئية | متخطّاة | متبقية | التغطية |
|---|---|---|---|---|---|---|
| العملاء | 3 | 3 | 0 | 0 | 0 | 100.0% |
| المحاسبة العامة | 5 | 5 | 0 | 0 | 0 | 100.0% |
| المخزون | 2 | 2 | 0 | 0 | 0 | 100.0% |
| الضرائب | 2 | 1 | 0 | 1 | 0 | 50.0% |
| المشتريات والموردون | 4 | 4 | 0 | 0 | 0 | 100.0% |
| المبيعات والفواتير | 5 | 4 | 0 | 1 | 0 | 80.0% |
| التقارير | 8 | 8 | 0 | 0 | 0 | 100.0% |
| النظام والإعدادات (أساسي — خارج مدير الوحدات) | 10 | 9 | 0 | 1 | 0 | 90.0% |
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
| الضرائب | ضريبة جديدة (`tax-form`) | الرابط ظاهر في الواجهة لكنه يعيد 404 (غير مطبّق في النظام المرجعي) |
| المبيعات والفواتير | معاينة طباعة الفاتورة (`sales-invoice-print`) | الرابط ظاهر في الواجهة لكنه يعيد 404 (غير مطبّق في النظام المرجعي) |
| النظام والإعدادات (أساسي — خارج مدير الوحدات) | دور جديد (`set-role-form`) | الرابط ظاهر في الواجهة لكنه يعيد 404 (غير مطبّق في النظام المرجعي) |

## الشاشات المتبقية

لا يوجد.

## الشاشات المدروسة جزئياً

لا يوجد.

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
| SM-001 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-002 | sales_invoice | item_type=stock, payment=partial, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-003 | sales_invoice | item_type=free_text, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-004 | sales_invoice | item_type=stock, payment=none, status=draft, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-005 | purchase_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-006 | purchase_invoice | item_type=service, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-007 | purchase_invoice | item_type=free_text, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-008 | item_opening_balance | item_type=stock, payment=none, status=n/a, currency=base, tax=none, discount=none, opening_balance=yes | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-009 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=inclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-010 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=exclusive, discount=document, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-011 | sales_invoice | item_type=service, payment=none, status=issued, currency=base, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-012 | sales_invoice | item_type=stock, payment=none, status=issued, currency=base, tax=zero_or_exempt, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-013 | receipt | item_type=stock, payment=full, status=n/a, currency=base, tax=none, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-014 | manual_journal | item_type=n/a, payment=none, status=posted, currency=base, tax=none, discount=none, opening_balance=no | blocked | وضع القراءة فقط — لم يأذن المستخدم بإنشاء سجلات |
| SM-015 | purchase_invoice | item_type=stock, payment=partial, status=issued, currency=foreign, tax=exclusive, discount=none, opening_balance=no | blocked | وضع القراءة فقط؛ ولا حقل عملة في نموذج فاتورة الشراء (Q-38) |

## شاشات قُرئت بالاستخراج السريع (fetch) دون تحقق بصري

لا يوجد.

## أثر الفجوات على البناء (تعليق spec-writer)

| الفجوة | يمس مرحلة في `08-build-brief.md` | الأثر | التوصية |
|---|---|---|---|
| 15 خلية مصفوفة محجوبة (وضع القراءة) | A، B، C | قواعد الترحيل P-06..P-10، P-12..P-18 من التوجيه والمبادئ لا من تجربة؛ أخطرها البند الحر في الشراء (Q-37) والرصيد الافتتاحي (Q-32) | **ابدأ مع افتراضات آمنة** — محرك الترحيل قابل للإعداد يمتص أي تصحيح لاحق. ويُستحسن تشغيل وضع التجربة (Q-02) قبل إغلاق المرحلة A لتأكيد SM-007 وSM-008 وSM-009 وSM-010 |
| 7 وحدات معطّلة لم تُدرس | خارج النطاق (القسم 8) | لا مواصفات للرواتب/الأصول/نقاط البيع… | تفعيلها ودراستها قبل إدراجها في أي مرحلة (Q-01) |
| 3 شاشات 404 (`tax-form`، `sales-invoice-print`، `set-role-form`) | A (الضرائب)، E (الطباعة/ZATCA، الأدوار) | لا مرجع لتصميمها | تُبنى من الاستنتاج الموثّق في ملفاتها؛ قالب الطباعة وفق متطلبات ZATCA الرسمية |
| مصفوفة صلاحيات دور واحد فقط معروضة | A، E | صلاحيات المدير والمندوب مُفترضة (Q-61، Q-65) | ابدأ بالافتراض الآمن: الإصدار يتطلب `approve`، المندوب scope=own |
| شاشات غير موجودة في المرجع (إشعار دائن، سند صرف، إقفال سنة، إقرار ضريبي) | B، D، E | ليست "فجوة دراسة" بل نقص في المرجع | مضمّنة في الـ Build Brief كمتطلبات جديدة موسومة |
| أرقام التقارير في المرجع لا تُشتق من القيود (AN-03، AN-07) | D | لا يمكن استخدام شاشات المرجع كمرجع أرقام للتحقق | التحقق بالمعادلات في `06-reports.md` واختبارات T-07، T-17، T-AN-03 |

**الخلاصة:** التغطية كافية لبدء المرحلة A (MVP) فوراً بالافتراضات الآمنة الموثّقة؛ لا فجوة جوهرية تمنع الـ MVP، لأن قيد فاتورة البيع —
أهم قاعدة — مؤكد من قيد قائم ومطابق للتوجيه. أعلى قيمة لاستكمال الدراسة: تفعيل وضع التجربة لخلايا الشراء والرصيد الافتتاحي والخصم والشامل.
