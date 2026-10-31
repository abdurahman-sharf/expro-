# تقرير WP6: إقفال نهاية السنة بقيد لكل فرع بمعاينة واعتماد وعكس وحراس (YE-01 وY5 وYE-03 وG-82)

> الحالة (2026-10-31): **نُفِّذ على الفرع `wp/WP6`** (المنشأ من الالتزام `4ae7802`) بتفويض المالك التنفيذي الشامل (E47): خدمة **إقفال السنة المالية** تُعاين ثم تعتمد **قيدًا واحدًا لكل فرع ولكل سنة** ينقل ما في حسابات الإيرادات والمصروفات في السنة إلى حساب الأرباح المحتجزة المأخوذ من **التوجيه** (`fiscal.retained_earnings`، وهو الحساب `32` افتراضيًا) بلا انتقال ربح بين الفروع، ويُعكس ولا يُعدَّل ولا يُحذف، بصلاحية **حساسة** جديدة `acc_year_end_close` لا يمنحها الترحيل لأحد، وشاشة «إقفال نهاية السنة» تحت وحدة الفترات والإقفال، وخيار «قبل قيد الإقفال / بعده» في ميزان المراجعة (الافتراضي **قبل**)، ووسم «إقفال نهاية السنة» في كشف الحساب، وتعريف **واحد** لقيد الإقفال (نوعه `year_end`) يُستثنى به من قائمة الدخل ويُدخل في الميزانية. **ترحيل واحد إضافي (جدول وصلاحية)، ولا يمسّ قيدًا ولا فترة ولا رصيدًا قائمًا. لا كتابة في دفترة، ولا دمج في `master`، ولا نشر، ولا تشغيل على قاعدة حقيقية.** قرار المالك `Y-DEC` **ما زال مفتوحًا**: اخترتُ القراءة المحافظة للسياسة ووثّقتُها افتراضًا (القسم 4) ويأتي حساب الأرباح المحتجزة من التوجيه فيغيّره المالك بلا كود. **سلوك دفترة في الإقفال (Y9) لم يُرصد (الدفعة E في الطابور): لا أدّعي تطابقًا معها.**

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الخدمة** `YearEndClosing` (وكائنات القيمة `YearEndPreview` و`YearEndBranchPlan` و`YearEndLine` و`YearEndBranchStatus`) | **معاينة** (`preview`) لا تكتب شيئًا: لكل فرع من فروع التنفيذ حسابات الإيرادات والمصروفات التي لها رصيد في السنة، وبالاتجاه المعاكس لرصيدها، والفرق إلى الأرباح المحتجزة (ربح دائن، وخسارة مدينة)، وما يمنع الاعتماد. **اعتماد** (`approve`) يقبل **بصمة المعاينة** (`fingerprint`) إلزاميًا: يُكتب قيد كل فرع جاهز **بالأرقام التي رآها المعتمِد وحدها**، وإن تغيّرت الدفاتر بعد المعاينة رُفض بـ`year_end_preview_changed`. **عكس** (`reverse`) بسبب إلزامي: يُكتب قيد معاكس بتاريخ القيد نفسه فتعود كل التقارير لكل يوم كما كانت، ويبقى الصف والقيدان. التنفيذ لفرع واحد أو للشركة (كل الفروع في معاملة واحدة، **كلها أو لا شيء**)؛ وفرع بلا حركة أو بحسابات متعادلة لا يُنشأ له قيد، ونتيجة صفر لا تكتب سطر أرباح محتجزة. تُقرأ **حركة السنة** (التاريخ من أول يوم في السنة إلى آخره، بلا قيود الإقفال) لا الرصيد المتراكم منذ البدء |
| **المفتاح الفريد والتزامن** | جدول `accounting_year_end_closings` فيه مفتاح فريد على (شركة، فرع، سنة، `active_key`) حيث `active_key` يساوي 1 للقيد الفعّال و`NULL` للمعكوس، فللفرع قيد فعّال واحد للسنة وأي عدد من المعكوسة، ويلزمه قيد فحص (`CHECK`) يربط الحالة بالمفتاح (بمقارنة آمنة للقيمة الفارغة `<=>`، وهذا عيب وجدته باختبار الترحيل: قيد فحص بـ`=` يمرّر فعّالًا بلا مفتاح). ومع المفتاح: **قفلة الفرع** (`PeriodLocks::holdBranch`) تؤخذ قبل أي قراءة، **وفروع تشغيل الشركة تُقفل بترتيب أرقامها** فلا جمود. وكل قراءة بعد القفل (القيد الفعّال وإقفال فترات الشركة) **قراءة مقفولة** فترى ما التزم به غيرها بعد لقطة المستدعي |
| **القيد في فترة مقفلة (الاستثناء الموثَّق)** | يُكتب قيد الإقفال بتاريخ آخر يوم من السنة وفترات الشركة مقفلة حتى ذلك اليوم **(شرط مسبق)**. الاستثناء **دالة واحدة** `PostingService::postYearEnd`: تتجاوز إقفال الفترات وحدها (وتقبل حسابًا أو فرعًا عُطِّلا بعد الترحيل لأن أرصدتهما ما زالت تُقفل) وتُبقي كل ما عداه (توازن القيد وحساب المجموعة وتاريخ ضمن سنة مالية والمفتاح الفريد وقفلات الفترات المشتركة). و`post()` **ترفض** أي قيد نوعه أو مصدره `year_end` فلا يكتب هذا القيد غير الإقفال (واختبار يفحص أن لا نداء لـ`postYearEnd` في `app/` إلا من الخدمة)، والعلم يُرفع في `finally`. ويُكتب **تدقيق** `year_end_closed` فيه `closed_period_exception` وإلى أي تاريخ كانت الفترة مقفلة، وتدقيق القيد نفسه `posted` |
| **التعريف الواحد (G-82)** | `YearEndEntries::leaveOut` صار يعرّف قيد الإقفال **بنوعه `year_end` وحده** (حالة جديدة `JournalEntryType::YearEnd`)، في موضع واحد، مع **اتباع سلسلة العكس** كما في WP4 (عكس الإقفال وعكس عكسه وبديله). المصدر `year_end` اسم المستند (مفتاح القيد الفريد) ولا يميّز شيئًا. فيحمل قيد الإقفال **وعكسه** النوع نفسه، وتحدّه الخدمة وحدها |
| **الصلاحية الحساسة** (Y5) | `acc_year_end_close` في `AccountingGuard::SENSITIVE`، تحت وحدة `accounting_periods`، **يمنحها الترحيل لأحد بلا استثناء** (يمنحها المدير باسم الموظف من شاشة الموظفين، وتظهر بوسم «حساسة» وتُقفل لغير المدير وتُسجَّل في التدقيق، وفي بطاقة حاملي الصلاحيات الحساسة بالاسم). لها مفاتيح لغة خاصة (`Accounting_periods.year_end_close`). تتطلبها المعاينة والاعتماد والعكس. وفوقها قواعد الفروع: **تنفيذ الشركة يتطلب كل الفروع** (E45)، وتنفيذ فرع يتطلب أن يعمل الموظف فيه |
| **الشروط المسبقة** | إقفال فترة **للشركة** (لا لفرع) حتى آخر يوم من السنة **أو بعده**، **ولا مسودات في السنة** في فروع التنفيذ (مسودة سنة أخرى أو فرع آخر لا تمنع)، وحساب أرباح محتجزة صالح (التوجيه نفسه يرفض حسابًا مفقودًا أو مجموعة أو معطَّلًا، والخدمة تشترط أن يكون من حقوق الملكية)، وقراءة آمنة لكل ذلك تحت القفل. وكل مانع يظهر **في المعاينة** نصًّا ويمنع الاعتماد برمزه |
| **منع ما يُفسد الإقفال** | `ClosedPeriodService::reopen` يرفض إعادة فتح فترة **تترك سنة مقفلة بقيد إقفال فعّال غير مغطّاة** (`year_end_closed_out` باسم السنة وتاريخها) حتى يُعكس القيد؛ ويقبل إعادة فتح إقفال لاحق ما دام إقفال سابق يغطيها، وإعادة فتح إقفال فرع ما دامت إقفالات الشركة أو فرع ذاته المتبقية تغطيها. و`JournalService::reverseOpening` (المنفذ الوحيد لعكس الأرصدة الافتتاحية الماليّة والمخزنية) يرفض عكس افتتاحي **تاريخه داخل سنة مقفلة** لفرعه، **بعد أخذ قفلة الفرع** فينتظر إقفالًا يُكتب في تلك اللحظة ويراه. و`FiscalYearService::update` يرفض تحريك تواريخ سنة مقفلة (الاسم والوصف يتغيّران). و`JournalService::reverse` (شاشة القيود) يرفض عكس قيد إقفال (`year_end_journal_locked`) ويحيل إلى شاشة نهاية السنة |
| **التقارير** | **ميزان المراجعة:** خيار `closing` (`before` الافتراضي و`after`) في المكتبة (`TrialBalanceClosing`) والشاشة: قبل = بلا قيود الإقفال وعكسها فتظهر حسابات السنة بأرصدتها؛ بعد = معها فتظهر أصفارًا والأرباح المحتجزة بنتيجتها، والميزان متوازن في الحالتين. **كشف الحساب:** كل سطر نوع قيده `year_end` يحمل وسم «إقفال نهاية السنة» (قيد الإقفال وعكسه)، ولا يُستثنى من الكشف. **قائمة الدخل:** تستثنيه (WP4)، فتقرأ السنة نفسها قبل الإقفال وبعده وبعد عكسه، وكل فترة تُبنى بلا قيد إقفال فمقارنة سنتين (كل واحدة بـ`IncomeStatement::build`) لا تحمل قيد الإقفال. **الميزانية:** تُدخله، والمعادلة قائمة قبله وبعده لفرع واحد ولفروع ولكل الشركة، ويبقى مجموع حقوق الملكية كما هو وينتقل «غير المرحلة» إلى `32` |
| **الشاشة** | تبويب «إقفال نهاية السنة» في شاشات الفترات (`accounting_periods/year_end`): اختيار السنة (السنوات المنتهية) والنطاق (الشركة أو فرع من فروع الموظف)، ثم **جدول المعاينة لكل فرع** (الحالة: جاهز أو لا شيء للإقفال أو مقفل بالفعل، وأسطر القيد بمدينها ودائنها وسطر الأرباح المحتجزة، وإيراد ومصروف وربح أو خسارة)، وموانع الاعتماد، وزر **«اعتماد إقفال السنة»** يفتح نافذة تأكيد فيها **بصمة ما عُرض** وخانة إقرار وتحذير وبـ`form_open` (رمز CSRF يُضاف حين تعمل الحماية، وهي عامة على كل المسارات عدا `login|migrate`)، وجدول **قيود الإقفال** (السنة والفرع والنتيجة والقيد والحالة ومن أنشأه ومن عكسه وسببه) وزر **عكس** لكل قيد فعّال بنافذة سبب. **المسارات التي تغيّر الحالة `POST` وحدها** (الاعتماد والعكس) والباقي `GET` قراءة. من يملك الوحدة بلا الصلاحية يقرأ القيود ويرى سطر «تحتاج صلاحية إقفال السنة» ولا يرى معاينة ولا أزرار. لغات: 46 لغة (الإنجليزية والعربية الفعليتان لـ`en` و`en-GB` و`ar-EG` و`ar-LB`، والباقي فارغ فيرجع للإنجليزية) |
| **الملفات** | `app/Libraries/Accounting/` : `YearEndClosing` و`ClosedOutYears` (القراءة: أي فرع أقفل أي سنة) و`YearEndBranchPlan` و`YearEndLine` و`YearEndPreview` و`YearEndBranchStatus` و`TrialBalanceClosing`، والمعدَّل منها بتغيير صغير `PostingService` (`postYearEnd` والعلم) و`PeriodGuard` (`enforceYearEnd`) و`JournalService` (عكس قيد الإقفال وعكس الافتتاحي) و`ClosedPeriodService` و`FiscalYearService` و`YearEndEntries` و`TrialBalance` و`TrialBalanceReport` و`AccountLedger` و`AccountingGuard` و`AccountingError` (11 رمزًا) و`JournalEntryType`؛ `app/Controllers/AccountingYearEnd.php` وتعديلات صغيرة في `AccountingReports` و`AccountingSetup` و`AccountingJournals`؛ الترحيل `20270900000000_YearEndClosings`؛ العروض `year_end` و`year_end_approve_form` و`year_end_reverse_form` وتعديل `tabs` وميزان المراجعة وكشف الحساب؛ المسارات في `Routes.php` (خمسة، إضافية) |
| **ما لم يتغير** | نموذج الفترات المقفلة وإعادة فتحها بلا قيد إقفال، وأي قيد ترحيل آلي، وتقرير الميزانية وقائمة الدخل (إلا الاستثناء الذي في WP4 نفسه)، والتدفق النقدي (لا قيد إقفال يمس النقدية، لم يُنفَّذ بعد). لم يُدخَل في الإقفال: ضريبة القيمة المضافة (حسابات ميزانية)، والسحوبات وتوزيعات الأرباح (حقوق ملكية، بقيد يدوي مستقل: Y4)، ومراكز التكلفة (أسطر الإقفال بلا مركز) |

