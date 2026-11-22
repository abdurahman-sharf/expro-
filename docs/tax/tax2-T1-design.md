# TAX2 — تصميم المرحلة T1: الهوية الضريبية للمنشأة والأمان الأساسي

| البند | القيمة |
|---|---|
| الحالة | **تصميم للاعتماد. لا كود ولا ترحيل قبل موافقة المالك على بدء T1.** |
| يعتمد على | D-01 وD-04 وD-05 (معتمدة)، وD-02 وD-03 وD-27 (معلقة، انظر [سجل القرارات](tax2-decisions.md) §3) |
| الفرع المقترح | `wp/TAX2-T1` من `origin/master`، ويُدمج فقط بأمر صريح |
| المبدأ الأعلى | **نشر T1 لا يغيّر سلوك أي تركيب قائم.** كل قيد جديد لا يسري إلا بعد أن يفعّل المالك الوضع السعودي صراحة، وهو مطفأ افتراضيًا. والمنشأة غير المسجلة لا تُطالَب بشيء (D-04) |

## 1. النطاق

### 1.1 داخل T1

1. **ملف الهوية الضريبية للشركة** (`company_tax_profile`)، مصدرًا واحدًا لـ:
   - «مسجلة في ضريبة القيمة المضافة» (نعم/لا/غير محدد)
   - الرقم الضريبي
   - الوضع السعودي وتاريخ تفعيله

   مع مرآتين تُكتبان في المعاملة نفسها: `app_config.tax_id` و`companies.tax_id` (D-03).
2. **خدمة كتابة واحدة** `CompanyTaxProfile`. كل كتابة للرقم الضريبي (`/config` و`/setup`) تمر بها. لا كاتب آخر.
3. **التحقق من الصيغة** (BR-KSA-40: 15 رقمًا يبدأ وينتهي بـ 3) **في الوضع السعودي أو حين تختار المنشأة «مسجلة»**. خارجهما يبقى سلوك اليوم: تنقية الأرقام فقط.
4. **الصلاحيات (D-05):**
   - في الوضع السعودي: تعديل الرقم الضريبي وبيانات البائع الحساسة يشترط `config` **و**`acc_einvoicing_manage` معًا.
   - صلاحية جديدة `acc_einvoicing_onboard` مسجلة وحساسة وغير ممنوحة، يستخدمها T1 لإطفاء الوضع بعد وجود مستندات، وT10 لاحقًا للتسجيل.
5. **سجل التدقيق** لكل تغيير في: الرقم الضريبي، وحالة التسجيل، والوضع السعودي وتاريخه، واسم البائع (`company`)، وبيانات طرف الفوترة للعميل (`customer_einvoice_parties`). القيمة قبل التغيير وبعده، والموظف، والسبب إن طُلب.
6. **مفتاح الوضع السعودي:**
   - التفعيل صريح، والتاريخ لا يسبق لحظة التفعيل.
   - الشروط:
     - المحاسبة مفعلة (D-04).
     - المنشأة «مسجلة».
     - الرقم الضريبي صحيح.
   - الإطفاء:
     - قبل أي مستند صادر بعد التفعيل: بالصلاحيتين.
     - بعد وجود مستند: بـ `acc_einvoicing_onboard` وسبب وتدقيق (F-04).
     - **في T1 لا يوجد مسار إصدار جديد بعد**، فلا مستندات «سعودية». القاعدة تُبنى وتُختبر الآن.
