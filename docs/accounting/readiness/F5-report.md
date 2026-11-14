# تقرير F5: التشغيل وأداة الجاهزية والتوثيق

الفرع `wp/F5` (أساسه `int/r1` ثم دمجتُه ثانية بعد وصول تقرير R1). **لا دمج في `master` ولا نشر ولا بيانات إنتاجية.** كل قواعد التجربة بأسماء `ospos_test_f5*`. الأدلة: `/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/f5/` (`selftest-final.log` و`drill.log` و`phpunit-final-1.log` و`phpunit-final-2.log`).

## 1. الجدول

| البند | الحكم | ما فُعل |
|---|---|---|
| R2-05 أ (`source_keys`) | **الفحص خاطئ: أُصلح** (وأصلحه R4 أيضًا في `26af6f6`: أخذتُ شيفرته) | شيك مودَع ثم مرفوض قيدان بمفتاح واحد بتصميم `ChequePostingBuilder` |
| R2-05 ب (`payment_stamping`) | **الفحص خاطئ: أُصلح** | الدفعة بلا خزينة التي ما زالت على حساب توجيهها وضع عادي؛ الفشل للانجراف فقط |
| R2-05 ج (`treasury_ties`) | **الفحص خاطئ: أُصلح (تعديل على افتراض E68 رقم 4: انظر 2.3)** | القيد اليدوي على حساب خزينة مسموح فصار REVIEW؛ خزينة بلا حساب تبقى FAIL |
| R3-B2 | **أُنجز** | فحص `ADMIN-DEFAULT-PASSWORD` (FAIL) |
| R5-05 | **أُنجز** | فحص `FIRST-RUN-SETTINGS` (REVIEW) وقائمة التهيئة الأولى في قائمة النشر والدليل |
| النسخ والاستعادة | **أُنجز وجُرِّب فعلًا** | `tools/ops/backup.sh` و`restore.sh` و`selftest.sh` |
| 5 (أ) الترحيل بالويب | **أُنجز** | قفل `GET_LOCK` + اختبارات + نص الدليل |
| 5 (ب) إلى (هـ) | **أُنجز** | القسمان 5 و7 من الدليل، والخطوتان 1 و8 |
| R1-01 | **أُنجز** | حارس في أمرَي الخطوة 3 (رمز 1 ورسالة) |
| R1-08 | **أُنجز** | فحص مسبق لقرار E38 قبل أول ترحيل بالويب (503 بلا ترحيل) |
| R1-09 | **أُنجز** | قفل بانتظار 3 ثوانٍ وصفحة «Another request is migrating this database» |
| R1-10 | **أُنجز** | `restore.sh` يرفض غير الفارغة برسالة تقول الأثر الفعلي، والدليل يقوله بدقة |

## 2. R2-05: القرار لكل حالة (من الشيفرة والوثائق)

### 2.1 `source_keys` (أ)
`ChequePostingBuilder::drafts` يبني **قيدين** لانتقال `DepositReject` (العميل ← أوراق القبض، ثم أوراق القبض ← تحت التحصيل) بمراجعتين `n` و`n+1` تحت مفتاح المصدر `cheque_in_dep_rejected` نفسه، والمفتاح الفريد `(source_type, source_id, entry_type, revision)` يسمح بذلك. وكل انتقال شيك آخر قيد واحد. **الفحص خاطئ للحالة، لا الدفاتر.** الإصلاح: المسموح لهذا المصدر وحده اثنان وثالث يُفشل. **بعد طلب المنسّق دمجتُ `int/r1`:** إصلاح R4 (`26af6f6`: `HAVING COUNT(*) > IF(source_type = 'cheque_in_dep_rejected', 2, 1)`) **مطابق لإصلاحي في السلوك** فتركتُ شيفرته كما هي في `LedgerChecks` (ألغيتُ ثابتي ودالتي)، واختباراه (`PassesForTheTwoLegsOfAChequeRejectedAfterItsDeposit` و`FailsWhenAChequeRejectedAfterItsDepositStandsAsMoreThanItsTwoLegs`) يغطيان حالتي (قيدان ← PASS، ثلاثة ← FAIL) فحذفتُ اختباريّ المكرَّرين؛ **وأبقيتُ اختبارًا ثالثًا ليس عندهم:** قيدان تحت مصدر آخر (`cheque_in_deposited`) ما زالا يُفشلان (`testSourceKeys_FailsWhenAChequeStatusChangeThatPostsOneJournalStandsTwice`). نجحت 46 اختبارًا في `LedgerChecksTest` بعد الدمج (ضمن 118 اختبارًا شغّلتُها بعده: `phpunit-after-merge.log`).

