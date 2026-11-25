# أدوات المتصفح — الاستخدام الآمن

السكيلز لا تفترض أداة بعينها. استخدم ما هو متاح في الجلسة، بهذا الترتيب من التفضيل.

## المحتويات
0. ضبط بيئة التصفح · 1. Claude in Chrome · 2. Playwright · 3. الاستخراج المزدوج (fetch / تصفّح) ·
4. مقتطفات قراءة · 5. الـ iframes · 6. العناصر المخصصة · 7. الـ API والترقيم · 8. زر التنقل أم زر الإجراء

## 0. ضبط بيئة التصفح (قبل أي شيء)
- **حجم سطح المكتب:** اضبط النافذة على حجم سطح المكتب (`resize_window` بالإعداد `desktop`، أو `viewport: {width: 1440,
  height: 900}` في Playwright). اللوحة الضيقة تعرض واجهة الجوال وتخفي القوائم الجانبية والأعمدة والأزرار.
- **نسبة الإحداثيات:** قبل أي نقر بالإحداثيات، احسب نسبة لقطة الشاشة إلى إحداثيات الصفحة:
  `({dpr: window.devicePixelRatio, w: innerWidth, h: innerHeight})` وقارنها بأبعاد اللقطة. إحداثيات النقر = إحداثيات
  اللقطة ÷ النسبة. فضّل دائماً النقر بالمرجع (ref/selector) على النقر بالإحداثيات.

## 1. Claude in Chrome (متصفح المستخدم نفسه)
الأفضل لأن المستخدم يسجّل الدخول بنفسه في متصفحه وترى أنت نفس الجلسة.

| الأداة | الاستخدام | ملاحظات |
|---|---|---|
| `navigate` | فتح صفحة الدخول أو رابط شاشة ظهر في الواجهة | لا روابط مخمّنة، ولا روابط إجراءات. |
| `resize_window` | ضبط حجم سطح المكتب | أول خطوة. |
| `read_page` | شجرة الوصول: الحقول، الأدوار، القوائم المخصصة | يكشف combobox/listbox التي لا تظهر كـ select. |
| `get_page_text` | النص المقروء | قد يعيد الـ iframes فارغة — انظر §5. |
| `find` | إيجاد عنصر ("زر المزيد"، "متقدم") | للقراءة والتنقل. |
| `computer` (click/hover/scroll/screenshot) | فتح القوائم والتبويبات والأقسام المطوية، والتحقق البصري | قبل كل نقرة: تنقّل أم إجراء؟ |
| `read_network_requests` | رصد طلبات GET التي تعيد JSON | البنية فقط، لا القيم ولا الترويسات. |
| `javascript_tool` | قراءة DOM، و`fetch` GET لقائمة السماح | لا POST، لا `click()` على أزرار إجراء، لا `submit()`. |
| `form_input` | **ممنوعة في وضع القراءة.** في وضع التجربة: لتعبئة نماذج السجلات التجريبية فقط | **لا تُستخدم أبداً في حقول الدخول**. |

## 2. Playwright / متصفح آلي
- شغّل المتصفح **بنافذة ظاهرة** إن أمكن، وافتح صفحة الدخول، واطلب من المستخدم الدخول بنفسه. إن كانت البيئة بلا شاشة
  فلا يمكن للمستخدم الدخول: توقف واطلب Claude in Chrome أو بيئة تجريبية لا تحتاج دخولاً.
- لا تحفظ `storageState` أو الكوكيز في ملف.
- للقراءة: `page.goto` (لروابط ظهرت في الواجهة)، `page.content()`، `locator.textContent()`، `page.on('request')` مع
  تصفية `GET` وتسجيل المسار مجرّداً من القيم.
- في وضع القراءة يُمنع `fill`/`type`/`press('Enter')`/`click` على أي زر إجراء. في وضع التجربة يُسمح بها على نماذج
  الإنشاء المسموحة فقط، ثم `click` على زر الحفظ في الواجهة.

## 3. الاستراتيجية المزدوجة: fetch للصفحات المولّدة من الخادم، وتصفّح للـ SPA

| نوع الصفحة | كيف تعرفها | طريقة الاستخراج |
|---|---|---|
| `server` (مولّدة من الخادم) | الحقول والجداول موجودة في HTML المصدر (`fetch` يعيدها كاملة) | `fetch` GET + `DOMParser` — أسرع بعشرات المرات |
| `spa` (React/Vue/Angular) | HTML شبه فارغ (`<div id="root">`)، المحتوى يُبنى بـ JS | تصفّح فعلي + `read_page` + رصد طلبات JSON |

