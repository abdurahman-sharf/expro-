# أدوات المتصفح — الاستخدام الآمن للقراءة فقط

السكيلز لا تفترض أداة بعينها. استخدم ما هو متاح في الجلسة، بهذا الترتيب من التفضيل:

## 1. Claude in Chrome (متصفح المستخدم نفسه)
الأفضل لأن المستخدم يسجّل الدخول بنفسه في متصفحه وترى أنت نفس الجلسة.

| الأداة | الاستخدام | ملاحظات |
|---|---|---|
| `navigate` | فتح صفحة الدخول أو رابط شاشة | لا تفتح روابط خارج نطاق النظام. |
| `read_page` / `get_page_text` | استخراج الحقول، التسميات، الجداول، القوائم | المصدر الرئيسي للمعلومات. |
| `find` | إيجاد عنصر ("زر المزيد"، "قائمة الإعدادات") | للقراءة والتنقل فقط. |
| `computer` (click/hover/scroll) | فتح القوائم والتبويبات والقوائم المنسدلة | قبل أي نقرة اسأل: هل هذا زر تنقّل أم زر إجراء؟ |
| `read_network_requests` | استنتاج بنية الـ API من طلبات GET | وثّق المسار وأسماء الحقول فقط، لا القيم ولا الترويسات. |
| `form_input` | **لا تستخدمها** في هذا العمل. | لا إدخال في أي حقل، وخاصة حقول الدخول. |
| `javascript_tool` | قراءة DOM فقط (انظر أدناه) | لا `fetch`، لا `click()`، لا `submit()`، لا تعديل للصفحة. |

## 2. Playwright / متصفح آلي
إن لم يتوفر Chrome الخاص بالمستخدم:
- شغّل المتصفح **بنافذة ظاهرة** (`headless: false`) إن أمكن، وافتح صفحة الدخول، واطلب من المستخدم
  الدخول بنفسه في تلك النافذة. إن كانت البيئة بلا شاشة فلا يمكن للمستخدم الدخول: توقف واطلب منه
  إمّا استخدام Claude in Chrome أو تزويدك ببيئة تجريبية لا تحتاج دخولاً.
- لا تحفظ `storageState` أو الكوكيز في ملف.
- استخدم `page.goto`، `page.content()`، `locator.textContent()`، `locator.getAttribute()`،
  و`page.on('request')` مع تصفية `request.method() === 'GET'` وتسجيل `url` المجرّد من قيم المعاملات.
- يُمنع `page.fill`، `page.type`، `locator.press('Enter')`، و`click` على أي زر إجراء.

## 3. مقتطف قراءة DOM (اختياري، للقراءة فقط)
لاستخراج حقول نموذج مفتوح دون نقر. لا يرسل طلبات ولا يغيّر شيئاً:

```js
(() => {
  const labelFor = el => {
    if (el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) return l.innerText.trim(); }
    const wrap = el.closest('label, .form-group, .field, [class*="form-item"]');
    return wrap ? (wrap.querySelector('label, .label, legend')?.innerText || '').trim() : '';
  };
  return [...document.querySelectorAll('input, select, textarea')]
    .filter(el => el.type !== 'hidden' && el.type !== 'password')
    .map(el => ({
      label: labelFor(el) || el.placeholder || el.name,
      name: el.name, tag: el.tagName.toLowerCase(), type: el.type,
      required: el.required || el.getAttribute('aria-required') === 'true',
      maxlength: el.maxLength > 0 ? el.maxLength : null,
      pattern: el.pattern || null, min: el.min || null, max: el.max || null, step: el.step || null,
      readonly: el.readOnly, disabled: el.disabled,
      options: el.tagName === 'SELECT' ? [...el.options].slice(0, 30).map(o => o.text.trim()) : undefined,
    }));
})()
```

خيارات القوائم المنسدلة التي تحوي بيانات حقيقية (أسماء عملاء مثلاً) لا تُنسخ: اكتب "مصدر القائمة:
العملاء" بدلاً منها. الخيارات الثابتة (أنواع الحسابات، طرق الدفع) تُنسخ لأنها وظيفة.

## 4. كيف تميّز زر التنقل من زر الإجراء
- **تنقل غالباً:** روابط `<a href>`، عناصر القائمة، التبويبات، "عرض"، "تفاصيل"، "جديد" (يفتح نموذجاً)،
  "تصفية"، "بحث"، "معاينة"، أسهم الصفحات.
- **إجراء غالباً:** `type="submit"`، "حفظ"، "ترحيل"، "اعتماد"، "حذف"، "إلغاء المستند" (يختلف عن
  "إلغاء" الذي يغلق النافذة)، "إرسال"، "استيراد"، "تنفيذ"، "معالجة"، "إقفال"، أيقونة سلة، أيقونة طائرة ورقية.
- **انتبه لكلمة "إلغاء":** في نافذة نموذج تعني غالباً "إغلاق دون حفظ" (آمن)، وعلى مستند مرحّل تعني
  "إلغاء المستند" (إجراء محاسبي). إن كان هناك شك، استخدم زر × أو الرجوع في المتصفح.