7. **قفل الرقم الضريبي بعد أول مستند** (`einvoice_documents` لهذه الشركة): التغيير يتطلب تأكيدًا وسببًا مسجلًا، ولا يُمنع (المنع يأتي مع الشهادات في T9).
8. **حجب الأسرار:** `#[\SensitiveParameter]` على المعاملات التي تحمل مسار المفتاح أو محتواه أو كائن المفتاح في `StampKeys` و`EcdsaSigner` و`DocumentStamper`، واختبار أن الاستثناءات وسياقها لا تحمل مادة المفتاح.
9. **فحص النشر للمنشأة غير المسجلة:** `DeploymentChecks` يحذّر اليوم إن كان `tax_id` فارغًا (`DeploymentChecks.php:92-95`). مع «غير مسجلة» يصبح ذلك **سليمًا** لا تحذيرًا.
10. **عرض الوضع في الواجهة القائمة** (لا مركز إعدادات بعد، فذلك T15):
    - في `/accounting_einvoicing`: بطاقة «الهوية الضريبية والوضع السعودي» للعرض والتفعيل.
    - في `/config` (تبويب الضريبة): حقل الرقم الضريبي كما هو. في الوضع السعودي يُرفض الحفظ لمن لا يملك الصلاحيتين **إن تغيّرت القيمة فقط**.

### 1.2 خارج T1 (صراحة)

التصنيف S/Z/E/O (T2)، والحساب (T3)، والإصدار الآلي (T6)، والطباعة (T7)، والمفتاح الرئيسي والتشفير والوحدات (T9)، ومركز الإعدادات ومسار المعالج السعودي (T15)، وأي تغيير لسلوك الطباعة أو الفواتير.

## 2. البيانات (ترحيل واحد)

### 2.1 جدول `company_tax_profile`

| العمود | النوع | ملاحظات |
|---|---|---|
| `company_id` | INT UNSIGNED PK، FK إلى `companies` | صف لكل شركة. اليوم صف واحد |
| `vat_registered` | TINYINT NULL | NULL = غير محدد (الافتراضي للتركيبات القائمة)، 1 = مسجلة، 0 = غير مسجلة |
| `vat_number` | VARCHAR(64) NULL | المصدر الوحيد. 64 للتوافق مع `companies.tax_id` والأرقام غير السعودية خارج الوضع |
| `ksa_mode` | TINYINT NOT NULL DEFAULT 0 | **مطفأ دائمًا عند الترحيل** |
| `ksa_mode_effective_at` | DATETIME NULL | لا يسبق `ksa_mode_activated_at` |
| `ksa_mode_activated_at` و`ksa_mode_activated_by` | DATETIME NULL، INT NULL | |
| `updated_at` و`updated_by` | | |

- **ملء البيانات عند الترحيل:**
  - `vat_number` = `app_config.tax_id` كما هو (حتى لو غير سعودي).
  - `vat_registered` = NULL، فلا نفترض تسجيلًا.
  - `ksa_mode` = 0.
- **لا تُعدَّل** `app_config` ولا `companies` في الترحيل.
- **`down()`:** يحذف الجدول. القيم باقية في المرآتين، فلا فقد.
- **لا قيود CHECK يُعتمد عليها** (MySQL 5.7). التحقق في الخدمة.

### 2.2 الصلاحية الجديدة

- يُدرَج `acc_einvoicing_onboard` في `permissions` تحت الوحدة `accounting_einvoicing`.
- **لا منح لأحد.**
- `down()` يحذف الصلاحية ومنحها.

## 3. الخدمة `App\Libraries\Tax\CompanyTaxProfile`

| الطريقة | السلوك |
|---|---|
| `current(): TaxProfile` | قراءة لشركة `CompanyContext::id()`. إن لم يوجد صف يُعيد ملفًا مبنيًا من `app_config.tax_id` (ترحيل لم يُشغَّل بعد) |
| `updateVatIdentity(?bool $registered, string $vatNumber, ?string $reason, int $employeeId): array` | (1) لا تغيير ← لا شيء، فيمر الحفظ لأي صاحب `config` كما اليوم. (2) الصيغة تُفحص إن كان الوضع مفعلًا أو `registered = true`. (3) في الوضع: الصلاحيتان. (4) إن وُجد مستند صادر: سبب إلزامي. (5) كتابة الصف والمرآتين في معاملة واحدة. (6) تدقيق `tax_profile`/`vat_identity_changed` بالقبل والبعد |
| `enableSaudiMode(string $effectiveAt, int $employeeId): void` | الصلاحيتان، والمحاسبة مفعلة، و`registered = true`، ورقم صحيح، و`effectiveAt` ≥ الآن. تدقيق |
| `disableSaudiMode(string $reason, int $employeeId): void` | قبل وجود مستند بعد التفعيل: الصلاحيتان. بعده: `acc_einvoicing_onboard` وسبب. تدقيق |
| `isSaudiMode(): bool` | الوضع مفعل **و** `now ≥ effective_at` |

