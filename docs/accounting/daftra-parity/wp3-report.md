# تقرير WP3: المصروفات وإقفال الصندوق في الدفاتر

> الحالة (2026-10-26): **نُفِّذ على الفرع `wp/WP3`** (نسخة العمل `/home/user/wt/WP3`، من الإيداع `f5d4927`) بالتفويض التنفيذي الشامل (E47)، حزمة **WP3**: بنود `FIN-05` و`FIN-02` و`FIN-04` و`A4`. **كل مصروف وتعديله وحذفه يُرحَّل إلى الدفتر في معاملة الحفظ نفسها بقيد واحد متوازن**، **وإقفال الصندوق يُرحَّل فرقه وحده** (تصميم متحفظ للمالك حق نقضه، القسم 3). **لا دمج في `master`، ولا دفع، ولا نشر، ولا شيكات، ولا كتابة في دفترة** (الحزمة لا تحتاج دخولًا إلى دفترة: القيد المرجعي `S-25` مرصود ومثبَّت في `T-25`، وبيانات الدخول المرسلة لم تُستعمل). ولم أعدّل `decisions.md` ولا المصفوفة ولا تقرير الفجوات ولا قائمة النشر: النص المقترح لها في آخر هذا التقرير للمُدمِج.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **قيد المصروف (`S-25`)** | مصروف مبلغه `230` فيه ضريبة `30`: **مدين** حساب المصروف `200` (حساب التصنيف أو `541` الموجَّه) و**مدين** حساب الضريبة المدفوعة الموجَّه (`215002`) `30` و**دائن** حساب الخزينة المدفوع منها `230`. القيد بلا طرف (لا ذمة) ومتوازن، بأربع منازل بلا انجراف، والضريبة بإشارة المبلغ وليست أكبر منه (`expense_tax_invalid`). المبلغ السالب (مال عاد) يقلب الجهتين. مبلغ صفر لا قيد له |
| **في معاملة الحفظ (D2)** | `Expense::save_value` يقفل صف المصروف (`FOR UPDATE`) ويخطّط ويحفظ ويرحّل **في معاملة واحدة**. رفض الدفتر (فترة مقفلة، فرع، سحب من خزينة، تصنيف بلا حساب، توجيه ناقص) **يعيد المعاملة كلها** فلا يبقى مصروف ولا قيد، ويُسجَّل الفشل في `accounting_posting_failures` **بعد** التراجع (وإلا ذهب السجل معه)، وتعرض الشاشة السبب بكلمات الموظف (`AccountingMessage`) لا رسالة عامة |
| **التعديل والحذف (E15)** | تعديل ما يغيّر القيد (المبلغ، الضريبة، التاريخ، الفرع، الخزينة، حساب التصنيف، وسيلة الدفع) **يعكس القيد القائم ويرحّل قيدًا جديدًا بالمراجعة التالية** ولا يُعدَّل قيد مرحَّل أبدًا؛ وتعديل لا يغيّر القيد (وصف مثلًا) **لا يكتب شيئًا**. حذف المصروف (أو علامة «محذوف») يعكسه، واسترجاعه يرحّله من جديد. حذف قائمة من المصروفات **ذرّي**: كلها أو لا شيء، بترتيب الأرقام. القيد المعكوس في فترة أُقفلت يُعكس **بتاريخ اليوم** (المفتوح) لا بتاريخه |
| **الإدمبوتنسي** | المفتاح `(source_type, source_id, entry_type, revision)` هو نفسه في `PostingService`؛ مساعد جديد `DocumentJournals` يقرر: لا قيد فيرحّل، وقيد لا يطابق (التاريخ أو الفرع أو سطور `حساب:مدين:دائن` بلا اعتبار للترتيب) فيعكس ويرحّل، وقيد يطابق فلا يفعل شيئًا. والمصدر `expense` للمصروف و`cashup` لإقفال الصندوق |
| **الفروع (`BranchGuard`)** | يُختم المصروف بفرع خزينته أو بفرع موظفه الافتراضي، ويُسأل **العمل في هذا الفرع** عند الحفظ والتعديل والحذف **في النموذج لا في الشاشة وحدها**. مصروف مكتوب باسم موظف آخر يُحكم بالموظف **المسجَّل دخوله** لا بصاحب المصروف |
| **الخزينة ونطاق السحب (`TreasuryScopes`)** | الخزينة اختيارية في النموذج (قائمة الخزائن المفتوحة للموظف وفي فروعه، ولا تُعرض مع المحاسبة المعطَّلة)؛ بلا اختيار تُؤخذ خزينة الموظف الافتراضية، وإلا حساب وسيلة الدفع الموجَّه، **وفي الحالين يُفحص نطاق السحب** (والإيداع لمال عاد وللعكس عند الحذف)، **وتعديل لا يحرّك مالًا لا يُسأل عنه**. **رصيد الخزينة = رصيد حسابها** (اختبار `assertTreasuryTiesToItsAccount` بعد كل تغيير). **الآجل:** المصروف بوسيلة «آجل» (Due) لا خزينة له ويُرحَّل إلى `2213 أطراف دائنة أخرى` (افتراض، القسم 3). وسائل الدفع التي لا يعرفها الدفتر (بطاقة هدية، نقاط، اسم مجهول) **تُرفض** (`payment_type_unknown`) |
| **`FIN-04`: حساب التصنيف** | عمود `expense_categories.account_id` اختياري: **تصنيف بلا حساب يقع على الحساب الموجَّه `541`** (الرجوع)، وتصنيف بحساب يُرحَّل إليه. الحسابات المعروضة: حسابات المصروفات الفعّالة القابلة للقيد غير المخصصة لغرض آخر (`ExpenseCategoryAccounts::options`). **اختيار الحساب قرار توجيه**: يلزمه إذن تغيير التوجيه **وكل الفروع**، ويُدقَّق (`account_changed`)، ويُحفظ مع التصنيف في معاملة واحدة، ومن لا إذن له يرى الحساب مقفلًا. **الحساب المختار لا يُعطَّل ولا يُحذف** (`AccountService::isRouted` يعدّه موجَّهًا). وإعداد جديد `accounting_expense_account_required` (افتراضيه مطفأ) **يشترط حسابًا للتصنيف** عند مصروف جديد أو نقل مصروف إلى تصنيف آخر، **ولا يمنع تعديل مصروف في تصنيفه الحالي** |
| **`FIN-02`: ضريبة المدخلات** | تذهب إلى الحساب الموجَّه `tax.input` (`215002`) لا إلى حساب ثابت في الكود، فتغيير التوجيه يغيّرها |
| **إقفال الصندوق (`A4`)** | يُرحَّل **الفرق وحده** بين النقد المتوقع والنقد المعدود (لأن المبيعات والمصروفات رحّلت الباقي في الخزينة): **عجز** مدين `1208 عجز وزيادة الصندوق` ودائن الخزينة، **وزيادة** عكسه. فرق صفر أو إقفال لم يُعدّ فيه نقد (أي المبالغ الأربعة المغلقة صفر) **لا قيد له**. **النقد المتوقع يُحسب في الخادم** (افتتاح + مُحوَّل + مدفوعات البيع النقدية المكتملة في فرع الإقفال بين الفتح والإغلاق − ما رُدَّ نقدًا − مصروفات الفرع النقدية غير المحذوفة) **ولا يُؤخذ مما تدّعيه الشاشة**، ويُحفظ مع الفرق في `cash_up.expected_amount_cash` و`cash_up.cash_difference`. **بالانضباط نفسه**: معاملة الحفظ، عكس وإعادة ترحيل عند تغيّر العد، عكس عند الحذف، فرع وخزينة وإقفال فترات ونطاقات، وتسجيل الفشل. شاشة الإقفال تملأ «النقد المتوقع» وتعرض الفرق وملاحظة |
| **التاريخي (لا ترحيل بأثر رجعي)** | مصروف أو إقفال صندوق **حُفظ قبل أن يتبعه الدفتر** (المحاسبة معطَّلة، أو مؤرَّخ قبل بدء المحاسبة) **لا قيد له ولا يُعطى قيدًا بتعديله**: العلامة هي أن `branch_id` فارغ في صفه. **ويُسرد غير مرحَّل** في عمود «الدفتر» وفلتر «غير المرحّل» في قائمتي المصروفات والإقفالات، **وفي ملاحظات جاهزية المحاسبة** (`expenses_unposted` و`cashups_unposted`). وحده حزمة الترحيل بأثر رجعي تعطيه قيدًا (**لم تُنفَّذ، القسم 4**) |
| **المحاسبة معطَّلة (D1)** | **لا أثر**: المسار القديم نفسه، ولا يُكتب `branch_id` ولا `treasury_id` ولا أي عمود محاسبي ولا قيد ولا سجل تدقيق، ولا عمود «الدفتر» ولا فلتره. اختبارات تثبّت ذلك للمصروف والإقفال والتصنيف |
| **قفل العكس اليدوي** | قيدا `expense` و`cashup` **لا يُعكسان من شاشة القيود** (`document_journal_locked`) ويُخفى الزر: يُصحَّح المستند نفسه (تعديله أو حذفه)، وإلا افترق القيد عن مستنده. (القائمة `JournalService::DOCUMENT_SOURCES` كقائمة `TRANSFER_SOURCES`) |
| **الترحيل** | `20261120000000_ExpensesAccounting`: أعمدة اختيارية وفهارس ومفاتيح فقط (القسم 5) |
| **اللغات** | 46 لغة لملفات `Accounting` و`Accounting_settings` و`Expenses` و`Expenses_categories` و`Cashups`: الإنجليزية في `en` و`en-GB`، والعربية الفعلية في `ar-EG` و`ar-LB`، وفراغ في الباقي، **مرتبة هجائيًا ومحاذاة** بأداة الإضافة. (أعادت الأداة ترتيب مفتاح `category_name_required` في ملف تصنيفات المصروفات إلى مكانه الهجائي: لا تغيير في قيمته) |

