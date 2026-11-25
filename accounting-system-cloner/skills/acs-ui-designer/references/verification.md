# التحقق قبل التسليم

نفّذ الفحوص بالترتيب، وأصلح المكوّن أو النمط (لا الشاشة وحدها) عند أي فشل، ثم أعد الفحص.

| الفحص | الأمر | يمر عندما |
|---|---|---|
| التطابق الوظيفي | `python3 <skill-dir>/scripts/acs_tool.py uicheck --project <spec>` | لا "مشكلات تمنع التسليم": لكل شاشة مطلوبة نموذج + ملف ربط، كل حقل وإجراء وحالة مربوط بمكوّن أو مبرَّر في "عناصر غير مصممة"، وكل شاشة في `ui/navigation.json`. يكتب `ui/coverage.md`. |
| الأصالة والخصوصية | `python3 <skill-dir>/scripts/acs_tool.py scrub --project <spec>` | نظيف: لا اسم المنتج المرجعي ولا نطاقه ولا ألوان/تسميات هويته المسجلة (في `_private/` — لا تفتحه أنت)، ولا بيانات حقيقية، داخل `ui/` وخارجه. |
| التباين | `python3 <skill-dir>/scripts/acs_tool.py uicontrast --project <spec>` | كل زوج في `contrast_pairs` يحقق AA في الفاتح والداكن. |
| بنية الملفات والوصول الثابت | `python3 <skill-dir>/scripts/ui_kit.py lint --project <spec>` | لا مشكلات: RTL و`lang`، روابط الحزمة، تسمية لكل مدخل، لا ألوان حرفية، لا موارد خارجية غير الخطوط. |
| السلوك التفاعلي | اختبار متصفح على **ملفاتك المحلية** (انظر أدناه) | التبويبات تتبدل، الحقول الشرطية تظهر وتختفي، سطر المستند يُحسب ويُضاف بلوحة المفاتيح، النوافذ تُفتح وتُغلق، لا أخطاء في الـ console. |

## اختبار السلوك — على ملفات `ui/` فقط
أدوات المتصفح مسموحة هنا **لفتح ملفاتك المحلية** (`file://…/ui/…`) لا غير. لا تفتح أي رابط للنظام المرجعي.

```js
// node — يتطلب playwright المثبت في البيئة
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1366, height: 900 } });
  const errors = []; p.on('pageerror', e => errors.push(e.message)); p.on('console', m => m.type() === 'error' && errors.push(m.text()));
  await p.goto('file://' + process.argv[2] + '/ui/screens/sales/sales-invoice-form.html');
  await p.fill('table[data-line-editor] tbody tr:first-child [data-col="qty"]', '2');
  await p.fill('table[data-line-editor] tbody tr:first-child [data-col="price"]', '100');
  console.log('total', await p.textContent('[data-sum="total"]'));        // 230.00 بضريبة 15% غير شاملة
  await p.focus('table[data-line-editor] tbody tr:last-child td:last-of-type input, table[data-line-editor] tbody tr:last-child [data-col="tax"]');
  await p.keyboard.press('Control+Enter');
  console.log('rows', await p.locator('table[data-line-editor] tbody tr').count()); // 2
  const tabs = p.locator('[role="tab"]'); if (await tabs.count() > 1) { await tabs.nth(1).click(); console.log('tab', await tabs.nth(1).getAttribute('aria-selected')); }
  console.log('errors', errors); await b.close();
})();
```

## الوصول — قائمة لكل نمط
- [ ] كل الوظائف بلوحة المفاتيح: Tab يمر بترتيب القراءة (RTL)، Enter/Space للأزرار، Escape يغلق النافذة، الأسهم في التبويبات.
- [ ] تركيز مرئي (`:focus-visible`) بتباين ≥ 3.
- [ ] كل مدخل له تسمية مرئية أو `aria-label`؛ الأخطاء مرتبطة بـ `aria-describedby` و`aria-invalid`.
- [ ] الحالة لا تُنقل باللون وحده (نص الشارة ظاهر).
- [ ] الأرقام معزولة الاتجاه (LTR) بمحاذاة ثابتة وفاصل آلاف.
- [ ] العناوين متسلسلة (h1 واحد للصفحة).
- [ ] اختبر عرض 390px لنمطي القائمة والعرض على الأقل.
سجّل النتيجة لكل نمط في `ui/accessibility.md`.
