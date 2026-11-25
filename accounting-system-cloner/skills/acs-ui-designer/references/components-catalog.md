# كتالوج المكونات — الحد الأدنى وطريقة توثيقه

`ui/components.md` يوثّق كل مكوّن بعنوان `## <اسم المكوّن>` (أداة `ui_kit.py index` تقرأ العناوين). المكونات أدناه
**إلزامية**؛ أضف ما تحتاجه المواصفات. الشيفرة تستخدم أصناف `proto.css` وخطافات `proto.js` كما هي — فتتصرف كل
الشاشات بالطريقة نفسها، وأي تعديل على المكوّن ينعكس على كل الشاشات.

## قالب توثيق المكوّن

```markdown
## <اسم المكوّن>
- **الغرض:** …
- **المتغيرات:** …
- **الحالات:** عادي · تركيز · معطّل · خطأ · تحميل (+ ما يخصه)
- **قواعد الاستخدام:** متى يُستخدم ومتى لا.
- **الوصول:** الدور/التسمية، لوحة المفاتيح، ما يُقرأ لقارئ الشاشة، التباين.
- **الشيفرة:** (المقتطف المعتمد)
```

## المكونات الإلزامية والمقتطف المعتمد لكل منها

### 1. حقول الإدخال (نص، رقم، بريد، هاتف، نص طويل، مربع اختيار، اختيار متعدد)
```html
<div class="field">
  <label for="f-name">الاسم <span class="req" aria-hidden="true">*</span></label>
  <input id="f-name" name="name" required maxlength="150" aria-describedby="f-name-hint">
  <span class="hint" id="f-name-hint">يظهر في الفواتير.</span>
</div>
<!-- حالة خطأ: أضف data-invalid على .field و <span class="error" id="…">…</span> و aria-invalid="true" + aria-describedby -->
```

### 2. القائمة المنسدلة القابلة للبحث (طرف، صنف، حساب)
اعتمد `input` + `datalist` في النموذج الأولي (أصلي ويعمل بلوحة المفاتيح)، ووثّق السلوك المطلوب في التنفيذ (combobox
بنمط ARIA 1.2: كتابة للبحث، أسهم للتنقل، Enter للاختيار، "+ إنشاء جديد" في آخر القائمة).
```html
<div class="field"><label for="f-customer">العميل</label>
  <input id="f-customer" name="customer" list="dl-customers" autocomplete="off" placeholder="ابحث بالاسم أو الرقم">
  <datalist id="dl-customers"><option value="عميل تجريبي 1"><option value="عميل تجريبي 2"></datalist></div>
```

### 3. منتقي التاريخ (ميلادي، وهجري إن طُلب)
`<input type="date">` في النموذج الأولي + سطر يعرض المقابل الهجري عند الطلب. وثّق: الصيغة المعروضة، اختصار "اليوم" (T)،
وفترات جاهزة في الفلاتر (هذا الشهر، الربع، السنة المالية).

### 4. حقل المبلغ بالعملة
```html
<div class="field"><label for="f-amount">المبلغ</label>
  <div class="amount"><input id="f-amount" name="amount" inputmode="decimal" data-money><span class="cur">ر.س</span></div></div>
```
الأرقام: محاذاة ثابتة (`tabular-nums`)، فاصل آلاف، خانتان، اتجاه LTR معزول داخل صفحة RTL.

### 5. جدول البيانات (فرز، فلترة، تحديد جماعي، ترقيم صفحات)
```html
<div class="toolbar"><div class="search grow"><input aria-label="بحث في الفواتير" placeholder="بحث…"></div>
  <button class="btn" type="button">فلترة</button><span class="chip">الحالة: مُصدَرة ×</span></div>
<div class="table-wrap"><table class="data">
  <thead><tr><th><input type="checkbox" aria-label="تحديد الكل"></th><th><button class="sort" aria-sort="descending">التاريخ</button></th><th class="num">الإجمالي</th><th>الحالة</th></tr></thead>
  <tbody><tr><td><input type="checkbox" aria-label="تحديد INV-0001"></td><td>2025-03-14</td><td class="num">1,150.00</td><td><span class="badge success">مدفوعة</span></td></tr></tbody>
</table></div>
<div class="pagination"><span>1–25 من 240</span><div class="btn-row"><button class="btn">السابق</button><button class="btn">التالي</button></div></div>
```
عند تحديد صفوف يظهر شريط إجراءات جماعية (عدد المحدد + الإجراءات المسموحة).