## 2. الأدلة

- **اختبارات جديدة (114 دالة في ثلاثة ملفات):** `YearEndClosingTest` (71 اختبارًا: المعاينة والاعتماد والعكس والقوائم والقبول والسباق)، و`AccountingYearEndControllerTest` (31 اختبارًا للشاشة: الصلاحية والنطاق والتأكيد والبصمة والرمز والمسارات POST)، و`YearEndClosingsMigrationTest` (12 اختبارًا: الجدول والمفتاح الفريد وقيد الفحص والمفاتيح الخارجية والصلاحية بلا منح، وأن الترحيل لا يمس قيدًا).
- **اختبارات الحالات السبع في `year-end-policy.md` القسم 8:** (1) المعادلة قبل الإقفال وبعده لفرع واحد ولفروع وللشركة: `testAcceptance_TheBalanceSheetBalancesBeforeAndAfter…` (وقيمة حقوق الملكية لكل نطاق، ونتيجة السنة التالية بعد الإقفال)؛ (2) قائمة الدخل لسنة منتهية متطابقة قبل القيد وبعده، ومقارنة سنتين: `…TheIncomeStatementOfTheYearReadsTheSame…` و`…OfTwoYearsLeavesOutTheClosingOfTheFirst`؛ (3) **إقفالان متزامنان ينتجان قيدًا واحدًا لكل فرع** باتصالين حقيقيين (`RaceWorker`: `testAcceptance_TwoRunsAtTheSameMomentWriteOneClosingForEachBranch` للشركة و`…TwoRunsOfOneBranch…` لفرع)، وطلب يصل أثناء إقفال آخر **ينتظر ثم يُرفض «مقفل بالفعل»** (`testApprove_MakesARequestThatArrivesWhileAnotherIsClosingWait…`)؛ (4) الرفض بلا إقفال فترة للشركة (وإقفال فرع لا يكفي، وإقفال ناقص بيوم) وبمسودة (في السنة وفروع التنفيذ وحدها) وبلا صلاحية؛ (5) إعادة الفتح مرفوضة ثم مقبولة بعد العكس (`ClosedPeriodServiceTest` و`AccountingClosingsControllerTest`)، وعكس الافتتاحي (`OpeningBalanceServiceTest`)، وتواريخ السنة (`FiscalYearServiceTest`)، وعكس القيد من شاشة القيود (`JournalServiceTest`)؛ (6) موظف محدود النطاق لا يشغّل إقفال الشركة ولا يرى قيود فروع غيره؛ (7) الخسارة والأرصدة المعاكسة والفرع الفارغ والسنة بلا حركة ونتيجة الصفر والحسابات المتعادلة والحساب والفرع المعطَّلان.
- **ما أُضيف إلى ملفات موجودة (44 دالة):** `PostingServiceTest` (13: رفض القيد من النوع أو المصدر، والكتابة في فترة مقفلة، وحسابات وفروع معطَّلة، وتعطيل العلم عند الرجوع، وقفلات الفترة، وأن لا نداء إلا من الخدمة) و`ClosedPeriodServiceTest` (7) و`OpeningBalanceServiceTest` (5 منها اختبار بعامل منفصل ينتظر إقفالًا يُكتب، واثنان لسنة مقفلة قبل الافتتاحي أو بعده) و`JournalServiceTest` (3) و`FiscalYearServiceTest` (1) و`TrialBalanceTest` (5: الافتراضي قبل، وبعد، وفرع وشركة، وعكس الإقفال) و`AccountLedgerTest` (2) و`AccountingReportsControllerTest` (3) و`AccountingClosingsControllerTest` (1) و`AccountingSetupControllerTest` (1) و`SensitivePermissionsTest` (1، وتحديث قائمتين) و`YearEndEntriesTest` (التعريف الواحد: 2 جديدة وإعادة تسمية واحدة) و`BalanceSheetTest` و`IncomeStatementTest` (تعديل مثبّتات القيد: صار قيد الإقفال يُكتب بـ`postYearEnd`، وعكس إقفال بأسلوب القيد القديم لاختبار اتباع السلسلة) و`EmployeesControllerTest` (الصلاحية في قائمة الصلاحيات المالية). ومثبّتات: `StatementFixtureTrait` (`postYearEnd` و`reverseAClosing`) و`AccountingFixtureTrait` (الجدول الجديد في التفريغ) و`RaceWorker` (ثلاث دوال: `yearEndClose` و`yearEndCloseOutcome` و`reverseOpeningBalanceOutcome`).
- **تعطيل الحراس:** `98` حارسًا عُطِّل كلٌّ منها وحده بـ`tools/dev/guard_mutations.py` وقائمتها في `wp6-guards.json` (القسم 3): **98 من 98 كُشفت**.
- **الملفات التي شُغّلت (كلها على قاعدة `ospos_test_wp6`، متتابعةً):** الجديدة الثلاثة، و`PostingServiceTest` و`ClosedPeriodServiceTest` و`OpeningBalanceServiceTest` و`JournalServiceTest` و`FiscalYearServiceTest` و`TrialBalanceTest` و`AccountLedgerTest` و`IncomeStatementTest` و`BalanceSheetTest` و`StatementFiguresTest` و`YearEndEntriesTest` و`SensitivePermissionsTest` و`AccountingMessageTest` و`OpeningStockTest` و`Readiness/SettingsChecksTest`، و`LanguageFilesTest`، وفي المتحكمات: `AccountingYearEndControllerTest` و`AccountingReportsControllerTest` و`AccountingStatementsControllerTest` و`AccountingClosingsControllerTest` و`AccountingPeriodsControllerTest` و`AccountingOpeningControllerTest` و`AccountingJournalsControllerTest` و`AccountingSetupControllerTest` و`EmployeesControllerTest`. **كلها خضراء** عدا أربعة اختبارات قديمة في `AccountingClosingsControllerTest` تفشل **بين الساعة 00:00 و04:00 بالتوقيت العالمي** لأنها تحسب التاريخ بـ`strtotime` قبل الطلب وبعده بمنطقتين زمنيتين مختلفتين (القسم 8)؛ لا علاقة لها بهذه الحزمة وتنجح في بقية اليوم.
- **لم تُشغَّل المجموعة الكاملة** (نحو ساعة، ووكلاء يتشاركون المعالجات): يشغّلها المُدمِج بعد الدمج. **لم تُجرَ جولة متصفح ولا فحص وصول للشاشة.**