### 2.2 `payment_stamping` (ب)
`TreasuryService::ofPayment/placeOfPayment` يكتب `treasury_id = NULL` **عمدًا** لكل دفعة لم تُسمَّ لها خزينة ولم يكن لموظفها خزينة افتراضية («تذهب إلى حساب نوعها كما كانت»)، والخزينة الرئيسية **تُنشأ كسولًا** (`ensureMain`) عند أول خزينة إضافية أو أول فتح لشاشة الخزائن فتصير الدفعات الموجودة «دخلت خزينة» في ربط الفحص. والمشكلة التي وُضع الوسم لها (E41: انجراف التوجيه W4) أن إعادة المستند إلى الدفاتر تنقل الدفعة إلى حساب **توجيه نوعها الحالي**؛ و`RoutingService` **يوسم قبل أن يحرّك توجيه وسيلة دفع**. فالدفعة بلا خزينة التي ما زالت على حساب توجيهها **لا تتحرك** (وضع عادي يتكرر مع كل بيع)، والفشل الحقيقي أن يكون توجيه نوعها صار إلى حساب غير حساب قيدها.
**الإصلاح** (`HistoricalDataChecks::paymentStamping`، ولم أغيّر `PaymentTreasuryStamping::plan()` ولا أمر الوسم ولا الوسم التلقائي): لكل صف تقترحه الخطة يُقارَن حساب قيده بـ`RoutingResolver::accountForPayment($type)`؛ **FAIL** إن اختلفا أو تعذّر التوجيه (حساب غير صالح) مع `accounting:stamp_payments --apply`؛ **PASS** مع سطر لكل جدول وعدّ في `data.in_place` إن تطابقا. `data.stamp` صار عدد المنجرف فقط. **REVIEW** لـ`account_differs` كما كان.
**اختبارات** (`HistoricalDataChecksTest`): الحالة السليمة (دفعة بيع ودفعة مورد بلا خزينة ← PASS و`in_place = 2`) ونقيضها (توجيه `payment.cash` إلى حساب آخر ← FAIL ويسرد الدفعتين) وبالنوع (نقد منجرف وبطاقة سليمة ← `stamp 1` و`in_place 1`) وحساب التوجيه غير صالح ← FAIL ولا كتابة وPASS بعد `stamp()`؛ وفي `AccountingReadinessTest` ثلاثة اختبارات (سليمة ← رمز 0، منجرفة ← رمز 1 وتعالج `--apply`).