**الكاتبون الحاليون يُوجَّهون إليها:**
- `Config::postSaveTax` (`Config.php:871`): يُزال `tax_id` من `batch_save` ويُمرَّر إلى الخدمة.
- `FirstRun::saveCompany` (`FirstRun.php:217, 419-424`): يكتب عبر الخدمة. والمالك في التثبيت الجديد يملك كل الصلاحيات، والوضع مطفأ، فلا رفض.
- `branches.tax_registration` لا يُمس (D-03).

## 4. الواجهة واللغات

- بطاقة في `app/Views/accounting_einvoicing/manage.php`:
  - الحالة: مسجلة / غير مسجلة / غير محدد، والرقم (للعرض).
  - الوضع السعودي: مطفأ / مفعل منذ.
  - زر التفعيل أو الإطفاء لمن يملك الصلاحية.
  - تنبيه D-27 حين يكون `invoice_type` ضريبيًا والمنشأة «غير مسجلة».
- `/config` تبويب الضريبة: حقل «المنشأة مسجلة في ضريبة القيمة المضافة؟» (ثلاث قيم)، ورسالة الرفض بالسبب.
- مفاتيح لغة جديدة في كل `app/Language/*/`، بترتيب أبجدي: `en` و`en-GB` بالإنجليزية، و`ar-EG` و`ar-LB` بالعربية، والبقية `''`. لا يوجد مجلد `ar-SA` في المشروع، وإضافته قرار مستقل (يُقترح مع T7 أو T15)، لا ضمن T1.

## 5. الاختبارات (ملف واحد لكل صنف، وأسماء `test{Method}_{Behavior}`)

| الملف | جديد/قائم | أمثلة الاختبارات |
|---|---|---|
| `tests/Libraries/Tax/CompanyTaxProfileTest.php` | جديد | `testUpdateVatIdentity_LeavesAnUnchangedNumberToAnyConfigHolder`، `testUpdateVatIdentity_RejectsAMalformedNumberInSaudiMode`، `testUpdateVatIdentity_AcceptsAnyNumberWhenNotRegisteredAndModeOff`، `testUpdateVatIdentity_RequiresBothPermissionsInSaudiMode`، `testUpdateVatIdentity_RequiresAReasonOnceADocumentWasIssued`، `testUpdateVatIdentity_WritesBothMirrorsInOneTransaction`، `testUpdateVatIdentity_AuditsTheChangeWithBeforeAndAfter`، `testEnableSaudiMode_RequiresAccounting`، `testEnableSaudiMode_RequiresARegisteredBusinessWithAValidNumber`، `testEnableSaudiMode_RejectsAnEffectiveDateInThePast`، `testDisableSaudiMode_RequiresTheOnboardPermissionOnceADocumentExists`، `testIsSaudiMode_RequiresTheEffectiveDateToHaveCome` |
| `tests/Database/CompanyTaxProfileMigrationTest.php` | جديد | `testUp_CopiesTheTaxIdAndLeavesTheModeOff`، `testUp_LeavesRegistrationUnknown`، `testDown_KeepsTheNumberInTheSettings`، `testUp_RegistersTheOnboardPermissionWithoutGrantingIt` |
| `tests/Controllers/ConfigTest.php` | قائم | `testPostSaveTax_SavesAsTodayWhenSaudiModeIsOff`، `testPostSaveTax_RejectsAChangeOfTheVatNumberWithoutBothPermissionsInSaudiMode`، `testPostSaveTax_SavesOtherTaxSettingsWhenTheVatNumberIsUnchanged` |
| `tests/Libraries/Setup/FirstRunTest.php` | قائم | `testSaveCompany_WritesTheVatNumberThroughTheProfile` |
| `tests/Libraries/Accounting/AccountingGuardTest.php` | قائم | `testSensitive_IncludesTheOnboardPermission` |
| `tests/Libraries/Roles/RoleGrantsTest.php` | قائم | `testRefused_IncludesTheOnboardPermission` |
| `tests/Libraries/Accounting/Readiness/DeploymentChecksTest.php` | قائم | `testCheck_AcceptsAnEmptyTaxIdForABusinessThatIsNotRegistered` |
| `tests/Libraries/EInvoicing/CustomerPartiesTest.php` | قائم | `testSave_AuditsAChangeOfTheParty` |
| `tests/Libraries/EInvoicing/StampKeysTest.php` | قائم | `testLoad_KeepsTheKeyOutOfTheExceptionThatRefusesIt` |
| `tests/Controllers/AccountingEInvoicingControllerTest.php` | قائم | `testPostSaudiMode_EnablesForAHolderOfBothPermissions`، `testPostSaudiMode_RefusesWithoutAccounting`، `testGetIndex_ShowsTheTaxIdentityCard` |
| **انحدار** | — | **كل** ملفات الضريبة والفوترة الـ 51 التي شُغّلت في TAX1، و`composer test` كاملًا: نجاح دون تغيير في التوقعات القائمة |