## 3. الحراس

كل حارس عُطِّل وحده في نسخة الملف ثم استُعيد الملف (كشفه = فشل اختبار أو أكثر من الملفات المسمّاة). القائمة كاملة بنتائجها (من `wp6-guards.json` وسجل التشغيل):

| # | الحارس | الملف الذي يحرسه | الاختبار الذي يكشفه | النتيجة |
|---|---|---|---|---|
| G01 | previewing and approving needs the permission to close the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G02 | taking a closing back needs the permission to close the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G03 | a run for the company needs every branch | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G04 | a run for a branch needs that branch | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G05 | a branch that does not exist is refused | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G06 | a closing is taken back only by someone who works in its branch | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G07 | a closing that does not exist is refused | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G08 | taking a closing back needs a reason | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G09 | the reason is at most 255 characters | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G10 | approving locks every branch of the run in the order of their numbers | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G11 | taking a closing back locks its branch | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G12 | what blocks a closing stops its approval | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G13 | a run with nothing to close or already closed is refused | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G14 | the preview approved must be the one the books give now | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G15 | the periods must be closed through the last day of the year, not before | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G16 | the periods of the company must be closed | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G17 | a draft in the year blocks the closing | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G18 | drafts are counted from the first day of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G19 | drafts are counted to the last day of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G20 | only the drafts of the branches of the run count | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G21 | the retained earnings account must be an equity account | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G24 | the movement is counted from the first day of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G25 | the movement is counted to the last day of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G26 | the movement is that of the branch | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G27 | only posted and reversed journals are movement | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G28 | only revenue and expense accounts are closed | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G29 | a journal that closes a year is not movement of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G30 | a branch with an active closing is not closed again | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G31 | an active closing is read under lock when approving | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G32 | the closing of the periods is read under lock when approving | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G33 | a second closing of the year takes the next revision | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G34 | the closing is dated on the last day of the year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G35 | the closing journal belongs to the branch it closes | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G36 | a profit is a credit to retained earnings | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G37 | a loss is a debit to retained earnings | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G38 | a result of zero writes no retained earnings line | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G39 | a closing already taken back cannot be taken back again | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G40 | only a posted closing journal is taken back | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G41 | the reversal is written on the day of the closing | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G42 | the closing journal is marked reversed | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G43 | taking a closing back frees the key of its branch and year | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G44 | the reversal mirrors every line of the closing | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G45 | the closing is written to the audit log with the exception it used | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G46 | taking a closing back is written to the audit log | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| G47 | a closing is made for a debit balance by a credit | `YearEndLine.php` | `YearEndClosingTest` | كُشف |
| G48 | a closing is made for a credit balance by a debit | `YearEndLine.php` | `YearEndClosingTest` | كُشف |
| G49 | the result of a branch is its revenue less its expenses | `YearEndClosing.php` | `YearEndClosingTest` | كُشف |
| P01 | the journal of a year-end closing is refused by ordinary posting | `PostingService.php` | `PostingServiceTest` | كُشف |
| P02 | only a closing or the reversal of one is written by postYearEnd | `PostingService.php` | `PostingServiceTest` | كُشف |
| P03 | the relief of the closed period ends when postYearEnd returns or fails | `PostingService.php` | `PostingServiceTest` | كُشف |
| P04 | a closing may use an account that was deactivated | `PostingService.php` | `PostingServiceTest` | كُشف |
| P05 | a closing may be written for a branch that was deactivated | `PostingService.php` | `PostingServiceTest` | كُشف |
| P06 | a closing is checked against the fiscal years, not the closed periods | `PostingService.php` | `PostingServiceTest` | كُشف |
| P07 | a closing is written inside the closed period | `PostingService.php` | `PostingServiceTest` | كُشف |
| P09 | a closing takes the period locks like any posting | `PeriodGuard.php` | `PostingServiceTest` | كُشف |
| P10 | a closing is checked against the fiscal years as last committed | `PeriodGuard.php` | `PostingServiceTest` | كُشف |
| J01 | the journals screen cannot reverse a journal whose type is year-end | `JournalService.php` | `JournalServiceTest` | كُشف |
| J02 | the journals screen cannot reverse a journal whose source is year-end | `JournalService.php` | `JournalServiceTest` | كُشف |
| J03 | opening balances of a closed-out year are not reversed | `JournalService.php` | `OpeningBalanceServiceTest` | كُشف |
| J04 | reversing opening balances takes the lock of the branch before it looks | `JournalService.php` | `OpeningBalanceServiceTest` | كُشف |
| C01 | a period is not reopened while it would leave a year closed out | `ClosedPeriodService.php` | `ClosedPeriodServiceTest` | كُشف |
| C02 | the closing being reopened is not counted among the ones that remain | `ClosedPeriodService.php` | `ClosedPeriodServiceTest` | كُشف |
| C03 | a branch reopening is judged against the closing of the company | `ClosedPeriodService.php` | `ClosedPeriodServiceTest` | كُشف |
| C04 | a branch reopening is judged against what remains of its own closings | `ClosedPeriodService.php` | `ClosedPeriodServiceTest` | كُشف |
| O01 | only the closings that the reopening would leave open block it | `ClosedOutYears.php` | `ClosedPeriodServiceTest` | كُشف |
| O02 | a branch reopening is blocked only by the closings of that branch | `ClosedOutYears.php` | `ClosedPeriodServiceTest` | كُشف |
| O03 | a closing taken back no longer holds a year closed out | `ClosedOutYears.php` | `OpeningBalanceServiceTest` | كُشف |
| O04 | the closing of another branch does not hold a year closed out | `ClosedOutYears.php` | `OpeningBalanceServiceTest` | كُشف |
| O05 | a date outside the year of the closing is not held | `ClosedOutYears.php` | `OpeningBalanceServiceTest` | كُشف |
| O07 | a date before the year of the closing is not held | `ClosedOutYears.php` | `OpeningBalanceServiceTest` | كُشف |
| O06 | only an active closing makes a branch closed | `ClosedOutYears.php` | `YearEndClosingTest` | كُشف |
| F01 | the dates of a year closed out do not change | `FiscalYearService.php` | `FiscalYearServiceTest` | كُشف |
| F02 | only an active closing holds the dates of a year | `ClosedOutYears.php` | `FiscalYearServiceTest` | كُشف |
| S01 | the permission to close the year is sensitive | `AccountingGuard.php` | `SensitivePermissionsTest` | كُشف |
| S02 | the permission screen names the permission to close the year | `AccountingSetup.php` | `AccountingSetupControllerTest` | كُشف |
| R01 | the trial balance is before the closing entry unless asked | `TrialBalance.php` | `TrialBalanceTest` | كُشف |
| R02 | the trial balance before the closing entry leaves the closing out | `TrialBalance.php` | `TrialBalanceTest` | كُشف |
| R03 | an option the trial balance does not know reads before the closing entry | `TrialBalanceClosing.php` | `AccountingReportsControllerTest` | كُشف |
| R04 | a journal is a closing of a year by its type | `YearEndEntries.php` | `YearEndEntriesTest`، `IncomeStatementTest` | كُشف |
| R05 | the reversal of a closing is left out with it | `YearEndEntries.php` | `YearEndEntriesTest`، `IncomeStatementTest` | كُشف |
| R06 | the ledger says what each line is by its type | `AccountLedger.php` | `AccountLedgerTest` | كُشف |
| R07 | the balance sheet counts the closing entries | `BalanceSheet.php` | `YearEndClosingTest` | كُشف |
| R08 | the income statement leaves the closing entries out | `IncomeStatement.php` | `YearEndClosingTest` | كُشف |
| U03 | the approval form is not offered when nothing can be approved | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U04 | the reversal form is hidden from someone who may not close the year | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U05 | the reversal form is not offered for a closing already taken back | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U06 | a scope outside the ones offered is not found | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U07 | a posted scope must be the company or a branch number | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U11 | the approval form carries the fingerprint of the page that was shown | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U12 | a fingerprint that is not a digest is not accepted by the approval form | `AccountingYearEnd.php` | `AccountingYearEndControllerTest` | كُشف |
| U08 | the approval is reached by POST only | `Routes.php` | `AccountingYearEndControllerTest` | كُشف |
| U09 | the reversal is reached by POST only | `Routes.php` | `AccountingYearEndControllerTest` | كُشف |
| U10 | the forms are built so that they carry the token against forged requests | `year_end_approve_form.php` | `AccountingYearEndControllerTest` | كُشف |
| M01 | a branch has one active closing for a year | `20270900000000_YearEndClosings.php` | `YearEndClosingsMigrationTest` | كُشف |
| M02 | the revision of a closing is unique | `20270900000000_YearEndClosings.php` | `YearEndClosingsMigrationTest` | كُشف |
| M03 | an active closing holds the key and a reversed one gives it up | `20270900000000_YearEndClosings.php` | `YearEndClosingsMigrationTest` | كُشف |
| M04 | a closing keeps its year | `20270900000000_YearEndClosings.php` | `YearEndClosingsMigrationTest` | كُشف |
| M05 | the permission belongs to the periods screen | `20270900000000_YearEndClosings.php` | `YearEndClosingsMigrationTest` | كُشف |