### 2.3 `treasury_ties` (ج)
`TreasuryService::position()` يحسب `documented = balance − manual` فالربط **بالتعريف** لا يفشل إلا بقيد يدوي أو خزينة بلا حساب؛ والقيد اليدوي على حساب خزينة **ميزة مدعومة** (E41 البند 2: يفرض نطاق الإيداع والسحب للخزينة عند الترحيل، وشاشة الخزائن تعرضه منفصلًا). وفي E68 كان هذا **افتراضًا للمراجعة** («يظهر فرقًا ويُفشل الفحص حتى يراجعه أحد») مع سؤال مفتوح للمالك (4) ولا طريق لإغلاقه، فبقي أحمر دائمًا بعد أول مصروف يدوي.
**القرار:** `REVIEW` (لا يوقف) يسرد كل خزينة ومقدار ما فيها بقيد يدوي (`data.by_hand` و`data.by_hand_total`، صافيًا بعد ما عُكس)، و**`FAIL` لخزينة بلا حساب** (الخلل الحقيقي الوحيد الذي يستطيع هذا الربط كشفه). **هذا يغيّر افتراض E68 (4) بتقدير هذه الحزمة: أنبّه المنسّق** كي يُسجَّل أو يُرفض؛ لم أعدّل `decisions.md` (ملف مشترك).
**اختبارات** (`TieChecksTest`): REVIEW ويقول المبلغ، وقيدان يصافيان صفرًا ← PASS، وخزينة بلا حساب مع أخرى فيها قيد يدوي ← FAIL يسرد الاثنتين، وخزينة معطَّلة تُعدّ.

### 2.4 الدليل الشامل
`AccountingReadinessTest::testRun_ExitsZeroAfterAChequeBouncesAfterItsDepositAFirstExtraTreasuryIsCreatedAndAJournalIsWrittenByHandOnATreasury` يجري الثلاث بالخدمات الحقيقية (استلام شيك ثم إيداعه ثم رفضه، وبيع نقدي بعد إنشاء الخزينة الإضافية، وقيد يدوي على الخزينة الرئيسية) ويطلب رمز 0 و`pass/pass/review`. **شغّلتُه على الشيفرة السابقة (بنزع تغييرات `app/` مؤقتًا) ففشل**، ونجح بعد الإصلاح.

## 3. R3-B2: `ADMIN-DEFAULT-PASSWORD`
المثبّت يزرع `admin` بتجزئة `$2y$10$…` (`hash_version = 2`) لكلمة منشورة في `README.md`. الفحص (`DeploymentChecks`، المعرّف `admin_default_password`) يتحقق من **كل حساب غير محذوف** بـ`password_verify` (وبـMD5 لـ`hash_version = 1` كما يفعل `Employee::login`)، فيكشف أيضًا حسابًا أُعيدت كلمته إلى الكلمة نفسها بملح آخر. FAIL ويقول **فقط** «the account admin still has the installer password». **الكلمة موجودة في الشيفرة** (`DeploymentChecks::INSTALLER_PASSWORD`) لأن `password_verify` يحتاجها، وهي منشورة أصلًا؛ **لا تُطبع ولا تُكتب في أي ناتج أو JSON أو سجل** (اختبار يفتّش ناتج الفحص والأمر عن الكلمة والتجزئة معًا)، ولا ترد في الوثائق. اختبار يتحقق من أن تجزئة `initial_schema.sql` تطابق الثابت (إن غيّر المثبّت كلمته فشل). التكلفة نحو عُشر ثانية لكل حساب.
**أثر على الاختبارات:** قواعد الاختبار فيها `admin` بالكلمة الافتراضية فيفشل الفحص عليها (صحيح)؛ فصار `InstallerPasswordFixtureTrait` (يغيّر كلمة `admin` ويعيدها) تستعمله `AccountingReadinessTest`. عدد الفحوص **31 ← 33**: `ReadinessRunnerTest` (العدد والترتيب والكلمات في الدليل `ثلاثة وثلاثين فحصًا`) و`AccountingReadinessTest` (عدّ الـJSON)، وصفّان في جدول القسم 2 من الدليل. **لم ألمس متحكمات الموظفين** (شاشة الإلزام عند F1). ملفات: `DeploymentChecksTest` (15 اختبارًا).

