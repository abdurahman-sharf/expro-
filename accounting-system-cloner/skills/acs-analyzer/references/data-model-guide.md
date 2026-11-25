# دليل استنتاج نموذج البيانات

## من أين تستنتج
| الدليل | ماذا يخبرك | درجة الثقة |
|---|---|---|
| حقول النموذج وسماتها (`required`، `maxlength`، `type=number`، `step`) | الحقول والأنواع والقيود | مرئي |
| أسماء الحقول في طلبات GET (`customer_id`، `branch_id`) | أسماء الأعمدة والمفاتيح الأجنبية | مرئي (بنية) |
| مسارات الـ API (`/api/sales/invoices/{id}/lines`) | الكيانات والعلاقات الأب/الابن | مرئي (بنية) |
| قوائم منسدلة مرجعية | مفتاح أجنبي إلى كيان | مرئي |
| جدول سطور داخل مستند | علاقة 1:N وجدول أسطر | مرئي |
| اختيار متعدد (وسوم، فروع مسموحة) | N:M وجدول ربط | [مُستنتج] |
| نمط ترقيم في القوائم (`INV-2025-00042` و`INV-2024-00113`) | تسلسل يُعاد ضبطه سنوياً | [مُستنتج] |
| فرع في رقم المستند (`RYD-INV-…`) | تسلسل لكل فرع | [مُستنتج] |
| فلاتر في القوائم | فهارس متوقعة | [مُستنتج] |
| رسائل "لا يمكن حذف عميل له حركات" (إن ظهرت نصاً ثابتاً) | `ON DELETE RESTRICT` | مرئي/[مُستنتج] |

## `03-data-model.md` — البنية

```markdown
# نموذج البيانات
## ملخص
عدد الكيانات N، مجمّعة حسب الوحدة. الاصطلاحات: snake_case، مفاتيح `bigint`/`uuid`، `company_id` في كل جدول (متعدد الشركات).

## <ENTITY_NAME> — <الاسم الوظيفي>
- **الغرض:** …
- **الشاشات المصدر:** `gl-coa`, `gl-account-form`
| الحقل | النوع | Null | افتراضي | القيد | المصدر |
|---|---|---|---|---|---|
| id | bigint | لا | identity | PK | — |
| code | varchar(20) | لا | — | فريد لكل شركة | مرئي: maxlength=20 |
| parent_id | bigint | نعم | — | FK → account | مرئي: حقل "الحساب الأب" |
| nature | enum(debit,credit) | لا | — | — | مرئي |
- **العلاقات:** …
- **الفهارس:** (company_id, code) فريد؛ (parent_id) [مُستنتج]
- **الترقيم:** …
- **قواعد سلامة:** لا يُحذف حساب له حركات [مُستنتج]
```

## الكيانات المتوقعة في أي نظام محاسبي (تحقق من وجودها)
company، branch، fiscal_year، fiscal_period، currency، exchange_rate، account (شجري)، cost_center (شجري)،
project، tax، tax_group، customer، supplier (أو party موحّد بدور)، contact، address، item، item_category،
unit_of_measure، item_uom، warehouse، price_list، journal_entry، journal_line، sales_quotation(+lines)،
sales_order(+lines)، sales_invoice(+lines)، credit_note(+lines)، receipt، receipt_allocation، purchase_*،
payment، payment_allocation، stock_movement(+lines)، stock_balance/stock_layer (FIFO)، bank_account،
bank_reconciliation، cheque، fixed_asset، depreciation_schedule، user، role، permission، role_permission،
user_branch، approval_rule، audit_log، document_sequence، attachment، setting.