## 2. الأدلة

- **مطابقة `S-25` (مرصودة):** ملف التثبيت `tests/Support/Parity/T-25.json` بثلاث حالات: `expense-200-with-15-percent-tax` **مطابقة تامة** (مدين `541` بـ200، مدين `215002` بـ30، دائن الخزينة 230)، و`expense-journal-owes-nobody` **مطابقة تامة** (لا طرف في أي سطر)، و`amount-entered-includes-the-tax` **يختلف**: في دفترة يُدخل المستخدم 200 وتُضاف الضريبة فيصير 230، وعندنا `amount` هو المدفوع شاملًا الضريبة و`tax_amount` جزؤه (القسم 3، الافتراض 1). ويقرؤه `ExpensePostingBuilderTest` فيبني القيد من الملف نفسه. (ما عدا هذه الحالة: **لا ادعاء مطابقة**، كله افتراض مدوَّن.)
- **اختبارات جديدة (218 اختبارًا في 12 ملفًا، ملف لكل صنف):** `ExpensePostingBuilderTest` (18) و`ExpensePostingTest` (48: كل مسار حفظ وتعديل وحذف عبر نموذج المصروف الحقيقي، الفترة المقفلة، الفرع، نطاق السحب والإيداع، التراجع الكامل وتسجيل الفشل، التاريخي، المحاسبة المعطَّلة، توازن الميزان بعد كل تغيير) و`CashupPostingBuilderTest` (7) و`CashupPostingTest` (30) و`CashupExpectationTest` (13) و`DocumentJournalsTest` (24) و`ExpenseCategoryAccountsTest` (13) و`ExpensesControllerTest` (23) و`ExpensesCategoriesControllerTest` (9) و`tests/Models/ExpenseTest` (13) و`tests/Models/CashupTest` (12) و`ExpensesAccountingMigrationTest` (8).
- **اختبارات أُضيفت إلى ملفات قائمة (22):** `CashupControllerTest` (12: الشاشة والنقد المتوقع والرفض بكلمات) و`AccountingSettingsTest` (3) و`AccountServiceTest` (2) و`JournalServiceTest` (2: قفل العكس اليدوي وعكس آلي لمصدر المستند) و`TreasuryServiceTest` (1: خزينة استُعملت في مصروف أو إقفال تُعدّ مستعمَلة) و`TrialBalanceTest` (1: **الميزان متوازن للشركة ولكل فرع بعد المصروفات**) و`AccountingJournalsControllerTest` (1: زر العكس مخفي لقيدي المصروف والإقفال).
- **تعطيل الحراس:** <!-- MUT-SUMMARY-START -->**قيد القياس: قيس 218 من 235 حارسًا حتى الآن، كُشف منها 215** (الجدول في 2.1 يُملأ دفعةً دفعة).<!-- MUT-SUMMARY-END -->
- **الجولة المنتظمة الأخيرة (الملفات الجديدة والمتغيرة وذات الصلة، كل ملف وحده على `ospos_test_b`):** @@RUNS@@ (المجموعة الكاملة يشغّلها المُدمِج، ولم أشغّلها).
- **التنسيق:** شُغّل `php-cs-fixer` (إعداد `.php-cs-fixer.no-header.php`) على كل ملفات `app/` الجديدة (11 ملفًا: صفر تغيير) وعلى المكتبات والمتحكمات المعدَّلة، فاقتصر الإصلاح على محاذاة مفتاح الإعداد الجديد في `AccountingSettings` و`AccountingSetup`. أما ملفات `app/Controllers` و`app/Models` و`tabular_helper.php` القديمة التي لمستُ فيها **سطورًا قليلة** فلم أُعد تنسيقها كلها (تغيير يغطي الملف بأكمله ويصعّب على المُدمِج الدمج)، وكل سطر جديد فيها على المعيار. واختبارات الملفات الجديدة على PSR-12 (صفر مخالفة).

