# تقرير A0: الأساس

مرحلة تأسيسية بلا واجهات: جداول المحاسبة، ومحرك ترحيل واحد، ومفتاح التشغيل وفاحص الاكتمال. **لا تغيير في سلوك النظام الحالي**: لا وحدات ولا صلاحيات ولا قوائم ولا مفاتيح لغة ولا خطّاف في مسار البيع.

## ما نُفِّذ

| البند | الملف |
|---|---|
| ترحيل 13 جدولًا جديدًا (إضافي فقط) ودليل الحسابات الافتراضي | `app/Database/Migrations/20261021000000_AccountingFoundation.php` |
| الأنواع المحصورة: نوع الحساب، الاتجاه، حالة القيد، مصدر القيد، مفاتيح التوجيه، أخطاء الترحيل | `app/Libraries/Accounting/*.php` |
| دليل الحسابات الافتراضي (34 حسابًا بالعربية) وتوجيهه | `DefaultChart.php` |
| محرك الترحيل | `PostingService.php` مع `JournalDraft` و`JournalLineDraft` و`PostingResult` و`PostingException` |
| سجل التدقيق وسجل فشل الترحيل | `AuditLog.php`، `PostingFailureLog.php` |
| مفتاح التشغيل وفاحص الاكتمال | `AccountingSettings.php`، `ReadinessCode.php`، `ReadinessIssue.php`، `AccountingNotReadyException.php` |
| النماذج | `app/Models/Accounting/{Account,CostCenter,Journal,JournalLine}.php` |

## الجداول

`accounting_accounts`، `accounting_cost_centers`، `accounting_fiscal_years`، `accounting_closed_periods`، `accounting_sequences`، `accounting_routing`، `accounting_backfill_batches`، `accounting_journals`، `accounting_journal_lines`، `accounting_allocations`، `accounting_posting_failures`، `accounting_audit_log`، `accounting_ai_proposals`.

مفاتيح الإعداد في `app_config`: `accounting_enabled` (الافتراضي `0`)، و`accounting_base_currency`، و`accounting_start_date`. المفتاحان الأخيران يُضبطان قبل التفعيل (لا يوجد في OSPOS إعداد لرمز العملة ISO، لذلك أُضيف).

## سلوك المحرك

- **التحقق قبل أي كتابة:** سطران على الأقل، كل سطر بمدين أو دائن لا كليهما وأكبر من صفر، المبالغ نصوص عشرية بحد أقصى 4 خانات، المجموعان متساويان بدقة (`bcmath`)، الحساب فرعي ونشط، ومركز التكلفة موجود ونشط، وتاريخ صحيح، وعملة من 3 أحرف، وطرف (عميل/مورد) صالح.
- **عدم التكرار:** إعادة الترحيل لنفس (`source_type`، `source_id`، `revision`) تعيد القيد الموجود (`created = false`) ولا تكتب قيدًا ثانيًا. مفتاح فريد في قاعدة البيانات يحمي من سباق متزامن.
- **التعديل لاحقًا:** نسخة جديدة (`revision`) من نفس المصدر تُنشئ قيدًا جديدًا. وعكس القيد القديم يأتي في A1.
- **الترقيم:** القيود اليدوية بتسلسل `manual` يُقفل صفه في المعاملة (بلا فجوات). والتلقائية تأخذ رقمها من معرّفها بلا قفل، حتى لا يتسلسل حفظ المبيعات على صف واحد.
- **المعاملات (D2):** إن لم تكن هناك معاملة مفتوحة تملك الخدمة معاملتها وتتراجع وتسجل سبب الفشل بعد التراجع. وإن كانت داخل معاملة المستدعي (مثل حفظ البيع) فهي تنضم إليها: تتراجع مع المستدعي ولا تسجل الفشل بنفسها، لأن أي سطر يُكتب قبل التراجع سيضيع. فيسجله المستدعي بـ`PostingFailureLog` بعد التراجع. واختبارات هذه الحالات موجودة.
- **حالة معاملة CodeIgniter:** `transBegin()` لا يعيد ضبط حالة الفشل في الوضع الصارم، فيبقى الاتصال المشترك يفشل كل معاملة لاحقة. تعيد الخدمة ضبطها عند بدء معاملة تملكها وبعد التراجع منها.