## 4. ما افترضتُه

**قراءة `Y-DEC` المحافظة (للمراجعة، وللمالك نقضها):**

1. **(Y1) الإقفال اختياري، يُنفَّذ بأمر المالك بمعاينة ثم اعتماد.** التقارير صحيحة قبل إقفال السنة وبعده (الأسلوب «ب» في WP4 يبقى الافتراضي)، فلا يتوقف شيء على الإقفال. ليس آليًا ولا مرتبطًا بإنشاء السنة التالية (تختلف عن المصدر العام لدفترة: G-129).
2. **(Y2) الترحيل مباشرة إلى حساب الأرباح المحتجزة** بلا حساب وسيط «ملخص الدخل»، والحساب **من التوجيه** (`fiscal.retained_earnings`، `32` افتراضيًا): يغيّره المالك من شاشة التوجيه، ويُقرأ عند المعاينة والاعتماد ويُسجَّل على صف كل إقفال (`retained_account_id`). يرفض النظام حسابًا ليس من حقوق الملكية، ويرفضه التوجيه نفسه إن كان مفقودًا أو مجموعة أو معطَّلًا.
3. **تُقفل حركة السنة لا الرصيد المتراكم.** في دفاتر قديمة تراكم فيها الإيراد منذ البدء تُقفل كل سنة بحركتها، وبأي ترتيب (لا يُحسب قيد مرتين ولا يُشترط إقفال الأقدم أولًا)، ويبقى الباقي في «غير المرحلة» في الميزانية حتى تُقفل سنته. **فخيار «بعد قيد الإقفال» في ميزان المراجعة يُظهر حسابات سنة مقفلة أصفارًا لتلك السنة وحدها**، لا لسنوات سابقة لم تُقفل.
4. **(Y4) السحوبات وتوزيعات الأرباح خارج الإقفال** (حسابات حقوق ملكية، لا إيراد ولا مصروف). **(Y7) قيد لكل فرع بلا انتقال ربح بين الفروع**؛ ويُنفَّذ الفرع المعطَّل ببقية الفروع إن كان فيه رصيد.
5. **(Y5) صلاحية حساسة تُمنح بالاسم من المدير وحده**، وتحت وحدة الفترات (لا وحدة جديدة): من يملك الوحدة بلا الصلاحية يقرأ القيود فقط.
6. **(Y6) إعادة الفتح مرفوضة حتى عكس الإقفال**، وكذلك عكس الأرصدة الافتتاحية داخل السنة وتغيير تواريخ السنة؛ ويُسمح بإعادة فتح إقفال **لاحق** لا يمسّ تغطية السنة المقفلة.
7. **العكس بتاريخ القيد الأصلي** (آخر يوم من السنة، داخل الفترة المقفلة وبالاستثناء نفسه) لا بتاريخ العكس، ليعود الدفتر **كما كان في كل يوم** (ميزان بعد القيد وميزانية عند أي تاريخ وقراءة أي فترة). البديل (تاريخ العكس) يترك الميزانية عند آخر السنة تُظهر قيدًا أُلغي لاحقًا.
8. **عكس قيد الإقفال من شاشته وحدها** (لا من شاشة القيود)، وللشركة قيد لكل فرع فيُعكس كل واحد بسببه (لا زر «اعكس السنة كلها» في هذه الحزمة).
9. **التعريف الواحد بالنوع** (G-82): كل ما يُكتب بالنوع `year_end` قيد إقفال أو عكس لقيد إقفال؛ والمصدر وحده لا يكفي ولا يوجد قيد قديم بأيهما (لم يُنشأ قيد إقفال قبل هذه الحزمة)، فلا أثر على بيانات قائمة.
10. **مقارنة سنتين لم تُبنَ** (`G-84`، WP31): تبقى كل فترة تُقرأ بـ`IncomeStatement::build` التي تستثني قيد الإقفال، ويغطي ذلك اختبار؛ وعلى WP31 أن تبني المقارنة من استدعاءين كهذين.
11. **أسطر الإقفال بلا مركز تكلفة ولا عملة أجنبية** (العملة الأساسية وحدها: خارج النطاق، كما في السياسة). وأي تقرير جديد بمراكز التكلفة (`G-85`) يجب أن يستثني قيد الإقفال كقائمة الدخل.
12. **لا قيد افتتاح للسنة التالية** (الحسابات الحقيقية تنتقل بلا قيد: السياسة القسم 2)، بخلاف المصدر العام لدفترة.
13. **المعاينة لا تُحفظ**: تُقرأ عند كل طلب، وبصمتها تربط الاعتماد بما عُرض. فمن يعتمد بعد دقائق قد يُرفض إن تغيّرت الدفاتر (لا يمكن أن تتغير سنة مقفلة إلا بإعادة فتح الفترة، وهي مرفوضة).