### 2.1 جدول تعطيل الحراس

<!-- MUT-TABLE-START -->
| الحارس | ما يحميه | النتيجة | اختبارات فاشلة |
|---|---|---|---|
| `G01` | a treasury the employee may not withdraw from cannot be posted into (assertMayPost) | مكشوف | 1 |
| `G02` | a journal of a treasury cannot be replaced or reversed against the scopes (assertMayReplace) | مكشوف | 2 |
| `G03` | a changed date is a change (holds: date) | مكشوف | 1 |
| `G04` | a changed branch is a change (holds: branch) | مكشوف | 1 |
| `G05` | changed lines are a change (holds: lines) | مكشوف | 4 |
| `G06` | the order of the lines is not a change (holds: sort) | مكشوف | 1 |
| `G07` | a journal of a closed period is taken back today (reverse date) | مكشوف | 1 |
| `G08` | only a journal in the books stands (active: posted) | مكشوف | 1 |
| `G09` | each change is the next revision (nextRevision) | مكشوف | 6 |
| `G10` | an expense is checked against the branches of the employee (plan, followed) | مكشوف | 2 |
| `G11` | an expense is checked against the branches of the employee (plan, deleted or moved out) | مكشوف | 2 |
| `G12` | deleting an expense is checked against the branches of the employee (cancel) | مكشوف | 1 |
| `G13` | an expense saved before the books followed it never gets a journal (legacy rule) | مكشوف | 1 |
| `G14` | an expense dated before accounting starts is not followed (follows) | مكشوف | 2 |
| `G15` | a deactivated treasury cannot be named | مكشوف | 1 |
| `G16` | an expense keeps a treasury that was deactivated later | مكشوف | 1 |
| `G17` | a treasury that does not exist is refused | مكشوف | 1 |
| `G18` | a category without an account is refused when the setting asks (FIN-04) | مكشوف | 2 |
| `G19` | an edit of the same category is not blocked by the setting | مكشوف | 1 |
| `G20` | moving an expense to a category without an account is refused when the setting asks | مكشوف | 1 |
| `G21` | an edit that names no treasury keeps the treasury of the expense | مكشوف | 1 |
| `G22` | money that came back is a deposit into the treasury (scope direction) | مكشوف | 2 |
| `G23` | an expense that is owed uses no treasury | مكشوف | 2 |
| `G24` | the unposted list leaves out deleted expenses | مكشوف | 1 |
| `G25` | the unposted list leaves out expenses of zero | مكشوف | 1 |
| `G26` | the unposted list shows nothing to a reader without branches | مكشوف | 1 |
| `G27` | an expense without a branch is shown only to the default branch | مكشوف | 1 |
| `G28` | cancelling an expense is audited | مكشوف | 1 |
| `G29` | a deleted expense is reversed, not posted again (sync) | مكشوف | 2 |
| `G30` | an expense moved before accounting starts is reversed (sync) | مكشوف | 1 |
| `G31` | the tax has the sign of the amount | مكشوف | 1 |
| `G32` | the tax is not more than the amount | مكشوف | 1 |
| `G33` | a gift card does not pay an expense | مكشوف | 1 |
| `G34` | reward points do not pay an expense | مكشوف | 1 |
| `G35` | a way of payment the books do not know is refused | مكشوف | 1 |
| `G36` | an expense that is due is owed to other creditors | مكشوف | 2 |
| `G37` | an expense of zero posts nothing | مكشوف | 1 |
| `G38` | the cost goes to the account of the category (FIN-04) | مكشوف | 1 |
| `G39` | the tax goes to the routed account of tax paid (FIN-02) | مكشوف | 4 |
| `G40` | the credit goes to the account of the treasury | مكشوف | 3 |
| `G41` | money that came back turns the sides around | مكشوف | 1 |
| `G42` | a cash-up is checked against the branches of the employee (plan, followed) | مكشوف بعد تقوية الاختبار | 1 |
| `G43` | a cash-up is checked against the branches of the employee (plan, deleted or moved out) | مكشوف بعد تقوية الاختبار | 2 |
| `G44` | deleting a cash-up is checked against the branches of the employee (cancel) | مكشوف | 1 |
| `G45` | a cash-up saved before the books followed it never gets a journal (legacy rule) | مكشوف | 1 |
| `G46` | a cash-up closed before accounting starts is not followed (follows) | مكشوف | 1 |
| `G47` | a cash-up with nothing counted yet is still open and posts nothing | مكشوف | 1 |
| `G48` | a change that does not name the counted cash keeps the counted cash of the stored cash-up | مكشوف بعد تقوية الاختبار | 1 |
| `G49` | the expected cash and the difference are never taken from the form | مكشوف بعد تقوية الاختبار | 1 |
| `G50` | a new cash-up is stamped with the treasury of the employee | مكشوف | 4 |
| `G51` | an edited cash-up keeps its treasury | مكشوف | 1 |
| `G52` | an edited cash-up keeps its branch | مكشوف | 1 |
| `G53` | a deleted cash-up is reversed, not posted again (sync) | مكشوف | 1 |
| `G54` | the unposted list leaves out deleted cash-ups | مكشوف بعد تقوية الاختبار | 1 |
| `G55` | the unposted cash-ups are shown only to the default branch | مكشوف | 1 |
| `G56` | cancelling a cash-up is audited | مكشوف بعد تقوية الاختبار | 1 |
| `G57` | a shortage debits the cash difference and an overage credits it | مكشوف | 3 |
| `G58` | a count that matches posts nothing | مكشوف | 1 |
| `G59` | a cash-up that is still open posts nothing | مكشوف بعد تقوية الاختبار | 1 |
| `G60` | the difference goes to the account of the treasury | مكشوف | 2 |
| `G61` | the difference goes to the routed cash difference account | مكشوف | 2 |
| `G62` | only completed payments are cash that came in | مكشوف | 1 |
| `G63` | only completed sales count | مكشوف | 1 |
| `G64` | only the sales of the branch count | مكشوف | 1 |
| `G65` | cash that was given back is taken off | مكشوف | 1 |
| `G66` | deleted expenses are not cash that went out | مكشوف | 1 |
| `G67` | an expense without a branch counts for the default branch | مكشوف | 1 |
| `G68` | only the expenses of the branch count for another branch | مكشوف | 1 |
| `G69` | only cash counts | مكشوف | 2 |
| `G70` | sales outside the time of the cash-up do not count | مكشوف بعد تقوية الاختبار | 1 |
| `G71` | only the kinds of sale that take cash count | مكشوف بعد تقوية الاختبار | 1 |
| `G72` | choosing the account of a category needs the permission to change the routing | مكشوف | 1 |
| `G73` | choosing the account of a category needs every branch | مكشوف | 1 |
| `G74` | the account of a category must be one that takes expenses (assertUsable) | مكشوف | 3 |
| `G75` | keeping the account a category has changes nothing and asks for nothing | مكشوف | 1 |
| `G76` | a category that does not exist is left alone | مكشوف | 1 |
| `G77` | the permission shown to the screen needs every branch too (mayAssign) | مكشوف | 1 |
| `G78` | accounts that are groups are not offered | مكشوف | 2 |
| `G79` | inactive accounts are not offered | مكشوف | 1 |
| `G80` | only expense accounts are offered | مكشوف بعد تقوية الاختبار | 1 |
| `G81` | accounts kept for another purpose are not offered | مكشوف | 2 |
| `G82` | a change of the account of a category is audited | مكشوف | 1 |
| `G83` | the journal of an expense or a cash-up cannot be reversed by hand | مكشوف | 1 |
| `G84` | the automatic reversal knows the journals of an expense and a cash-up | مكشوف | 1 |
| `G85` | the screen of a journal hides the reverse button of an expense or a cash-up | مكشوف | 1 |
| `G86` | an account chosen by a category is routed and cannot be taken out of use | مكشوف | 2 |
| `G87` | a treasury used by an expense is used (isUsed: expenses) | مكشوف | 1 |
| `G88` | a treasury used by a cash-up is used (isUsed: cash_up) | مكشوف | 1 |
| `G89` | the readiness notices carry the expenses and cash-ups that are not in the books | مكشوف | 1 |
| `G90` | the readiness notice of unposted expenses | مكشوف | 1 |
| `G91` | the readiness notice of unposted cash-ups | مكشوف | 1 |
| `G92` | the setting that asks for a category account is audited and saved | مكشوف | 2 |
| `G93` | accounting off writes no accounting column of an expense (D1) | مكشوف | 1 |
| `G94` | a refused expense save is rolled back | مكشوف | 9 |
| `G95` | a refused expense delete is rolled back as a whole | مكشوف | 1 |
| `G96` | an expense of zero is not unposted in the list | مكشوف | 4 |
| `G97` | a deleted expense is not unposted in the list | مكشوف | 1 |
| `G98` | the unposted filter of the expense list | مكشوف | 4 |
| `G99` | the unposted filter is ignored while accounting is off (expenses) | مكشوف | 1 |
| `G100` | accounting off writes no accounting column of a cash-up (D1) | مكشوف | 1 |
| `G101` | a refused cash-up save is rolled back | مكشوف | 4 |
| `G102` | a refused cash-up delete is rolled back as a whole | مكشوف | 1 |
| `G103` | a deleted cash-up is not unposted in the list | مكشوف | 1 |
| `G104` | the unposted filter of the cash-up list | مكشوف | 3 |
| `G105` | the unposted filter is ignored while accounting is off (cash-ups) | مكشوف | 1 |
| `G106` | the category account is ignored while accounting is off | مكشوف بعد تقوية الاختبار | 1 |
| `G107` | a refused category save is rolled back | مكشوف | 2 |
| `G108` | the books refusal is said in words (expenses) | مكشوف | 5 |
| `G109` | the books refusal is said in words (cash-ups) | مكشوف | 3 |
| `G110` | the account of a category is locked for who may not change the routing | مكشوف | 1 |
| `G111` | the treasuries offered are the ones the employee may withdraw from | مكشوف | 1 |
| `G112` | the treasury an expense already uses stays offered | مكشوف | 1 |
| `G113` | the ledger column is shown only when the list carries it (expenses) | مكشوف | 1 |
| `G114` | the ledger column is shown only when the list carries it (cash-ups) | مكشوف بعد تقوية الاختبار | 1 |
| `G115` | the migration adds a column only when it is missing | مكشوف | 8 |
| `G116` | the migration adds an index only when it is missing | مكشوف | 8 |
| `G117` | the migration adds a key only when it is missing | مكشوف | 8 |
| `H01` | a journal that still matches the document is left alone (idempotent sync) | مكشوف | 4 |
| `H02` | a document that calls for no journal and has none posts nothing | مكشوف | 1 |
| `H03` | a document that calls for no journal has its journal only reversed | مكشوف | 5 |
| `H04` | a date in a closed period is refused for an expense (PeriodGuard::assert, the one point behind both period checks of PostingService) | مكشوف بعد تقوية الاختبار | 3 |
| `H06` | a date in a closed period is refused for a cash-up (PeriodGuard::assert) | مكشوف بعد تقوية الاختبار | 2 |
| `H10` | the category account is checked when an expense is saved (assertCategoryAccount call) | مكشوف | 2 |
| `H11` | with the setting off no category account is required | مكشوف | 48 |
| `H12` | a new expense that is already deleted is not stamped or posted | مكشوف بعد تقوية الاختبار | 1 |
| `H13` | a new expense dated before accounting starts is not stamped or posted | مكشوف | 1 |
| `H14` | an expense moved before accounting starts is not stamped again | مكشوف بعد تقوية الاختبار | 1 |
| `H15` | an expense that is marked deleted is not stamped again | مكشوف بعد تقوية الاختبار | 1 |
| `H16` | the treasury that is named decides the branch | مكشوف | 6 |
| `H17` | a new expense is paid from the default treasury of the employee (stampFor) | مكشوف | 3 |
| `H18` | an edited expense keeps its branch | مكشوف بعد تقوية الاختبار | 2 |
| `H19` | a treasury of 0 asks for the default treasury | مكشوف بعد تقوية الاختبار | 1 |
| `H20` | posting an expense is audited | مكشوف | 2 |
| `H21` | posting an expense again is audited | مكشوف | 1 |
| `H22` | an expense the books never followed has nothing to sync | مكشوف | 1 |
| `H23` | an expense without a branch is not a document of the books | مكشوف | 1 |
| `H24` | deleting an expense that is not in the books does nothing | مكشوف | 3 |
| `H25` | deleting an expense is audited (cancel) | مكشوف | 1 |
| `H26` | a reversed journal does not count as being in the books (unposted) | مكشوف | 2 |
| `H27` | the journal of another source does not count as being in the books (unposted) | مكشوف | 2 |
| `H30` | no cost line when the whole amount is tax | مكشوف | 1 |
| `H31` | no tax line for an expense without tax | مكشوف | 4 |
| `H34` | money that came back credits the cost and the tax | مكشوف | 1 |
| `H35` | the account owed to other creditors must exist | مكشوف بعد تقوية الاختبار | 1 |
| `H36` | the account owed to other creditors cannot be a group | مكشوف بعد تقوية الاختبار | 1 |
| `H37` | the account owed to other creditors cannot be inactive | مكشوف بعد تقوية الاختبار | 1 |
| `H40` | a new cash-up that is already deleted is not stamped or posted | مكشوف بعد تقوية الاختبار | 1 |
| `H41` | a new cash-up closed before accounting starts is not stamped or posted | مكشوف | 2 |
| `H42` | a cash-up moved before accounting starts is not stamped again | مكشوف بعد تقوية الاختبار | 1 |
| `H43` | a cash-up that is marked deleted is not stamped again | مكشوف بعد تقوية الاختبار | 1 |
| `H44` | the difference is the counted cash less the expected cash | مكشوف | 17 |
| `H45` | the counted cash of a change defaults to the stored one | مكشوف | 1 |
| `H46` | the dates of a change default to the stored ones | مكشوف | 1 |
| `H47` | the opening cash of a change defaults to the stored one | مكشوف | 1 |
| `H48` | the transferred cash of a change defaults to the stored one | مكشوف بعد تقوية الاختبار | 1 |
| `H49` | posting a cash-up is audited | مكشوف | 2 |
| `H50` | posting a cash-up again is audited | مكشوف | 1 |
| `H51` | a cash-up the books never followed has nothing to sync | مكشوف | 1 |
| `H52` | a cash-up without a branch is not a document of the books | مكشوف بعد تقوية الاختبار | 1 |
| `H53` | deleting a cash-up that is not in the books does nothing | مكشوف | 2 |
| `H54` | deleting a cash-up is audited (cancel) | مكشوف | 1 |
| `H55` | only the cash-ups without a branch are unposted | مكشوف | 1 |
| `H56` | the expected cash is for the default branch of the employee | مكشوف بعد تقوية الاختبار | 1 |
| `H62` | the cash that is over is a debit of the drawer | مكشوف | 1 |
| `H65` | the expenses paid out of the drawer are taken off | مكشوف | 6 |
| `H66` | the cash the drawer was opened with counts | مكشوف | 16 |
| `H67` | the cash that was moved into the drawer counts | مكشوف | 1 |
| `H68` | the times of the cash-up count when the screen asks for them | مكشوف بعد تقوية الاختبار | 1 |
| `H70` | a value that is not a number is no account | مكشوف بعد تقوية الاختبار | 1 |
| `H71` | an account of 0 is no account | مكشوف بعد تقوية الاختبار | 1 |
| `H72` | the account chosen for a category is saved | مكشوف | 4 |
| `H73` | the screen asks for the permission to change the routing (mayAssign) | مكشوف | 1 |
| `H80` | an expense that is changed is read under a lock | مكشوف بعد تقوية الاختبار | 1 |
| `H81` | the expenses of a delete are locked in order | مكشوف بعد تقوية الاختبار | 1 |
| `H82` | the employee of the expense is the actor when none is given | مكشوف بعد تقوية الاختبار | 1 |
| `H84` | a reversed journal does not count as in the books (ledger state) | مكشوف | 4 |
| `H85` | the journal of another source does not count as in the books (ledger state) | مكشوف | 5 |
| `H86` | the ledger state is read only with accounting on (search) | مكشوف | 1 |
| `H87` | the ledger state is read only with accounting on (get_info) | مكشوف | 1 |
| `H88` | a deleted expense is marked deleted by the delete | مكشوف | 2 |
| `H90` | a cash-up that is changed is read under a lock | مكشوف بعد تقوية الاختبار | 1 |
| `H91` | the cash-ups of a delete are locked in order | مكشوف بعد تقوية الاختبار | 1 |
| `H92` | the employee who closes the cash-up is the actor when none is given | مكشوف بعد تقوية الاختبار | 1 |
| `H94` | the ledger state is read only with accounting on (search) | مكشوف | 1 |
| `H95` | the ledger state is read only with accounting on (get_info) | مكشوف | 1 |
| `H96` | a deleted cash-up is marked deleted by the delete | مكشوف | 2 |
| `H97` | the ledger state of one cash-up is not unposted once deleted (get_info) | مكشوف بعد تقوية الاختبار | 1 |
| `H100` | the category is saved with its account in one transaction | مكشوف | 2 |
| `H101` | the account is assigned only when the category was saved | مكشوف بعد تقوية الاختبار | 1 |
| `H102` | a form that does not carry an account leaves the account alone | مكشوف | 1 |
| `H110` | the filter of unposted expenses is offered only with accounting on | مكشوف | 1 |
| `H111` | the filter of unposted cash-ups is offered only with accounting on | مكشوف بعد تقوية الاختبار | 1 |
| `H113` | the logged in employee is the actor of an expense save | مكشوف بعد تقوية الاختبار | 1 |
| `H114` | the logged in employee is the actor of an expense delete | مكشوف | 1 |
| `H115` | the treasuries offered are only those of the branches the employee works in | مكشوف بعد تقوية الاختبار | 1 |
| `H116` | no treasuries are offered while accounting is off | مكشوف | 1 |
| `H118` | the logged in employee is the actor of a cash-up delete | مكشوف | 1 |
| `H119` | the screen of a cash-up works out the expected cash with accounting on | مكشوف | 1 |
| `H120` | the screen of a closed cash-up shows what was expected when it was saved | مكشوف بعد تقوية الاختبار | 1 |
| `H121` | the screen of a category knows whether accounting is on | مكشوف | 1 |
| `H123` | the setting of the category account is read from the form | مكشوف بعد تقوية الاختبار | 1 |
| `H130` | the ledger column of the expense list is shown only with accounting on | مكشوف بعد تقوية الاختبار | 1 |
| `H131` | the ledger column of the cash-up list is shown only with accounting on | مكشوف بعد تقوية الاختبار | 1 |
| `H132` | an expense that is in the books says so | مكشوف | 1 |
| `H133` | a cash-up that is in the books says so | مكشوف | 1 |
| `H134` | the treasury of an expense is offered only when there is a choice (view) | مكشوف بعد تقوية الاختبار | 1 |
| `H135` | the account of a category is offered only with accounting on (view) | مكشوف بعد تقوية الاختبار | 1 |
| `H136` | the account of a category is locked for who may not change the routing (view) | مكشوف | 1 |
| `H137` | the setting of the category account shows its state (view) | مكشوف بعد تقوية الاختبار | 1 |
| `K01` | a branch sent with an expense is never written (plan unset branch_id) | غير مكشوف | 0 |
| `K02` | a treasury sent with an expense is never written unchecked (plan unset treasury_id) | مكشوف | 1 |
| `K03` | a branch sent with a cash-up is never written (plan unset branch_id) | مكشوف | 1 |
| `K04` | a treasury sent with a cash-up is never written unchecked (plan unset treasury_id) | مكشوف | 1 |
| `K05` | the books follow an expense only while accounting is on (follows: isEnabled) | غير مكشوف | 0 |
| `K06` | the books follow a cash-up only while accounting is on (follows: isEnabled) | غير مكشوف | 0 |
| `K07` | a stale refusal is cleared by the next expense save | مكشوف | 1 |
| `K08` | a stale refusal is cleared by the next expense delete | مكشوف | 1 |
| `K09` | a stale refusal is cleared by the next cash-up save | مكشوف | 1 |
<!-- MUT-TABLE-END -->