## 4. R5-05: `FIRST-RUN-SETTINGS`
REVIEW (لا يفشل أبدًا): يسرد بالاسم ما بقي من قيم المثبّت: `company` و`address` و`phone` و`email` و`return_policy` و`invoice_default_comments` و`quote_default_comments` و`currency_symbol` و`dateformat` و`timezone`، والرقم الضريبي الفارغ `tax_id`، والترقيم المطفأ للفاتورة والإشعار الدائن والفاتورة المرتجعة وعرض السعر والشراء. PASS إن ضُبط الكل. رمز العملة والتاريخ والمنطقة صحيحة لبعض الأنشطة فيبقى ما يقبله المالك في القائمة (مكتوب في الدليل). **قائمة التهيئة الأولى** (كلمة سر `admin` والشركة والرقم الضريبي وسياسة الإرجاع والتعليقات والعملة والخانات والتاريخ والمنطقة بجانبيها `timezone` و`app.appTimezone` والترقيم وأرقام البدء وقوالب الفاتورة والإيصال والضرائب والمواقع والفروع والخزائن والأرصدة الافتتاحية والأدوار والإنتاج) في `docs/ui-redesign/deploy-checklist.md` (قسم جديد في آخره) وربطٌ منها في الدليل؛ واختبار `ReadinessRunbookTest` يطابق أسماء الإعدادات فيها بما يسمّيه الفحص.

## 5. أدوات النسخ والاستعادة (المعيار 6)
`tools/ops/lib.sh` و`backup.sh` و`restore.sh` و`selftest.sh` (bash، بلا أسرار؛ `.gitignore` يستثني `backups/`).
- **الاعتماد:** `MYSQL_HOST_NAME` و`MYSQL_USERNAME` و`MYSQL_PASSWORD` و`MYSQL_DB_NAME` أو `.env` (`database.<مجموعة>.*` بحسب `CI_ENVIRONMENT`)، وتصل إلى `mysql` و`mysqldump` **بملف خيارات مؤقت `0600` في مجلد `0700` يُحذف عند الخروج** (`--defaults-extra-file`)؛ لا في سطر أوامر ولا في ناتج.
- **`backup.sh`:** `mysqldump --single-transaction --routines --triggers --hex-blob` ← gzip، وأرشيف `writable/` (يستثني `cache` و`session` و`debugbar` و`logs`)، ونسخة `.env` بـ`0600`، وبيان `manifest.txt` (الوقت UTC والإصدار git وحالة الشجرة وإصدار الخادم والترميز وعدد صفوف **كل جدول** و`CHECKSUM TABLE` له وSHA-256 لكل ملف) في `DIR/YYYYMMDD-HHMMSS`؛ تُكتب في مجلد مؤقت ثم `mv` (يظهر كاملًا أو لا)؛ `--keep N` (الافتراضي 14، ≥ 1)؛ `--verify` (SHA-256 ثم استعادة إلى قاعدة تجربة `ospos_test_verify_…` أو `--scratch NAME` ومقارنة `CHECKSUM TABLE` والصفوف بالبيان وبالقاعدة الآن ثم حذفها، و`--keep-scratch`)؛ رموز الخروج في رأس الملف وفي الدليل (القسم 6).
- **`restore.sh --yes-empty-database NAME FOLDER [--no-files]`:** بدون الراية رمز 2 ولا يلمس شيئًا؛ يفحص SHA-256 قبل أن يكتب (6)؛ **يرفض قاعدة فيها جدول أو عرض أو إجراء أو تريغر أو حدث (8)** برسالة تقول إن الترحيل التالي سيفشل في منتصفه؛ ينشئ القاعدة إن لم توجد؛ يحمّل ويقارن كل جدول بالبيان؛ يعيد `writable/`؛ **لا يكتب فوق `.env` الحي**؛ ويطبع أمر الجاهزية.
- **التجربة الفعلية على MariaDB 10.11.14:**
  - `selftest.sh` على قاعدة اصطناعية (عربي وإيموجي وعشري وطابع زمني وblob ودالة وتريغر وعرض ومفتاح أجنبي): **49 تأكيدًا نجح، 0 فشل** (`selftest-final.log`): الصلاحيات 0700/0600، محتوى المجلد، البيان، الأرشيف يستثني cache وlogs، نسخة `.env` مطابقة، رفض ملف تالف وجدول مغيَّر في البيان (6) واسم الجدول، حذف قاعدة التجربة حتى عند الفشل، رفض استعادة بلا راية (2) وفوق قاعدة غير فارغة (8)، الاحتفاظ بأحدث 3 من 5، **هدم القاعدة ثم استعادتها وتطابق `CHECKSUM TABLE` لكل جدول وعدد الكائنات والبيانات (blob والعربي والطابع) والملفات**، عدم الكتابة فوق `.env`، قراءة `.env` عند غياب المتغيرات، **لا كلمة سر في أي ناتج ولا في أي سطر أوامر لـ`mysql` و`mysqldump`** (مغلّفان يسجّلان الوسائط).
  - **الاختبار ينكشف بالتخريب:** أربع تخريبات لنسخ من الأداتين (إلغاء رفض غير الفارغة ← فشل تأكيدان، إلغاء الاحتفاظ ← تأكيد، إلغاء فحص SHA-256 ← تأكيدان، تمرير كلمة السر في `--password` ← تأكيد «لا كلمة سر في سطر أوامر») كُشف كلٌّ منها بتأكيد فاشل باسمه؛ وتخريب خامس (طباعة متغير غير معرَّف) أسقط التنفيذ فلا أعدّه.
  - **قاعدة OSPOS حقيقية المخطط** (`ospos_test_f5_src`: 164 جدولًا من الترحيلات ومعها صفوف اصطناعية: 25 عميلًا و60 صنفًا و40 بيعًا ودفعاتها و30 قيدًا وblob): نسخة بـ`--verify-after` (ناجحة، الـ164 مطابقة)، **حذف القاعدة، استعادتها**، فمقارنة `CHECKSUM TABLE` وعدد الصفوف لكل جدول: **متطابقة (329 سطرًا)** (`drill.log`)، ثم `php spark accounting:readiness` على المستعادة (يعمل؛ يفشل `LEDGER-PERIODS` لأن قيودي الاصطناعية بلا سنة مالية).
