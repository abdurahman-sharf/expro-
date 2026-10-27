# تقرير WP7: التسوية بين الفروع والأرصدة الثنائية والدفع عبر الفروع

> الحالة (2026-10-27، **بعد مرحلة الإصلاح**: القسم 7.2 فيه ما كشفه مراجعان مستقلان وما فُعل بكل نقطة): **نُفِّذ على الفرع `wp/WP7`** (من الالتزام `f9fe3e6` من فرع التطوير) بتفويضك التنفيذي E47، للبنود `G-61` (المتبقي منه: مَن عليه لمن بين فرعين بعينهما) و`INTERBRANCH-SETTLEMENT` و`P6-ASSUMPTION-7` (دفعة من فرع في خزينة فرع آخر) من [قائمة الأعمال](backlog.md). **لا دمج في `master`، ولا نشر، ولا كتابة في دفترة.** هذا تصميم داخلي لا مقابل له في دفترة، فلم أحتج ملاحظة جديدة منها، وما يحتاج قرارك أو رصد دفترة وسمتُه **افتراضًا** في القسم 4. **لم أشغّل شاشات التطبيق في متصفح (ui-smoke):** كل ما يلي مُثبَت بالاختبارات عبر النماذج والمتحكمات الحقيقية.

## 1. ما نُفِّذ

| الجزء | ما يفعله |
|---|---|
| **الأرصدة الثنائية** (`InterBranchBalances` والشاشة `accounting_treasuries/inter_branch`) | لكل **زوج مرتَّب** من الفروع: ما **يستحقه** الفرع على الآخر وما **عليه** له والصافي، من قيود الحسابين `12044` و`2214`. الفرع المقابل للقيد يُعرف **من سجل يُكتب مع القيد** (تسوية أو دفعة عبر الفروع: الأساس «مسجَّل») أو، للتحويلات التي رُحِّلت قبل هذا العمل، **من مستندها** (`treasury_transfers` و`receivings.to_branch_id`: الأساس «من التحويلات»)، **والزوج الذي يجمع الاثنين «مختلط»**. **قيد عكس يتبع زوج القيد الذي يعكسه.** ما لا يقوله شيء (قيد يدوي على الحسابين مثلًا) **يُعرض منفصلًا باسم «غير منسوب» ولا يُخمَّن ولا يُعدَّل قيد ليُنسب**. عمود «اتفاق» يقول إن **ما يستحقه أ على ب هو ما على ب لأ، وما على أ لب هو ما يستحقه ب على أ، كلٌّ على حدة** (القاعدة نفسها التي تُرفض بها التسوية: `PairBalance::agree`). **تُجمع المبالغ في قاعدة البيانات، صفًّا لكل زوج لا لكل قيد** (2.8). **فحص الشركة** `InterBranchPosition::difference` (صفر دائمًا بعد كل عملية) يظهر **لمن يقرأ كل الفروع وحدهم**. للشاشة **تاريخ «حتى يوم»** وتتبع **نطاق التقارير (E45)**: من يعمل في فرع واحد يرى أزواج فرعه فقط ويُنبَّه أن الصورة جزئية. الصلاحية: **وحدة `accounting_treasuries` نفسها** (لا صلاحية جديدة للقراءة) |
| **سجل «الفرع المقابل»** (`InterBranchLegs`، جدول `accounting_interbranch_legs`) | صف لكل (قيد، حساب بين الفروع) يُكتب **مع القيد الجديد وحده**: الفرع المقابل ونوع الجانب (يستحق/عليه) والمستند ونسخته. **لا يُعدَّل قيد قائم ولا يُكتب سجل لقيد قديم.** وهو أيضًا ما يجعل قيدين **زوجًا**: يُجمعان بالمستند والنسخة (`partnersOf`) فيُعكسان معًا |
| **وثيقة التسوية** (`InterBranchSettlements`، جدول `interbranch_settlements`) | **وثيقة واحدة وقيدان**: قيد لكل فرع، **كل منهما متوازن داخل فرعه**، يُخرج من الحسابين بين الفروع ما كان للفرع وما كان عليه حتى اليوم المختار، **والباقي يتحرك نقدًا** من خزينة الفرع الذي عليه إلى خزينة الفرع الذي له (**تسوية نقدية**) أو **لا يتحرك شيء إن تقاصّ الطرفان تمامًا** (**تصافٍ**، لا خزائن). **تُكتب القيود بترتيب رقمَي الفرعين** (فتأخذ أقفال الفترة للفرعين بالترتيب نفسه دائمًا)، **وتُسبقها قفلة صف الزوج** (`interbranch_pair_locks`) فتنتظر تسويتان لزوج واحد إحداهما الأخرى. **وفق مفتاح الطلب** (`request_key`، فريد بالشركة) **يعيد الطلب المكرَّر التسوية الأولى** ولا يرحّل مرتين. **يُرفض** إن وُجدت **تسوية للزوج غير ملغاة بتاريخ بعد اليوم المختار** (`settlement_older_than_settled`: كانت تُصفّي الأرصدة نفسها وتحرّك النقد نفسه مرتين، 2.7) أو إن لم يتفق الفرعان على ما بينهما (`settlement_pair_not_agreed`) أو لم يكن بينهما شيء (`settlement_nothing_to_settle`) أو كان الفرعان واحدًا أو تاريخ غير صالح أو في فترة مقفلة **لأي** من الفرعين (ترجع المعاملة كلها)، وفي النقدية: لا بد من خزينتين مختلفتين فعّالتين **من فرعي الطرفين بالاتجاه الصحيح**، **ونطاق سحب** خزينة الدافع **ونطاق إيداع** خزينة المستلم. **الموظف يعمل في الفرعين (E43)** ويحمل الصلاحية الجديدة. **يُكتب التدقيق سطرًا لكل فرع** (`interbranch_settlement` / `created`) |
| **إلغاء التسوية** (`InterBranchSettlements::cancel`) | **يعكس القيدين معًا أو لا يعكس شيئًا** (معاملة واحدة)، بسبب إلزامي، **كل قيد في يومه** (يوم القيد إن كانت فترة فرعه مفتوحة وإلا اليوم)، **بترتيب رقمَي الفرعين**، ويسأل **نطاقي الخزينتين** قبل أن يعكس أيًّا منهما، ويأخذ قفلة الزوج. **لا إلغاء جزئي**: لا يوجد مسار يعكس أحد القيدين وحده (انظر عكس الزوج). يُكتب التدقيق سطرًا لكل فرع (`cancelled`). بعد الإلغاء يمكن تسوية الزوج من جديد |
| **الدفع عبر الفروع** (`CrossBranchPayments`) | **دفعة عميل على بيع** أو **دفعة مورد على استلام** أو **مصروف** لفرع أ قد يدخل خزينة فرع ب أو يخرج منها (للمسترد). تحمل الدفعة عمودًا جديدًا `treasury_branch_id` (**فارغ دائمًا لما هو موجود**): **وحده من يحمله ويخالف فرع المستند يُرحَّل قيدين**: قيد المستند في فرع أ (يسوّي العميل أو المورد أو المصروف **مقابل حساب بين الفروع**) وقيد الخزينة في فرع ب (الخزينة **مقابل الحساب الآخر**)، **كل منهما متوازن في فرعه، يُكتبان بترتيب رقمَي الفرعين، ويُعدَّلان ويُعكسان معًا**. يبقى **ميزان كل فرع متوازنًا، ورصيد الخزينة = رصيد حسابها، وتسوية العميل/المورد في فرع المستند، ويتقاصّ الحسابان بين الفروع على الشركة**، والعكس يعيد كل شيء. المصادر الجديدة للقيد الثاني `sale_payment_branch` و`supplier_payment_branch` و`expense_branch` (بالمستند ورقمه ونسخته نفسها). **لا `purchase_payment_branch`**: الدفعة التي تؤخذ مع الشراء نفسه تذهب دائمًا إلى خزينة فرع الشراء، فحُذف المصدر الذي لا تنتجه شاشة ولا نموذج. **إقفال الصندوق يتوقع النقد حسب فرع الخزينة التي دخلها المال أو خرج منها** (2.9) |
| **عكس الزوج** (`JournalService::reverseAutomatic` وما يستدعيه `reversePair`) | عند عكس قيد له شريك (بالسجل) **يُعكس الشريك معه في المعاملة نفسها**، **بعد أن يُسأل** عن فرع الشريك (**يعمل الموظف فيه**، وإلا `interbranch_branches_denied` باسم الفرع) وعن **نطاق خزينته**، قبل أي عكس، **وكل قيد في يومه** حسب فترة فرعه، **بترتيب رقمَي الفرعين**. **عكس قيد واحد من زوج باليد مرفوض** (`transfer_journal_locked`) وزر العكس لا يظهر له في شاشة القيود |
| **شاشات الدفع** | قوائم «الخزينة» في نموذجي البيع والاستلام تعرض **خزائن الفروع الأخرى مسمّاة برمز فرعها** (`اسم (B2)`) **لمن يعمل في فرع المستند وفي ذلك الفرع فقط** (`TreasuryService::choicesFor`)، ولا تُعرض أبدًا والمحاسبة معطَّلة. **نموذج المصروف** صار فيه (لمن يعمل في أكثر من فرع) حقل **«الفرع الذي يُحمَّل عليه المصروف»** (الأول: فرع الخزينة كما كان) |
| **الصلاحية** | `acc_interbranch_settle` تحت وحدة `accounting_treasuries` **ولها اسم في جدول حاملي الصلاحيات الحساسة بشاشة إعدادات المحاسبة وفي ملاحظة نموذج الموظف** (بدونه كانت الشاشة تفشل لكل مستخدم ما إن تُمنح لأحد: القسم 7.2)، **حساسة** (في `AccountingGuard::SENSITIVE`: لا يمنحها ولا يسحبها إلا المدير، ويُكتب ذلك في التدقيق)، **والترحيل لا يمنحها لأحد** (اختبار). تحكم **التسوية وإلغاءها** وظهور زرّيهما. **الدفع عبر الفروع لا صلاحية جديدة له**: يلزم أن يعمل الموظف في الفرعين وأن يسمح نطاق الخزينة (E43 وE42) |
| **الترحيلان** (إضافيان، يُعاد تشغيلهما بأمان) | `20270200000000_InterBranchSettlements`: الجداول `interbranch_settlements` و`accounting_interbranch_legs` و`interbranch_pair_locks` والصلاحية بلا منح. `20270200000001_CrossBranchPayments`: العمود `treasury_branch_id` (فارغ، بفهرس ومفتاح أجنبي إلى `branches`) في `sales_payments` و`receivings_payments` و`expenses`. **لا يمسان قيدًا ولا دفعة ولا رصيدًا** (اختبار) |
| **اللغات** | مفاتيح جديدة في `Accounting` (أخطاء التسوية والفروع، ومنها `error_settlement_older_than_settled`، ومصادر القيود الجديدة) و`Accounting_treasuries` (47 مفتاحًا للشاشة والصلاحية) و`Expenses` (3 مفاتيح) و`Sales` (`entry_payment_branch`: اسم قيد الخزينة في نموذج تعديل البيع) في **46 لغة**: الإنجليزية في `en` و`en-GB`، **والعربية الفعلية في `ar-EG` و`ar-LB`**، والباقي `''`. نصّ `error_transfer_journal_locked` عُدِّل ليشمل قيد الدفعة والتسوية |
| **ما لم يتغير** | المحاسبة المعطَّلة: لا أثر (خزائن الفروع الأخرى مرفوضة والحقلان `expense_branch_id` و`treasury_branch_id` يُسقطان)، فيبقى OSPOS كما كان. كل دفعة قائمة تُرحَّل **قيدًا واحدًا كما كانت** (لا إعادة ترحيل). التحويلات بين الخزائن والمخزون بين الفروع كما هي (قيد لكل فرع) ولا يُكتب لها سجل |