سجّل النوع لكل شاشة في الخريطة: `"rendering": "server" | "spa"`، وسجّل `"extraction": "fetch" | "browse"` و
`"visual_check": true | false` (هل تحققت بلقطة شاشة؟). تقرير التغطية يعرض الشاشات المقروءة بـ fetch دون تحقق بصري.

**قيود fetch (من `guardrails.md` §2.1):** قائمة روابط محددة ظهرت في الواجهة **كروابط تنقّل** فقط؛ لا كلمات أفعال في
الرابط أو نص الزر؛ لا تتبّع متكرر. افحص كل رابط بالسكربت قبل طلبه:
```bash
python3 scripts/acs_tool.py urlcheck "/owner/clients/view/12" --label "عرض"        # allowed
python3 scripts/acs_tool.py urlcheck "/owner/invoices/delete/1" --label "حذف"      # forbidden
```

مقتطف استخراج دفعة صفحات (GET فقط، القائمة مُعدّة سلفاً من روابط التنقل):
```js
async (urls) => {
  const BAD = /(delete|remove|destroy|cancel|void|close|post|approve|make_|mark_|send|email|sms|submit|sync|revalue|reverse|undo|toggle|enable|disable|activate|deactivate|reset|run|process|import|logout)/i;
  const out = [];
  for (const url of urls) {
    if (BAD.test(url)) { out.push({ url, skipped: 'action-like' }); continue; }
    const res = await fetch(url, { method: 'GET', credentials: 'same-origin' });
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const spa = doc.body && doc.body.innerText.trim().length < 200 && doc.querySelector('#root, #app, [data-reactroot]');
    out.push({
      url, status: res.status, rendering: spa ? 'spa' : 'server',
      title: doc.querySelector('h1, title')?.textContent.trim(),
      fields: [...doc.querySelectorAll('input, select, textarea')].filter(e => e.type !== 'hidden' && e.type !== 'password')
        .map(e => ({ name: e.name, tag: e.tagName.toLowerCase(), type: e.type, required: e.required,
                     maxlength: e.maxLength > 0 ? e.maxLength : null, pattern: e.pattern || null })),
      navLinks: [...doc.querySelectorAll('nav a[href], .menu a[href], table a[href]')].map(a => ({ href: a.getAttribute('href'), text: a.textContent.trim() })),
      iframes: [...doc.querySelectorAll('iframe[src]')].map(f => f.getAttribute('src')),
    });
  }
  return out;
}
```
لا تحفظ HTML الخام في المخرجات؛ استخرج البنية واترك الخام.

## 4. مقتطف قراءة نموذج مفتوح (DOM)
```js
(() => {
  const labelFor = el => {
    if (el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) return l.innerText.trim(); }
    const wrap = el.closest('label, .form-group, .field, [class*="form-item"]');
    return wrap ? (wrap.querySelector('label, .label, legend')?.innerText || '').trim() : '';
  };
  const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  return [...document.querySelectorAll('input, select, textarea')]
    .filter(el => el.type !== 'hidden' && el.type !== 'password')
    .map(el => ({
      label: labelFor(el) || el.placeholder || el.name, name: el.name, tag: el.tagName.toLowerCase(), type: el.type,
      required: el.required || el.getAttribute('aria-required') === 'true', visible: visible(el),
      maxlength: el.maxLength > 0 ? el.maxLength : null, pattern: el.pattern || null, min: el.min || null, max: el.max || null,
      readonly: el.readOnly, disabled: el.disabled,
      options: el.tagName === 'SELECT' ? [...el.options].slice(0, 30).map(o => o.text.trim()) : undefined,
    }));
})()
```
حقل `visible: false` غالباً حقل شرطي أو داخل قسم مطوي — وثّق متى يظهر (انظر acs-analyzer §2.2).
خيارات القوائم المأخوذة من بيانات العميل لا تُنسخ ("مصدر القائمة: العملاء")؛ الخيارات الثابتة تُنسخ لأنها وظيفة.