- **لم يُجرَّب:** MySQL 5.7/8 (مثلًا تحذير `mysqldump` 8 بلا `PROCESS`)، قاعدة كبيرة (الزمن)، متجر مفتوح أثناء النسخة، أعمدة `FLOAT` بقيم (R1-19: جدول `sales_reward_points` بلا صفوف في تجربتي)، `rsync` والنسخ البعيد (نص فقط)، وتشغيل `cron` فعليًا.

## 6. بند 5 من المهمة
- **(أ) الترحيل بالويب:** `MigrationLock` (`GET_LOCK` باسم يحوي هاش اسم القاعدة، **ينتظر 3 ثوانٍ**، ويُرفع في `finally` وعند انقطاع الاتصال) و`MigrationPreflight` (R1-08) و`WebMigration` (الفحص ثم القفل؛ أي خطأ ← عبارة تسمّي `writable/logs` و`php spark migrate -n App` بلا رسم تتبّع ولا نص الاستثناء، وسطر في السجل)، وتستعملها `Login::index` (POST) و`Login::migrate`. الحالات: فحص E38 أو قفل مشغول أو خطأ ← **503** بصفحة الدخول ورسالة (و`Retry-After`) أو JSON، **ولا ترحيل**. الرسائل بالإنجليزية في الشيفرة (مثل رسائل `migrate()` القائمة)؛ **لم أضف مفاتيح لغة** (46 ملفًا تتقاطع مع عمل غيري على `Login`) والرسالة موجّهة لمن يشغّل الخادم. ملف الواجهة `app/Views/login.php`: تنبيه إضافي واحد.
  اختبارات: `MigrationLockTest` (8: يُشغَّل ويُرفع، يرفض والقفل مأخوذ، ينتظر الثواني، يُرفع عند الاستثناء، قفل قاعدة أخرى لا يمنع، طول الاسم)، `MigrationPreflightTest` (6)، `WebMigrationTest` (5)، `LoginTest` (5 جديدة: قفل مشغول لـ`/login` و`/migrate`، قرار E38 غير محسوم لـ`POST /login` و`GET /login` و`/migrate`، كلها تؤكد أن **لا ترحيل** عاد إلى جدول `migrations`). **فشل الاختباران الأولان على الشيفرة السابقة** (200/500 بدل 503 وانتقل ترحيل).