## 2. التحليل والقرارات

### 2.1 لماذا سجل «الفرع المقابل» وليس استنتاجه

الحسابان `12044` و`2214` **واحدان لكل الفروع**، فسطر على أحدهما **لا يقول شيئًا عن الفرع الآخر**. كان `G-61` يعرف **فقط** أن الشركة كلها تتقاصّ (`InterBranchPosition::difference`). الفرع المقابل إما أن يُكتب مع القيد (ما بعد هذا العمل) أو يُقرأ من مستند التحويل (ما قبله)، وإلا فلا يُعرف. **اخترتُ ألا أنسب قيدًا مجهولًا لفرع بالتخمين** ولا أن أعيد كتابة قيد قديم: الزوج المجهول يظهر «غير منسوب» وفحص الشركة يراه. **«مسجَّل» أقوى من «من التحويلات»** (إن اجتمعا لقيد، يغلب السجل: اختبار).

### 2.2 لماذا قيدان وليس قيدًا واحدًا

القيد ينتمي لفرع واحد وكل فرع له دفتره وميزان مراجعته وفتراته المقفلة (D10). فالدفعة عبر الفروع **قيدان**، وهو تصميم G-61 للتحويل نفسه. **لا قيد يُكتب في فرع ولا يتوازن داخله**: ميزان كل فرع متوازن بعد كل عملية (اختبار على كل فرع بعد كل مسار).

### 2.3 حساب التسوية

المتاح للفرع أ على الفرع ب = ما قيّده أ على `12044` مقابل ب، وما عليه = ما قيّده على `2214` مقابل ب، **حتى اليوم المختار** (القيود بعده تبقى). الفرعان **يتفقان** إن كان `أ.يستحق = ب.عليه` و`أ.عليه = ب.يستحق`. الصافي = `أ.يستحق − أ.عليه`: **سالب → أ يدفع نقدًا لب، موجب → ب يدفع نقدًا لأ، صفر مع وجود أرصدة → تصافٍ بلا نقد**. **تُصفَّى الأرصدة كلها دفعة واحدة** (لا تسوية جزئية: افتراض 4.1). يوم القيدين هو يوم التسوية. **ولا تُقبل تسوية لتاريخ يسبق تسوية قائمة للزوج** (2.7).

### 2.4 الأقفال: مَن ينتظر مَن

ترحيل أي قيد يأخذ قفلة الشركة مشتركة ثم قفلة فرعه مشتركة (`PeriodLocks`)، والإقفال يأخذ قفلة نطاقه حصرية، **والترتيب الدائم: الشركة ثم الفرع**. القيدان معًا يأخذان قفلتي الفرعين، فلئلا تنتظر عمليتان مختلفتا الاتجاه إحداهما الأخرى (**أ→ب** و**ب→أ**) **تُكتب القيود وتُعكس دائمًا بترتيب رقم الفرع الأصغر أولًا** أيًّا كان الفرع الدافع أو صاحب الخزينة. الاختبارات تقرأ **ترتيب استعلامات القفل الفعلي** (`[1, ب]` في كل اتجاه، للتسوية وللإلغاء وللدفعة وللعكس، **وإن أُعطيت القيود لـ`post` مقلوبة**). التسويتان المتزامنتان لزوج واحد: الثانية **تنتظر** الأولى (اختبار بجلستين حقيقيتين)، وكذلك الإلغاء.

### 2.5 الدفعات القديمة

كل دفعة موجودة `treasury_branch_id` فيها فارغ، **فتُرحَّل كما كانت قيدًا واحدًا**، ولا يمسها الترحيل ولا إعادة الترحيل. دفعة **وُسمت بخزينة فرع آخر قبل هذا العمل** (لا تُنتجها الشاشات: كان `assertForBranch` يمنعها، لكن قاعدة بيانات عبث بها قد تحمل واحدة) **تبقى قيدًا واحدًا** وتُقرأ كما هي (اختبار).

### 2.6 عكس الزوج في فرع أُقفلت فترته: خلاف مؤقت بين الفرعين

إن أُقفلت فترة أحد الفرعين بعد التسوية أو الدفعة، **يُعكس قيد ذلك الفرع اليوم** بينما يُعكس قيد الفرع الآخر في يومه. **بين اليومين لا يتفق الزوج** في شاشة الأرصدة «حتى يوم» يقع بينهما، **ولا تُقبل تسوية لذلك اليوم** (`settlement_pair_not_agreed`) وتُقبل لما بعد اليومين. هذا مقصود: كل فرع لا يُحرَّر قيده في فترة مقفلة (D3)، **وفحص الشركة بلا تاريخ يبقى صفرًا**. موثَّق في اختبارات الإلغاء والحذف.

### 2.7 تسوية بتاريخ يسبق تسوية قائمة

**الخلل (كشفه المراجع):** قيد التسوية يُؤرَّخ بيوم التسوية والخطة تعدّ القيود حتى ذلك اليوم وحده. فتسوية بتاريخ 25 أكتوبر بعد تسوية بتاريخ 31 أكتوبر **لا ترى الأولى** (قيدها بعد اليوم المطلوب)، فيبدو الزوج مدينًا ويتفق، ويُصفّى مرة ثانية ويُحرَّك النقد نفسه مرة ثانية؛ وفحص الشركة يبقى صفرًا فلا ينبّه شيء. وشاشة الأرصدة بـ«حتى يوم» تعرض زر التسوية بهذا التاريخ نفسه (`as_of`). وكذلك تسوية بتاريخ مستقبلي ثم أخرى بتاريخ اليوم.

**القاعدة المختارة:** تُرفض التسوية، في الخطة وفي الوثيقة (`settlement_older_than_settled` ومعها تاريخ التسوية القائمة)، متى وُجدت **تسوية للزوج نفسه غير ملغاة بتاريخ بعد اليوم المختار**؛ ونموذج التسوية يقول ذلك ولا يعرض خزائن. وتُقبل: **اليوم نفسه أو ما بعده** (التسوية الثانية ترى الأولى)، **ويومٌ قبل تسوية ملغاة** (قيدها وعكسه بعد ذلك اليوم فلا أثر لهما فيه)، **ويومٌ قبل تسوية زوج آخر أو شركة أخرى**.

**لماذا لم أمنع «أي نشاط لاحق للزوج»** (البديل الأشد في التكليف): نشاط لاحق غير التسوية (تحويل بتاريخ بعد اليوم) لا تعدّه الخطة أصلًا ولا تلمسه التسوية ويبقى بعدها كما هو، ومنعُ التسوية بسببه يمنع تسوية آخر الشهر والشهر التالي بدأ، وهو ما يفعله المحاسبون. الذي يضرّ هو **قيد تسوية** في الفترة التي لا تراها الخطة، وهذا ما تمنعه القاعدة. **وتبقى حالة آمنة بالرفض لا بالتصفية المضاعفة:** تسوية أُلغيت وعُكس قيداها بعد اليوم المختار (لأن فترتهما أُقفلت) تُرى عند ذلك اليوم قائمة، فيبدو الزوج مصفّى ويُرفض بـ`settlement_nothing_to_settle`؛ وتُقبل بعد يوم العكس.

**الإثبات:** `InterBranchSettlementsTest` (يوم قبل تسوية قائمة، وقبل تسوية مستقبلية بأي ترتيب للفرعين، وقبل آخر عدة تسويات، واليوم نفسه، وقبل تسوية ملغاة، وقبل تسوية زوج آخر وشركة أخرى، والخطة)، و`AccountingInterBranchControllerTest` (الشاشة بـ`as_of` قبل التسوية تعرض الزر بالتاريخ نفسه، **ونموذج التسوية ثم الإرسال يرفضان** بالرسالة والتاريخ ولا يتحرك نقد).

### 2.8 تكلفة حساب الأرصدة

كانت `InterBranchBalances` تقرأ **كل قيد مسّ الحسابين في الشركة** وتبني كائنًا لكل قيد وتجمعها في PHP، وتفعل ذلك **داخل معاملة التسوية** (وهي تحمل قفلة الزوج وصف الخزينتين) وفي كل تحميل للشاشة. **قِسْتُ** على قاعدة اختبار بقيود اصطناعية (قيدان لكل دفعة عبر الفروع وسجل لكل قيد؛ نصفها بين الفرعين المسوَّيين والباقي بين فرعين آخرين) على جهاز تتقاسمه عدة عمليات (فالأزمنة تتذبذب بين تشغيل وآخر، والذاكرة ثابتة):

| القياس (250 ألف قيد على الحسابين، منها 50 ألفًا للفرعين المسوَّيين) | قبل (`cf3195d`) | بعد |
|---|---|---|
| زوج واحد (`between`، ما تقرؤه التسوية) | 5.7 إلى 6.9 ثانية، **396 ميغابايت** | **1.0 إلى 1.5 ثانية، 40 ميغابايت** |
| الشاشة كلها (`report` مع فحص الشركة) | 8.7 إلى 10.8 ثانية، 398 ميغابايت | **5.3 إلى 6.4 ثانية، 40 ميغابايت** |
| زوج واحد على 50 ألف قيد | 0.7 ثانية، 100 ميغابايت | 0.2 ثانية، 34 ميغابايت |
| الشاشة كلها على 50 ألف قيد | 1.1 إلى 1.3 ثانية، 100 ميغابايت | 1.0 ثانية، 34 ميغابايت |

**الإصلاح:** تُجمع المبالغ **في قاعدة البيانات** (استعلام واحد: صف لكل فرع ولكل فرع مقابل ولكل أساس معرفة)، ولا يبقى في الذاكرة غير الصفوف، **وتقرأ التسوية قيود فرعيها وحدهما** (`between`). النتيجة **مطابقة** للتنفيذ السابق على القيود نفسها (قارنتُ المصفوفتين كاملتين في القياس) وعلى كل حالات الاختبار (مسجَّل ومن التحويلات ومختلط وغير منسوب وعكس وحتى يوم). **ما بقي:** الزمن ما زال **خطيًّا في عدد القيود** على الحسابين (لا جدول ملخَّص): 250 ألف قيد، أي 125 ألف دفعة عبر الفروع، تحتاج بضع ثوانٍ للشاشة؛ فإن بلغ حجمك هذا فملخَّص مجمَّع للأزواج هو الخطوة التالية (القسم 5). وشرط `account_id` في الاستعلام للفهرس لا للنتيجة (المبالغ مقصورة على الحسابين بـ`CASE`)، فلا حارس يُعطَّل لإثباته.

### 2.9 إقفال الصندوق ودفعة عبر الفروع

**الخلل (كشفه المراجع، وتحققتُ منه):** `CashupExpectation` كانت تعدّ نقد مبيعات فرعٍ بفرع **البيع** وتطرح مصروفاته النقدية بفرع **المصروف**، وكانت هذه هي فرع الخزينة دائمًا قبل WP7. وبعد أن صارت الدفعة تدخل خزينة فرع آخر: إقفال صندوق ذلك الفرع يتوقع أقل مما في الدرج فيُرحِّل زيادة **وهمية** على خزينته، وإقفال فرع البيع يتوقع ما لم يدخل درجه فيُرحِّل عجزًا وهميًا؛ والمصروف المدفوع من درج فرع آخر يُطرح من الفرع الخطأ. **الإصلاح:** يُعدّ النقد بفرع الخزينة التي تحمله الدفعة (`sales_payments.treasury_branch_id`) وإلا بفرع البيع، ويُطرح المصروف النقدي من فرع خزينته (`expenses.treasury_branch_id`) وإلا من فرعه، فلا يتغير شيء لما ليس فيه فرع خزينة. **الإثبات:** `CashupExpectationTest` (دفعة نقدية في خزينة فرع آخر، ودفعتان لبيع واحد في فرعين، ومصروف في كل اتجاه) و`CashupPostingTest` (إقفال لا يُرحِّل فرقًا لأن ما دخل درجه هو المتوقع).