## 5. ما بقي

- **عكس كل قيود الشركة بنقرة واحدة** (الافتراض 8)، و**إقفال متعدد السنوات بنقرة** (تسلسل)، **وقيد افتتاح للسنة التالية** (إن قرّر المالك على نمط دفترة): قرار المالك أولًا.
- **مقارنة سنتين وقسمة المتداول والثابت** (`G-84`، WP31) و**تقرير مراكز التكلفة** (`G-85`، WP38): يجب أن تستثني قيد الإقفال.
- **التدفق النقدي** (لا يمسّه القيد).
- **الأسماء القديمة** `3102` و`3103` في ثلاث وثائق تصميم تاريخية (`YEAR-END-STALE-ACCOUNT-REFS`): لم أحدّثها لأنها وثائق مشتركة (أحصِيَت للمُدمِج في القسم 9)؛ تُصحَّح بعد قرار المالك `Y-DEC`.
- **جولة المتصفح وفحص الوصول** للشاشة وعرض الهاتف (`WAVE2-BROWSER-PASS` في WP45).
- **ملف تصدير/طباعة** لمعاينة الإقفال.

## 6. بنود تنتظر قرار المالك وملاحظات دفترة

**قرارات المالك (`ownerDecisionsNeeded`):**

1. **`Y-DEC` نفسه:** أتعتمد الاقتراح المحافظ (إقفال اختياري بمعاينة واعتماد، قيد لكل فرع إلى `32` من التوجيه، وإعادة الفتح بعكس الإقفال) أم تريده آليًا مرتبطًا بإغلاق السنة وبقيد افتتاح للتالية على نمط المصدر العام لدفترة (Y1 وY2 وY6)؟
2. **تاريخ العكس:** بتاريخ القيد (المنفَّذ، فتعود الدفاتر لكل يوم كما كانت) أم بتاريخ يوم العكس؟
3. **إقفال الأقدم أولًا:** هل يُشترط إقفال السنوات السابقة بالتسلسل (الآن: لا يُشترط، وتُقفل كل سنة بحركتها)؟
4. **عكس الشركة دفعة واحدة** بدل قيد لكل فرع؟
5. **منح الصلاحية `acc_year_end_close`:** لمن؟ (لا أحد بعد الترحيل)، وهل تُقيَّد بفرع لمن له فرع واحد؟
6. **التنفيذ على قاعدة حقيقية:** يتطلب إذنك المستقل (أول إقفال حقيقي)؛ ما نُفِّذ كله على بيانات التطوير.

