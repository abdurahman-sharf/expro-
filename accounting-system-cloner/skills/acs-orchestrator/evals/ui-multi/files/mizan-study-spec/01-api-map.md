# خريطة الـ API (البنية فقط — بلا قيم)

النظام المرجعي **مولّد من الخادم** في الغالب (HTML كامل في المصدر). رُصدت طلبات الشبكة أثناء تصفّح كل الشاشات
المدروسة (Playwright، `page.on('request')`): طلب JSON واحد فقط تصدره الواجهة. لم تُرصد نقاط API للحسابات أو القيود
أو الفواتير — هذه تُقرأ من HTML.

## GET /api/customers
- **الكيان:** Customer · **الشاشات:** `cust-list` (تستدعيه عند التحميل لملء الجدول)
- **المعاملات:** `page` (int)، `per_page` (int، الواجهة ترسل 25)، `sort` (string، الواجهة ترسل `name`)
- **الترقيم:** `meta.page`، `meta.per_page`، `meta.total`، `meta.last_page` — **تحققت:** الصفحة 1 أعادت عدداً = `meta.total`،
  والصفحة 2 أعادت `data: []` (اكتمال مؤكد، الترقيم يعمل ولا يكرر الصفحة الأولى).
- **الحقول:** `{"id": "integer", "code": "string", "name": "string", "vat": "string", "phone": "string", "email": "string", "balance": "string", "currency": "string"}`
- **صيغ الحقول (وصفاً):** `code` بنمط بادئة + 4 أرقام؛ `vat` 15 رقماً أو فارغ للعميل الأجنبي؛ `balance` مبلغ منسّق بفواصل الآلاف كنص؛ `currency` رمز ISO 4217 من 3 أحرف.
- **الغلاف:** `{"data": "array<Customer>", "meta": {"page": "integer", "per_page": "integer", "total": "integer", "last_page": "integer"}}`
- **ملاحظة للمنفّذ:** `balance` يُعاد نصاً منسّقاً لا رقماً عشرياً — في النظام الجديد أعد `decimal` كنص غير منسّق
  (`"1000.00"`) مع `currency`، واترك التنسيق للواجهة.

## صفحات HTML ذات بنية بيانات (بدل API)
| المسار | الكيان | ملاحظة |
|---|---|---|
| `/accounts` | Account | جدول مسطّح: الرمز، الاسم (المستوى بشرطة)، المستوى (تجميعي/حركة)، الدور. لا ترقيم. |
| `/journal/view/{id}/frame` | JournalEntry + JournalLine | iframe بأسطر القيد (الحساب، مدين، دائن) — بلا عملة ولا سعر صرف ظاهر |
| `/sales/invoices` | SalesInvoice | الرقم، التاريخ، العميل، الإجمالي، الحالة |
| `/reports/{sales,purchases,payments,stock}` | تقارير | معاملات `group_by` و`view` (انظر كتالوج التقارير في `01-sitemap.md`) |

## نقاط إجرائية رُصدت في الـ HTML (للعلم — لم تُطلب)
وُثّقت لتصميم الـ API الجديد (كلها يجب أن تكون POST/PATCH/DELETE مع CSRF في النظام الجديد):
`POST /sales/invoices` (+ `?draft=1`)، `POST /purchases/invoices`، `POST /receipts`، `POST /journal` (+ `?post=1`)،
`POST /customers` (+ `?inline=1`)، `POST /suppliers`، `POST /items`، `POST /accounts`، `POST /inventory/transfers` (+ `?send=1`)،
`POST /inventory/settings`، `POST /settings/company`، `POST /settings/numbering/reset`، `POST /settings/zatca/onboard`،
`POST /fx/revaluation/run`، `POST /import`، `POST /customers/export`، `POST /reports/trial-balance/export`،
`POST /customers/{id}/delete`، `POST /customers/{id}/send-statement`، `POST /dashboard/refresh-balances`.
**روابط GET تغيّر الحالة في المرجع (خلل تصميمي — لا يُنسخ، انظر AN-07):** `/sales/invoices/make_undelivered/{id}`،
`/sales/invoices/delete/{id}`، `/apps/toggle/{n}`، `/settings/periods/reopen/{id}`، `/settings/periods/close/{id}`، `/logout`.