- **(ب) `.env` للإنتاج، (ج) الصلاحيات، (هـ) ماذا أفعل إن:** الأقسام 5 و7 من الدليل. **ملاحظتان تخالفان صياغة المهمة:** (1) **`logger.threshold = 0` في CodeIgniter 4 لا يكتب كل شيء بل يكاد يطفئ السجل** (`range(1, 0)` في `Logger::__construct` ← مستوى الطوارئ وحده) و`9` هو «كل شيء»؛ كتبتُ الحقيقة (اتركه غير مضبوط أو `4`). (2) **`app.baseURL` في `.env` لا أثر له في هذا الإصدار** (`Config\App::__construct` يحسبه من المضيف المدرج و`HTTPS=on` أو `FORCE_HTTPS=true`)، فكتبتُ `FORCE_HTTPS` و`app.forceGlobalSecureRequests` و`cookie.secure` و`app.allowedHostnames` و`app.appTimezone`.
- **(د) خيارات `spark` بمسافة:** راجعتُ الدليل والمطبوع وقائمة النشر: **لا `--option=value` في أي أمر**، والخطوة 7 بمسافة؛ وأضفتُ سطرًا في القسم 5.

## 7. R1-01 وR1-08 وR1-09 وR1-10
- **R1-01:** `accounting:stamp_payments` و`accounting:bank_accounts` يفحصان `SchemaProbe` على `PaymentTreasuryStamping::REQUIRES` و`BankAccountPlacement::REQUIRES` (ثابتان جديدان يستعملهما فحص `payment_stamping` أيضًا) ويطبعان «Not available yet: the database does not have … run "php spark migrate -n App" first» **ويخرجان بـ1 قبل أن يقرآ أو يكتبا**. 4 اختبارات (جدول القيود مفقود، عمود بيانات البنك مفقود، جدول التوجيه مفقود مع `--move`، عمود الخزينة في دفعات الموردين مفقود مع `--apply` فلا يوسم شيء): أخطأت (استثناء) ثم نجحت. نص الدليل: الخطوة 3 تقول إنهما لعلاج ما يعرضه التقرير بعد الترحيلات.
- **R1-08:** الفحص المسبق (انظر 6 أ) يقرأ `LegacyPurchaseDiscount::plan()['blocked']` **قبل أن يبدأ أي ترحيل** إن لم يكن `LegacyFixedPurchaseDiscount` مطبَّقًا، ويقول «N purchase line(s) … cannot be told apart … Nothing was migrated. Run "php spark accounting:purchase_discount" … resolve … then "php spark migrate -n App"» **بلا رقم مستند ولا سطر ولا تاريخ** (الصفحة عامة). الدليل: الخطوة 3 والخطوة 4.
- **R1-09:** القفل بالانتظار القصير وصفحة «Another request is migrating this database» (503)؛ والدليل يقول إن الطلب عبر الويب تقطعه مهلة البوابة (86 ثانية قيست على 10× بيانات) فتُشغَّل الترحيلات من سطر الأوامر قبل فتح الموقع.
- **R1-10:** `restore.sh` يرفض غير الفارغة (كان كذلك) وصارت رسالته تقول: «ترحيل لاحق يفشل في منتصفه (الترحيل 59 `invoice_date` و58 من 116)» ويؤكدها `selftest.sh`؛ والدليل (الخطوة 8 والقسم 7) يقولها بدقة ويوصي بالاستعادة **في قاعدة فارغة فقط**.