## 3. ما افترضتُه (**لم يؤكّدها المالك بعد**)

1. **`amount` يشمل الضريبة** (ما دفعه الصندوق فعلًا)، و`tax_amount` جزء منه: القيد مدين المصروف `amount − tax_amount` ومدين الضريبة `tax_amount` ودائن الخزينة `amount`. **الاستنتاج** من أن إقفال الصندوق القديم في الأول برو يطرح `amount` وحده من النقد، فلو كان `amount` بلا ضريبة لنقص الصندوق عن المتوقع بقيمتها. **هذا يختلف عن شاشة دفترة** (تُدخل 200 وتضيف 15% فيصير 230): من يُدخل 200 مع ضريبة 30 عندنا يصل إلى 170 مصروفًا و30 ضريبة. إن أردتَ سلوك دفترة (المبلغ قبل الضريبة) فالتغيير في المتحكم والنموذج وحدهما، لا في الباني.
2. **مصروف «آجل» (Due) يُرحَّل إلى `2213 أطراف دائنة أخرى`** (دائن بالمبلغ كله، ومدين المصروف والضريبة)، وتغيير وسيلة دفعه إلى نقدية **يعكس القيد ويرحّل قيدًا جديدًا من الخزينة** (تسوية). لا أثر لخزينة ولا نطاق سحب ما دام آجلًا. **لم يُرصد في دفترة ما يفعله المصروف غير المدفوع**، فهذا افتراض من عندي ويبقى رهن الرصد (`daftraObservationsNeeded`).
3. **فرع المصروف:** فرع الخزينة المسماة، وإلا الفرع الافتراضي للموظف المسجَّل دخوله (أو الفرع الافتراضي للشركة). ومن غير فروع الموظف يُرفض (`not_allowed`). وفرع إقفال الصندوق هو الفرع الافتراضي للموظف.
4. **خزينة المصروف الجديد:** المسماة، وإلا خزينة الموظف الافتراضية في فرعه (إن كانت مفتوحة له للسحب)، وإلا حساب وسيلة الدفع الموجَّه. **تعديل مصروف مرحَّل لا يغيّر خزينته** ما لم تُسمَّ خزينة أخرى.
5. **صاحب القرار في الصلاحيات هو المسجَّل دخوله** لا موظف المصروف (`employee_id` حقل عرض)، لأن من يضغط «حفظ» هو من يحرّك المال.
6. **اختيار حساب التصنيف = إذن تغيير التوجيه + كل الفروع** (كاختيار الحسابات الموجَّهة)، لا صلاحية جديدة. وتعيين حساب لتصنيف فيه مصروفات مرحَّلة **لا يمسّ قيودها فورًا**؛ **يُعاد ترحيل كل مصروف منها عند أول حفظ له** لأن القيد القائم يُقارَن بما يُبنى الآن (فيُعكس ويُرحَّل بالحساب الجديد).
7. **تصميم إقفال الصندوق (A4) المتحفظ، للمالك حق نقضه:** لا مقابل له في دفترة (لم يُرصد)، فاخترتُ أقل ما يصحّح الدفاتر **دون ازدواج**: **الفرق وحده** يُرحَّل لأن كل بيع نقدي ومصروف نقدي رُحِّل في خزينته أصلًا؛ ترحيل المبلغ المعدود كله كان سيضاعف النقد. **تعريف النقد المتوقع** (أخذتُه من حساب شاشة الإقفال القديمة وقيّدته بالفرع): افتتاح + مُحوَّل + نقد البيع المكتمل − المردود − مصروفات النقد، والنقد يُعرف باسم الوسيلة بلغة التشغيل أو الإنجليزية. **لا فرق للبطاقة ولا للشيك ولا للآجل** (تُعدّ ولا تُرحَّل). حساب العجز والزيادة `1208` هو الموجَّه لـ`cash_difference`. **إقفال لم يُعدّ فيه شيء** (المبالغ الأربعة المغلقة صفر) **يُعدّ مفتوحًا** كما تعامله الشاشة، فلا فرق له.
8. **الإقفال بلا فرع** (القديم) والمصروف بلا فرع **يُحسبان من الفرع الافتراضي** في حساب النقد المتوقع وفي قائمة «غير المرحّل» (من يرى الفرع الافتراضي يرى القديمة).
9. **العلامة التاريخية = `branch_id` فارغ.** مصروف مؤرَّخ قبل بدء المحاسبة لا يُعطى فرعًا فلا يُرحَّل ولو عُدِّل بعد التفعيل. ومصروف حُفظ مرحَّلًا ثم نُقل تاريخه إلى ما قبل البدء **يُعكس قيده** (لأن الدفتر لم يعد يتبعه) ويبقى بفرعه.
10. **القائمة:** «الدفتر» مرحَّل حين يقوم له قيد، **أو حين لا يلزمه قيد** (مبلغ صفر أو محذوف)، فيتفق الفلتر وعدّاد الجاهزية.
11. **إعداد `accounting_expense_account_required`** لإلزام الحساب للمصروفات فقط (دفترة فيها إعدادان للمصروف والإيراد؛ الإيراد خارج الحزمة).

