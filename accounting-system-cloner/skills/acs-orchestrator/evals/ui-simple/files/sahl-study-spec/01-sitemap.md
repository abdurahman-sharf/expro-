# خريطة النظام المرجعي

## ملخص
الوحدات: 14 في مدير الوحدات (مفعّلة 7، معطّلة 7) + وحدة أساسية `system` · الشاشات: 39 (منها 3 روابط تعيد 404)
· حسب النوع: list 10 (منها `rpt-center`)، form 12، detail 4، report 6، settings 6، dashboard 1 · حسب العرض:
server 38، spa 1 (`cust-list`) · الاستخراج: browse (Playwright بلا نقر على أزرار، كل طلب غير GET محجوب على مستوى المتصفح).

## الأماكن المفحوصة
- [x] مدير الوحدات (`/apps`) — 14 وحدة
- [x] القائمة الرئيسية الجانبية (5 مجموعات)
- [x] "المزيد…" (قائمة مطوية): سجل التدقيق، استيراد البيانات
- [x] قائمة المستخدم (مطوية): إدارة التطبيقات، إعدادات الشركة، توجيه الحسابات، الترقيم، الفترات المالية، المستخدمون والصلاحيات، تسجيل الخروج (لم يُطلب)
- [x] الإعدادات بكل صفحاتها (لا تبويبات فرعية إضافية عدا توجيه الحسابات: 3 تبويبات)
- [x] زر الإنشاء السريع في لوحة المعلومات ("+ فاتورة جديدة")
- [x] التبويبات داخل الشاشات: بطاقة العميل (الفواتير، السندات، كشف الحساب)
- [x] قوائم "إجراءات" داخل السجلات: قُرئت أسماؤها فقط (عرض، إلغاء التسليم، حذف) — لم يُفتح أي عنصر
- [x] مركز التقارير + منشئ التقارير المخصص
- [x] شاشات من روابط داخلية: عرض الفاتورة، عرض القيد (iframe)، معاينة الطباعة (404)
- [x] النماذج المضمّنة: "+ عميل جديد (دون مغادرة الفاتورة)"

## الشجرة

### 1. المحاسبة العامة `gl` (أولوية 1)
- **البيانات الأساسية**
  - دليل الحسابات — `list` — `server` — `/accounts` — `gl-coa`
  - حساب جديد — `form` — `server` — `/accounts/new` — `gl-account-form`
- **القيود**
  - القيود اليومية — `list` — `server` — `/journal` — `gl-journal-list`
  - قيد يدوي جديد — `form` — `server` — `/journal/new` — `gl-journal-form`
  - عرض قيد — `detail` — `server` (iframe `/journal/view/{id}/frame`) — `/journal/view/{id}` — `gl-journal-view`

### 2. الضرائب `tax` (أولوية 1)
- الضرائب — `list` — `/taxes` — `tax-list`
- ضريبة جديدة — `form` — `/taxes/new` — `tax-form` — **404** (skipped)

### 3. العملاء `customers` (أولوية 1)
- العملاء — `list` — **`spa`** (GET `/api/customers`) — `/customers` — `cust-list`
- عميل جديد — `form` — `/customers/new` — `cust-form`
- بطاقة العميل — `detail` — `/customers/{id}` (`?tab=invoices|receipts|statement`) — `cust-detail`

### 4. المخزون `inventory` (أولوية 1)
- الأصناف — `list` — `/items` — `item-list`
- صنف جديد — `form` — `/items/new` — `item-form`

### 5. المبيعات والفواتير `sales` (أولوية 2)
- **فواتير المبيعات**
  - فواتير المبيعات — `list` — `/sales/invoices` — `sales-invoice-list`
  - فاتورة مبيعات جديدة — `form` — `/sales/invoices/new` — `sales-invoice-form`
  - عرض فاتورة — `detail` — `/sales/invoices/{id}` — `sales-invoice-view`
  - معاينة الطباعة — `detail` — `/sales/invoices/{id}/print` — `sales-invoice-print` — **404** (skipped)