**ملاحظات دفترة (`daftraObservationsNeeded`):** `Y9` غير مرصود (الدفعة E): (أ) هل يُنشئ إغلاق السنة قيودًا فعلًا وكم لكل فرع وما مصدرها (المصدر العام: حتى ثلاثة، «الرصيد الافتتاحي» و«قيد الإغلاق» و«ترحيل خسائر السنة المالية»)؛ (ب) هل يتطلب فترة تالية وهل يجيز مسودات؛ (ج) أثر إعادة الفتح على قيود الإغلاق (إلغاء الأحدث أولًا؟)؛ (د) هل تدخل الضريبة والسحوبات؛ (هـ) ما يظهر في الميزانية وميزان المراجعة وقائمة الدخل قبل الإقفال وبعده. **لا أدّعي أن شيئًا منها يطابق دفترة** (مصفوفة `ACC-38` تبقى «غير مرصود»).

## 7. قبل النشر

- **الترحيل `20270900000000_YearEndClosings`** إضافي: جدول `accounting_year_end_closings` (بمفاتيح خارجية `RESTRICT` وقيد فحص) وسطر في `permissions` للصلاحية `acc_year_end_close` تحت `accounting_periods`؛ **آمن للتكرار** (`CREATE TABLE IF NOT EXISTS` وإدراج متجاهَل)، **لا يمنح الصلاحية لأحد ولا يمسّ قيدًا ولا فترة ولا رصيدًا**. التراجع (`down`) يحذف الجدول والصلاحية ومنوحاتها (بعد عكس كل قيود الإقفال).
- **بعد الترحيل لا يظهر أي تغيير** لمن لا يملك الصلاحية؛ تظهر الشاشة (قراءة) لمن يملك وحدة الفترات. وفي ميزان المراجعة **الافتراضي صار «قبل قيد الإقفال»** وهو يطابق ما قبله تمامًا ما دام لا قيد إقفال.
- **قبل أول إقفال حقيقي (بإذنك المستقل):** (1) تأكد من حساب الأرباح المحتجزة في شاشة التوجيه؛ (2) أقفل الفترات للشركة حتى آخر يوم من السنة بلا مسودات؛ (3) افتح «إقفال نهاية السنة»، اقرأ المعاينة لكل فرع وقارنها بقائمة الدخل للسنة نفسها؛ (4) اعتمد؛ (5) تأكد أن الميزانية متوازنة وأن قائمة الدخل لم تتغير. **التراجع:** زر «عكس» لكل قيد (بسبب) فتعود الدفاتر كما كانت، ثم يمكن إعادة فتح الفترات.
- **لا تُشغَّل الحزمة على قاعدة إنتاجية قبل إذن مستقل** (E47)؛ ولا يُنشأ قيد إقفال على بيانات حقيقية بلا قرار `Y-DEC`.