## 3. الأدلة

### 3.1 ما شغّلتُه بالضبط

| ما شُغِّل | النتيجة |
|---|---|
| `MYSQL_DB_NAME=ospos_test_i vendor/bin/phpunit --no-coverage tests/Libraries/Accounting` (**المجلد كله**، ويشمل ملفاتي الجديدة والموسَّعة) | **OK**: الاختبارات 2481، التأكيدات 13467 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/LanguageFilesTest.php` | **OK**: الاختبارات 1726، التأكيدات 2878 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Support/ParityFixtureTest.php` | **OK**: الاختبارات 104، التأكيدات 1131 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Database` | **OK**: الاختبارات 189، التأكيدات 949 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Libraries/Organization` | **OK**: الاختبارات 88، التأكيدات 369 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Models/Accounting` | **OK**: الاختبارات 5، التأكيدات 11 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Models/SaleTest.php` | **OK**: الاختبارات 47، التأكيدات 266 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Models/ReceivingTest.php` | **OK**: الاختبارات 21، التأكيدات 136 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Models/ExpenseTest.php` | **OK**: الاختبارات 29، التأكيدات 169 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Models/CashupTest.php` | **OK**: الاختبارات 13، التأكيدات 61 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Libraries/Sale_libPaymentTest.php` | **OK**: الاختبارات 12، التأكيدات 21 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Libraries/Sale_libTest.php` | **OK**: الاختبارات 34، التأكيدات 97 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Libraries/Receiving_libTest.php` | **OK**: الاختبارات 10، التأكيدات 12 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/helpers/NavigationHelperTest.php` | **OK**: الاختبارات 16، التأكيدات 22 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingInterBranchControllerTest.php` | **OK**: الاختبارات 29، التأكيدات 319 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingJournalsControllerTest.php` | **OK**: الاختبارات 112، التأكيدات 535 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingTreasuriesControllerTest.php` | **OK**: الاختبارات 47، التأكيدات 372 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingReportsControllerTest.php` | **OK**: الاختبارات 49، التأكيدات 278 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingReceivablesControllerTest.php` | **OK**: الاختبارات 16، التأكيدات 116 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingPayablesControllerTest.php` | **OK**: الاختبارات 30، التأكيدات 250 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingPeriodsControllerTest.php` | **OK**: الاختبارات 16، التأكيدات 74 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingClosingsControllerTest.php` | **OK**: الاختبارات 19، التأكيدات 93 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingChartControllerTest.php` | **OK**: الاختبارات 33، التأكيدات 132 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingCostCentersControllerTest.php` | **OK**: الاختبارات 22، التأكيدات 81 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingOpeningControllerTest.php` | **OK**: الاختبارات 38، التأكيدات 225 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingRoutingControllerTest.php` | **OK**: الاختبارات 21، التأكيدات 136 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/AccountingSetupControllerTest.php` | **OK**: الاختبارات 35، التأكيدات 202 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/BranchesControllerTest.php` | **OK**: الاختبارات 23، التأكيدات 92 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/CashupControllerTest.php` | **OK**: الاختبارات 19، التأكيدات 131 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/EmployeesControllerTest.php` | **OK**: الاختبارات 45، التأكيدات 246 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/ExpensesControllerTest.php` | **OK**: الاختبارات 44، التأكيدات 288 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/ReceivingsControllerTest.php` | **OK**: الاختبارات 86، التأكيدات 734 |
| `MYSQL_DB_NAME=ospos_test_i2 vendor/bin/phpunit --no-coverage tests/Controllers/SalesControllerTest.php` | **OK**: الاختبارات 152، التأكيدات 965 |

**لم أشغّل المجموعة الكاملة** (قاعدة هذه الحزمة: يشغّلها المُدمِج بعد الدمج). **لا اختبار فشل في ما شغّلتُه.** **قاعدتان:** `ospos_test_i` و`ospos_test_i2` وحدهما (لا اثنان من `phpunit` على قاعدة واحدة في وقت واحد: التعطيل على نسختين من الشجرة، واحدة على كل قاعدة، ثم الاختبارات واحدًا واحدًا)، ولا أمسّ `ospos_test` ولا `ospos_ui` ولا قاعدة حزمة أخرى (القياس في 2.8 على قاعدة `ospos_test_i_m1` الفرعية الخاصة بهذه الحزمة وأُفرغت بعده). **كل اختبار شُغِّل بعد آخر تعديل لشيفرة التطبيق** (آخره في الالتزام `1d30966`: تسمية متغيرين في حلقة الأرصدة، وقبله الاستعلام في `7d79323`، وما بعده الوثائق وحدها).

### 3.2 الاختبارات التي أضفتُها

| الملف | اختبارات مضافة |
|---|---:|
| `Controllers/AccountingInterBranchControllerTest.php` | 29 |
| `Controllers/AccountingJournalsControllerTest.php` | 1 |
| `Controllers/AccountingSetupControllerTest.php` | 2 |
| `Controllers/AccountingTreasuriesControllerTest.php` | 1 |
| `Controllers/ExpensesControllerTest.php` | 7 |
| `Controllers/ReceivingsControllerTest.php` | 4 |
| `Controllers/SalesControllerTest.php` | 5 |
| `Database/CrossBranchPaymentsMigrationTest.php` | 5 |
| `Database/InterBranchSettlementsMigrationTest.php` | 12 |
| `Libraries/Accounting/CashupExpectationTest.php` | 3 |
| `Libraries/Accounting/CashupPostingTest.php` | 1 |
| `Libraries/Accounting/CrossBranchPaymentsTest.php` | 32 |
| `Libraries/Accounting/ExpensePostingTest.php` | 20 |
| `Libraries/Accounting/InterBranchBalancesTest.php` | 19 |
| `Libraries/Accounting/InterBranchLegsTest.php` | 7 |
| `Libraries/Accounting/InterBranchPositionTest.php` | 1 |
| `Libraries/Accounting/InterBranchSettlementsTest.php` | 56 |
| `Libraries/Accounting/JournalServiceTest.php` | 9 |
| `Libraries/Accounting/ReceivingPostingTest.php` | 15 |
| `Libraries/Accounting/SalePostingTest.php` | 23 |
| `Libraries/Accounting/TreasuryScopesTest.php` | 4 |
| `Libraries/Accounting/TreasuryServiceTest.php` | 14 |
| **المجموع** | 270 |

وتعديلات اختبارات قائمة **مقصودة** (لأن السلوك تغيّر عمدًا): (1) `SalesControllerTest` و`ReceivingsControllerTest`: اسم الخزينة المحمولة من فرع آخر صار يحمل رمز فرعها «(B2)»؛ (2) `ReceivingsControllerTest`: اختبار رفض خزينة فرع آخر لمشترٍ صار «لمشترٍ لا يعمل في الفرعين» (من يعمل فيهما يُقبل الآن)؛ (3) `SensitivePermissionsTest` و`EmployeesControllerTest`: قوائم الصلاحيات الحساسة تشمل `acc_interbranch_settle`؛ (4) `TreasuriesMigrationTest::testDown_TakesTheTablesTheColumnsAndTheScreenAway`: يعيد ترحيل التسوية إلى الوراء أولًا (جداولها لها مفتاح أجنبي إلى `treasuries`) كما يفعل مع الترحيلات اللاحقة الأخرى؛ (5) `AccountingFixtureTrait::clearJournals` تفرغ الجداول الثلاثة الجديدة، وأُضيفت إليها `periodLocksTakenBy`؛ (6) **في مرحلة الإصلاح:** `InterBranchSettlementsTest::testCancel_ReversesTheJournalOfTheLowerBranchFirstWhicheverBranchPaid` صارت تؤرخ كل تسوية بعد ما قبلها (القاعدة 2.7 كانت ستمنع تسوية ثانية بتاريخ أسبق)، وأربعة اختبارات `testReverse_…ByHand` في `InterBranchSettlementsTest` و`SalePostingTest` و`ExpensePostingTest` و`ReceivingPostingTest` نُقلت إلى `JournalServiceTest` (7.2 البند 11).

### 3.3 الحراس ومن كشفها (التعطيل واحدًا واحدًا)

كل حارس **عُطِّل وحده** بـ`mutate_generic.py` (يستبدل نصًّا واحدًا في الملف، يشغّل الاختبارات المعنيّة، يعيد الملف) **فكُشف في 195 من 195**. التصنيف:

| المجال | الحراس | المكشوف |
|---|---:|---:|
| التسوية (`InterBranchSettlements`): الصلاحية، الفرعان، القفلة، مفتاح الطلب، الاتفاق، الخزينتان ونطاقاهما، الترتيب، التدقيق، الإلغاء، البحث | 37 | 37 |
| الأرصدة الثنائية (`InterBranchBalances`): النطاق، التاريخ، العكس، الأساس، الاتفاق | 11 | 11 |
| سجل الفرع المقابل (`InterBranchLegs`) | 5 | 5 |
| حسابا بين الفروع المسجَّلان (`InterBranchPosition`) | 1 | 1 |
| الدفع عبر الفروع (`CrossBranchPayments`): الكتابة بالترتيب، المعاملة، الفروع، النطاقات، المطابقة عند الاستبدال | 22 | 22 |
| عكس الزوج (`JournalService`): الشريك، الفرع، النطاق، اليوم، الترتيب، المعاملة، العكس اليدوي | 8 | 8 |
| الخزائن (`TreasuryService`): القائمة والتسمية، `otherBranchOf`، `placeOfPayment` | 13 | 13 |
| نطاق استبدال الزوج (`TreasuryScopes::assertMayReplaceAll`) | 3 | 3 |
| البيع والاستلام والمصروف والنماذج (`SalePosting` و`ReceivingPosting` و`ExpensePosting` و`DocumentJournals` و`Sale` و`Receiving` و`Expense`) | 14 | 14 |
| الشاشات والمتحكمات (`AccountingInterBranch` وقيود الدفتر والمصروف) | 23 | 23 |
| الواجهة | 1 | 1 |
| الصلاحية الحساسة | 1 | 1 |
| الترحيلان: القيود والمفاتيح الأجنبية وإعادة التشغيل | 22 | 22 |
| النموذج والمحاسبة معطَّلة | 2 | 2 |
| مرحلة الإصلاح: يوم التسوية، اسم الصلاحية، إقفال الصندوق، تهريب القوائم، اسم القيد، السهم، حساب الأرصدة في قاعدة البيانات، عكس قيد له سجل | 32 | 32 |
| **المجموع** | **195** | **195** |

**ما أزلتُه لأن تعطيله لا يغيّر أي سلوك** (القسم 7): حراسٌ يكررها حارس آخر فلا يُثبَت أي منها وحده، فحذفتُ المكرَّر بدل أن أدّعي إثباته. وعُطِّل كل حارس **بنص واحد** في **نسخة** من شجرة العمل (`rsync`: نسختان تعملان معًا كلٌّ على قاعدة) كي لا يمسّ التعطيلُ الملفاتِ التي تشغّلها اختباراتٌ أخرى في الوقت نفسه؛ **الملفات الأصلية في `/home/user/wt/WP7` لم يمسّها التعطيل**. **في مرحلة الإصلاح** أعدتُ تعطيل **كل** الحراس السابقة (154) مع **41** حارسًا جديدًا أو مُعاد تعريفه: التشغيل الأول على الالتزام `5f7e80f` كشف **191 من 195**، والأربعة الباقية عالجتُها بالاختبار أو بنقله (7.2) ثم أعدتُ تعطيل الحراس التي مسّتها الإضافات، وهي 19، على الالتزام `1d30966`.

### ملحق: الحراس واحدًا واحدًا (أسماؤها كما في ملف التعطيل)

| الرمز | الحارس | الملف | الاختبار | النتيجة |
|---|---|---|---|---|
| `S01` | settle needs the permission to settle | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S02` | cancel needs the permission to settle | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_Rejects` | **كُشف** |
| `S03` | plan needs the employee in the first branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testPlan_` | **كُشف** |
| `S04` | plan needs the employee in the second branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testPlan_` | **كُشف** |
| `S05` | settle needs the employee in the first branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S06` | settle needs the employee in the second branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S07` | settle runs in one transaction | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_LeavesNothingBehind` | **كُشف** |
| `S08` | settle takes the lock of the pair | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_WaitsFor` | **كُشف** |
| `S09` | cancel takes the lock of the pair | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_WaitsFor` | **كُشف** |
| `S10` | a request key returns the settlement it made | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_ReturnsTheSettlement\|testSettle_TakesADifferentKey\|testSettle_PostsEachJournalOnlyOnce` | **كُشف** |
| `S11` | a pair that does not agree is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S12` | nothing owed is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S13` | a cash settlement needs both treasuries | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S14` | a cash settlement needs two different treasuries | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S15` | the treasuries are those of the branches the money moves between | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S16` | a deactivated treasury is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S17` | the withdraw scope of the paying treasury is asked | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_` | **كُشف** |
| `S18` | the deposit scope of the receiving treasury is asked | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_` | **كُشف** |
| `S19` | a treasury without an account stops the settlement | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_` | **كُشف** |
| `S20` | the journal of the lower branch is written first | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_WritesTheJournalOfTheLowerBranchFirst` | **كُشف** |
| `S21` | each journal records the branch on its other side | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_RecordsTheBranch` | **كُشف** |
| `S22` | settle writes an audit entry for each branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_WritesOneAuditEntry` | **كُشف** |
| `S24` | cancel of a settlement that is not there is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_Rejects` | **كُشف** |
| `S25` | cancel needs the employee in both branches | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_Rejects` | **كُشف** |
| `S26` | a settlement is cancelled once | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_Rejects` | **كُشف** |
| `S27` | the scope of the treasuries is asked before anything is reversed | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_` | **كُشف** |
| `S28` | each journal is reversed on its day or today when its period is closed | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_` | **كُشف** |
| `S29` | cancel writes an audit entry for each branch | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_WritesOneAuditEntry` | **كُشف** |
| `S30` | a journal that is reversed already is skipped | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testCancel_` | **كُشف** |
| `S31` | the same branch twice is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S32` | a branch that does not exist is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S33` | a date that is not one is refused | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Rejects` | **كُشف** |
| `S34` | the accounts between branches must be in the routing | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_LeavesNothingBehindWhenTheRouting` | **كُشف** |
| `S35` | search without branches finds nothing | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSearch_` | **كُشف** |
| `S36` | search counts only the settlements of the branches asked | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSearch_` | **كُشف** |
| `S37` | search lists only the settlements of the branches asked | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSearch_` | **كُشف** |
| `B02` | only the pairs of the branches the reader sees are listed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B03` | only the unattributed amounts of the branches the reader sees are listed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B04` | the company difference is shown to a reader of every branch only | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B07` | the other branch of a pair is given the bases of the first | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B10` | a pair known both ways is called mixed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `L01` | the record of a journal is written once | `InterBranchLegs.php` | `InterBranchLegsTest` · `testRecord_` | **كُشف** |
| `L02` | partners are the journals of the same revision | `InterBranchLegs.php` | `InterBranchLegsTest` · `testPartnersOf_` | **كُشف** |
| `L03` | partners are the journals of the same document | `InterBranchLegs.php` | `InterBranchLegsTest` · `testPartnersOf_` | **كُشف** |
| `L04` | partners leave out the journals reversed already | `InterBranchLegs.php` | `InterBranchLegsTest` · `testPartnersOf_` | **كُشف** |
| `L05` | a journal is not its own partner | `InterBranchLegs.php` | `InterBranchLegsTest` · `testPartnersOf_` | **كُشف** |
| `P01` | the accounts a journal was recorded on count when the routing names others | `InterBranchPosition.php` | `InterBranchPositionTest` · `testAccounts_` | **كُشف** |
| `C01` | a payment into a treasury of its own branch stays one journal | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C02` | a payment with no treasury stays one journal | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C03` | a source without a journal for the other branch is refused | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C04` | a draft that does not move the treasury stays as it is | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C05` | a draft that moves the treasury twice is refused | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C06` | the drafts of a pair are given the lower branch first | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `C07` | the pair is written in the order of the branch numbers | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testPost_` | **كُشف** |
| `C08` | a single journal records no branch | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testPost_` | **كُشف** |
| `C09` | the record of the branch is written for the accounts between branches only | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testPost_` | **كُشف** |
| `C10` | the accounts between branches must be in the routing | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_RejectsWhenTheRouting` | **كُشف** |
| `C11` | posting a pair is one transaction | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testPost_` | **كُشف** |
| `C12` | syncing a pair is one transaction | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C13` | the employee must work in both branches before a first posting | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C14` | a pair that holds what the drafts say is left alone | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C15` | the branches are asked before a pair is replaced | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C16` | the scopes of the treasuries are asked before a pair is replaced | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C17` | the scopes of the treasuries are asked before a first posting | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_\|testAssertMayPost_` | **كُشف** |
| `C18` | the employee must work in the branches of the drafts | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_\|testAssertMayPost_` | **كُشف** |
| `C19` | the employee must work in the branches of the journals that stand | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C20` | a pair of a different number of journals is replaced | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C21` | a pair on another date or branch is replaced | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `C22` | a pair with other lines is replaced | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testSync_` | **كُشف** |
| `J01` | a journal of a pair is taken back with its partners | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J02` | the employee must work in the branch of each partner | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J03` | the scope of the treasury of each partner is asked | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J04` | each partner is reversed on its own day | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J05` | the journals of a pair are reversed in the order of the branch numbers | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J06` | one journal of a pair is not taken back by hand | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J07` | a pair is taken back in one transaction | `JournalService.php` | `JournalServiceTest` · `testReverseAutomatic_\|testReverse_RejectsOneJournal` | **كُشف** |
| `J08` | the journals of a settlement are taken back automatically | `JournalService.php` | `InterBranchSettlementsTest` · `testCancel_` | **كُشف** |
| `T01` | other branches are offered only while the books are on | `TreasuryService.php` | `TreasuryServiceTest` · `testChoicesFor_` | **كُشف** |
| `T02` | other branches are offered only to who works in the branch of the document | `TreasuryService.php` | `TreasuryServiceTest` · `testChoicesFor_` | **كُشف** |
| `T03` | only active treasuries are offered unless a payment carries them | `TreasuryService.php` | `TreasuryServiceTest` · `testChoicesFor_` | **كُشف** |
| `T04` | only treasuries the employee may use are offered | `TreasuryService.php` | `TreasuryServiceTest` · `testChoicesFor_` | **كُشف** |
| `T05` | a treasury of another branch is named with the code of its branch | `TreasuryService.php` | `TreasuryServiceTest` · `testChoicesFor_` | **كُشف** |
| `T06` | a treasury that does not exist is refused | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_\|testPlaceOfPayment_` | **كُشف** |
| `T07` | a treasury of the branch of the document has no other branch | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_\|testPlaceOfPayment_` | **كُشف** |
| `T08` | a treasury of another branch is refused while the books are off | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_` | **كُشف** |
| `T09` | a treasury of another branch needs the employee in both branches | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_` | **كُشف** |
| `T10` | a payment already in the treasury is told both branches are needed | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_` | **كُشف** |
| `T11` | a deactivated treasury of another branch is refused | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_` | **كُشف** |
| `T12` | a payment already in a deactivated treasury may stay | `TreasuryService.php` | `TreasuryServiceTest` · `testOtherBranchOf_` | **كُشف** |
| `T13` | the scope of the treasury named is asked in the direction of the payment | `TreasuryService.php` | `TreasuryServiceTest` · `testPlaceOfPayment_` | **كُشف** |
| `K01` | replacing a pair asks the scope of what the journals put into the treasury | `TreasuryScopes.php` | `TreasuryScopesTest` · `testAssertMayReplaceAll_` | **كُشف** |
| `K02` | replacing a pair asks the scope of what the replacements put into the treasury | `TreasuryScopes.php` | `TreasuryScopesTest` · `testAssertMayReplaceAll_` | **كُشف** |
| `K03` | replacing a pair asks the scopes at all | `TreasuryScopes.php` | `TreasuryScopesTest` · `testAssertMayReplaceAll_` | **كُشف** |
| `X01` | a payment of two journals is not posted as one (sale) | `SalePosting.php` | `SalePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X03` | a payment of two journals is not posted as one (purchase) | `ReceivingPosting.php` | `ReceivingPostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X05` | the journals of a sale list the journal of the other branch | `SalePosting.php` | `SalePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X06` | the journals of a purchase list the journal of the other branch | `ReceivingPosting.php` | `ReceivingPostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X07` | the journals of an expense list the journal of the other branch | `ExpensePosting.php` | `ExpensePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X08` | an expense of two journals is not posted as one | `DocumentJournals.php` | `ExpensePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X10` | the branch of the treasury is never taken from the form | `ExpensePosting.php` | `ExpensePostingTest` · `testSaveValue_NeverStoresWhatTheForm\|testSaveValue_NeverTakesTheBranch` | **كُشف** |
| `X12` | an expense already paid from a treasury is told both branches are needed | `ExpensePosting.php` | `ExpensePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X13` | an expense that names no treasury keeps the branch of the one it has | `ExpensePosting.php` | `ExpensePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X14` | a new treasury of a sale is checked for other branches | `Sale.php` | `SalePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X15` | a payment of a sale keeps the branch of its treasury | `Sale.php` | `SalePostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X16` | a new treasury of a purchase is checked for other branches | `Receiving.php` | `ReceivingPostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `X17` | a payment of a purchase keeps the branch of its treasury | `Receiving.php` | `ReceivingPostingTest` · `AnotherBranch\|EitherBranch\|LowerBranch\|EachBranch\|BothJournals\|ThePair\|OneOfTheBranches\|AcrossBranches\|Stamped\|BooksAreOn\|OnlyOneOfTheTwo\|ByHand\|JournalsOf\|BooksAreOff\|TheBranchOfTheTreasury\|TheBranchOfTheExpense` | **كُشف** |
| `Y01` | the branch an expense is charged to is offered only to who works in more than one | `Expenses.php` | `ExpensesControllerTest` · `testGetView_` | **كُشف** |
| `Y02` | the journals screen does not offer to reverse one journal of a pair | `AccountingJournals.php` | `AccountingJournalsControllerTest` | **كُشف** |
| `Y03` | the settle screens are for who may settle | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettleForm_\|testGetCancelForm_` | **كُشف** |
| `Y04` | a settlement is shown to who works in a branch of its pair | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_` | **كُشف** |
| `Y05` | a settlement is taken back by who works in both branches | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetCancelForm_` | **كُشف** |
| `Y06` | who may see a settlement | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_\|testGetCancelForm_\|testGetSettlementsSearch_` | **كُشف** |
| `Y08` | the button to settle is on one row of a pair | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y09` | the button to settle is for who works in both branches | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y10` | the button to settle is for a pair with something owed | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y11` | the company check is shown to who reads every branch only | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y12` | the cancel icon is for settlements that stand | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_\|testPostCancel_` | **كُشف** |
| `Y13` | the cancel icon is for who may settle | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_` | **كُشف** |
| `Y14` | the cancel icon is for who works in both branches | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_` | **كُشف** |
| `Y15` | the list of settlements is cut to the branches of the reader | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsSearch_` | **كُشف** |
| `Y16` | the treasuries offered are those the employee may use | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettleForm_` | **كُشف** |
| `Z01` | the permission to settle is sensitive | `AccountingGuard.php` | `SensitivePermissionsTest` | **كُشف** |
| `D01` | the settlements table is created once | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D02` | the table of records is created once | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D03` | the table of locks is created once | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D04` | the permission is registered once | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D05` | a settlement is of a pair in the order of the branch numbers | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D06` | a request key makes one settlement | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D07` | a settlement is cash or netting | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D08` | a settlement is completed or cancelled | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D09` | a record is of the receivable or the payable side | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D10` | a treasury a settlement went out of cannot be removed | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D11` | a treasury a settlement went into cannot be removed | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D12` | a journal with a record cannot be removed | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D13` | the column of the branch of the treasury is added once | `20270200000001_CrossBranchPayments.php` | `CrossBranchPaymentsMigrationTest` · `testUp_` | **كُشف** |
| `D14` | the index of the branch of the treasury is added once | `20270200000001_CrossBranchPayments.php` | `CrossBranchPaymentsMigrationTest` · `testUp_` | **كُشف** |
| `D15` | the key of the branch of the treasury is added once | `20270200000001_CrossBranchPayments.php` | `CrossBranchPaymentsMigrationTest` · `testUp_` | **كُشف** |
| `D16` | the key of the branch of the treasury is created | `20270200000001_CrossBranchPayments.php` | `CrossBranchPaymentsMigrationTest` · `testUp_` | **كُشف** |
| `D17` | a settlement is of a company that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D18` | a settlement is of a branch that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D19` | a settlement is with a branch that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D20` | a record is of a company that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D21` | a record is of an account that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `D22` | a record is with a branch that exists | `20270200000000_InterBranchSettlements.php` | `InterBranchSettlementsMigrationTest` · `testUp_` | **كُشف** |
| `S38` | a note is cut to 500 characters | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_Trims` | **كُشف** |
| `Y17` | the list of settlements has a page of at most 500 | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsSearch_` | **كُشف** |
| `Y18` | the list of settlements has a page of at least one | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsSearch_` | **كُشف** |
| `Y19` | the list of settlements starts at the first row at the earliest | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsSearch_` | **كُشف** |
| `M01` | the books off ignore the branch an expense is charged to | `Expense.php` | `ExpensesControllerTest` · `testPostSave_` | **كُشف** |
| `M02` | the books off ignore the branch of a treasury | `Expense.php` | `ExpensePostingTest` · `testSaveValue_ChangesNothingInTheBooksWhenAccountingIsOff\|testSaveValue_IgnoresTheBranchOfTheExpenseWhileTheBooksAreOff` | **كُشف** |
| `Y20` | the settle form is not found when a branch is refused | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettleForm_` | **كُشف** |
| `Y21` | the settle form is not found when the books refuse the pair | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettleForm_` | **كُشف** |
| `Y22` | the screen shows the branches of the employee | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y23` | the day the screen is asked for must be a day | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `B05` | nothing after the day asked is counted | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_\|testBetween_` | **كُشف** |
| `B06` | a reversal belongs to the pair of the journal it takes back | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B08` | a receipt of money that names no other branch is left unattributed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B12` | a receipt of stock that names no other branch is left unattributed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B09` | the screen says a pair agrees by the rule of the settlement | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `B11` | a journal is attributed to the branch recorded with it before the documents are asked | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `X11` | an expense paid across branches keeps its branch when the form names the same treasury | `ExpensePosting.php` | `ExpensePostingTest` · `testSaveValue_KeepsTheBranchOfTheExpenseWhenTheFormNamesTheSameTreasuryAndNoBranch\|testSaveValue_LeavesThePairAloneWhenNothing` | **كُشف** |
| `V01` | the button to settle is for who holds the permission | `inter_branch_pairs.php` | `AccountingInterBranchControllerTest` · `testGetIndex_` | **كُشف** |
| `Y24` | the branch of an expense is offered only when there is a treasury to choose | `Expenses.php` | `ExpensesControllerTest` · `testGetView_` | **كُشف** |
| `F01` | the settings screen has the label of the permission to settle | `AccountingSetup.php` | `AccountingSetupControllerTest` · `PermissionToSettle\|EverySensitive` | **كُشف** |
| `F02` | a settlement is refused for a day before one that stands | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_RejectsADayBefore` | **كُشف** |
| `F03` | a settlement that was cancelled does not stand | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_AcceptsADayBeforeASettlementThatWasCancelled` | **كُشف** |
| `F04` | a settlement of the same day stands with this one | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_AcceptsTheDayOfASettlement\|testSettle_RejectsWhenNothing\|testCancel_LetsThePair` | **كُشف** |
| `F05` | a plan with a settlement after its day cannot be settled | `SettlementPlan.php` | `InterBranchSettlementsTest` · `testPlan_SaysWhenASettlement` | **كُشف** |
| `F06` | the form says why a day before a settlement cannot be settled | `settle_form.php` | `AccountingInterBranchControllerTest` · `testGetIndex_OffersTheDayAsked` | **كُشف** |
| `F07` | the plan looks for the settlement after its day | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_RejectsADayBefore\|testPlan_SaysWhenASettlement` | **كُشف** |
| `F08` | only a settlement of the same pair stands with this one | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_AcceptsADayBeforeASettlementOfAnotherPair` | **كُشف** |
| `F09` | only a settlement of the same company stands with this one | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_AcceptsADayBeforeASettlementOfAnotherCompany` | **كُشف** |
| `F10` | the settlement agrees by the rule of the screen | `InterBranchSettlements.php` | `InterBranchSettlementsTest` · `testSettle_RejectsWhenTheTwoBranchesDoNotSay` | **كُشف** |
| `F11` | two branches agree when what one is owed the other owes and the other way round | `PairBalance.php` | `InterBranchBalancesTest` · `testReport_` | **كُشف** |
| `F12` | the cash of a sale is counted by the branch of the treasury it went into | `CashupExpectation.php` | `CashupExpectationTest` · `testExpected_Counts` | **كُشف** |
| `F13` | the cash of an expense is taken off the drawer that paid it | `CashupExpectation.php` | `CashupExpectationTest` · `testExpected_TakesTheCashOfAnExpensePaidOut` | **كُشف** |
| `F14` | a cash-up expects what went into its drawer | `CashupExpectation.php` | `CashupPostingTest` · `testSaveValue_ExpectsOnlyTheCash` | **كُشف** |
| `F15` | the treasuries of the expense form are escaped | `form.php` | `ExpensesControllerTest` · `testGetView_EscapesTheNames` | **كُشف** |
| `F16` | the branches of the expense form are escaped | `form.php` | `ExpensesControllerTest` · `testGetView_EscapesTheNames` | **كُشف** |
| `F17` | the treasuries of the register are escaped | `register.php` | `SalesControllerTest` · `testGetIndex_EscapesTheNames` | **كُشف** |
| `F18` | the treasuries of a new payment of a sale are escaped | `form.php` | `SalesControllerTest` · `testGetEdit_EscapesTheNames` | **كُشف** |
| `F19` | the treasuries of a payment of a sale are escaped | `form.php` | `SalesControllerTest` · `testGetEdit_EscapesTheNames` | **كُشف** |
| `F20` | the treasuries of a new payment of a purchase are escaped | `form.php` | `ReceivingsControllerTest` · `testGetEdit_EscapesTheNames` | **كُشف** |
| `F21` | the treasuries of a payment of a purchase are escaped | `form.php` | `ReceivingsControllerTest` · `testGetEdit_EscapesTheNames` | **كُشف** |
| `F22` | the journal of a payment into a treasury of another branch has a name | `form.php` | `SalesControllerTest` · `testGetEdit_NamesTheJournal` | **كُشف** |
| `F23` | the branch of the treasury is asked for by the form | `ExpensePosting.php` | `ExpensePostingTest` · `testSaveValue_ChargesAnExpenseThatIsPaidAcrossBranches` | **كُشف** |
| `F24` | a purchase payment has no journal for the treasury of another branch | `CrossBranchPayments.php` | `CrossBranchPaymentsTest` · `testDrafts_` | **كُشف** |
| `F25` | the names of the treasuries of a settlement are escaped | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_ShowsTheTreasuries` | **كُشف** |
| `F26` | the arrow of a settlement turns in Arabic | `AccountingInterBranch.php` | `AccountingInterBranchControllerTest` · `testGetSettlementsRow_ShowsTheTreasuries` | **كُشف** |
| `F27` | a settlement reads only the journals of its two branches | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testBetween_ReadsOnly` | **كُشف** |
| `F28` | the branches asked for are the only ones read | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testBetween_ReadsOnly` | **كُشف** |
| `F29` | a draft is not what a branch is owed | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_LeavesOutADraft` | **كُشف** |
| `F30` | the journals of another company are not counted | `InterBranchBalances.php` | `InterBranchBalancesTest` · `testReport_LeavesOutADraft` | **كُشف** |
| `F31` | a settlement is of the settlement sources taken back by cancelling it only | `JournalService.php` | `JournalServiceTest` · `testReverse_RefusesTheJournalsOfASettlement` | **كُشف** |
| `F32` | a journal that has a record of the other branch is not taken back by hand | `JournalService.php` | `JournalServiceTest` · `testReverse_RefusesAJournalThatHasARecord\|testReverse_TakesBackAPaymentThatHasNoRecord` | **كُشف** |