- **التحصيل**
  - سند قبض — `form` — `/receipts/new` — `sales-receipt-form`

### 6. المشتريات والموردون `purchases` (أولوية 2)
- الموردون — `list` — `/suppliers` — `supp-list` · مورد جديد — `form` — `/suppliers/new` — `supp-form`
- فواتير المشتريات — `list` — `/purchases/invoices` — `pur-invoice-list` · فاتورة مشتريات جديدة — `form` — `/purchases/invoices/new` — `pur-invoice-form`

### 7. التقارير `reports` (أولوية 3)
- مركز التقارير — `/reports` — `rpt-center`
- ميزان المراجعة — `report` — `/reports/trial-balance` — `rpt-trial-balance`
- قائمة الدخل — `report` — `/reports/income-statement` — `rpt-income-statement`
- تقرير المبيعات / المشتريات / المدفوعات / حركة المخزون — `report` — `/reports/{sales|purchases|payments|stock}` — `rpt-sales`، `rpt-purchases`، `rpt-payments`، `rpt-stock`
- منشئ التقارير المخصص — `form` — `/reports/builder` — `rpt-builder`

### 8. النظام والإعدادات `system` (أولوية 4؛ توجيه الحسابات أولوية 1)
- لوحة المعلومات `/` — `dashboard` · سجل النشاط `/audit-log` — `audit-log` · استيراد البيانات `/import` — `data-import`
- إدارة التطبيقات `/apps` — `apps-manager` · إعدادات الشركة `/settings/company` — `set-company` · توجيه الحسابات
  `/settings/account-routing` — `set-account-routing` · الترقيم `/settings/numbering` — `set-numbering` · الفترات المالية
  `/settings/periods` — `set-periods` · المستخدمون والصلاحيات `/settings/roles` — `set-roles` · دور جديد `/settings/roles/new`
  — `set-role-form` — **404** (skipped)

### 9–15. وحدات معطّلة (أولوية 9، بلا شاشات)
الرواتب · الحضور والانصراف · الأصول الثابتة · نقاط البيع · المشاريع وتتبّع الوقت · العقود والإيجارات · المتجر الإلكتروني.

## كتالوج التقارير (40 رابطاً ← 4 تقارير + منشئ مخصص)
| نقطة النهاية | التقرير | `group_by` | `view` | الفلاتر | الروابط |
|---|---|---|---|---|---|
| `/reports/sales` | تقرير المبيعات | day, week, month, client, staff | summary, details | من/إلى | 10 |
| `/reports/purchases` | تقرير المشتريات | ″ | ″ | ″ | 10 |
| `/reports/payments` | تقرير المدفوعات | ″ | ″ | ″ | 10 |
| `/reports/stock` | تقرير حركة المخزون | ″ | ″ | ″ | 10 |
| `/reports/builder` | منشئ التقارير المخصص (مصدر: الفواتير/القيود) | — | — | — | 1 |
إضافة إلى ميزان المراجعة وقائمة الدخل من القائمة الرئيسية. ملاحظة: البعد `client` يظهر حتى في تقرير المشتريات (يعني
"الطرف")، و`staff` في تقرير المخزون — صياغة عامة موحّدة.

## التخطيط العام (وصف وظيفي)
شريط علوي فيه اسم المستخدم وقائمته المطوية؛ قائمة جانبية بمجموعات الوحدات وروابطها مع "المزيد…" مطوية؛ منطقة
محتوى: عنوان + زر إنشاء + جدول، أو نموذج بحقول متتالية وجدول سطور ثابت (3 أسطر) وأزرار الحفظ في الأسفل؛ صفحات
العرض تضع القيد المحاسبي في إطار مضمّن. الواجهة عربية RTL بالكامل.

## ترتيب الدراسة المقترح (المتّبع)
1. توجيه الحسابات + دليل الحسابات + الضرائب + العملاء + الموردون + الأصناف
2. القيود، فاتورة المبيعات، سند القبض، فاتورة المشتريات
3. التقارير
4. الإعدادات والصلاحيات والنظام