## 4. ما بقي

- **الترحيل بأثر رجعي (`A6`)** للمصروفات والإقفالات المحفوظة قبل التفعيل: لم يُنفَّذ بقصد. تُسرد غير مرحَّلة والجاهزية تنبّه إليها فقط.
- **`FIN-03`** سند القبض (إيراد) وشاشة تصنيفات الإيراد، و**`FIN-06`** المصروف المتكرر: حزمتان منفصلتان. وكذلك ما في دفترة ولا عندنا: عملة المصروف، ومرفقاته، وحسابات متعددة في مصروف واحد، ومراكز التكلفة.
- **إقفال الصندوق للبطاقة والشيك والآجل** (يُعدّ ولا يُرحَّل)، وفرق **خزينة بنكية** ما زال خارجه.
- **رصد دفترة** (`daftraObservationsNeeded`): ما يفعله المصروف غير المدفوع، وهل يعدَّل قيد المصروف بعكس وإعادة أم في مكانه، وما يظهر عند حذفه، وهل المبلغ المُدخَل قبل الضريبة أم بعدها إن كُتبت الضريبة مبلغًا لا نسبة.
- **تقارير المصروفات حسب التصنيف والحساب** (`REP-15`): ليست في الحزمة.
- شاشة الإقفال تعرض النقد المتوقع للفرع الافتراضي للموظف المسجَّل دخوله فقط (فرع الإقفال الأول)، وتعديل إقفال قديم يعرض ما حُفظ.

