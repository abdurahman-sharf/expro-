# خريطة الـ API (البنية فقط — لا قيم)

رُصدت طلبات الشبكة أثناء فتح كل شاشات الخريطة. النظام مولّد من الخادم في معظمه؛ **نقطة نهاية JSON واحدة فقط** رُصدت.
لم تُرصد أي ترويسات مصادقة (البيئة لا تطلب دخولاً)، ولم تُنسخ أي ردود.

## GET /api/customers
- الكيان: Customer · الشاشات: `cust-list`
- الترقيم: `page`/`per_page` مع `meta.total` و`meta.last_page` — **تحققت:** بـ `per_page=25` صفحة واحدة، العدد = `total`؛
  وبـ `per_page=2` صفحتان والصفحة الثانية مختلفة عن الأولى (الترقيم يعمل فعلاً ولا يعيد الصفحة الأولى).
- المعاملات: page (integer)، per_page (integer)، sort (string — المرصود: `name`)
- الحقول: `{"id": "integer", "code": "string", "name": "string", "vat": "string", "phone": "string", "email": "string", "balance": "string"}`
- الغلاف: `{"data": "array<Customer>", "meta": {"page": "integer", "per_page": "integer", "total": "integer", "last_page": "integer"}}`
- ملاحظة للمنفّذ: `balance` يُعاد نصاً منسّقاً لا رقماً — في النظام الجديد أعِد المبالغ `decimal` كنص غير منسّق أو رقم عشري دقيق.

## نقاط نهاية غير JSON ذات صلة (روابط HTML)
| المسار | النوع | ملاحظة |
|---|---|---|
| `/journal/view/{id}/frame` | HTML داخل iframe | جدول أسطر القيد (الحساب، مدين، دائن) |
| `/reports/{sales,purchases,payments,stock}?group_by=&view=` | HTML | معاملات التقارير (انظر الكتالوج في `01-sitemap.md`) |

## نقاط إجراء رُصدت في الواجهة ولم تُطلب (للتوثيق — `never_request`)
| المسار | الطريقة | الأثر المعلن |
|---|---|---|
| `/sales/invoices/make_undelivered/{id}` | GET (رابط) | إلغاء التسليم |
| `/sales/invoices/delete/{id}` | GET (رابط) | حذف الفاتورة |
| `/apps/toggle/{n}` | GET (رابط) | تفعيل/تعطيل وحدة |
| `/settings/periods/close/{id}`، `/settings/periods/reopen/{id}` | GET (رابط) | إقفال/إعادة فتح فترة |
| `/dashboard/refresh-balances` | POST (نموذج) | "تحديث الأرصدة" — غير واضح الأثر |
| `/customers/{id}/delete`، `/customers/{id}/send-statement` | POST | حذف عميل، إرسال كشف حساب بالبريد |
| `/customers/export`، `/reports/trial-balance/export` | POST | تصدير Excel |
| `/settings/numbering/reset` | POST | إعادة ضبط التسلسل |
| `/accounts`، `/journal`، `/customers`، `/customers?inline=1`، `/suppliers`، `/items`، `/sales/invoices`، `/sales/invoices?draft=1`، `/purchases/invoices`، `/receipts`، `/import`، `/settings/company` | POST | حفظ النماذج |
للمنفّذ: **لا تجعل أي تغيير حالة عبر GET** في النظام الجديد (انظر AN-01 في `04-business-rules.md`).