## 4. ما افترضتُه

1. **التسوية تُصفّي ما بين الفرعين كله حتى اليوم المختار، لا جزءًا منه.** لا مبلغ يُدخله المستخدم. (قرارك: تسوية جزئية؟)
2. **الدافع نقدًا هو الفرع الذي صافيه سالب** (عليه أكثر مما له). والتصافي لا يحتاج خزينة.
3. **زوج لا يتفق طرفاه يُرفض** (قيد في فرع ولا مقابل له في الآخر): لا تسوية تُخفي خطأً. (قرارك: تسوية مع قيد تعديل يُدخله المحاسب؟)
4. **التاريخ الافتراضي لـ«حتى يوم» اليوم**، وتاريخ التسوية الافتراضي في النموذج هو اليوم المعروض في الشاشة.
5. **الصلاحية الحساسة `acc_interbranch_settle` لا تُمنح لأحد** ويمنحها المدير، **ويلزم مع ذلك أن يعمل الموظف في الفرعين** (E43). لا صلاحية جديدة للدفع عبر الفروع: العمل في الفرعين ونطاق الخزينة (E42) يكفيان. (قرارك: صلاحية خاصة لها؟)
6. **قراءة الشاشة بوحدة `accounting_treasuries`** (بلا صلاحية جديدة)، **ويرى كل موظف أزواج فروعه** (E45)، **وفحص الشركة لمن يقرأ كل الفروع**.
7. **الأساس «من التحويلات» لما قبل هذا العمل ولكل تحويل (حتى بعده)**: لم أغيّر ترحيل التحويلات ليكتب سجلًا (لا قيد قائم يُمس ولا سلوك G-61 يتغير)، فتبقى أزواج التحويلات «من التحويلات» دائمًا. (قرارك: سجل لتحويلات المستقبل أيضًا؟)
8. **مفتاح الطلب يُعيد التسوية الأولى ولو أُلغيت.** حدّ الملاحظة 500 حرف وسبب الإلغاء 255.
9. **المصروف:** الفرع الافتراضي لمصروف يُدفع من خزينة فرع آخر هو **فرع الخزينة** (كما كان)، ومن يعمل في أكثر من فرع يختار فرعًا آخر من القائمة. **تقييد المصروف على فرع لا يعمل فيه الموظف مرفوض.**
10. **الدفع عبر الفروع للمحاسبة المفعَّلة وحدها.** والمرتجع (دفعة بإشارة سالبة) يعمل بالاتجاه المعاكس: يستحق فرع الخزينة على فرع المستند.
11. **القيد الثاني بتاريخ المستند ونسخته نفسها، ووصفه يحمل رمز فرع المستند** (`... (HQ)`)، ومصدره المرافِق (`*_branch`) كي لا يتصادم مفتاح عدم التكرار.
12. **عكس قيد في فرع أُقفلت فترته يؤرَّخ اليوم لكل فرع على حدة** (2.6) كما يفعل كل عكس تلقائي قائم.
13. **أرصدة «غير منسوبة»** (قيود يدوية على الحسابين) تُترك كما هي وتُعرض، ولا أداة لنسبتها (القسم 5).
14. **لا تسوية لتاريخ يسبق تسوية قائمة للزوج** (2.7)، وما عدا ذلك من نشاط لاحق لا يمنعها. (قرارك: قاعدة أشد، أو السماح مع إلغاء التسوية اللاحقة تلقائيًا؟)
15. **فرع مُعطَّل عليه رصيد لا يُسوّى حتى يُفعَّل:** الدفاتر ترفض قيدًا جديدًا في فرع معطَّل (`posting_branch_inactive`) فتُرجَع التسوية كلها ولا يبقى شيء، والعكس يعمل (اختبار). أبقيتُ هذا لأن إعادة التفعيل مؤقتًا أوضح من استثناء في الدفاتر. (قرارك: منع تعطيل فرع عليه رصيد بين الفروع، أو السماح بالتسوية في فرع معطَّل؟)
16. **إقفال الصندوق يعدّ النقد بفرع الخزينة** لا بفرع المستند (2.9).