## 8. ما شُغِّل
- `tests/Libraries/Accounting/Readiness` كلها (يشمل `Wave3ChecksTest` و`ClockChecksTest` و`ReadinessRunnerTest` و`ReadinessRunbookTest` و`DeploymentChecksTest`) + `tests/Commands/AccountingReadinessTest` و`AccountingReadinessCompareTest` و`AccountingStampPaymentsTest` و`AccountingBankAccountsTest`: **443 اختبارًا، 1962 تأكيدًا، بلا فشل** (34 دقيقة: الجهاز مشترك)، `phpunit-final-1.log`.
- `tests/Controllers/LoginTest` و`tests/Libraries/MigrationLockTest` و`MigrationPreflightTest` و`WebMigrationTest` و`tests/LanguageFilesTest` و`DeploymentChecksTest`: **3854 اختبارًا بلا فشل** (`phpunit-final-2.log`).
- بعد دمج `int/r1` الثاني (R4): `LedgerChecksTest` و`ReadinessRunnerTest` و`ReadinessRunbookTest` و`AccountingReadinessTest`: **118 اختبارًا، 482 تأكيدًا، بلا فشل** (`phpunit-after-merge.log`).
- `tools/ops/selftest.sh`: 49 تأكيدًا نجح (`selftest-final.log`).
- لم أُشغّل المجموعة الكاملة.

## 9. ما لم أستطع التحقق منه أو تركتُه
1. لم أر صفحة الدخول بالمتصفح (اختبارات HTTP وعرض الصفحة فقط؛ لا لقطة).
2. الفحص المسبق يغطي E38 وحده (الوحيد المعروف الذي يحتاج المشغّل)؛ غيره يظهر العبارة العامة بعد أن يكون الترحيل قد بدأ.
3. `GET_LOCK` قفل **اتصال**: خلف وسيط يعيد استعمال الاتصالات (ProxySQL بتعدد إرسال) لا يُضمن (استنتاج، لم أجرّبه). أمر `php spark migrate` نفسه لا يأخذ القفل.
4. **المنسّق:** (أ) أقرّوا أو ارفضوا تعديل افتراض E68 (4) (القيد اليدوي على الخزينة REVIEW)؛ (ب) أنا لم أعدّل `decisions.md`؛ (ج) تعديلي في `app/Controllers/Login.php` و`app/Views/login.php` صغير لكنه قد يتقاطع مع عمل F1/F3 عند الدمج؛ (د) `data.stamp` في `payment_stamping` صار «المنجرف فقط» فمن يقرأ التقارير القديمة بـ`readiness_compare` يرى تغيّر القيمة بين تقريرين من إصدارين.
5. **حادثة:** `git stash` **مشترك بين كل أشجار العمل لنفس المستودع**؛ استعملتُه مرة للتحقق من أن اختبارًا يفشل على الشيفرة السابقة فتبادلتُ ومعي F4 تغييراتنا لثوانٍ؛ **عاد كل شيء** (قارنتُ الفروق بأمر `cmp` فكانت مطابقة) ولم أستعمله بعدها (استعملتُ `git apply -R` ونسخ الملف). على من يختبر «قبل الإصلاح» ألا يستعمل `stash`.
6. السلسلة الإنجليزية «Another request is migrating…» لا ترجمة لها (قرار مذكور في 6 أ).

## 10. الالتزامات
`c3dd4fa` (الفحوص والقفل والأدوات ونصوص الدليل الأولى)، `a0ecd70` (دمج `int/r1` الأول)، `3e3c6d5` (R1-01 وR1-08 وR1-09 وR1-10 والأقسام 5 إلى 7 وقائمة التهيئة)، `481123f` (هذا التقرير)، `dedd653` (دمج `int/r1` الثاني: شيفرة R4 في `LedgerChecks`).