## 5. قبل النشر

1. **الترحيل `20261120000000_ExpensesAccounting`** إضافي ويُنفَّذ تلقائيًا عند أول دخول ويُراجَع صراحة: يضيف `expenses.branch_id` و`expenses.treasury_id` و`expense_categories.account_id` و`cash_up.branch_id` و`cash_up.treasury_id` و`cash_up.expected_amount_cash` و`cash_up.cash_difference` (كلها تقبل الفراغ بلا قيمة افتراضية) وفهارس ومفاتيح خارجية للخمسة الأولى، وكل خطوة تُفحص بـ`information_schema` فيكتمل ما توقّف في منتصفه ويُنفَّذ مرتين بلا أثر (اختبار). **لا صف قائم يتغير ولا قيد يُكتب**: كل مصروف وإقفال موجود يبقى بلا فرع ولا قيد ويظهر في قائمة «غير المرحّل».
2. **لا أثر حتى تُفعَّل المحاسبة** (D1): قبلها لا يتغير سلوك أي شاشة.
3. **بعد التفعيل:** كل مصروف جديد يُرحَّل **وقد يُرفض** (فترة مقفلة، فرع خارج فروع الموظف، خزينة لا يسحب منها، توجيه `tax.input` أو `expenses.default` ملغى)، فتأكد من التوجيه (`/accounting_routing`) قبل التفعيل.
4. **نقطة دمج:** `tests/Support/ParityFixtureTest.php` (`testIds_ListsFixturesT01ToT25`) يعدّ قائمة معرّفات الملفات حرفيًا؛ أي حزمة أخرى تضيف `T-26` ستتعارض معه في السطر نفسه فيُحسم عند الدمج. والمفاتيح الجديدة في ملفات اللغة تتعارض نصيًا مع حزم أخرى تضيف مفاتيح إلى `Accounting.php` و`Accounting_settings.php`: تُدمج بالترتيب الهجائي.
5. **مراجعة صلاحية:** من يضبط حساب التصنيف يحتاج إذن توجيه المحاسبة وعملًا في كل الفروع؛ لا صلاحية جديدة ولا ترحيل صلاحيات.