### قرارات المالك المطلوبة

- تسوية **جزئية** (مبلغ أقل من الصافي) أم الصافي كله فقط.
- **تسوية زوج لا يتفق طرفاه** بقيد تعديل (ومَن يملكه) أم الرفض كما الآن.
- **صلاحية خاصة** للدفع عبر الفروع أم يكفي العمل في الفرعين ونطاق الخزينة.
- **منح الصلاحية الجديدة** لمن (المدير يمنحها يدويًا: لا منح تلقائي).
- كتابة **سجل للفرع المقابل لتحويلات المستقبل** (الآن تُقرأ من مستندها).
- نسب **القيود غير المنسوبة** (إن وُجدت عندك قيود يدوية على `12044`/`2214`) بقيد تعديل يُنسبها.
- **قاعدة يوم التسوية** (2.7) وموقف **الفرع المعطَّل** (4.15): كما نُفِّذا أم أشد.

### ملاحظات دفترة المطلوبة (لا تُبنى المطابقة قبلها)

- كيف تعرض دفترة **ما بين الفروع**: حساب وسيط لكل فرع أم لكل زوج، وهل في تقاريرها «مَن عليه لمن»؟
- هل في دفترة **مستند تسوية بين الفروع** (نقدي وتصافٍ)؟ وبأي أثر محاسبي؟
- هل تقبل دفترة **دفعة على فاتورة فرع في خزينة فرع آخر** وكيف تقيّدها؟
- هل يمكن **تحميل مصروف على فرع غير فرع الخزينة** في دفترة؟