## 5. الـ iframes
كثير من الأنظمة تعرض القيد المحاسبي ومعاينة الفاتورة والسجلات داخل iframe، و`get_page_text` قد يعيدها فارغة.
إن بدت الصفحة فقيرة المحتوى:
```js
[...document.querySelectorAll('iframe')].map(f => {
  let text = null; try { text = f.contentDocument?.body?.innerText?.slice(0, 5000) ?? null; } catch (e) { text = 'cross-origin'; }
  return { src: f.getAttribute('src'), sameOrigin: text !== 'cross-origin', text };
})
```
إن كانت من نطاق آخر أو فارغة، افتح `src` نفسه (بعد `urlcheck`) أو اجلبه بـ fetch إن كان رابط عرض.

## 6. العناصر المخصصة (select2، React-select، Vue-select...)
لا تظهر كـ `<select>`. ادمج ثلاثة مصادر قبل الحكم على الحقل:
1. **DOM:** ابحث عن `[role=combobox]`، `.select2-container`، `[class*="select__control"]`، وعن `<select>` مخفي مرتبط.
2. **شجرة الوصول (`read_page`):** تُظهر الدور والتسمية والقيمة الحالية.
3. **لقطة شاشة:** للتحقق البصري من التسمية وأن الحقل ظاهر فعلاً.
لقراءة الخيارات: افتح القائمة بالنقر (قراءة)، اقرأ `[role=option]`، ثم أغلقها بـ Escape. لا تختر خياراً في وضع القراءة.

## 7. الـ API والترقيم
- بعد فتح كل شاشة، اقرأ طلبات GET التي تعيد JSON (`read_network_requests` أو `page.on('response')`).
- وثّق البنية في `01-api-map.md` (المسار، المعاملات، أسماء الحقول وأنواعها، الكيان) — لا ردود خام ولا قيم.
- **الترقيم:** تابع كل الصفحات حتى النهاية وتحقق من الاكتمال: قارن عدد العناصر بـ `total`/`meta.total`/`last_page`،
  وتوقف عند صفحة فارغة. إن كان أي رقم صفحة يعيد الصفحة الأولى نفسها فالترقيم معطّل — سجّله ولا تكرر بلا نهاية:
```js
async (base) => {
  const all = []; let page = 1, total = null, prevFirst = null;
  while (page < 200) {
    const sep = base.includes('?') ? '&' : '?';
    const j = await (await fetch(`${base}${sep}page=${page}`, { credentials: 'same-origin' })).json();
    const rows = j.data ?? j.items ?? j.results ?? (Array.isArray(j) ? j : []);
    total = j.meta?.total ?? j.total ?? total;
    const first = JSON.stringify(rows[0] ?? null);
    if (!rows.length || first === prevFirst) break;
    prevFirst = first; all.push(...rows); page++;
    if (total !== null && all.length >= total) break;
  }
  return { pages: page - 1, count: all.length, total, complete: total === null ? 'unknown' : all.length >= total,
           fields: all[0] ? Object.keys(all[0]) : [] };
}
```
  أعد **البنية والعدد** فقط إلى المخرجات. البيانات المسحوبة (مثل شجرة الحسابات) تُستخدم لاستنتاج الهيكل
  (المستويات، الأدوار، الأنواع) ثم تُهمل؛ أسماء الحسابات القياسية العامة (العملاء، المبيعات) وظيفة يجوز ذكرها،
  وأسماء الأطراف الحقيقية لا.

## 8. كيف تميّز زر التنقل من زر الإجراء
- **تنقل غالباً:** روابط القائمة، التبويبات، "عرض"، "تفاصيل"، "جديد"/"إضافة" (يفتح نموذجاً)، "تعديل" (يفتح نموذجاً)،
  "تصفية"، "بحث"، "معاينة"، أسهم الصفحات.
- **إجراء غالباً:** `type="submit"`، "حفظ"، "ترحيل"، "اعتماد"، "حذف"، "إلغاء المستند"، "إرسال"، "استيراد"، "تنفيذ"،
  "معالجة"، "إقفال"، "تأكيد التسليم"، "تحويل إلى..."، أيقونة سلة أو طائرة ورقية، **وكل عنصر داخل قائمة "إجراءات"**
  حتى لو كان `<a href>` عادياً.
- **انتبه لكلمة "إلغاء":** في نافذة نموذج تعني غالباً "إغلاق دون حفظ" (آمن، استخدم × أو الرجوع إن شككت)، وعلى مستند
  تعني "إلغاء المستند" (إجراء محاسبي).