## عناصر يجب التأكد من توثيقها إن وُجدت
| العنصر | ماذا توثّق | أعمدة مقترحة |
|---|---|---|
| حالات المستند | مسودة / مُصدَر / مُسلَّم / ملغى، وحالات التسليم المنفصلة، والانتقالات المسموحة | `status`, `delivery_status` |
| حالات الدفع | غير مكتمل / مكتمل / تحت المراجعة / فاشل / مسودة — للدفعة نفسها، ومنفصلة عن حالة دفع الفاتورة | `payment.status`, `invoice.payment_status` |
| العملة في سطر القيد | المبلغ بعملة المعاملة وبالعملة المحلية، سعر الصرف، ومتى يُحدَّث | `debit`, `credit`, `debit_base`, `credit_base`, `currency_code`, `exchange_rate` |
| الترقيم لكل كيان | البادئة، عدد الخانات، التفرّد (عام/لكل فرع/لكل سنة)، خيارات إعادة التسلسل، متى يُحجز الرقم | جدول `document_sequence(entity, prefix, digits, scope, reset_policy, next_value)` |
| وحدات القياس | الوحدة الأساسية، وحدات بديلة ومعاملات التحويل، الوحدة في سطر المستند | `item_uom(item_id, uom_id, factor)`, `line.uom_id`, `line.qty_base` |
| الأعمدة والحقول المخصصة | أعمدة مخصصة في سطور المستندات، حقول مخصصة للكيانات وأنواعها | `custom_field(entity, key, type, options)`, `custom_value` أو `jsonb` |
| المالك | إن كانت الصلاحيات بنطاق "الخاص به" | `owner_id`/`created_by` + فهرس + سياسة RLS |
| أدوار الحسابات | الوسوم النظامية على الحسابات وتوجيه العمليات | `account.role`, `account_routing(operation, mode, account_id)` |
| متطلبات الدولة | السعودية: العنوان الوطني (رقم المبنى، الرقم الفرعي، العنوان المختصر، الشارع، الحي، المدينة، الرمز البريدي)، نوع المعرّف (CRN/700/…)، علامة الجهة الحكومية | `building_no`, `additional_no`, `short_address`, `id_type`, `id_number`, `is_government` |

## قرارات تصميم يجب توثيقها صراحة (من سلوك النظام المرجعي)
- **party موحّد أم جداول منفصلة** للعملاء والموردين؟ (هل يمكن أن يكون الطرف عميلاً ومورداً معاً؟)
- **المستندات:** جدول لكل نوع أم جدول `document` عام بحقل نوع؟ (وثّق ما يوحي به النظام، واقترح.)
- **المبالغ بعملتين:** `amount` و`amount_base` و`exchange_rate` على المستند والسطر وسطر القيد.
- **الأرصدة:** محسوبة من أسطر القيود دائماً أم جداول أرصدة مخزّنة؟ (اقترح: محسوبة + لقطات للأداء.)
- **المخزون:** جدول طبقات للتكلفة في FIFO؛ متوسط متحرك يحتاج تكلفة متوسطة لكل صنف/مستودع.
- **تعدد الشركات:** `company_id` في كل جدول، وسياسة عزل (Row Level Security في PostgreSQL).

## `03-erd.mmd`
```
erDiagram
    ACCOUNT ||--o{ ACCOUNT : "parent"
    JOURNAL_ENTRY ||--|{ JOURNAL_LINE : contains
    ACCOUNT ||--o{ JOURNAL_LINE : "posted to"
    CUSTOMER ||--o{ SALES_INVOICE : "billed"
    SALES_INVOICE ||--|{ SALES_INVOICE_LINE : has
    SALES_INVOICE ||--o| JOURNAL_ENTRY : "posts"
```
أسماء الكيانات بأحرف لاتينية كبيرة وشرطة سفلية؛ التسميات بين علامات تنصيص. لا تكتب الحقول كلها في الـ ERD
إن تجاوز 30 كياناً — المفاتيح فقط، والتفاصيل في `03-data-model.md`.

## `03-schema.sql` — اصطلاحات
- `numeric(18,4)` للمبالغ والكميات حسب الحاجة، `numeric(18,6)` لأسعار الصرف، `numeric(7,4)` للنسب.
- `created_at`, `updated_at`, `created_by`, `updated_by` في كل جدول معاملات.
- `status` كـ `text` مع `CHECK (status IN (...))` بحالات دورة الحياة الموثّقة.
- توازن القيد لا يُفرض بـ CHECK على صف واحد؛ اقترح trigger مؤجّل (`DEFERRABLE INITIALLY DEFERRED`) أو فرضه في طبقة
  الخدمة مع اختبار إلزامي — واذكر ذلك صراحة.
- منع الترحيل لفترة مقفلة: trigger أو قيد في طبقة الخدمة — اذكر الاختيار.
- علّق كل استنتاج: `-- [مُستنتج] من نمط الترقيم في قائمة الفواتير`.
- يجب أن يُنفَّذ الملف بلا أخطاء على PostgreSQL 15+ (رتّب الجداول حسب الاعتماديات).
