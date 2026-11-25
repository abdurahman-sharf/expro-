# أنماط الشاشات (Screen Archetypes)

صمّم **نمطاً واحداً متقناً** لكل نوع في `ui/archetypes/<type>.html`، واعرضه على المستخدم، ثم ولّد شاشات النوع نفسه منه
(غيّر المحتوى لا البنية). خريطة النوع من `01-sitemap.json` (`type`):

| النمط | `type` | المناطق | سلوك إلزامي |
|---|---|---|---|
| قائمة List | `list` | رأس (عنوان + إجراء إنشاء أساسي) · شريط أدوات (بحث فوري، فلاتر كرقائق، فترات جاهزة) · جدول · ترقيم | فرز بالأعمدة، تحديد جماعي + شريط إجراءات، تصدير، حالة فارغة توجّه للإنشاء |
| نموذج بسيط Form | `form` | رأس · أقسام حقول (fieldsets) · "متقدم" قابل للطي · شريط إجراءات ثابت أسفل | تحقق بجانب الحقل، حقول شرطية، Ctrl+S للحفظ، تحذير "تغييرات غير محفوظة" |
| محرر مستند Document Editor | `form` لمستند بسطور | رأس المستند (طرف، تاريخ، عملة) · محرر السطور · الإجماليات · ملاحظات/مرفقات · إجراءات حسب الحالة | إضافة سطر بلوحة المفاتيح، بحث الصنف في السطر، حسابات لحظية، نموذج مضمّن لإنشاء طرف |
| عرض سجل Record View | `detail` | رأس (رقم + شارة حالة + إجراءات) · ملخص · تبويبات (سطور، قيد، مدفوعات، سجل) · خط زمني | إجراءات حسب الحالة والصلاحية، القيد المحاسبي مرئي، روابط للمستندات المرتبطة |
| تقرير Report | `report` | معايير (فترة، أبعاد، مستوى) · نتائج (جدول بمستويات قابلة للتوسيع) · إجماليات | تجميع حسب (group_by)، ملخص/تفاصيل، تصدير، تفصيل بالنقر (drill-down) |
| إعدادات Settings | `settings` | تنقل جانبي بين أقسام الإعدادات · نموذج القسم | حفظ لكل قسم، وصف لكل إعداد يشرح أثره، تحذير للإعدادات غير القابلة للتغيير لاحقاً |
| لوحة معلومات Dashboard | `dashboard` | بطاقات مؤشرات · رسم أو اثنان · "يحتاج انتباهك" · إنشاء سريع | كل رقم يقود لمصدره |
| معالج خطوات Wizard | `wizard` | مؤشر الخطوات · محتوى الخطوة · السابق/التالي | حفظ التقدم، تحقق قبل الانتقال، ملخص قبل التأكيد |

## إلزامي في كل نمط
- `.preview-bar` لمعاينة الأدوار والحالات (فارغة، تحميل، خطأ، أخطاء التحقق، وحالات المستند).
- الهيكل العام من `ui/navigation.json` (شريط جانبي بأقسام التنقل الجديد، الصفحة الحالية `aria-current="page"`).
- بحث شامل في الشريط العلوي (`/` أو Ctrl+K) وزر إنشاء سريع.
- عنوان `<title>` باسم الشاشة واسم النظام الجديد، `<html lang dir>` صحيحان.
- كل مدخل له تسمية؛ ترتيب Tab منطقي؛ الإجراء الأساسي واحد.

## هيكل ملف شاشة (نقطة البداية)

```html
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>فاتورة بيع جديدة — <اسم النظام></title>
<link rel="stylesheet" href="../../kit/tokens.css"><link rel="stylesheet" href="../../kit/proto.css"></head>
<body>
<div class="app">
  <nav class="app-nav" aria-label="التنقل الرئيسي"><span class="brand"><اسم النظام></span>
    <div><h2>أبيع</h2><ul><li><a href="../sales/sales-invoice-list.html" aria-current="page">الفواتير</a></li></ul></div></nav>
  <div class="app-main">
    <header class="topbar"><div class="search"><input aria-label="بحث شامل" placeholder="بحث… ( / )"></div>
      <button class="btn btn-primary" type="button">+ إنشاء</button><button class="btn btn-ghost" type="button" data-theme-toggle>الوضع</button></header>
    <div class="preview-bar no-print"><label>الدور <select data-role-switch><option value="admin">مدير</option><option value="accountant">محاسب</option></select></label>
      <label>الحالة <select data-state-switch data-target="doc"><option value="draft">مسودة</option><option value="issued">مُصدَرة</option><option value="empty">فارغة</option><option value="loading">تحميل</option><option value="error">خطأ</option></select></label></div>
    <main class="page" id="doc">…</main>
  </div>
</div>
<script src="../../kit/proto.js"></script>
</body></html>
```

المسارات نسبية (`../../kit/…`) لتعمل الملفات بالفتح المباشر من القرص ومن صفحة الفهرس.