## 6. تحديثات الوثائق المشتركة للمُدمِج

لم ألمس `decisions.md` ولا `conformance-matrix.md` ولا `gap-report.md` ولا `docs/ui-redesign/deploy-checklist.md` ولا أي README. النصوص التالية هي ما يضيفه المُدمِج (بالترقيم الذي يحدده هو، دون رقم `E` هنا).

### 6.1 `decisions.md` (صف مقترح في آخر الجدول، بعد آخر صف)

```text
| — | **تنفيذ WP3: المصروفات وإقفال الصندوق في الدفاتر** (2026-10-26، [التقرير](daftra-parity/wp3-report.md)) بالتفويض التنفيذي الشامل: (1) **كل مصروف وتعديله وحذفه يُرحَّل في معاملة الحفظ بقيد واحد متوازن** (المصدر `expense`، مفتاح `(source_type, source_id, entry_type, revision)`)؛ **مدين** حساب التصنيف أو `541` الموجَّه بصافي المصروف **ومدين** `tax.input` (`215002`) بالضريبة **ودائن** حساب خزينة الدفع بالمبلغ كله بلا طرف، مطابق لقيد دفترة المرصود `S-25` (200 + 15% = 230). التعديل **عكس وإعادة ترحيل بمراجعة جديدة** (E15) والحذف عكس، والمعكوس في فترة مقفلة يُعكس بتاريخ اليوم. (2) **الفترات المقفلة ونطاق الفرع (`BranchGuard`) ونطاق سحب الخزينة (`TreasuryScopes`) تُفرض في النموذج** بحكم الموظف المسجَّل دخوله، ورصيد الخزينة يساوي رصيد حسابها. (3) **`FIN-04`:** للتصنيف حساب اختياري يقع عند غيابه على `541` الموجَّه، واختياره يلزمه إذن تغيير التوجيه وكل الفروع، وإعداد `accounting_expense_account_required` (مطفأ افتراضيًا) يشترط الحساب. (4) **إقفال الصندوق (A4) بتصميم متحفظ:** يُرحَّل **الفرق وحده** بين النقد المتوقع (يُحسب في الخادم: افتتاح + مُحوَّل + نقد البيع المكتمل في الفرع − المردود − مصروفات النقد) والمعدود: عجز مدين `1208` ودائن الخزينة، وزيادة عكسه، بالانضباط نفسه، **وللمالك نقض هذا التصميم**. (5) **التاريخي:** مصروف أو إقفال حُفظ قبل أن يتبعه الدفتر **لا قيد له ويُسرد غير مرحَّل** ولا يُرحَّل إلا بحزمة الترحيل بأثر رجعي؛ والمحاسبة المعطَّلة لا أثر لها (D1). (6) **قيدا المصروف والإقفال لا يُعكسان من شاشة القيود** (يُصحَّح المستند). **افتراضات بانتظار تأكيدك:** `amount` يشمل الضريبة (يخالف شاشة دفترة التي تضيفها)، والمصروف الآجل يُرحَّل إلى `2213`، وإذن اختيار حساب التصنيف هو إذن التوجيه، وتعريف النقد المتوقع، وفرع المصروف من خزينته أو من فرع موظفه الافتراضي. |
```