## قرارات اتُّخذت في A0 (ولم تكن مكتوبة سابقًا)

| القرار | السبب |
|---|---|
| رقم القيد `NULL` للمسودة ويُخصَّص عند الترحيل | تسلسل بلا فجوات للمرحَّل، وملاءمة المسودات في A1 |
| `source_id` فارغ للقيد اليدوي | المفتاح الفريد لا يقيّد القيود اليدوية ويحمي التلقائية فقط |
| كل المفاتيح الأجنبية `RESTRICT` بلا `CASCADE` | الثبات: لا حذف قيد مرحّل بالخطأ. وحذف المسودة (A1) يحذف أسطرها صراحة |
| قيد `CHECK` على مبالغ السطر | يمنع مبلغين في سطر واحد حتى خارج الخدمة. (يُتجاهل في MySQL 5.7، والخدمة تفرضه على كل حال) |
| أعمدة ضريبية اختيارية على السطر (`tax_code`، `tax_rate`، `tax_base`، `tax_exemption_reason`) و`document_uuid` على القيد | تجهيز بنية الفوترة الإلكترونية (D7). هي أعمدة **حاجزة** تُراجَع مع المواصفة الرسمية عند بدء مرحلتها |
| دليل الحسابات يُثبَّت في الترحيل، وحسابات النظام لها `system_key` وتُحمى | D6 |
| 15 مفتاح توجيه للبنود الثابتة فقط | توجيه أنواع الدفع وفئات المصروفات الديناميكي يأتي مع A3 وA4 |

## ما لا يفعله A0 عمدًا

- لا يتحقق من الفترات المالية أو المغلقة (A2).
- لا مسودات ولا عكس ولا حذف ولا شاشات ولا وحدات ولا صلاحيات (A1).
- لا خطّاف في `Sale::save_value` ولا غيره (A3 وما بعدها).
- جدولا `accounting_ai_proposals` و`accounting_allocations` و`accounting_backfill_batches` فارغة بلا كود يستعملها بعد (A9 وA5 وA6).

## التحقق

- اختبارات جديدة (61 اختبارًا): `tests/Libraries/Accounting` (`PostingServiceTest`، `DefaultChartTest`، `AccountingSettingsTest`)، و`tests/Models/Accounting` (`AccountTest`، `JournalTest`)، و`tests/Database/AccountingFoundationMigrationTest.php` (إنشاء الجداول، وتكرار `up()`، و`down()` ثم `up()` بلا لمس غير جداول المحاسبة).
- خط الأساس قبل A0: 1620 اختبارًا ناجحًا (4 متخطاة و1 deprecation كانت موجودة).
- المصحّح `php-cs-fixer` يُطبَّق على `app/` فقط (نطاقه في `.php-cs-fixer.no-header.php`). **لا يُطبَّق على `tests/`** لأنه يزيل الشرطة السفلية من أسماء الاختبارات المخالفة لـ`AGENTS.md`.
- للتشغيل: `CI_ENVIRONMENT=testing MYSQL_HOST_NAME=127.0.0.1 vendor/bin/phpunit --no-coverage tests/Libraries/Accounting tests/Models/Accounting tests/Database` (الإعداد في [sandbox-recipe.md](../ui-redesign/sandbox-recipe.md)).

## ما يلزم عند النشر

- الترحيل يُنفَّذ تلقائيًا عند أول دخول بعد النشر (انظر [قائمة النشر](../ui-redesign/deploy-checklist.md)). يُراجَع صراحة لأنه يضيف 13 جدولًا ويُدخل مفتاح `accounting_enabled`.
- لا أثر ظاهرًا للمستخدمين: لا قوائم ولا شاشات جديدة.
- التراجع: `down()` يحذف جداول المحاسبة ومفاتيحها فقط. أو استعادة النسخة الاحتياطية.
