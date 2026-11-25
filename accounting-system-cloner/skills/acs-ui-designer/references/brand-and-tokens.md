# الهوية ونظام التصميم

## 1. استلام الهوية (Brand Intake)

اسأل في رسالة واحدة، مع اقتراح افتراضي لكل سؤال:

| السؤال | الافتراضي إن لم يُجب |
|---|---|
| اسم النظام الجديد، والشعار | اسم مؤقت يحدده المستخدم لاحقاً؛ شعار نصي |
| الألوان أو الإحساس (رسمي، حديث، دافئ، تقني...) | يُقترح 3 اتجاهات (أدناه) |
| الخطوط | عربي واضح يدعم الأرقام: IBM Plex Sans Arabic أو Noto Sans Arabic، وخط أرقام بمحاذاة ثابتة |
| الجمهور: محاسبون محترفون أم أصحاب منشآت صغيرة | يحدد الكثافة: `compact` (جداول كثيفة، اختصارات ظاهرة) أو `comfortable` (مسافات أوسع، إرشاد وحالات فارغة توجيهية) |
| الأجهزة | سطح المكتب أولاً + تجاوب للجوال في القوائم والعرض |
| اللغات | عربي RTL؛ إن كان ثنائياً فكل مكوّن يُختبر في الاتجاهين |
| نظام تصميم جاهز؟ | إن وُجد فهو المرجع: انقل قيمه إلى `tokens.json` ولا تخترع بديلاً |

### إن لم تكن لديه هوية: 3 اتجاهات مختلفة فعلاً
لكل اتجاه: اسم وصفي، الإحساس في سطر، لوحة من 5–6 ألوان (خلفية، سطح، نص، أساسي، تمييز) مع نسب التباين، ثنائي خطوط،
الكثافة، وشكل الزوايا والظلال، وعيّنة صغيرة (زر أساسي + شارة حالة + صف جدول). اجعل الثلاثة متباعدة (درجة لونية
وحرارة ونبرة مختلفة)، واختر لكل اتجاه مبرراً من جمهور المستخدم. لا تبنِ أي اتجاه على شكل النظام المرجعي — أنت لا
تعرفه أصلاً (غرفة نظيفة)، وفحص `scrub` يرفض أي لون هوية مسجّل له.

## 2. `ui/tokens.json` — المخطط

```json
{
  "meta": {
    "product_name": "<اسم النظام الجديد>",
    "language": "ar", "direction": "rtl", "languages": ["ar"],
    "density": "compact",
    "google_fonts": "https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap"
  },
  "color": {
    "bg": "…", "surface": "…", "surface-2": "…", "border": "…", "border-strong": "…",
    "text": "…", "text-muted": "…", "link": "…",
    "primary": "…", "primary-hover": "…", "primary-soft": "…", "on-primary": "…", "focus": "…",
    "success": "…", "success-bg": "…", "warning": "…", "warning-bg": "…",
    "danger": "…", "danger-bg": "…", "on-danger": "…", "info": "…", "info-bg": "…",
    "nav-bg": "…", "nav-text": "…", "nav-muted": "…", "nav-hover": "…", "nav-active-bg": "…", "nav-active-text": "…"
  },
  "color_dark": { "…": "نفس المفاتيح بقيم الوضع الداكن (إن طُلب)" },
  "font": { "body": "'IBM Plex Sans Arabic', Tahoma, sans-serif", "heading": "…", "numeric": "'IBM Plex Mono', ui-monospace, monospace" },
  "font_size": { "xs": 12, "sm": 13, "md": 14, "lg": 16, "xl": 20, "2xl": 26 },
  "space": { "1": 4, "2": 8, "3": 12, "4": 16, "5": 24, "6": 40 },
  "radius": { "sm": 6, "md": 10, "lg": 14 },
  "shadow": { "md": "0 4px 16px rgb(… / .12)", "lg": "0 12px 40px rgb(… / .18)" },
  "breakpoint": { "sm": 480, "md": 768, "lg": 1024, "xl": 1280 },
  "contrast_pairs": [
    ["text", "bg", 4.5], ["text", "surface", 4.5], ["text-muted", "surface", 4.5], ["text-muted", "surface-2", 4.5],
    ["on-primary", "primary", 4.5], ["primary", "surface", 4.5], ["link", "surface", 4.5],
    ["success", "success-bg", 4.5], ["warning", "warning-bg", 4.5], ["danger", "danger-bg", 4.5], ["info", "info-bg", 4.5],
    ["nav-text", "nav-bg", 4.5], ["nav-active-text", "nav-active-bg", 4.5], ["focus", "surface", 3], ["border-strong", "surface", 3]
  ]
}
```

- المفاتيح الإلزامية تتحقق منها `ui_kit.py tokens`؛ المفاتيح `nav-*` و`link` و`border-strong` اختيارية (لها بدائل).
- كل زوج في `contrast_pairs` يُفحص في الفاتح والداكن بـ `acs_tool.py uicontrast`: النص ≥ 4.5، العناصر غير النصية
  والحدود الدالة ≥ 3. عدّل القيم حتى يمر كل زوج — لا تحذف زوجاً لتمرير الفحص.
- لون الحالة على خلفيته الفاتحة (`success` على `success-bg`) هو ما تستخدمه الشارات والتنبيهات.

## 3. ترجمتها إلى CSS
`python3 <skill-dir>/scripts/ui_kit.py tokens --project <spec>` يولّد `ui/kit/tokens.css` (فاتح، داكن بـ
`prefers-color-scheme`، و`data-theme`). لا تكتب ألواناً في ملفات الشاشات: كل لون من متغير — `ui_kit.py lint` يرفض
الألوان الحرفية في الأنماط المضمّنة.