### 6. محرر سطور المستند — القلب
إضافة سطر بلوحة المفاتيح (Enter في آخر خانة من آخر سطر، أو Ctrl/⌘+Enter)، بحث الصنف داخل السطر، حسابات لحظية،
إجماليات، وضع "شامل الضريبة"، وخصم على الإجمالي.
```html
<form>
  <label class="check"><input type="checkbox" name="prices_include_tax" data-tax-inclusive> الأسعار شاملة الضريبة</label>
  <div class="table-wrap"><table class="data lines" data-line-editor>
    <thead><tr><th>#</th><th>الصنف</th><th>الوصف</th><th class="num">الكمية</th><th class="num">السعر</th><th class="num">خصم %</th><th>الضريبة</th><th class="num">الإجمالي</th><th><span class="sr-only">حذف</span></th></tr></thead>
    <tbody></tbody>
    <template><tr>
      <td data-line-no></td>
      <td><input data-item-search list="dl-items" aria-label="الصنف"></td>
      <td><input aria-label="الوصف"></td>
      <td><input data-col="qty" inputmode="decimal" value="1" aria-label="الكمية"></td>
      <td><input data-col="price" inputmode="decimal" aria-label="السعر"></td>
      <td><input data-col="discount" inputmode="decimal" value="0" aria-label="خصم السطر %"></td>
      <td><select data-col="tax" aria-label="الضريبة"><option value="15">15%</option><option value="0">0%</option></select></td>
      <td class="num" data-col="total">0.00</td>
      <td><button type="button" class="btn btn-ghost" data-remove-line aria-label="حذف السطر">×</button></td>
    </tr></template>
  </table></div>
  <datalist id="dl-items"><option value="صنف تجريبي A" data-price="100"></datalist>
  <div class="btn-row"><button type="button" class="btn" data-add-line>+ سطر <kbd>Ctrl+Enter</kbd></button></div>
  <dl class="totals"><dt>قبل الضريبة</dt><dd data-sum="net">0.00</dd><dt>الضريبة</dt><dd data-sum="tax">0.00</dd>
    <dt class="grand">الإجمالي</dt><dd class="grand" data-sum="total">0.00</dd></dl>
</form>
```
وثّق المعادلات بالضبط كما في ملف المواصفات (ترتيب الخصم والضريبة والتقريب)؛ إن اختلفت عن سلوك `proto.js` فاضبطها في
المقتطف ووثّق الفرق.

### 7. شارات الحالة
`<span class="badge success|warning|danger|info|neutral">مدفوعة</span>` — جدول في `components.md` يربط **كل حالة من
حالات المستندات في المواصفات** بلون دلالي (ولا يعتمد على اللون وحده: النص دائماً ظاهر).

### 8. الأزرار وقوائم الإجراءات
`btn-primary` لإجراء واحد أساسي في الصفحة، `btn` للثانوي، `btn-ghost` للثالثي، `btn-danger` للإجراءات الخطرة (مع تأكيد).
قائمة "المزيد" لبقية الإجراءات، ويظهر فيها فقط ما تسمح به الحالة والصلاحية.

### 9. النوافذ المنبثقة والتأكيد
```html
<button class="btn btn-danger" type="button" data-open-modal="dlg-void">إلغاء المستند</button>
<dialog id="dlg-void" aria-labelledby="dlg-void-title"><div class="dialog-head"><h3 id="dlg-void-title">إلغاء المستند؟</h3></div>
  <div class="dialog-body"><p>سيُنشأ قيد عكسي. لا يمكن التراجع.</p></div>
  <div class="dialog-foot"><button class="btn" data-close-modal value="cancel">تراجع</button><button class="btn btn-danger" data-close-modal value="ok" data-toast="أُلغي المستند">إلغاء المستند</button></div></dialog>
```

### 10. الإشعارات
`data-toast="…"` لرسالة مؤقتة (`aria-live="polite"`)، و`.alert` لرسالة ثابتة في الصفحة (نجاح/تحذير/خطأ/معلومة).

### 11. التبويبات
```html
<div role="tablist" aria-label="أقسام الفاتورة">
  <button role="tab" aria-selected="true" aria-controls="tab-lines" id="t-lines">السطور</button>
  <button role="tab" aria-selected="false" aria-controls="tab-journal" id="t-journal">القيد</button></div>
<div role="tabpanel" id="tab-lines" aria-labelledby="t-lines">…</div>
<div role="tabpanel" id="tab-journal" aria-labelledby="t-journal" hidden>…</div>
```

### 12. الخط الزمني (سجل المستند)
`<ol class="timeline"><li><time>2025-03-14 10:22</time><div>أُصدرت الفاتورة</div></li></ol>`

### 13. بطاقات المؤشرات
`<div class="kpis"><div class="kpi"><span class="label">مستحق من العملاء</span><span class="value">62,570.75</span><span class="delta muted">+4% عن الشهر الماضي</span></div></div>`

### 14. الحالات الفارغة والتحميل والخطأ
`.empty` (عنوان + سطر توجيه + زر الإنشاء)، `.skeleton` للتحميل، `.alert.danger` مع "إعادة المحاولة" للخطأ.

### 15. الحقول الشرطية
`data-show-when="name=value"` على الحاوية؛ `proto.js` يخفيها ويعطّل حقولها. مثال: الرقم الضريبي يظهر لنوع "منشأة مسجّلة":
```html
<div class="field"><label for="f-kind">نوع العميل</label><select id="f-kind" name="kind"><option value="registered">منشأة مسجّلة</option><option value="individual">فرد</option></select></div>
<div class="field" data-show-when="kind=registered"><label for="f-vat">الرقم الضريبي</label><input id="f-vat" name="vat" maxlength="15" pattern="3[0-9]{13}3"></div>
```

### 16. معاينة الأدوار والحالات (للمراجعة فقط)
شريط `.preview-bar` أعلى كل نموذج: `select[data-role-switch]` (يطبّق `data-hide-for`/`data-disable-for`) و
`select[data-state-switch]` (يبدّل `data-state-view`: فارغة، تحميل، خطأ، مسودة، مُصدَر…). يوضّح للمراجع والمنفّذ ما يتغير.