## 5. ما بقي

- **دفعات أخرى إلى الخزائن**: **سداد رصيد عميل بلا بيع** و**الدفعات المقدمة للمورد** (`SupplierAdvances`) و**إقفال الصندوق** (`Cashup`) ما زالت **في فرع خزينتها** (لا خزينة فرع آخر لها).
- **تسوية جزئية** وتسوية زوج لا يتفق، و**أداة لنسبة القيود غير المنسوبة** (4.3 و4.13).
- **عملة واحدة**: لا تسوية بعملات مختلفة (عملة أساس واحدة).
- **تقرير** يعرض الأرصدة الثنائية **عبر الزمن** أو يصدّرها: الشاشة بتاريخ واحد.
- **اختبار ضغط بجلسات كثيرة**: ثبت الانتظار والترتيب بجلستين حقيقيتين وبقراءة ترتيب الأقفال، لا بحمل.
- **ui-smoke** للشاشات الجديدة في متصفح لم يُشغَّل.
- **جدول ملخَّص للأزواج** (أرصدة مجمَّعة تُحدَّث مع كل قيد) إن تجاوزت القيود على الحسابين بين الفروع مئات الآلاف: الحساب الآن في قاعدة البيانات وخطي (2.8).
- **فرع معطَّل عليه رصيد** لا يُسوّى حتى يُفعَّل (4.15)، ولا يمنع تعطيلَه رصيدٌ بين الفروع (قرار مالك).
- **تحويلات المستقبل بين الخزائن والمخزون** ما زالت بلا سجل للفرع المقابل (تُقرأ من مستندها).

## 6. قبل النشر

1. **ترحيلان إضافيان** يُنفَّذان تلقائيًا عند أول دخول ويُراجَعان صراحة: `20270200000000_InterBranchSettlements` (ثلاثة جداول وصلاحية بلا منح) و`20270200000001_CrossBranchPayments` (عمود فارغ بفهرس ومفتاح أجنبي في ثلاثة جداول: `sales_payments` و`receivings_payments` و`expenses` وهي كبيرة فيُنفَّذ في وقت هادئ بنسخة احتياطية). **لا يمسان قيدًا ولا دفعة ولا رصيدًا.**
2. **لا أثر حتى يمنح المدير** `acc_interbranch_settle` ويسجّل أحد دفعة في خزينة فرع آخر. شاشة «الأرصدة بين الفروع» تظهر لكل من يحمل وحدة الخزائن وتعرض **أرصدة تحويلاتك الحالية «من التحويلات»**.
3. **الحسابان `12044` و`2214` وتوجيههما (`branches.receivable` و`branches.payable`) لازمان** (من G-61)؛ بدونهما تُرفض التسوية والدفعة عبر الفروع (`interbranch_accounts_missing`).
4. **قبل أول تسوية حقيقية:** وأنت تحمل نسخة ما قبل الترحيل شغّل هذا الاستعلام (بادئة الجداول `ospos_` افتراضيًا، غيّرها إن كانت عندك أخرى) لترى **القيود غير الآتية من تحويل** على الحسابين بين الفروع، وهي التي ستظهر «غير منسوب» ولا تُعدَّل:

   ```sql
   SELECT j.branch_id, j.source_type, COUNT(DISTINCT j.journal_id) AS journals
   FROM ospos_accounting_journals j
   JOIN ospos_accounting_journal_lines l ON l.journal_id = j.journal_id
   JOIN ospos_accounting_accounts a ON a.account_id = l.account_id
   WHERE a.system_key IN ('branches_receivable', 'branches_payable')
     AND j.status IN ('posted', 'reversed')
     AND j.source_type NOT IN ('treasury_transfer', 'treasury_transfer_receipt', 'stock_transfer', 'stock_transfer_receipt')
   GROUP BY j.branch_id, j.source_type;
   ```

   ثم افتح الشاشة وتأكد أن عمود «اتفاق» **نعم** لكل زوج؛ وإن ظهر «غير منسوب» فهي قيود يدوية عندك على الحسابين تُراجَع مع المحاسب.
5. **التراجع:** `down()` يحذف الجداول الثلاثة والأعمدة والصلاحية **ولا يمس القيود**: بعد استعمال حقيقي (تسوية أو دفعة عبر الفروع) يُفضَّل استعادة النسخة الاحتياطية لأن القيود تبقى بلا سجل مستندها.

## 7. المراجعة

### 7.1 ما كشفه إثبات الحراس

بدأتُ بـ149 حارسًا فكُشف منها 121 وبقي 28 غير مكشوف، ثم أضفتُ حراسًا وجدتُها ناقصة في القائمة (مفاتيح أجنبية وقيود الجدولين وحدود الصفحة والنماذج) فبلغت 195. **تتبّعتُ كل واحد وعالجتُه بأحد وجهين: اختبار أقوى حين كان الحارس حقيقيًا، أو حذف المكرَّر حين كان لا يضيف:**

| الحارس | ما وجدتُه | ما فعلتُه |
|---|---|---|
| تحقق `سبب الإلغاء` في `cancel` | `reverseJournal` يرفض السبب الفارغ والأطول من 255 نفسه داخل المعاملة | **حذفتُ التحقق المكرَّر** (والثابت `REASON_MAX`)؛ الاختبار يبقى يثبت الرفض |
| الخروج المبكر لتقرير بلا فروع في `InterBranchBalances::report` | النتيجة نفسها بدونه (كل الأزواج تُرشَّح) | **حذفتُه** |
| `isPaired` في `postPayment` (بيع واستلام) و`syncAll` (مصروف) | الدفعة المزدوجة التي تصير قيدًا واحدًا تُعكس أصلًا عبر `reverseAutomatic` فيعكس شريكه | **حذفتُ الشرط المكرَّر ودالة `CrossBranchPayments::isPaired`** |
| `can()` داخل رابط التسوية في المتحكم | الواجهة لا ترسم الخلية أصلًا لمن لا يملك الصلاحية | **حذفتُه من المتحكم** وأثبتُّ الواجهة (`V01`) |
| `$accountingOn &&` في استلام `expense_branch_id` | النموذج يُسقط الحقل والمحاسبة معطَّلة | **حذفتُه** |
| فحص وجود الجدول في ترحيل الدفعات | الجداول الثلاثة موجودة دائمًا | **حذفتُه** |
| عمود `treasury_branch_id` المرسل من النموذج | حارس حقيقي بلا اختبار | اختبار يرسله فيُهمل |
| قيود `CHECK` والمفاتيح الأجنبية للجدولين | الاختبار كان يعيد `up()` فوق جدول قائم فلا يمس تعريفه، **والاختبار «ترتيب الزوج» كان يفشل بمفتاح الطلب المكرَّر لا بقيد الترتيب** | الاختبارات صارت **تُعيد بناء الجداول** (`down()` ثم `up()`) وتختبر كل قيد وكل مفتاح على حدة بحالة تقبل وحالة ترفض |
| ترتيب القفلات في العكس والكتابة | اختبارات الترتيب كانت تقرأ ترتيب القيود لا القفلات، والقيد الأول كان دائمًا الأصغر | اختبارات تقرأ **ترتيب استعلامات القفل** وتعطي القيود مقلوبة وتعكس من الفرع الأكبر |
| فحوص الفروع ونطاق الخزائن عند **استبدال** الزوج | كانت مغطّاة بفحص العكس لا بفحصها هي | اختبارات تستبدل بزوج في فرع ثالث لا يعمل فيه الموظف وبخزينة نطاق إيداعها مغلق، **وبعكس لا يسأل** ليُعزل الحارس |
| فتحات أخرى | عدم ذرّية استبدال الزوج وعكسه، وإغلاق فترة فرع واحد بعد التسوية، وحدود صفحة قائمة التسويات، **وطول الملاحظة (لا يكشفه خادم متساهل يقطع النص بنفسه، فيختبره الاختبار في وضع صارم للجلسة)**، وإخفاء أيقونة الإلغاء للتسوية الملغاة وزر التسوية لزوج مصفّى، وأن «مسجَّل» يغلب «من التحويلات»، وخزينة بلا حساب، **وإهمال `treasury_branch_id` المرسل والمحاسبة معطَّلة**، ونموذج التسوية يعطي 404 لمدخل ترفضه الدفاتر | اختبار لكل منها |