## 8. ملاحظات التشغيل

- **لا يُشغَّل `php-cs-fixer` على ملفات الاختبار:** تعريف الباحث (`finder`) في `.php-cs-fixer.no-header.php` يشمل `app/` و`public/` وحدهما، فتمريرها بمسارها يطبّق قواعد الإطار عليها: يحذف الشرطة السفلية من أسماء الدوال (`testPreview_Lists…` تصير `testPreviewLists…`) ويحوّل `assertEquals` إلى `assertSame` فتفشل مقارنة الكائنات. شغّلتُه على ثلاثة ملفات جديدة فأفسدها، فاستعدتُ الأسماء والتأكيدات (65 و12 و29 دالة حينها) قبل التسليم؛ ويُشغَّل على ملفات `app/` الجديدة وملفات اللغة وحدها.
- **أربعة اختبارات قديمة في `AccountingClosingsControllerTest` وقتية:** `testPostClose_ClosesTheCompanyAndReportsTheDraftsThatBlockIt` و`testPostClose_ClosesOneBranchOnly` و`testGetReopenForm_CarriesTheClosingTheDialogShowed` و`testPostReopen_RefusesAClosingThatChangedSinceTheDialogWasOpened` تحسب `date('Y-m-d', strtotime('-30 days'))` قبل الطلب (بتوقيت العملية) وبعده (بعد أن يضبط التطبيق `UTC`)، فتفشل حين يختلف تاريخ المنطقتين، أي بعد منتصف الليل بالتوقيت العالمي بساعات (شُغّلت 00:50 و01:28 UTC). لم أغيّرها (خارج الحزمة)، وتنجح في بقية اليوم.
- **جدول الإقفال في تفريغ الاختبار:** أضفتُ `accounting_year_end_closings` إلى قائمة `AccountingFixtureTrait::clearJournals` (وإلا بقيت صفوف الاختبار السابق فرفضت الإقفال التالي)؛ وأي اختبار يفتح اتصالًا ثانيًا بمعاملة يجب أن يُنهيه في `finally` وإلا علّق `TRUNCATE` التفريغ على قفل بيانات (وصفيّ) مدة غير محدودة.
- **المجلد المؤقت مشترك بين الوكلاء:** ملفات السجل بأسماء عامة (`mut1.log`) تتداخل بين الحزم؛ استعملتُ أسماء بادئتها `wp6_`.

## 9. تحديثات الوثائق المشتركة للمُدمِج

لم أُعدِّل `decisions.md` ولا مصفوفة التطابق ولا تقرير الفجوات ولا `backlog.md` ولا أي `README` ولا قائمة النشر. هذا ما يُضاف (يعيّن المُدمِج رقم القرار، ويتبع E67):

**decisions.md** (صف جديد، رقمه E-التالي بعد E67):

> | E-؟؟ | **إقفال نهاية السنة بقيد لكل فرع بمعاينة واعتماد وعكس وحراس (WP6: `YE-01` و`Y5` و`YE-03` و`G-82`)** (2026-10-31، [التقرير](daftra-parity/wp6-report.md)): **قرار تنفيذي أُخذ تحت E47 على اقتراح مؤلف الحزمة بالقراءة المحافظة لـ`Y-DEC`، وللمالك حق نقضه.** `YearEndClosing` تعاين ثم تعتمد **قيدًا واحدًا لكل فرع ولكل سنة** (مصدره ونوعه `year_end`، **بتعريف واحد بالنوع** في `YearEndEntries::leaveOut`) ينقل **حركة السنة** (لا الرصيد المتراكم) من الإيرادات والمصروفات إلى **حساب الأرباح المحتجزة من التوجيه** (`fiscal.retained_earnings`، `32` افتراضيًا) بلا انتقال ربح بين الفروع (الربح دائن والخسارة مدينة)، **اختياريًا** (التقارير صحيحة قبله وبعده)، بصلاحية **حساسة** `acc_year_end_close` لا تُمنح لأحد تلقائيًا، وبشروط: إقفال فترة للشركة حتى آخر يوم من السنة ولا مسودات فيها، والاعتماد **بصمة المعاينة** وحدها، **والقيد يُكتب في الفترة المقفلة باستثناء موثَّق ومحصور في `PostingService::postYearEnd` ومدقَّق**، **ولا يُعدَّل ولا يُحذف: يُعكس بتاريخه** فيعود الدفتر كما كان في كل يوم. **إعادة فتح فترة تترك سنة مقفلة، وعكس الأرصدة الافتتاحية داخلها، وتغيير تواريخها، وعكس قيدها من شاشة القيود مرفوضة حتى عكس الإقفال.** مفتاح فريد (فرع، سنة، `active_key`) وقفلة الفرع بترتيب الأرقام ومعاملة واحدة لتنفيذ الشركة. ميزان المراجعة: خيار «قبل/بعد قيد الإقفال» (الافتراضي قبل)؛ كشف الحساب يوسمه؛ قائمة الدخل تستثنيه والميزانية تُدخله. **افتراضات للمراجعة:** العكس بتاريخ القيد الأصلي؛ لا قيد افتتاح للتالية؛ لا إقفال متسلسل مفروض؛ أسطر بلا مركز تكلفة؛ عملة أساسية وحدها. **`Y-DEC` بقي مفتوحًا للمالك، وسلوك دفترة (`Y9`) غير مرصود فلا يُدّعى تطابق.** 98 حارسًا عُطِّل كلٌّ منها وحده فكُشف. **لا كتابة في دفترة، ولا دمج في `master`، ولا نشر، ولا إقفال على قاعدة حقيقية** |