### 6.2 `conformance-matrix.md` (القسم 5، تعديل ثلاثة صفوف وملاحظة)

استبدل خلايا «الأول برو الآن» و«الحالة» و«الدليل والملاحظة» في الصفوف التالية بما يلي (ولا يُغيَّر عمود «دفترة (المرصود)»):

```text
| FIN-02 | (دفترة كما هو) | تصنيف ومبلغ وضريبة ودفع ومورد نصي ووصف، **ويُرحَّل كل مصروف وتعديله وحذفه إلى الدفتر في معاملة الحفظ** (WP3): مدين حساب التصنيف أو `541` الموجَّه بصافي المصروف، ومدين `215002` الموجَّه بالضريبة، ودائن الخزينة بالمبلغ بلا طرف، وعكس وإعادة ترحيل عند التعديل. **ينقص:** التكرار والمرفقات والحسابات المتعددة ومراكز التكلفة والعملة، و`amount` عندنا شامل الضريبة (دفترة تضيفها) | مختلف ويحتاج تعديلًا (**قيد المصروف مطابق في S-25 لحالة نقدية من خزينة بضريبة 15%**، والباقي ينقص) | addH, S-25, [تقرير WP3](wp3-report.md)، `T-25`، `ExpensePostingBuilderTest`، `ExpensePostingTest` |
| FIN-04 | (دفترة كما هو) | **للتصنيف حساب اختياري** (يقع عند غيابه على `541` الموجَّه) واختياره بإذن التوجيه وكل الفروع، وإعداد `accounting_expense_account_required` للمصروف (مطفأ افتراضيًا). **ينقص:** إعداد الإيراد (لا إيراد) | مختلف ويحتاج تعديلًا (المصروف فقط) | setC, [تقرير WP3](wp3-report.md)، `ExpensesControllerTest`، `ExpenseCategoryAccountsTest` |
| FIN-05 | (دفترة كما هو) | تصنيفات المصروفات **بحساب اختياري لكل تصنيف** (WP3)، **وشاشة تصنيفات الإيراد غير منفّذة** | مختلف ويحتاج تعديلًا | setC, [تقرير WP3](wp3-report.md)، `ExpensesCategoriesControllerTest` |
```

وفي صف `ACC-17` (أنواع القيود) أضف إلى خانة «الأول برو الآن» أن مصدري `expense` و`cashup` صارا نوعين للقيد الآلي (لا يُعكسان يدويًا). وصف إقفال الصندوق `A4` لا نظير له في المصفوفة فلا صف جديد: يكفي ما في تقرير الفجوات أدناه.

### 6.3 `gap-report.md` (بند جديد في قسم ما نُفِّذ بعد تقريرها، أو صف مقابل)

```text
| `A4` + `FIN-02` | **المصروفات وإقفال الصندوق صارت تُرحَّل إلى الدفتر (WP3):** كل مصروف وتعديله وحذفه بقيد واحد متوازن في معاملة الحفظ (`S-25` مطابق لحالته)، وإقفال الصندوق **بفرقه وحده** (عجز مدين `1208`، وزيادة دائن `1208`) بتصميم متحفظ للمالك حق نقضه. **ما بقي:** الترحيل بأثر رجعي للمصروفات والإقفالات القديمة (`A6`، تُسرد غير مرحَّلة وتنبّه الجاهزية)، وسند القبض `FIN-03` والتكرار `FIN-06`، وما يفعله المصروف غير المدفوع في دفترة (رصد)، وقاعدة هل `amount` قبل الضريبة (قرار المالك). |
```

### 6.4 `docs/ui-redesign/deploy-checklist.md` (بند في قائمة الترحيلات قبل «بعد النشر»)

```text
- [ ] **المصروفات وإقفال الصندوق (WP3):** الترحيل `20261120000000_ExpensesAccounting` إضافي ويُنفَّذ تلقائيًا عند أول دخول ويُراجَع صراحة. يضيف أعمدة **تقبل الفراغ** إلى `expenses` (`branch_id` و`treasury_id`) و`expense_categories` (`account_id`) و`cash_up` (`branch_id` و`treasury_id` و`expected_amount_cash` و`cash_difference`) وفهارس ومفاتيح خارجية لما يشير إلى جدول آخر، كلٌّ يُفحص على حدة فيكتمل ما توقّف في منتصفه. **لا يلمس صفًّا قائمًا ولا قيدًا**: المصروفات والإقفالات الموجودة تبقى بلا قيد وتُسرد «غير مرحّلة» (ويُنبَّه إليها في جاهزية المحاسبة) ولا يُرحَّلها إلا ترحيل بأثر رجعي. **لا أثر حتى تُفعَّل المحاسبة.** وبعد التفعيل يُرحَّل كل مصروف وإقفال صندوق جديد ويُرفض إن أُقفلت فترته أو خرج فرعه عن فروع الموظف أو لم يسحب من خزينته أو أُلغي توجيه `tax.input` أو `expenses.default` أو `cash_difference`، فتحقق من التوجيه قبل التفعيل. وتتعارض نصيًا ملفات اللغة وسطر معرّفات `ParityFixtureTest` مع حزم أخرى فتُدمج بالترتيب.
```