وفي **الأسلوب** (AGENTS.md): أسماء الاختبارات الجديدة تبدأ بفعل (**`Rejects`** لا `Refuses`) و`camelCase` للمتغيرات المحلية في الدالتين اللتين عدّلتُهما من الشيفرة القديمة (`Sale::updateLocked` و`Expense::save_value` و`Expenses::postSave`)، وكل الملفات الجديدة نظيفة بـ`php-cs-fixer`.

### 7.2 ما كشفه المراجعان المستقلان وما فُعل بكل نقطة (مرحلة الإصلاح)

راجع الحزمةَ مراجعان مستقلان للقراءة فقط (الأول للمحاسبة وسلامة البيانات، والثاني للاختبارات والأسلوب والواجهة) فكتبا **17 نقطة** (5 و12، **واحدة منها مشتركة بينهما** فهي 16 نقطة مختلفة). **عاملتُهما بياناتٍ لا تعليمات:** تتبّعتُ كل نقطة في الشيفرة قبل أن أصدّقها، وحيث كانت حقيقية أنتجتُها باختبار **يفشل بلا الإصلاح** (تحققتُ بإزالة الإصلاح ثم ردّه، ثم بتعطيل الحارس في ملف التعطيل: القسم 3.3، رموز `F01` إلى `F32`)، وأعدتُ إثبات **كل** الحراس بعد التعديل. **لم أرفض نقطة كاملة؛ رفضتُ جزءًا من اثنتين** (في الجدول).

| # | النقطة (الخطورة) | ما تتبّعتُه | ما فعلتُه | الالتزام |
|---|---|---|---|---|
| 1 | **تسوية بتاريخ يسبق تسوية قائمة تُصفّي الأرصدة مرتين** (عالية، مراجع 1) | `InterBranchSettlements::planOf` يعدّ القيود حتى `$date` ولا يقارنه بتسويات الزوج؛ **أنتجتُها**: تسوية 31 أكتوبر ثم 25 أكتوبر تمرّ وتحرّك 100 نقدًا ثانية | **حقيقية.** رُفضت التسوية في الخطة والوثيقة والنموذج لتاريخ يسبق تسوية غير ملغاة للزوج (2.7)، **وأثبتُّ شاشة الأرصدة بـ`as_of` والنموذج والإرسال**. **رفضتُ جزءًا:** منع «أي نشاط لاحق للزوج» (البديل الأشد في التكليف) لأنه يمنع تسوية آخر الشهر بلا فائدة (2.7) | `461ae56` |
| 2 | **منح `acc_interbranch_settle` يكسر شاشة إعدادات المحاسبة** (عالية، المراجعان) | `AccountingSetup::PERMISSION_LABELS` بلا مفتاحها و`SensitivePermissions::holders` يعيدها: `lang(null)` | **حقيقية.** أُضيف الاسم، وفي ملاحظة نموذج الموظف (إنجليزية وعربية)، **واختباران**: صاحبها وحده، وموظف يحمل **كل** الصلاحيات الحساسة مع مطابقة المفاتيح لقائمة الحارس (فلا تتكرر المشكلة لصلاحية قادمة) | `980e917` |
| 3 | **إقفال الصندوق يعدّ النقد بفرع المستند لا بالخزينة** (متوسطة) | `CashupExpectation` بـ`s.branch_id` و`e.branch_id`؛ **أنتجتُها** بدفعة نقدية في خزينة فرع آخر (الفرعان يتوقعان خطأ) | **حقيقية.** يُعدّ بفرع الخزينة (`COALESCE(treasury_branch_id, branch_id)`) للمبيعات والمصروف (2.9)، **واختبار إقفال حقيقي لا يُرحِّل فرقًا** | `9685a06` |
| 4 | **اسم الفرع والخزينة غير مُهرَّب في قوائم المصروف** (XSS، منخفضة) | `form_dropdown` يطبع نص الخيار كما هو؛ اسم الفرع يُحفظ كما أُدخل. **وجدتُ المشكلة أوسع**: قوائم الخزينة في المصروف والبيع والاستلام والسجل (سبعة مواضع) | **حقيقية.** `array_map('esc', …)` في المواضع السبعة، **واختبار باسم عدائي لكل شاشة** (يفشل بلا الإصلاح) | `0c44aa7` |
| 5 | **جمع كل تاريخ الحسابين في PHP داخل معاملة التسوية** (منخفضة) | قِسْتُ: 250 ألف قيد = 396 ميغابايت و6 ثوانٍ لزوج واحد (**الذاكرة تنفد قبل ذلك على خادم عادي**) | **حقيقية بالقياس.** الجمع في قاعدة البيانات وتقرأ التسوية قيود فرعيها وحدهما (2.8) | `d81d62f` `7d79323` |
| 6 | **نموذج تعديل البيع يطبع `Sales.entry_general`** (متوسطة) | مصدر `sale_payment_branch` ونوع `general` بلا مفتاح لغة | **حقيقية.** مفتاح `Sales.entry_payment_branch` في 46 لغة (الإنجليزية في `en` و`en-GB`، العربية الفعلية في `ar-EG` و`ar-LB`، والباقي `''`، بترتيب أبجدي)، **واختبار يقرأ النموذج** | `724ab89` |
| 7 | **علم «اتفاق» الشاشة يقارن الصافي والتسوية تقارن الجانبين** (منخفضة) | `PairBalance::agrees` بصافي الطرفين و`planOf` بالجانبين؛ **أنتجتُها** بقيد يستحق وآخر يلتزم على فرع واحد | **حقيقية.** دالة واحدة `PairBalance::agree` يستعملها الاثنان، **واختبار** يطابق علم الشاشة بخطة التسوية | `461ae56` |
| 8 | **خيار «فرع الخزينة» (0) على مصروف عبر الفروع قائم يُهمَل** (منخفضة) | `ExpensePosting::resolve` يعدّ 0 كالغائب | **حقيقية.** الاختيار الصريح يغلب (والغائب يبقي الفرع)، **واختبار** يعيد التحميل على فرع الخزينة | `e7832dc` |
| 9 | **المصدر `purchase_payment_branch` لا يُنتَج** (منخفضة) | `ReceivingPosting::postPayment` لا يمرّ بـ`drafts` ولا يضع `Receiving::save` فرع خزينة | **حقيقية.** حُذف الربط والمفتاح (46 لغة) وعبارة التقرير، **واختبار** يرفض مصدر الشراء | `e7832dc` |
| 10 | **فرع معطَّل عليه رصيد لا يُسوّى** (منخفضة) | `PostingService::resolveBranch` يرفض قيدًا في فرع معطَّل؛ `BranchService::deactivate` لا يسأل الأرصدة | **حقيقية وأبقيتُها بقرار مُوثَّق** (4.15): الرفض يرجع كل شيء ولا يترك أثرًا، **واختبار يثبته ويثبت نجاحها بعد التفعيل** | `a87882c` |
| 11 | **اختبارات صنف في ملفات أصناف أخرى** (منخفضة) | `accounts()` و`getTransfers` و`JournalService::reverse` | **حقيقية.** نُقلت إلى ملفاتها؛ **رفضتُ جزءًا:** الاختبارات الأربعة التي تعكس قيدًا من زوج باليد على مستندات حقيقية استُبدلت **باختبارات مباشرة في `JournalServiceTest`** (مصدرا التسوية، وقيد له سجل الفرع الآخر أيًّا كان مصدره، ودفعة بلا سجل تُعكس) لأن الحارس في `JournalService` لا في المستندات؛ ويبقى كل مسار إلغاء مستند يعكس زوجه | `820086e` |
| 12 | **متغيرات العرض `snake_case`** (منخفضة) | شاشة الأرصدة والنماذج الثلاثة | **حقيقية.** `camelCase` (`$tableHeaders` و`$asOf` و`$canSettle` و`$fromTreasuries` و`$requestKey`… وخيارات فرع نموذج المصروف أيضًا) | `461ae56` `ccfad4f` |
| 13 | **رموز فروع عشوائية في اختبار الترحيل تتصادم نحو 6%** (منخفضة) | `'MG' . random_int(10, 99)` في سبعة مواضع | **حقيقية.** رمز فريد لكل فرع | `820086e` |
| 14 | **اختبار يدّعي ما لا يختبره، واستيرادات زائدة ومختلة الترتيب** (منخفضة) | `testAssertMayPost_…` ينتهي بتأكيد على `BranchGuard` | **حقيقية.** اختباران (الفرعان، ونطاق الإيداع)، وحُذفت الاستيرادات الزائدة وأُصلح الترتيب (وما صار زائدًا بنقل الاختبارات) | `820086e` |
| 15 | **استعلام التحقق قبل النشر ينتهي بـ`ON ...`** (منخفضة) | القسم 8.4 | **حقيقية.** استعلام يُنفَّذ (يعدّ القيود غير الآتية من تحويل بحسب الفرع والمصدر، بالمفتاح `system_key` لا الرقم) في 8.4 وفي القسم 6 | التزام التقرير |
| 16 | **السهم بين الخزينتين لا ينقلب في العربية** (منخفضة) | `' → '` نص عادي في خلية | **حقيقية.** أيقونة `bi-arrow-right` التي تقلبها `app.css` في `rtl`، وأسماء الخزينتين في `bdi` ومُهرَّبة، **واختبار** | `a87882c` |

**ما كشفه إثبات الحراس في هذه المرحلة** (القسم 3.3): من 195 حارسًا كُشف في التشغيل الأول 191، وبقيت أربعة عالجتُها: `P01` (**اختباره انتقل** إلى `InterBranchPositionTest` فصار الملف القديم بلا اختبار له: صار الحارس يُعطَّل على الملف الجديد)، و`B08` و`B12` (**شرطا «تحويل بلا فرع مقابل يبقى غير منسوب» في الاستعلام الجديد** بلا اختبار: **اختباران** يضعان `to_branch_id` فارغًا لتحويل نقدي ولتحويل مخزون ويطلبان أن يبقى القيدان غير منسوبين ولا يُقرأ قيد الاستلام فرعًا للمرسل)، و`F09` (شرط الشركة في البحث عن تسوية قائمة: **اختبار** تسوية قائمة لزوج الشركة الأخرى لا تمنع). ثم صار الكشف **195 من 195**.

**ما لم يتغيّر بالمراجعة (اعتمده المراجعان سليمًا ولم أمسّه):** توازن كل قيد في فرعه، والذرية، وترتيب الأقفال، وسلامة عكس الزوج، وسلوك القديم، وقواعد E43 ونطاقات الخزائن، وقراءة E45، وتهريب الواجهات الجديدة، والترحيلان، ومفتاح الطلب، وفحص الشركة، والمحاسبة المعطَّلة.

## 8. تحديثات الوثائق المشتركة للمُدمِج

المُدمِج يضيف هذا النص حرفيًا (ويعطي القرار رقمه) ولا يعدّل ما عداه:

### 8.1 `docs/accounting/decisions.md`: صف جديد (يعطيه المُدمِج رقم `E` التالي)