**مصفوفة التطابق** (`conformance-matrix.md`):

- الصف `ACC-38` (إقفال السنة المالية): يُضاف إلى «ما عندنا»: «**إقفال نهاية السنة (WP6):** قيد لكل فرع بمعاينة واعتماد وعكس، اختياري، إلى حساب الأرباح المحتجزة من التوجيه، بصلاحية حساسة؛ **بلا قيد افتتاح للسنة التالية ولا فترة تالية إلزامية**». التصنيف يبقى «غير مرصود» (Y9: الدفعة E)، ولا يُكتب «مطابق».
- الصف `ACC-23` (الأرباح المرحلة): «الحساب `32` يُغذَّى الآن بقيد الإقفال بعد اعتماده (WP6)، وقبله يُقرأ ناتج السنوات غير المقفلة في الميزانية ديناميكيًا (WP4)».
- فقرة تحديث بعد الجدول: «**تحديث بعد WP6 (2026-10-31):** نُفِّذ إقفال نهاية السنة وخيار ميزان المراجعة ووسم الكشف (`YE-01` و`YE-03` و`Y5` و`G-82`) ([تقرير WP6](wp6-report.md)). **لا يتغير تصنيف أي صف ولا الأعداد**، لأن سلوك دفترة (Y9) لم يُرصد بعد.»

**تقرير الفجوات** (`gap-report.md`):

- الصفوف `YE-01` و`Y5` و`G-82`: تُستبدل بـ«**نُفِّذ (2026-10-31، WP6)**: الخدمة والجدول والصلاحية الحساسة والشاشة وتعريف الإقفال الواحد بنوعه `year_end` وحراسه ([التقرير](wp6-report.md)). **ما بقي:** قرار المالك `Y-DEC`، وعكس الشركة بنقرة، وقيد افتتاح للتالية إن قرّر، وجولة المتصفح.»
- الصف `YE-03`: «نُفِّذ: ميزان المراجعة قبل/بعد (الافتراضي قبل)، ووسم كشف الحساب، واستثناء قائمة الدخل والمقارنة. **بقي:** مقارنة السنتين نفسها (`G-84`، WP31).»
- الصف `G-129`: يُضاف «اخترنا في WP6 قيدًا اختياريًا لكل فرع بمعاينة واعتماد وعكس، لا إقفالًا آليًا بفترة تالية إلزامية ولا ثلاثة قيود؛ الفرق **مقصود حتى يقرر المالك** `Y-DEC`، وسلوك دفترة غير مرصود (الدفعة E)».
- الصف `YEAR-END-STALE-ACCOUNT-REFS`: يبقى مفتوحًا (`a2b-periods.md` السطران 52 و53، و`ui/a2-design.md` الأسطر 108 و111 و232 و245، و`multi-branch.md` السطر 97 تسمّي `3102` و`3103`) إلى قرار `Y-DEC`.

**قائمة النشر** (`docs/ui-redesign/deploy-checklist.md`، بند جديد):

> - [ ] **إقفال نهاية السنة (WP6، E-؟؟):** ترحيل واحد `20270900000000_YearEndClosings` (جدول `accounting_year_end_closings` والصلاحية الحساسة `acc_year_end_close` تحت `accounting_periods`، **تُمنح لأحد بالاسم من شاشة الموظفين ولا تُمنح تلقائيًا**)، آمن للتكرار ولا يمس قيدًا ولا فترة. **بعد النشر لا يتغير شيء لمن لا يملك الصلاحية**؛ وميزان المراجعة **الافتراضي «قبل قيد الإقفال»** (يطابق السابق ما لم يوجد قيد إقفال)، وفي كشف الحساب وسم لأي سطر إقفال. **لا يُنشأ قيد إقفال على قاعدة حقيقية بلا إذن المالك المستقل وقرار `Y-DEC`.** قبل أول إقفال: (1) راجع حساب الأرباح المحتجزة في شاشة التوجيه؛ (2) أقفل فترات الشركة حتى آخر يوم من السنة بلا مسودات؛ (3) من «إقفال نهاية السنة» اقرأ المعاينة لكل فرع وقارنها بقائمة الدخل للسنة؛ (4) اعتمد؛ (5) تأكد أن الميزانية متوازنة وقائمة الدخل لم تتغير. **التراجع:** «عكس» لكل قيد بسبب (تعود الدفاتر كما كانت في كل يوم)، ثم يمكن إعادة فتح الفترات. (6) أضف `accounting_periods/year_end` إلى `tools/ui-smoke/screens.json` (لم تُجرَ جولة متصفح ولا فحص وصول عند المؤلف). تفاصيل في [تقرير WP6](../accounting/daftra-parity/wp6-report.md).

**backlog.md:** صف WP6 «نُفِّذ (2026-10-31)» وأعمدة الحالة: `YE-01` و`Y5` و`G-82` «done»، و`YE-03` «done (مقارنة السنتين نفسها في WP31/G-84)»، و`Y-DEC` يبقى «partial» (القرار للمالك: Y1 إلى Y9)، و`G-129` «open» (قرار المالك وملاحظة دفترة Y9)، و`YEAR-END-STALE-ACCOUNT-REFS` «open».