## 6. معايير القبول

1. **أثر صفري على التركيبات القائمة:** بالوضع مطفأ، كل اختبار قائم ينجح دون تعديل توقعاته. ويُثبت بمقارنة استجابات `/config` و`/setup` قبل وبعد.
2. **كاتب واحد:** اختبار يبحث في `app/` عن أي كتابة لـ `tax_id` خارج الخدمة ويفشل إن وجدها.
3. المرآتان تساويان المصدر بعد كل كتابة، وفي معاملة واحدة.
4. كل تغيير في الحقول الحساسة له صف تدقيق بالقبل والبعد.
5. `acc_einvoicing_onboard` في `SENSITIVE`، ومرفوضة في الأدوار، وغير ممنوحة في أي ترحيل.
6. المنشأة غير المسجلة: لا تحذير نشر، ولا تحقق صيغة، ولا قيد جديد.
7. `composer test` كامل ناجح، و`php-cs-fixer` نظيف، ومفاتيح اللغات كاملة ومرتبة.
8. تقرير المرحلة `docs/tax/tax2-T1-report.md` وملاحظة نشر.

## 7. التراجع

- إطفاء الوضع: لا أثر قبل التفعيل أصلًا.
- `down()` للترحيل: يحذف الجدول والصلاحية، والقيم باقية في المرآتين.
- إرجاع الفرع: لا بيانات أخرى تغيّرت.

## 8. ما لا يُغيَّر في T1

المبيعات والقيود والمستندات الصادرة وطباعتها وQR، وصلاحيات أي مستخدم خارج الوضع السعودي، و`RecordScope`، و`branches.tax_registration`، ومعالج `/setup` (إلا توجيه الكتابة إلى الخدمة)، والقيم الافتراضية المصرية للمعالج.

## 9. المخاطر

| الخطر | التخفيف |
|---|---|
| 16 ملفًا يقرأ `app_config.tax_id` | المرآة تبقى. لا قارئ يتغير في T1 |
| حفظ `/config` يرسل كل الحقول دائمًا | القيد يسري على **التغيير الفعلي** للرقم فقط |
| `vat_registered = NULL` للتركيبات القائمة | لا قيد قبل أن يختار المالك. والتفعيل يشترط «مسجلة» صراحة |
| المالك في التثبيت الجديد يملك `onboard` (`grantEverything`) | موثق ومقبول (D-05) |

## 10. التقدير

M: 5 إلى 8 أيام عمل، كما في الخطة. المرحلة أصغر قليلًا لأن المفتاح الرئيسي وحجب السجلات الكامل انتقلا إلى T9.