> **E?? | تنفيذ WP7: التسوية بين الفروع والأرصدة الثنائية والدفع عبر الفروع** (2026-10-27، بتفويض E47، [التقرير](daftra-parity/wp7-report.md)): **(1)** **الأرصدة الثنائية** لكل زوج مرتَّب من الفروع (يستحق/عليه/صافٍ/اتفاق) من الحسابين `12044` و`2214`، **بتاريخ «حتى يوم» وبنطاق E45**، **وفحص الشركة `InterBranchPosition::difference` لمن يقرأ كل الفروع وحدهم**. **الفرع المقابل للقيد** يُكتب مع كل قيد جديد في جدول `accounting_interbranch_legs` («مسجَّل») أو يُقرأ من مستند التحويل لما قبله («من التحويلات»، ويجمع الزوج الاثنين فيصير «مختلط»)، **وما لا يُعرف «غير منسوب» ولا يُخمَّن ولا يُعاد كتابة قيد قائم.** **(2)** **وثيقة التسوية** `interbranch_settlements`: **قيدان، قيد لكل فرع متوازن داخله**، يُخرجان ما للفرع وما عليه حتى اليوم المختار، **والباقي نقدًا** من خزينة الفرع الذي عليه إلى خزينة الفرع الذي له، **أو تصافٍ بلا نقد** إن تقاصّ الطرفان. تُكتب القيود وتُعكس **بترتيب رقمَي الفرعين** تحت قفلة الزوج، **وتُرفض كلها بفترة مقفلة لأي من الفرعين**، **ويعمل الموظف في الفرعين (E43)** وتُسأل **نطاقات الإيداع والسحب للخزينتين**، **وتُرفض إن لم يتفق الفرعان أو لم يكن بينهما شيء**، **ومفتاح الطلب يمنع الترحيل المزدوج**، **ويُكتب التدقيق لكل فرع**. **الإلغاء يعكس القيدين معًا أو لا شيء** (لا إلغاء جزئي ولا عكس يدوي لقيد واحد). **(3)** **صلاحية حساسة جديدة `acc_interbranch_settle`** (للتسوية وإلغائها، **لا تُمنح لأحد**، يمنحها المدير). **(4)** **الدفع عبر الفروع:** دفعة عميل على بيع أو دفعة مورد على استلام أو مصروف لفرع أ يدخل خزينة فرع ب أو يخرج منها **بقيدين** (قيد المستند مقابل حساب بين الفروع، وقيد الخزينة مقابل الحساب الآخر)، **ميزان كل فرع متوازن ورصيد الخزينة = رصيد حسابها وتسوية العميل/المورد في فرع المستند**، **ويُعكسان ويُعدَّلان معًا**. **تحمل الدفعة `treasury_branch_id` ولا يُرحَّل قيدين إلا ما يحمله، فكل دفعة قائمة تبقى قيدًا واحدًا.** **لا تُعرض خزائن الفروع الأخرى إلا لمن يعمل في الفرعين، ولا والمحاسبة معطَّلة.** **المصروف:** حقل «الفرع الذي يُحمَّل عليه المصروف» (الافتراضي فرع الخزينة). **حدود لا تُكسر:** كل فرع متوازن، وعكس زوج لا يُكسر (أي قيد منه لا يُعكس باليد)، وفحص الشركة صفر. **افتراضات لم تُرصد في دفترة:** التسوية كاملة لا جزئية، والرفض عند عدم اتفاق الطرفين، والدافع نقدًا هو صاحب الصافي السالب، وعكس قيد فرع مقفل اليوم (فيختلف الزوج بين اليومين)، **ولا تسوية لتاريخ يسبق تسوية قائمة للزوج** (وإلا صُفّيت الأرصدة مرتين)، **وإقفال الصندوق يعدّ النقد بفرع الخزينة التي دخلها**، **والفرع المعطَّل لا يُسوّى حتى يُفعَّل**. **بانتظار قرارك:** تسوية جزئية؛ تسوية زوج لا يتفق بقيد تعديل؛ صلاحية خاصة للدفع عبر الفروع؛ سجل للفرع المقابل لتحويلات المستقبل؛ نسب القيود غير المنسوبة | تُحدَّث `FIN-01` وصفّان جديدان في المصفوفة، ويُغلق `G-61` وتنفّذ `INTERBRANCH-SETTLEMENT` و`P6-ASSUMPTION-7` في الفجوات

### 8.2 `docs/accounting/daftra-parity/conformance-matrix.md`

**(أ)** في الصف `FIN-01` عمود «عندنا»: **استبدل** عبارة «**وتحويل بين الفروع**» في جملة «**ينقص:**» بـ«**(التحويل بين الفروع نُفِّذ في G-61، والتسوية بين الفروع والدفع عبر الفروع في WP7)**».

**(ب)** **صفّان جديدان** بعد `FIN-08` (يعطيهما المُدمِج رقميهما):

| الصف | الميزة | دفترة | عندنا | الحالة | الملاحظات |
|---|---|---|---|---|---|
| `FIN-??` | الأرصدة بين الفروع وتسويتها | لم يُرصد | شاشة «الأرصدة بين الفروع» (`accounting_treasuries/inter_branch`): **لكل زوج مرتَّب** ما يستحقه الفرع وما عليه والصافي واتفاق الطرفين، **بتاريخ وبنطاق الموظف**، وفحص الشركة لقارئ كل الفروع، والأساس «مسجَّل» أو «من التحويلات» أو «مختلط» و«غير منسوب» منفصل. **وثيقة تسوية** نقدية أو تصافٍ: **قيد لكل فرع متوازن داخله، تُكتب وتُعكس بترتيب رقم الفرع، تحت قفلة الزوج، وترفضها فترة مقفلة لأي من الفرعين**، ويعمل الموظف في الفرعين وتُسأل نطاقات الخزينتين، **ومفتاح الطلب يمنع التكرار**، **والإلغاء يعكس القيدين معًا**. صلاحية حساسة `acc_interbranch_settle` بلا منح | **غير قابل للمقارنة بدفترة** (تصميم داخلي لا مقابل مرصودًا له) | تصميم داخلي (`backlog.md` WP7). **WP7** ([التقرير](wp7-report.md)): `InterBranchBalancesTest` و`InterBranchSettlementsTest` و`AccountingInterBranchControllerTest` وترحيلها. **لا يُوصف بـ«مطابق» لأن لا قياس في دفترة** |
| `FIN-??` | الدفع في خزينة فرع آخر (بيع واستلام ومصروف) | لم يُرصد | دفعة عميل على بيع أو دفعة مورد على استلام أو مصروف **يدخل خزينة فرع آخر أو يخرج منها بقيدين** (المستند مقابل حساب بين الفروع، والخزينة مقابل الآخر)، **ميزان كل فرع متوازن ورصيد الخزينة = رصيد حسابها**، ويُعدَّل القيدان ويُعكسان معًا، وأي قيد منهما لا يُعكس باليد. **لا تُعرض الخزائن الأخرى إلا لمن يعمل في الفرعين** وبنطاق الخزينة، **ولا والمحاسبة معطَّلة**. المصروف يُحمَّل على فرع يختاره صاحب أكثر من فرع | **غير قابل للمقارنة بدفترة** | **WP7**. `CrossBranchPaymentsTest` و`SalePostingTest` و`ReceivingPostingTest` و`ExpensePostingTest` و`JournalServiceTest`. **ما بقي:** سداد رصيد العميل بلا بيع والدفعات المقدمة للمورد وإقفال الصندوق في فرع خزينتها |

### 8.3 `docs/accounting/daftra-parity/gap-report.md`

- في الصف `G-61` (القسم 5ز): في آخر «**ما بقي:**» استبدل «**ودفعة من فرع في خزينة فرع آخر (رقم 7 في تقرير P6)، ومَن عليه لمن بين فرعين بعينهما (A7)**» بـ«**~~ودفعة من فرع في خزينة فرع آخر (رقم 7 في تقرير P6)~~ و~~مَن عليه لمن بين فرعين بعينهما (A7)~~: نُفِّذا في [WP7](wp7-report.md) (تسوية بين الفروع وأرصدة ثنائية ودفع عبر الفروع) بقرار E??**».
- **فجوات جديدة** (يعطيها المُدمِج أرقامها): **(أ)** تسوية **جزئية** غير مدعومة (تُصفّى الأرصدة كلها)؛ **(ب)** زوج **لا يتفق طرفاه** لا يُسوّى وليس عندنا قيد تعديل لنسب القيد المجهول؛ **(ج)** تحويلات المستقبل لا تكتب سجل الفرع المقابل (تُقرأ من مستندها)؛ **(د)** سداد رصيد العميل بلا بيع والدفعات المقدمة للمورد وإقفال الصندوق **لا تدخل خزينة فرع آخر**؛ **(هـ)** لا تسوية بعملات مختلفة؛ **(و)** كيف تعرض دفترة **ما بين الفروع** ومستند التسوية والدفع عبر الفروع **غير مرصود** (ملاحظة دفترة مطلوبة).

### 8.4 `docs/ui-redesign/deploy-checklist.md`: بند جديد في «قبل النشر»

> - [ ] **التسوية بين الفروع والدفع عبر الفروع (WP7):** الترحيلان `20270200000000_InterBranchSettlements` و`20270200000001_CrossBranchPayments` إضافيان ويُنفَّذان تلقائيًا عند أول دخول ويُراجَعان صراحة. الأول يضيف الجداول `interbranch_settlements` و`accounting_interbranch_legs` و`interbranch_pair_locks` والصلاحية الحساسة `acc_interbranch_settle` **بلا منح لأحد**، والثاني يضيف العمود `treasury_branch_id` **الفارغ** (بفهرس ومفتاح أجنبي إلى `branches`) إلى `sales_payments` و`receivings_payments` و`expenses` فيُنفَّذ **في وقت هادئ بنسخة احتياطية**. **لا يمسان قيدًا ولا دفعة ولا رصيدًا، وكل دفعة قائمة تبقى قيدًا واحدًا.** **لا أثر ظاهر حتى** يمنح المدير الصلاحية ويسجّل أحد دفعة في خزينة فرع آخر، **إلا شاشة «الأرصدة بين الفروع»** (رابط في شاشة التحويلات) تعرض لكل من يحمل وحدة الخزائن أرصدة التحويلات القائمة «من التحويلات». **قبل النشر:** `SELECT j.branch_id, j.source_type, COUNT(DISTINCT j.journal_id) AS journals FROM ospos_accounting_journals j JOIN ospos_accounting_journal_lines l ON l.journal_id = j.journal_id JOIN ospos_accounting_accounts a ON a.account_id = l.account_id WHERE a.system_key IN ('branches_receivable', 'branches_payable') AND j.status IN ('posted', 'reversed') AND j.source_type NOT IN ('treasury_transfer', 'treasury_transfer_receipt', 'stock_transfer', 'stock_transfer_receipt') GROUP BY j.branch_id, j.source_type` (بادئة الجداول `ospos_` افتراضيًا، غيّرها إن كانت عندك أخرى) لترى القيود على الحسابين التي لا تأتي من تحويل (القيود اليدوية عليهما تظهر «غير منسوب» ولا تُعدَّل)، وتأكد أن توجيه `branches.receivable` و`branches.payable` موجود (وإلا رُفضت التسوية والدفعة عبر الفروع). **بعد النشر:** أنشئ فرعًا تجريبيًا وخزينة له، سجّل دفعة بيع في تلك الخزينة بموظف يعمل في الفرعين، وتأكد أن الزوج «يتفق» وأن فحص الشركة صفر، ثم سوِّ الزوج وألغِ التسوية. **التراجع:** `down()` يحذف الجداول والعمود والصلاحية **ولا يمس القيود**: بعد استعمال حقيقي تُفضَّل استعادة النسخة الاحتياطية. تفاصيل في [تقرير WP7](../accounting/daftra-parity/wp7-report.md).
