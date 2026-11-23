# سجل شاشات إعادة التصميم (Claude Design)

هذا هو سجل العمل الرئيسي لإعادة تصميم واجهات Expro شاشةً شاشة. **الترتيب ثابت**، ولا يتغير فيه إلا عمود الحالة بعد كل جولة.

- **مسار العمل في كل جولة:** فحص الشاشة، ثم كتابة برومبت Claude Design، ثم انتظار التصميم المعتمد من المالك، ثم مطابقته بالوظائف، ثم خطة تنفيذ، ثم موافقة، ثم تنفيذ على فرع تطوير، ثم اختبار.
- **الحالات:** لم تبدأ / أُرسل للتصميم / التصميم معتمد / قيد التنفيذ / مكتمل.
- **الأولوية:** P1 للاستخدام اليومي الكثيف، وP2 للاستخدام المنتظم، وP3 للإعداد أو الاستخدام النادر.
- **مصدر الجرد:** `app/Config/Routes.php` والتوجيه التلقائي، و`app/Controllers` و`app/Views`، و`app/Libraries/Navigation/SidebarMenu.php`. ما لم يُدرج عن قصد مذكور في آخر الملف.

## لماذا هذا الترتيب

1. **الأساس أولًا:** لوحة التحكم ومعها إطار التطبيق (القائمة الجانبية والترويسة والبحث الشامل)، لأن كل شاشة بعدها تُعرض داخل هذا الإطار، فيُصمم مرة واحدة ويُعاد استخدامه.
2. **الدخول والحالات العامة:** صفحات تظهر قبل الإطار أو بدلًا منه.
3. **الأنماط المشتركة** (صفحة قائمة، نافذة نموذج، نافذة تأكيد أو إلغاء أو عكس، صفحة تقرير، شاشة طباعة مستند، محرر بنود المستند). كل نمط منها يُصمم مع **أول شاشة تستخدمه** ثم يُعاد استخدامه، ولا يحتاج جولة مستقلة.
4. **إدارة النظام** قبل غيرها لأنها تنشئ الموظفين والفروع والإعدادات، وفيها أول صفحة قائمة وأول نافذة نموذج.
5. **المخازن** قبل المبيعات والمشتريات لأن الأصناف بيانات أساسية تعتمد عليها نقطة البيع والاستلام.
6. **المبيعات** ثم **المشتريات** ثم **الإدارة المالية**، وكل دورة منها تعيد استخدام ما قبلها (العملاء والموردون والسندات وشاشة الطباعة).
7. **التقارير** في الآخر، لأن أغلبها يقوم على ثلاثة قوالب مخرجات تُصمم مرة واحدة.
8. **بوابة العملاء وقوالب PDF** خارج الإطار، فتأتي بعد اعتماد قوالب المستندات.

الأقسام السبعة المعتمدة تبقى تصنيفًا لكل شاشة في عمود «القسم»، والرقم التسلسلي هو ترتيب التطوير.

---

## المرحلة 1: الأساس، الرئيسية ولوحة التحكم

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 1 | DASH-01 | لوحة التحكم الرئيسية (الترحيب، تنبيه تشغيل المحاسبة، الاختصارات السريعة، المؤشرات، رسم المبيعات، الأكثر مبيعًا، نقص المخزون، بلاطات الوحدات) | 1 الرئيسية | `home` (وشعار القائمة، و`office` يحوّل إليها) | `Home::getIndex` · `home/home.php`، `Libraries/Dashboard_lib.php`، `Libraries/Navigation/QuickActions.php`، `resources/assets/js/82-home-shortcuts.js` | P1 | SHL-01..04 (تُصمم معها) | مكتمل (على فرع التطوير، لم يُدمج) |
| 2 | SHL-01 | القائمة الجانبية (11 قسمًا، أكورديون، درج على الجوال، تذييل المستخدم والخروج) | مشترك | كل الصفحات | `navigationSections()` · `partial/sidebar.php`، `SidebarMenu.php` | P1 | — | مكتمل (مع DASH-01) |
| 3 | SHL-02 | الترويسة العلوية (زر القائمة، عنوان الصفحة، اسم الشركة، مبدّل الفروع، زر البحث، الساعة، شريط إيقاف العمليات) | مشترك | كل الصفحات؛ `POST home/switchBranch` | `Home::postSwitchBranch` · `partial/header.php`، `partial/branch_switcher.php` | P1 | — | مكتمل (مع DASH-01) |
| 4 | SHL-03 | نافذة البحث الشامل (Ctrl K) | مشترك | `home/searchIndex` (JSON) | `Home::getSearchIndex` · `partial/footer.php` `#global_search` | P1 | SHL-02 | مكتمل (مع DASH-01) |
| 5 | SHL-04 | تذييل الصفحة (الحقوق، الإصدار) | مشترك | كل الصفحات | `partial/footer.php` | P3 | — | مكتمل (مع DASH-01) |
| 6 | DASH-02 | نافذة تخصيص الاختصارات | 1 الرئيسية | داخل `home` | `home/home.php` `#home_shortcuts_modal` | P2 | DASH-01 | مكتمل (مع DASH-01) |
| 7 | DASH-03 | الإشعارات | 1 الرئيسية | `notifications`؛ `POST notifications/read/{id}`، `read_all` | `Notifications::getIndex` · `notifications/manage.php` | P2 | SHL-01 | مكتمل (على فرع التطوير، لم يُدمج) |

## المرحلة 2: الدخول والحالات العامة

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 8 | AUTH-01 | تسجيل الدخول (مع زر ترقية قاعدة البيانات) | مشترك | `/`، `login`؛ `POST migrate` | `Login::index`، `Login::migrate` · `login.php` | P1 | — | قيد التنفيذ (منفّذ على فرع التطوير، بانتظار مراجعة المالك) |
| 9 | AUTH-02 | مطالبة المالك بالحساب (أول تشغيل) | مشترك | `login` قبل وجود مالك؛ `POST owner_setup` | `OwnerSetup::postClaim` · `setup/claim.php` | P3 | AUTH-01 | لم تبدأ |
| 10 | AUTH-03 | معالج الإعداد الأول (اللغة، الشركة، الفرع، الضريبة، المحاسبة) | مشترك | `setup?step=…`؛ `POST setup/language|company|branch|tax|finish` | `Setup::*` · `setup/wizard.php` + `accounting_settings/activation_panels.php` | P3 | AUTH-02، FIN-56 | لم تبدأ |
| 11 | AUTH-04 | تغيير كلمة المرور الإجباري | مشترك | `home/mustChangePassword`؛ `POST home/save` | `Home::getMustChangePassword` · `home/must_change_password.php` | P2 | AUTH-01 | لم تبدأ |
| 12 | AUTH-05 | نافذة تغيير كلمة المرور (من تذييل القائمة) | مشترك | `home/changePassword/{id}`؛ `POST home/save` | `Home::getChangePassword` · `home/form_change_password.php` | P2 | SHL-01، CMP-02 | لم تبدأ |
| 13 | AUTH-06 | لا تملك صلاحية | مشترك | `no_access/{module}/{permission}` | `No_access::getIndex` · `no_access.php` | P2 | SHL-01 | لم تبدأ |
| 14 | AUTH-07 | صفحات الأخطاء (404، الاستثناء، قاعدة البيانات، الإنتاج) | مشترك | أي مسار | الإطار · `errors/html/*` (مستثناة من المنسّق) | P3 | — | لم تبدأ |

## الأنماط المشتركة (تُصمم مع أول شاشة تستخدمها)

| # | المعرف | المكوّن | القسم | أين يظهر | الملفات | الأولوية | يُصمم مع | الحالة |
|---|---|---|---|---|---|---|---|---|
| 15 | CMP-01 | صفحة قائمة قياسية (شريط أدوات، بحث، فلاتر، جدول، تحديد جماعي، حذف، تصدير، ترقيم صفحات) | مشترك | كل صفحات `manage.php` | `people/manage.php` ونظائرها، `partial/table_filter_persistence.php`، `js/35-export.js` | P1 | SYS-17 | لم تبدأ |
| 16 | CMP-02 | نافذة نموذج قياسية (إضافة وتعديل، تبويبات داخلية، حفظ وإلغاء، رسائل التحقق) | مشترك | كل روابط `modal-dlg` | كل `form.php` | P1 | SYS-18 | لم تبدأ |
| 17 | CMP-03 | نافذة تأكيد أو إلغاء أو عكس أو اعتماد (سبب، تاريخ، أثر العملية) | مشترك | كل `*_cancel_form`، `reverse_form`، `approve_form` | — | P2 | INV-20 | لم تبدأ |
| 18 | CMP-04 | صفحة تقرير (فلاتر، ملخص، جدول، قائمة تصدير CSV/XLSX/PDF، طباعة) | مشترك | تقارير الدفاتر | `partial/report_files.php`، `partial/daterangepicker.php`، `accounting_statements/toolbar.php` | P2 | SAL-24 | لم تبدأ |
| 19 | CMP-05 | شاشة طباعة مستند مع شريط إجراءات (طباعة، PDF، بريد) | مشترك | القيود والسندات وفواتير الشراء والإيصالات | `documents/screen.php`، `partial/document_actions.php`، `partial/print_receipt.php` | P1 | SAL-09 | لم تبدأ |
| 20 | CMP-06 | محرر بنود المستند (أصناف، كميات، أسعار، ضريبة، إجماليات) | مشترك | أوامر البيع، المتكررات، دورة الشراء | `partial/document_lines.php`، `purchase_cycle/lines.php` | P2 | SAL-15 | لم تبدأ |
| 21 | CMP-07 | كتل نماذج الأشخاص (البيانات الأساسية، الملاحظات، جهات الاتصال، الحقول الإضافية) | مشترك | نماذج العملاء والموردين والموظفين والأصناف | `people/form_basic_info.php`، `form_comments.php`، `contacts_panel.php`، `contacts_list.php`، `custom_fields/inputs.php` | P2 | SYS-18 | لم تبدأ |
| 22 | CMP-08 | نافذة إرسال رسالة SMS لشخص | مشترك | قوائم العملاء والموردين والموظفين | `Messages::getView` · `messages/form_sms.php` | P3 | CMP-02 | لم تبدأ |
| 23 | CMP-09 | لوحة حالة الفاتورة الإلكترونية (إصدار، إشعار مدين، XML) | مشترك | الإيصال والفاتورة | `AccountingEInvoicing::postIssue` · `accounting_einvoicing/sale_panel.php`، `status_panel.php` | P2 | SAL-04 | لم تبدأ |
| 24 | CMP-10 | تنبيه المحاسبة على الصفحات (مثل: المحاسبة موقوفة أو الفترة مقفلة) | مشترك | صفحات متعددة | `accounting_journals/notice.php` | P3 | SHL-02 | لم تبدأ |

## المرحلة 3: إدارة النظام

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 25 | SYS-01 | الإعدادات: الهيكل والتنقل بين المجموعات الخمس | 2 النظام | `config` | `Config::getIndex` · `configs/manage.php` | P2 | SHL-01 | لم تبدأ |
| 26 | SYS-02 | تبويب معلومات الشركة (مع الشعار) | 2 النظام | `config#info_tab` | `Config::postSaveInfo` · `configs/info_config.php` | P2 | SYS-01 | لم تبدأ |
| 27 | SYS-03 | تبويب الإعدادات العامة | 2 النظام | `config#general_tab` | `configs/general_config.php` | P2 | SYS-01 | لم تبدأ |
| 28 | SYS-04 | تبويب إعدادات طباعة الإيصال | 2 النظام | `config#receipt_tab` | `configs/receipt_config.php` | P2 | SYS-01 | لم تبدأ |
| 29 | SYS-05 | تبويب إعدادات طباعة الفاتورة | 2 النظام | `config#invoice_tab` | `configs/invoice_config.php` | P2 | SYS-01 | لم تبدأ |
| 30 | SYS-06 | تبويب الترقيم | 2 النظام | `config#numbering_tab` | `configs/numbering_config.php` | P3 | SYS-01 | لم تبدأ |
| 31 | SYS-07 | تبويب اختصارات لوحة المفاتيح في المبيعات | 2 النظام | `config#shortcuts_tab` | `configs/shortcuts_config.php` | P3 | SYS-01 | لم تبدأ |
| 32 | SYS-08 | تبويب أماكن المخزون (المستودعات) | 2 النظام | `config#stock_tab` | `configs/stock_config.php` + `partial/stock_locations.php` | P2 | SYS-01 | لم تبدأ |
| 33 | SYS-09 | تبويب الباركود | 2 النظام | `config#barcode_tab` | `configs/barcode_config.php` | P3 | SYS-01 | لم تبدأ |
| 34 | SYS-10 | تبويب اللغة والتنسيق المحلي | 2 النظام | `config#locale_tab` | `configs/locale_config.php` | P3 | SYS-01 | لم تبدأ |
| 35 | SYS-11 | تبويب الضريبة (افتراضيات الشركة) | 2 النظام | `config#tax_tab` | `configs/tax_config.php` + `partial/tax_categories.php` | P3 | SYS-01 | لم تبدأ |
| 36 | SYS-12 | تبويب المكافآت | 2 النظام | `config#reward_tab` | `configs/reward_config.php` + `partial/customer_rewards.php` | P3 | SYS-01 | لم تبدأ |
| 37 | SYS-13 | تبويب الطاولات | 2 النظام | `config#table_tab` | `configs/table_config.php` + `partial/dinner_tables.php` | P3 | SYS-01 | لم تبدأ |
| 38 | SYS-14 | تبويب النظام بتبويباته الفرعية: معلومات النظام، البريد، رسائل SMS، Mailchimp، الترخيص | 2 النظام | `config#system_tab` | `configs/system_config.php`، `system_info.php`، `email_config.php`، `message_config.php`، `integrations_config.php`، `license_config.php` | P3 | SYS-01 | لم تبدأ |
| 39 | SYS-15 | الفروع (قائمة) | 2 النظام | `branches` | `Branches::getIndex` · `branches/manage.php` | P2 | CMP-01 | لم تبدأ |
| 40 | SYS-16 | نموذج الفرع | 2 النظام | `branches/view[/{id}]` | `Branches::getView` · `branches/form.php` | P2 | CMP-02 | لم تبدأ |
| 41 | SYS-17 | الموظفون (قائمة) | 2 النظام | `employees` | `Persons::getIndex` · `people/manage.php` | P2 | SHL-01 | لم تبدأ |
| 42 | SYS-18 | نموذج الموظف (بيانات أساسية، الدخول، الصلاحيات، الفروع، الأدوار) | 2 النظام | `employees/view/{id}` | `Employees::getView` · `employees/form.php` | P2 | CMP-07 | لم تبدأ |
| 43 | SYS-19 | الأدوار (قائمة) | 2 النظام | `roles` | `Roles::getIndex` · `roles/manage.php` | P2 | CMP-01 | لم تبدأ |
| 44 | SYS-20 | نموذج الدور (الصلاحيات ونطاق السجلات) | 2 النظام | `roles/view[/{id}]` | `Roles::getView` · `roles/form.php` | P2 | CMP-02 | لم تبدأ |
| 45 | SYS-21 | الحقول الإضافية (قائمة) | 2 النظام | `custom_fields` | `Custom_fields::getIndex` · `custom_fields/manage.php` | P3 | CMP-01 | لم تبدأ |
| 46 | SYS-22 | نموذج الحقل الإضافي | 2 النظام | `custom_fields/view[/{id}]` | `custom_fields/form.php` | P3 | CMP-02 | لم تبدأ |
| 47 | SYS-23 | مركز الضرائب والفوترة الإلكترونية (بطاقات وفحوص الجاهزية) | 2 النظام | `tax_settings` | `TaxSettingsHub::getIndex` · `tax_settings/hub.php` | P3 | SHL-01 | لم تبدأ |
| 48 | SYS-24 | الضرائب (التبويبات الأربعة: الرموز، الولايات الضريبية، الفئات، النسب) | 2 النظام | `taxes` | `Taxes::getIndex`، `getAjax_tax_*` · `taxes/manage.php`، `tax_codes.php`، `tax_jurisdictions.php`، `tax_categories.php`، `tax_rates.php` | P3 | CMP-01 | لم تبدأ |
| 49 | SYS-25 | نموذج نسبة الضريبة | 2 النظام | `taxes/view/{id}` | `taxes/tax_rates_form.php` | P3 | CMP-02 | لم تبدأ |
| 50 | SYS-26 | فئات ضريبة القيمة المضافة (الفئات، النسب، تصنيف الأصناف) | 2 النظام | `vat_classes` | `Vat_classes::getIndex` · `vat_classes/manage.php` | P3 | SYS-23 | لم تبدأ |
| 51 | SYS-27 | تعريفات الضرائب (قائمة) | 2 النظام | `tax_definitions` | `Tax_definitions::getIndex` · `tax_definitions/manage.php` | P3 | CMP-01 | لم تبدأ |
| 52 | SYS-28 | نموذج تعريف الضريبة | 2 النظام | `tax_definitions/view[/{id}]` | `tax_definitions/form.php` | P3 | CMP-02 | لم تبدأ |
| 53 | SYS-29 | إعدادات التكامل | 2 النظام | `integrations` | `Integrations::getIndex` · `integrations/manage.php` | P3 | SHL-01 | لم تبدأ |
| 54 | SYS-30 | ترجمة رموز التكامل | 2 النظام | `integrations/translations` | `integrations/translations.php` | P3 | SYS-29 | لم تبدأ |
| 55 | SYS-31 | طرق الدفع | 2 النظام | `integrations/payment_methods` | `integrations/payment_methods.php` | P2 | SHL-01 | لم تبدأ |
| 56 | SYS-32 | قواعد الإشعارات | 2 النظام | `integrations/rules` | `integrations/rules.php` | P3 | SHL-01 | لم تبدأ |
| 57 | SYS-33 | مفاتيح API | 2 النظام | `api_keys` | `Api_keys::getIndex` · `api_keys/manage.php` | P3 | SHL-01 | لم تبدأ |

## المرحلة 4: إدارة المخازن

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 58 | INV-01 | الأصناف (قائمة) | 6 المخازن | `items` | `Items::getIndex` · `items/manage.php` | P1 | CMP-01 | لم تبدأ |
| 59 | INV-02 | نموذج الصنف (مع كتلة السمات والحقول الإضافية) | 6 المخازن | `items/view/{id}`، `items/attributes/{id}` | `Items::getView` · `items/form.php`، `attributes/item.php` | P1 | CMP-02، CMP-07 | لم تبدأ |
| 60 | INV-03 | تعديل كمية الصنف | 6 المخازن | `items/inventory/{id}` | `items/form_inventory.php` | P2 | INV-02 | لم تبدأ |
| 61 | INV-04 | سجل حركة الصنف | 6 المخازن | `items/countDetails/{id}` | `items/form_count_details.php` | P2 | INV-02 | لم تبدأ |
| 62 | INV-05 | التعديل الجماعي للأصناف | 6 المخازن | `items/bulkEdit` | `items/form_bulk.php` | P3 | CMP-02 | لم تبدأ |
| 63 | INV-06 | استيراد الأصناف من CSV | 6 المخازن | `items/csvImport` | `items/form_csv_import.php` | P3 | CMP-02 | لم تبدأ |
| 64 | INV-07 | صفحة طباعة ملصقات الباركود | 6 المخازن | `items/generateBarcodes/{ids}`، `item_kits/generateBarcodes/{ids}` | `barcodes/barcode_sheet.php` | P2 | SYS-09 | لم تبدأ |
| 65 | INV-08 | الأطقم (قائمة) | 6 المخازن | `item_kits` | `Item_kits::getIndex` · `item_kits/manage.php` | P2 | CMP-01 | لم تبدأ |
| 66 | INV-09 | نموذج الطقم | 6 المخازن | `item_kits/view/{id}` | `item_kits/form.php` | P2 | CMP-02 | لم تبدأ |
| 67 | INV-10 | سمات الأصناف (قائمة) | 6 المخازن | `attributes` | `attributes/manage.php` | P3 | CMP-01 | لم تبدأ |
| 68 | INV-11 | نموذج السمة | 6 المخازن | `attributes/view/{id}` | `attributes/form.php` | P3 | CMP-02 | لم تبدأ |
| 69 | INV-12 | العلامات التجارية (قائمة) | 6 المخازن | `item_brands` | `LookupScreen::getIndex` · `lookups/manage.php` | P3 | CMP-01 | لم تبدأ |
| 70 | INV-13 | نموذج القوائم المرجعية (العلامة التجارية وتصنيف العملاء) | 6 المخازن | `item_brands/view[/{id}]`، `customer_classifications/view[/{id}]` | `LookupScreen::getView` · `lookups/form.php` | P3 | CMP-02 | لم تبدأ |
| 71 | INV-14 | الوحدات والتتبع (قوالب الوحدات، وحدات الصنف، الدفعات والأرقام التسلسلية، الباركودات) | 6 المخازن | `stock_units` | `StockUnits::getIndex` · `stock_units/manage.php` | P2 | SHL-01 | لم تبدأ |
| 72 | INV-15 | نموذج قالب الوحدة | 6 المخازن | `stock_units/templateForm/{kind}` | `stock_units/template_form.php` | P3 | CMP-02 | لم تبدأ |
| 73 | INV-16 | قوائم الأسعار | 6 المخازن | `price_lists` | `price_lists/manage.php` | P2 | CMP-01 | لم تبدأ |
| 74 | INV-17 | نموذج قائمة الأسعار | 6 المخازن | `price_lists/view[/{id}]` | `price_lists/form.php` | P2 | CMP-02 | لم تبدأ |
| 75 | INV-18 | مستندات المخزون: تبويبات + أذون المخزون (قائمة) | 6 المخازن | `stock_documents`، `stock_documents/permits` | `StockPermits::getIndex` · `stock_permits/manage.php` + `stock_documents/tabs.php` | P2 | CMP-01 | لم تبدأ |
| 76 | INV-19 | نموذج إذن المخزون (إضافة، صرف، تحويل) | 6 المخازن | `stock_documents/permits/form/{type}`، `edit/{id}` | `stock_permits/form.php` | P2 | CMP-02 | لم تبدأ |
| 77 | INV-20 | عرض إذن المخزون + نافذة إلغائه | 6 المخازن | `stock_documents/permits/view/{id}`، `cancel_form/{id}` | `stock_permits/view.php`، `cancel_form.php` | P2 | CMP-03 | لم تبدأ |
| 78 | INV-21 | الجرد (قائمة) + نافذة جرد جديد | 6 المخازن | `stock_documents/stocktakes`، `stocktakes/form` | `stocktakes/manage.php`، `form.php` | P2 | INV-18 | لم تبدأ |
| 79 | INV-22 | ورقة الجرد (إدخال الكميات، إضافة وحذف أصناف، اعتماد) + نافذة الإلغاء | 6 المخازن | `stock_documents/stocktakes/view/{id}`، `cancel_form/{id}` | `stocktakes/sheet.php`، `cancel_form.php` | P2 | INV-21 | لم تبدأ |
| 80 | INV-23 | ورقة الجرد المطبوعة | 6 المخازن | `stock_documents/stocktakes/sheet/{id}` | `stocktakes/print.php` | P3 | INV-22 | لم تبدأ |
| 81 | INV-24 | طلبات المخزون (قائمة) + نافذة طلب جديد | 6 المخازن | `stock_documents/requests`، `requests/form` | `stock_requests/manage.php`، `form.php` | P3 | INV-18 | لم تبدأ |
| 82 | INV-25 | عرض طلب المخزون (اعتماد، تنفيذ) + نافذة الإلغاء | 6 المخازن | `stock_documents/requests/view/{id}`، `cancel_form/{id}` | `stock_requests/view.php`، `cancel_form.php` | P3 | INV-24 | لم تبدأ |

## المرحلة 5: إدارة المبيعات (ومعها العملاء)

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 83 | SAL-01 | نقطة البيع (أوضاع البيع والإرجاع وعرض السعر وأمر العمل) | 4 المبيعات | `sales` | `Sales::getIndex` · `sales/register.php` | P1 | INV-01، CMP-05 | لم تبدأ |
| 84 | SAL-02 | المبيعات المعلقة | 4 المبيعات | `sales/suspended` | `sales/suspended.php` | P1 | SAL-01 | لم تبدأ |
| 85 | SAL-03 | مساعدة اختصارات لوحة المفاتيح في نقطة البيع | 4 المبيعات | `sales/salesKeyboardHelp` | `sales/help.php` | P2 | SAL-01 | لم تبدأ |
| 86 | SAL-04 | إيصال البيع (القوالب: الافتراضي، المختصر، الحراري 80 و58 ملم) | 4 المبيعات | `sales/receipt/{id}` | `Sales::getReceipt` · `sales/receipt.php`، `receipt_default.php`، `receipt_short.php`، `receipt_thermal*.php` | P1 | SYS-04 | لم تبدأ |
| 87 | SAL-05 | الفاتورة والفاتورة الضريبية | 4 المبيعات | `sales/invoice/{id}` | `Sales::getInvoice` · `sales/invoice.php`، `tax_invoice.php` | P1 | SYS-05، CMP-09 | لم تبدأ |
| 88 | SAL-06 | طباعة عرض السعر | 4 المبيعات | بعد `sales/complete` | `sales/quote.php` | P2 | SAL-05 | لم تبدأ |
| 89 | SAL-07 | طباعة أمر العمل | 4 المبيعات | بعد `sales/complete` | `sales/work_order.php` | P3 | SAL-05 | لم تبدأ |
| 90 | SAL-08 | طباعة مستند البيع (إشعار دائن وغيره) | 4 المبيعات | `sales/print/{id}/{type}` | `sales/document_print.php` | P2 | SAL-05 | لم تبدأ |
| 91 | SAL-09 | سند قبض دفعة العميل | 4 المبيعات | `sales/paymentReceipt/{id}` | `documents/screen.php` | P2 | CMP-05 | لم تبدأ |
| 92 | SAL-10 | سجل فواتير المبيعات | 4 المبيعات | `sales/manage` | `Sales::getManage` · `sales/manage.php` | P1 | CMP-01 | لم تبدأ |
| 93 | SAL-11 | نافذة تعديل البيع | 4 المبيعات | `sales/edit/{id}` | `sales/form.php` | P2 | CMP-02 | لم تبدأ |
| 94 | SAL-12 | المسودات وعروض الأسعار (قائمة) | 4 المبيعات | `sales/documents` | `sales/documents.php` | P2 | CMP-01 | لم تبدأ |
| 95 | SAL-13 | تفاصيل المسودة أو عرض السعر (إصدار، حالة العرض، تحويل) | 4 المبيعات | `sales/document/{id}` | `sales/document.php` | P2 | SAL-12 | لم تبدأ |
| 96 | SAL-14 | أوامر البيع (قائمة) | 4 المبيعات | `customer_orders` | `customer_orders/manage.php` | P2 | CMP-01 | لم تبدأ |
| 97 | SAL-15 | نموذج أمر البيع | 4 المبيعات | `customer_orders/add`، `edit/{id}` | `customer_orders/form.php` | P2 | CMP-06 | لم تبدأ |
| 98 | SAL-16 | عرض أمر البيع (حالة، إلغاء، تحويل لفاتورة) | 4 المبيعات | `customer_orders/view/{id}` | `customer_orders/view.php` | P2 | SAL-15 | لم تبدأ |
| 99 | SAL-17 | الفواتير المتكررة (قائمة) | 4 المبيعات | `recurring_invoices` | `RecurringScreen::getIndex` · `recurring/manage.php` | P3 | CMP-01 | لم تبدأ |
| 100 | SAL-18 | نموذج الفاتورة المتكررة | 4 المبيعات | `recurring_invoices/add`، `edit/{id}` | `recurring/form.php` | P3 | CMP-06 | لم تبدأ |
| 101 | SAL-19 | عرض الفاتورة المتكررة (الجدول، التشغيلات، إعادة المحاولة) | 4 المبيعات | `recurring_invoices/view/{id}` | `recurring/view.php` | P3 | SAL-18 | لم تبدأ |
| 102 | SAL-20 | المرتجعات والإشعارات الدائنة | 4 المبيعات | `accounting_receivables/credit_notes` | `AccountingReceivables::getCreditNotes` · `accounting_receivables/credit_notes.php` | P2 | CMP-01 (تبويب في SAL-30) | لم تبدأ |
| 103 | SAL-21 | إشعارات مدينة للعملاء (قائمة) | 4 المبيعات | `accounting_debit_notes` | `accounting_debit_notes/manage.php` | P3 | CMP-01 | لم تبدأ |
| 104 | SAL-22 | نافذة إشعار مدين جديد + نافذة عكسه | 4 المبيعات | `accounting_debit_notes/form`، `reverse_form/{id}` | `accounting_debit_notes/form.php`، `reverse_form.php` | P3 | CMP-02، CMP-03 | لم تبدأ |
| 105 | SAL-23 | مقبوضات العملاء | 4 المبيعات | `accounting_receivables/payments` | `accounting_receivables/payments.php` | P2 | CMP-01 (تبويب في SAL-30) | لم تبدأ |
| 106 | SAL-24 | صافي موقف الفواتير (ومنه: الفواتير المحصّلة، الفواتير المرتجعة) | 4 المبيعات | `accounting_reports/net_position` (بفلاتر الحالة) | `AccountingNetPosition::getIndex` · `accounting_operational/report.php` | P2 | CMP-04 | لم تبدأ |
| 107 | SAL-25 | بطاقات الهدايا (قائمة) + نموذجها | 4 المبيعات | `giftcards`، `giftcards/view/{id}` | `giftcards/manage.php`، `form.php` | P3 | CMP-01، CMP-02 | لم تبدأ |
| 108 | SAL-26 | العملاء (قائمة) | 4 المبيعات | `customers` | `Customers::getIndex` · `customers/manage.php` | P1 | CMP-01 | لم تبدأ |
| 109 | SAL-27 | نموذج العميل (بيانات أساسية، جهات اتصال، البوابة، إحصاءات، Mailchimp) | 4 المبيعات | `customers/view/{id}` | `Customers::getView` · `customers/form.php`، `customer_portal/panel.php` | P1 | CMP-07 | لم تبدأ |
| 110 | SAL-28 | استيراد العملاء من CSV | 4 المبيعات | `customers/csvImport` | `customers/form_csv_import.php` | P3 | INV-06 | لم تبدأ |
| 111 | SAL-29 | تصنيفات العملاء (قائمة) | 4 المبيعات | `customer_classifications` | `lookups/manage.php` | P3 | INV-12، INV-13 | لم تبدأ |
| 112 | SAL-30 | أرصدة العملاء (تبويبات الذمم المدينة) | 4 المبيعات | `accounting_receivables` | `AccountingReceivables::getIndex` · `accounting_receivables/manage.php` + `tabs.php` | P2 | CMP-01 | لم تبدأ |
| 113 | SAL-31 | حساب العميل (كشف، مستندات، جهات اتصال) | 4 المبيعات | `accounting_receivables/customer/{id}` | `accounting_receivables/customer.php` | P2 | SAL-30 | لم تبدأ |
| 114 | SAL-32 | نوافذ حساب العميل: الرصيد الافتتاحي (إضافة، تعديل، إلغاء)، دفعة مقدمة، استرداد، إلغاء مستند | 4 المبيعات | `accounting_receivables/opening_form/{id}`، `opening_edit_form`، `opening_cancel_form`، `advance_form`، `refund_form`، `cancel_form/{what}/{id}` | `accounting_receivables/*_form.php` | P2 | SAL-31، CMP-03 | لم تبدأ |
| 115 | SAL-33 | أعمار ديون العملاء | 4 المبيعات | `accounting_receivables/aged_ledger` | `accounting_parties/aged_ledger.php` | P2 | CMP-04 | لم تبدأ |
| 116 | SAL-34 | الرسائل (إرسال SMS للعملاء) | 4 المبيعات | `messages` | `Messages::getIndex` · `messages/sms.php` | P3 | SHL-01 | لم تبدأ |


## المرحلة 6: إدارة المشتريات (ومعها الموردون)

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 117 | PUR-01 | سجل فواتير المشتريات (مع التصدير) | 5 المشتريات | `purchases` | `Purchases::getIndex` · `purchases/manage.php` | P1 | CMP-01 | لم تبدأ |
| 118 | PUR-02 | شاشة الاستلام: فاتورة شراء، مرتجع، تحويل مخزني | 5 المشتريات | `receivings` | `Receivings::getIndex` · `receivings/receiving.php` | P1 | SAL-01 | لم تبدأ |
| 119 | PUR-03 | إيصال الاستلام | 5 المشتريات | `receivings/receipt/{id}` | `receivings/receipt.php` | P2 | CMP-05 | لم تبدأ |
| 120 | PUR-04 | طباعة فاتورة الشراء | 5 المشتريات | `receivings/print/{id}` | `documents/screen.php` + `documents/purchase_invoice.php` | P2 | CMP-05 | لم تبدأ |
| 121 | PUR-05 | سند صرف دفعة المورد | 5 المشتريات | `receivings/paymentReceipt/{id}` | `documents/screen.php` | P2 | CMP-05 | لم تبدأ |
| 122 | PUR-06 | نافذة تعديل فاتورة الشراء | 5 المشتريات | `receivings/edit/{id}` | `receivings/form.php` | P2 | CMP-02 | لم تبدأ |
| 123 | PUR-07 | دورة الشراء: القائمة الموحدة (طلبات الشراء، طلبات عروض الأسعار، عروض الموردين، أوامر الشراء) | 5 المشتريات | `purchase_requests`، `quotation_requests`، `supplier_quotations`، `purchase_orders` | `PurchaseCycleScreen::getIndex` · `purchase_cycle/manage.php` | P2 | CMP-01 | لم تبدأ |
| 124 | PUR-08 | دورة الشراء: نموذج المستند (للأنواع الأربعة) | 5 المشتريات | `{type}/add`، `{type}/edit/{id}` | `PurchaseCycleScreen::getForm` · `purchase_cycle/form.php`، `lines.php` | P2 | CMP-06 | لم تبدأ |
| 125 | PUR-09 | دورة الشراء: عرض المستند (حالة، إلغاء، تحويل للمرحلة التالية أو للاستلام) | 5 المشتريات | `{type}/view/{id}` | `PurchaseCycleScreen::getShow` · `purchase_cycle/view.php` | P2 | PUR-08 | لم تبدأ |
| 126 | PUR-10 | الموردون (قائمة) | 5 المشتريات | `suppliers` | `Suppliers::getIndex` · `people/manage.php` | P1 | CMP-01 | لم تبدأ |
| 127 | PUR-11 | نموذج المورد | 5 المشتريات | `suppliers/view/{id}` | `suppliers/form.php` | P1 | CMP-07 | لم تبدأ |
| 128 | PUR-12 | أرصدة الموردين | 5 المشتريات | `accounting_payables` | `AccountingPayables::getIndex` · `accounting_payables/manage.php` | P2 | CMP-01 | لم تبدأ |
| 129 | PUR-13 | حساب المورد | 5 المشتريات | `accounting_payables/supplier/{id}` | `accounting_payables/supplier.php` | P2 | PUR-12 | لم تبدأ |
| 130 | PUR-14 | نوافذ حساب المورد: دفعة، رصيد افتتاحي، استرداد، إلغاء مستند | 5 المشتريات | `accounting_payables/payment_form/{id}`، `opening_form`، `refund_form`، `cancel_form/{what}/{id}` | `accounting_payables/*_form.php` | P2 | PUR-13، CMP-03 | لم تبدأ |
| 131 | PUR-15 | أعمار ديون الموردين | 5 المشتريات | `accounting_payables/aged_ledger` | `accounting_parties/aged_ledger.php` | P2 | SAL-33 | لم تبدأ |
| 132 | PUR-16 | إشعارات ومرتجعات الموردين | 5 المشتريات | `accounting_payables/debit_notes` | `accounting_payables/debit_notes.php` | P2 | CMP-01 | لم تبدأ |
| 133 | PUR-17 | مدفوعات الموردين | 5 المشتريات | `accounting_payables/payments` | `accounting_payables/payments.php` | P2 | CMP-01 | لم تبدأ |

## المرحلة 7: الإدارة المالية

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 134 | FIN-01 | المصروفات وسندات صرفها (قائمة) | 3 المالية | `expenses` | `Expenses::getIndex` · `expenses/manage.php` | P1 | CMP-01 | لم تبدأ |
| 135 | FIN-02 | نموذج المصروف | 3 المالية | `expenses/view/{id}` | `expenses/form.php` | P1 | CMP-02 | لم تبدأ |
| 136 | FIN-03 | سند صرف المصروف (طباعة) | 3 المالية | `expenses/voucher/{id}` | `documents/screen.php` | P2 | CMP-05 | لم تبدأ |
| 137 | FIN-04 | فئات المصروفات (قائمة) + نموذجها | 3 المالية | `expenses_categories`، `view/{id}` | `expenses_categories/manage.php`، `form.php` | P3 | CMP-01، CMP-02 | لم تبدأ |
| 138 | FIN-05 | المصروفات المتكررة: القائمة والنموذج والعرض | 3 المالية | `recurring_expenses`، `add`، `edit/{id}`، `view/{id}` | `RecurringScreen` · `recurring/*.php` | P3 | SAL-17..19 | لم تبدأ |
| 139 | FIN-06 | سندات القبض (قائمة) | 3 المالية | `receipt_vouchers` | `receipt_vouchers/manage.php` | P2 | CMP-01 | لم تبدأ |
| 140 | FIN-07 | نموذج سند القبض | 3 المالية | `receipt_vouchers/view[/{id}]` | `receipt_vouchers/form.php` | P2 | CMP-02 | لم تبدأ |
| 141 | FIN-08 | طباعة سند القبض | 3 المالية | `receipt_vouchers/print/{id}` | `documents/screen.php` | P2 | CMP-05 | لم تبدأ |
| 142 | FIN-09 | فئات الإيرادات (قائمة) + نموذجها | 3 المالية | `revenue_categories`، `view[/{id}]` | `revenue_categories/*.php` | P3 | FIN-04 | لم تبدأ |
| 143 | FIN-10 | الخزائن والحسابات البنكية (قائمة) | 3 المالية | `accounting_treasuries` | `accounting_treasuries/manage.php` | P2 | CMP-01 | لم تبدأ |
| 144 | FIN-11 | نموذج الخزينة أو الحساب البنكي | 3 المالية | `accounting_treasuries/view[/{id}]` | `accounting_treasuries/form.php` | P2 | CMP-02 | لم تبدأ |
| 145 | FIN-12 | كشف الخزينة | 3 المالية | `accounting_treasuries/statement/{id}` | `accounting_treasuries/statement.php` | P2 | CMP-04 | لم تبدأ |
| 146 | FIN-13 | الخزائن الافتراضية لكل طريقة دفع وفرع | 3 المالية | `accounting_treasuries/defaults` | `accounting_treasuries/defaults.php` | P3 | FIN-10 | لم تبدأ |
| 147 | FIN-14 | التحويلات بين الخزائن (قائمة) | 3 المالية | `accounting_treasuries/transfers` | `accounting_treasuries/transfers.php` | P2 | CMP-01 | لم تبدأ |
| 148 | FIN-15 | نافذة تحويل جديد + نافذة إلغاء تحويل | 3 المالية | `accounting_treasuries/transfer_form`، `cancel_transfer_form/{id}` | `transfer_form.php`، `cancel_form.php` | P2 | CMP-02، CMP-03 | لم تبدأ |
| 149 | FIN-16 | الشيكات (تبويبا الواردة والصادرة) | 3 المالية | `accounting_cheques?direction=received|paid` | `accounting_cheques/manage.php` | P2 | CMP-01 | لم تبدأ |
| 150 | FIN-17 | نموذج الشيك | 3 المالية | `accounting_cheques/form/{direction}[/{id}]` | `accounting_cheques/form.php` | P2 | CMP-02 | لم تبدأ |
| 151 | FIN-18 | تفاصيل الشيك + نافذة الإجراء (إيداع، تحصيل، ارتداد…) | 3 المالية | `accounting_cheques/view/{id}`، `action_form/{action}/{id}` | `accounting_cheques/view.php`، `action_form.php` | P2 | FIN-16 | لم تبدأ |
| 152 | FIN-19 | دفاتر الشيكات + نموذج الدفتر | 3 المالية | `accounting_cheques/books`، `book_form[/{id}]` | `books.php`، `book_form.php` | P3 | FIN-16 | لم تبدأ |
| 153 | FIN-20 | التسوية البنكية: الحسابات | 3 المالية | `accounting_treasuries/bank` | `AccountingBankReconciliation::getIndex` · `accounting_bank/index.php` | P2 | SHL-01 | لم تبدأ |
| 154 | FIN-21 | التسوية البنكية: الحساب الواحد (مع الاستيراد والسطور والمطابقات والاقتراحات ونافذة مطابقة سطر) | 3 المالية | `accounting_treasuries/bank/{id}`، `/import`، `/lines`، `/matches`، `/match/{line}`، `/suggestions` | `accounting_bank/account.php`، `import.php`، `lines.php`، `matches.php`، `match.php`، `suggestions.php` | P2 | FIN-20 | لم تبدأ |
| 155 | FIN-22 | تقرير التسوية البنكية | 3 المالية | `accounting_treasuries/bank/{id}/report` | `accounting_bank/report.php` | P3 | CMP-04 | لم تبدأ |
| 156 | FIN-23 | التسويات بين الفروع (الأرصدة والتسويات، نافذة تسوية جديدة، نافذة الإلغاء) | 3 المالية | `accounting_treasuries/inter_branch`، `settle_form`، `cancel_form/{id}` | `AccountingInterBranch` · `inter_branch.php`، `inter_branch_pairs.php`، `settle_form.php`، `settlement_cancel_form.php` | P3 | CMP-01، CMP-03 | لم تبدأ |
| 157 | FIN-24 | إقفالات الصندوق (قائمة) + نموذج فتح وإقفال الصندوق | 3 المالية | `cashups`، `cashups/view/{id}` | `cashups/manage.php`، `form.php` | P1 | CMP-01، CMP-02 | لم تبدأ |
| 158 | FIN-25 | نظرة عامة على المحاسبة | 3 المالية | `accounting_reports/overview` | `AccountingOverview::getIndex` · `accounting_reports/overview.php` | P2 | DASH-01 | لم تبدأ |
| 159 | FIN-26 | دليل الحسابات (الشجرة) | 3 المالية | `accounting_chart` | `AccountingChart::getIndex` · `accounting_chart/manage.php`، `node.php`، `branch.php`، `find.php`، `actions.php` | P2 | SHL-01 | لم تبدأ |
| 160 | FIN-27 | نموذج الحساب | 3 المالية | `accounting_chart/view[/{id}]` | `accounting_chart/form.php` | P2 | CMP-02 | لم تبدأ |
| 161 | FIN-28 | القيود اليومية (قائمة، ومنها عرض «ترحيل القيود» للمسودات) | 3 المالية | `accounting_journals`، `?status[]=draft` | `AccountingJournals::getIndex` · `accounting_journals/manage.php` | P1 | CMP-01 | لم تبدأ |
| 162 | FIN-29 | محرر القيد (جديد، تعديل مسودة، مرفقات، توزيع مراكز التكلفة) | 3 المالية | `accounting_journals/new`، `edit/{id}` | `accounting_journals/form.php` + `attachments.php`، `line_more.php`، `line_charge.php` | P1 | FIN-26 | لم تبدأ |
| 163 | FIN-30 | عرض القيد | 3 المالية | `accounting_journals/view/{id}` | `accounting_journals/view.php` | P1 | FIN-28 | لم تبدأ |
| 164 | FIN-31 | طباعة القيد | 3 المالية | `accounting_journals/print/{id}` | `documents/screen.php` + `documents/journal.php` | P2 | CMP-05 | لم تبدأ |
| 165 | FIN-32 | نافذة عكس القيد + صفحة تعديل بيانات قيد مرحّل | 3 المالية | `accounting_journals/reverse_form/{id}`، `edit_details/{id}` | `reverse_form.php`، `edit_details.php` | P2 | CMP-03 | لم تبدأ |
| 166 | FIN-33 | كشف الحساب (الأستاذ العام) | 3 المالية | `accounting_reports/ledger` | `accounting_reports/ledger.php`، `ledger_blocks.php` | P1 | CMP-04 | لم تبدأ |
| 167 | FIN-34 | ميزان المراجعة | 3 المالية | `accounting_reports/trial_balance` | `accounting_reports/trial_balance.php` | P1 | CMP-04 | لم تبدأ |
| 168 | FIN-35 | الميزانية العمومية | 3 المالية | `accounting_statements/balance_sheet` | `accounting_statements/balance_sheet.php` + `toolbar.php`، `filters_common.php`، `section.php`، `notes.php` | P2 | CMP-04 | لم تبدأ |
| 169 | FIN-36 | قائمة الدخل | 3 المالية | `accounting_statements/income_statement` | `accounting_statements/income_statement.php` | P2 | FIN-35 | لم تبدأ |
| 170 | FIN-37 | قائمة التدفقات النقدية + إعدادات تصنيف الحسابات | 3 المالية | `accounting_statements/cash_flow`، `cash_flow/settings` | `cash_flow.php`، `cash_flow_settings.php` | P2 | FIN-35 | لم تبدأ |
| 171 | FIN-38 | تقرير الضريبة (إقرار القيمة المضافة) | 3 المالية | `accounting_reports/tax` | `accounting_reports/tax_report.php` | P2 | CMP-04 | لم تبدأ |
| 172 | FIN-39 | السنوات المالية (تبويبات الفترات) + نموذج السنة | 3 المالية | `accounting_periods`، `view[/{id}]` | `accounting_periods/manage.php`، `tabs.php`، `form.php` | P2 | CMP-01 | لم تبدأ |
| 173 | FIN-40 | إقفال الفترات + نافذتا الإقفال وإعادة الفتح | 3 المالية | `accounting_periods/closings`، `close_form`، `reopen_form` | `closings.php`، `close_form.php`، `reopen_form.php` | P2 | FIN-39 | لم تبدأ |
| 174 | FIN-41 | الإقفال السنوي + نافذتا الاعتماد والعكس | 3 المالية | `accounting_periods/year_end`، `approve_form`، `reverse_form/{id}` | `year_end.php`، `year_end_approve_form.php`، `year_end_reverse_form.php` | P3 | FIN-39 | لم تبدأ |
| 175 | FIN-42 | الأرصدة الافتتاحية (الحسابات والمخزون الافتتاحي) | 3 المالية | `accounting_periods/opening` | `accounting_periods/opening.php`، `opening_stock.php` | P3 | FIN-39 | لم تبدأ |
| 176 | FIN-43 | نوافذ الأرصدة الافتتاحية: عكس، فروقات المخزون، عكس المخزون، إعادة التسعير | 3 المالية | `accounting_periods/opening/reverse_form`، `stock/differences_form`، `stock/reverse_form`، `stock/reprice_form` | `opening_*_form.php` | P3 | FIN-42، CMP-03 | لم تبدأ |
| 177 | FIN-44 | استيراد الأرصدة الافتتاحية + المعاينة + نافذة الاعتماد | 3 المالية | `accounting_periods/opening_import`، `preview/{kind}/{token}`، `approve_form/…` | `opening_import.php`، `opening_import_preview.php`، `opening_import_approve_form.php` | P3 | FIN-42 | لم تبدأ |
| 178 | FIN-45 | الترحيل بأثر رجعي للمستندات السابقة (الدفعات، دفعة جديدة، عرض الدفعة، اعتمادها، إلغاؤها) | 3 المالية | `accounting_periods/backfill`، `new_form`، `view/{id}`، `approve_form/{id}`، `cancel_form/{id}` | `AccountingBackfill` · `backfill*.php` | P3 | FIN-39 | لم تبدأ |
| 179 | FIN-46 | سجل الأصول الثابتة (تبويبات الأصول) | 3 المالية | `accounting_assets` | `accounting_assets/index.php`، `tabs.php` | P3 | CMP-01 | لم تبدأ |
| 180 | FIN-47 | نموذج الأصل الثابت | 3 المالية | `accounting_assets/form[/{id}]` | `accounting_assets/form.php` | P3 | FIN-46 | لم تبدأ |
| 181 | FIN-48 | تفاصيل الأصل + نوافذ الاستخدام والاستبعاد أو البيع وعكس الاستبعاد | 3 المالية | `accounting_assets/view/{id}`، `usage_form/{id}`، `dispose_form/{kind}/{id}`، `dispose_reverse_form/{id}` | `detail.php`، `usage_form.php`، `dispose_form.php`، `dispose_reverse_form.php` | P3 | FIN-46، CMP-03 | لم تبدأ |
| 182 | FIN-49 | فئات الأصول + نموذج الفئة | 3 المالية | `accounting_assets/categories`، `category_form[/{id}]` | `categories.php`، `category_form.php` | P3 | FIN-46 | لم تبدأ |
| 183 | FIN-50 | الإهلاك (التشغيلات، نافذتا الاعتماد والعكس) | 3 المالية | `accounting_assets/depreciation`، `approve_form`، `reverse_form/{id}` | `depreciation.php`، `approve_form.php`، `reverse_form.php` | P3 | FIN-46 | لم تبدأ |
| 184 | FIN-51 | مراكز التكلفة (قائمة، نموذج، الافتراضيات) | 3 المالية | `accounting_cost_centers`، `view[/{id}]`، `defaults[/{id}]` | `accounting_cost_centers/*.php` | P3 | CMP-01 | لم تبدأ |
| 185 | FIN-52 | العملات (قائمة) + نموذج العملة | 3 المالية | `accounting_currencies`، `form[/{code}]` | `accounting_currencies/manage.php`، `form.php` | P3 | CMP-01 | لم تبدأ |
| 186 | FIN-53 | أسعار صرف العملة + نموذج السعر | 3 المالية | `accounting_currencies/rates/{code}`، `rate_form/{code}[/{id}]` | `rates.php`، `rate_form.php` | P3 | FIN-52 | لم تبدأ |
| 187 | FIN-54 | الوكيل الذكي: المقترحات، تفاصيل المقترح، نافذتا الاعتماد والرفض | 3 المالية | `accounting_ai`، `proposal/{id}`، `approve_form/{id}`، `reject_form/{id}` | `accounting_ai/proposals.php`، `proposal.php`، `evidence.php`، `approve_form.php`، `reject_form.php` | P3 | SHL-01 | لم تبدأ |
| 188 | FIN-55 | الوكيل الذكي: الملاحظات ونافذة القرار، والإعدادات | 3 المالية | `accounting_ai/findings`، `decide_form/{id}`، `settings` | `findings.php`، `decide_form.php`، `settings.php` | P3 | FIN-54 | لم تبدأ |
| 189 | FIN-56 | إعدادات المحاسبة (الحالة، إيقاف العمليات، عام، القيود، شركات الشحن، الضوابط، أصحاب الصلاحيات، السنة الأولى) | 3 المالية | `accounting_settings` | `AccountingSetup::getIndex` · `accounting_settings/manage.php` | P3 | SHL-01 | لم تبدأ |
| 190 | FIN-57 | معالج تشغيل المحاسبة (الوجهة من تنبيه لوحة التحكم) | 3 المالية | `accounting_settings/activate` | `AccountingSetup::getActivate` · `accounting_settings/activate.php`، `activation_panels.php` | P3 | FIN-56 | لم تبدأ |
| 191 | FIN-58 | توجيه الحسابات (الحسابات الافتراضية لكل عملية) | 3 المالية | `accounting_routing` | `AccountingRouting::getIndex` · `accounting_routing/manage.php` | P3 | FIN-26 | لم تبدأ |
| 192 | FIN-59 | الفوترة الإلكترونية (الإعدادات، المستندات الصادرة) | 3 المالية | `accounting_einvoicing` | `AccountingEInvoicing::getIndex` · `accounting_einvoicing/manage.php` | P3 | SYS-23 | لم تبدأ |
| 193 | FIN-60 | تكامل ZATCA (تصميم فقط، دون تفعيل الوضع السعودي أو الاتصال) | 3 المالية | `accounting_zatca` | `AccountingZatca::getIndex` · `accounting_zatca/manage.php` | P3 | FIN-59 | لم تبدأ |

## المرحلة 8: التقارير

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 194 | RPT-01 | مركز التقارير (بطاقات مجمّعة وبحث) | 7 التقارير | `reports` | `Reports::getIndex` · `reports/listing.php` | P1 | SHL-01 | لم تبدأ |
| 195 | RPT-02 | قوالب مدخلات التقارير الكلاسيكية: الفترة، الكيان المحدد، العميل المحدد، ملخص المخزون | 7 التقارير | خطوة الإدخال قبل كل تقرير | `reports/date_input.php`، `specific_input.php`، `specific_customer_input.php`، `inventory_summary_input.php`، `_head.php` | P2 | CMP-04 | لم تبدأ |
| 196 | RPT-03 | قوالب مخرجات التقارير الكلاسيكية: جدول، جدول بتفاصيل قابلة للفتح، رسم بياني | 7 التقارير | مخرجات كل تقرير | `reports/tabular.php`، `tabular_details.php`، `graphical.php`، `_summary.php` | P2 | CMP-04 | لم تبدأ |
| 197 | RPT-04 | تقارير المبيعات: رسم، ملخص، تفصيلي، مبيعات الأصناف التفصيلية | 7 التقارير | `reports/graphical_summary_sales`، `summary_sales`، `detailed_sales`، `detailed_item_sales` | `Reports` · RPT-02/03 | P2 | RPT-03 | لم تبدأ |
| 198 | RPT-05 | تقارير العملاء: رسم، ملخص، تفصيلي لعميل | 7 التقارير | `reports/graphical_summary_customers`، `summary_customers`، `specific_customers` | RPT-02/03 | P2 | RPT-03 | لم تبدأ |
| 199 | RPT-06 | تقارير الموظفين: رسم، ملخص، تفصيلي لموظف | 7 التقارير | `reports/graphical_summary_employees`، `summary_employees`، `specific_employees` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 200 | RPT-07 | تقارير المدفوعات: رسم، ملخص | 7 التقارير | `reports/graphical_summary_payments`، `summary_payments` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 201 | RPT-08 | تقارير الخصومات: رسم، ملخص، تفصيلي | 7 التقارير | `reports/graphical_summary_discounts`، `summary_discounts`، `specific_discounts` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 202 | RPT-09 | تقارير الأصناف والفئات: رسم، ملخص | 7 التقارير | `reports/graphical_summary_items`، `summary_items`، `graphical_summary_categories`، `summary_categories` | RPT-02/03 | P2 | RPT-03 | لم تبدأ |
| 203 | RPT-10 | تقرير المخزون المنخفض (وجهة بطاقة لوحة التحكم) | 7 التقارير | `reports/inventory_low` | RPT-03 | P1 | RPT-03 | لم تبدأ |
| 204 | RPT-11 | ملخص المخزون (وجهة اختصار تقرير المخزون) | 7 التقارير | `reports/inventory_summary` | RPT-02/03 | P1 | RPT-03 | لم تبدأ |
| 205 | RPT-12 | تقارير الاستلام والموردين: الاستلام التفصيلي، رسم وملخص وتفصيلي الموردين | 7 التقارير | `reports/detailed_receivings`، `graphical_summary_suppliers`، `summary_suppliers`، `specific_suppliers` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 206 | RPT-13 | تقارير الضرائب وضرائب المبيعات: رسم، ملخص | 7 التقارير | `reports/graphical_summary_taxes`، `summary_taxes`، `graphical_summary_sales_taxes`، `summary_sales_taxes` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 207 | RPT-14 | تقارير فئات المصروفات: رسم، ملخص | 7 التقارير | `reports/graphical_summary_expenses_categories`، `summary_expenses_categories` | RPT-02/03 | P3 | RPT-03 | لم تبدأ |
| 208 | RPT-15 | تقييم المخزون من الدفاتر | 7 التقارير | `accounting_reports/inventory_valuation` | `accounting_reports/inventory_valuation.php` | P2 | CMP-04 | لم تبدأ |
| 209 | RPT-16 | الأرباح والخسائر من الدفاتر | 7 التقارير | `accounting_statements/profit_loss` | `accounting_statements/profit_loss.php` | P2 | FIN-36 | لم تبدأ |
| 210 | RPT-17 | قالب التقارير التشغيلية من الدفاتر (يخدم الصفوف 211–218 و106) | 7 التقارير | — | `accounting_operational/report.php` | P2 | CMP-04 | لم تبدأ |
| 211 | RPT-18 | إيرادات المبيعات (حسب اليوم، العميل…) | 7 التقارير | `accounting_reports/revenue` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 212 | RPT-19 | المبيعات حسب العميل | 7 التقارير | `accounting_reports/clients_sales` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 213 | RPT-20 | الفواتير والمرتجعات | 7 التقارير | `accounting_reports/invoice_returns` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 214 | RPT-21 | مبيعات الأصناف (حسب الصنف، الفئة، المستخدم) | 7 التقارير | `accounting_reports/item_sales` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 215 | RPT-22 | مقبوضات العملاء (تقرير) | 7 التقارير | `accounting_reports/payments` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 216 | RPT-23 | الدفعات المقدمة | 7 التقارير | `accounting_reports/advance_payments` | RPT-17 | P3 | RPT-17 | لم تبدأ |
| 217 | RPT-24 | الربح من حركات المخزون | 7 التقارير | `accounting_reports/profit` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 218 | RPT-25 | الربح حسب الصنف | 7 التقارير | `accounting_reports/item_profit` | RPT-17 | P2 | RPT-17 | لم تبدأ |
| 219 | RPT-26 | قالب تقارير العائلات من الدفاتر (يخدم الصفوف 220–241) | 7 التقارير | — | `accounting_reports/family.php` | P2 | CMP-04 | لم تبدأ |
| 220 | RPT-27 | المشتريات | 7 التقارير | `accounting_reports/purchases` | RPT-26 | P2 | RPT-26 | لم تبدأ |
| 221 | RPT-28 | مشتريات المورد | 7 التقارير | `accounting_reports/supplier_purchases` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 222 | RPT-29 | مشتريات المنتج | 7 التقارير | `accounting_reports/product_purchases` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 223 | RPT-30 | مدفوعات الموردين (تقرير) | 7 التقارير | `accounting_reports/supplier_payments` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 224 | RPT-31 | مدفوعات المشتريات والمتبقي للسداد | 7 التقارير | `accounting_reports/purchase_payments` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 225 | RPT-32 | دليل الموردين | 7 التقارير | `accounting_reports/supplier_directory` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 226 | RPT-33 | أعمار ديون الموردين (تقرير العائلة) | 7 التقارير | `accounting_reports/supplier_aged_ledger` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 227 | RPT-34 | أرصدة الموردين (تقرير العائلة) | 7 التقارير | `accounting_reports/supplier_balances` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 228 | RPT-35 | ورقة جرد المخزون | 7 التقارير | `accounting_reports/stock_count_sheet` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 229 | RPT-36 | ملخص عمليات المخزون | 7 التقارير | `accounting_reports/stock_operations` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 230 | RPT-37 | حركات المخزون | 7 التقارير | `accounting_reports/stock_movements` | RPT-26 | P2 | RPT-26 | لم تبدأ |
| 231 | RPT-38 | رصيد المخزون حسب الموقع | 7 التقارير | `accounting_reports/stock_balance` | RPT-26 | P2 | RPT-26 | لم تبدأ |
| 232 | RPT-39 | رصيد المنتج | 7 التقارير | `accounting_reports/product_balance` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 233 | RPT-40 | أعمار المخزون | 7 التقارير | `accounting_reports/stock_aging` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 234 | RPT-41 | دوران المخزون | 7 التقارير | `accounting_reports/stock_turnover` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 235 | RPT-42 | المصروفات (تقرير) | 7 التقارير | `accounting_reports/expenses` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 236 | RPT-43 | الإيرادات (سندات القبض) | 7 التقارير | `accounting_reports/income` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 237 | RPT-44 | حركات القيود | 7 التقارير | `accounting_reports/journal_movements` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 238 | RPT-45 | تقرير مراكز التكلفة | 7 التقارير | `accounting_reports/cost_centers` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 239 | RPT-46 | القيود (تقرير) | 7 التقارير | `accounting_reports/journals` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 240 | RPT-47 | دليل الحسابات (تقرير) | 7 التقارير | `accounting_reports/chart` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 241 | RPT-48 | الحركات المالية | 7 التقارير | `accounting_reports/financial_movements` | RPT-26 | P3 | RPT-26 | لم تبدأ |
| 242 | RPT-49 | تقريرا أرصدة العملاء وأرصدة الموردين | 7 التقارير | `accounting_receivables/balances`، `accounting_payables/balances` | `accounting_parties/balances.php` | P2 | CMP-04 | لم تبدأ |
| 243 | RPT-50 | تقريرا الشيكات الواردة والصادرة | 7 التقارير | `accounting_cheques/report/received`، `report/paid` | `accounting_cheques/report.php` | P3 | CMP-04 | لم تبدأ |
| 244 | RPT-51 | سجل النشاط المحاسبي + تبويب إخفاقات الترحيل | 7 التقارير | `accounting_journals/audit`، `accounting_journals/failures` | `audit.php`، `failures.php`، `audit_tabs` | P3 | CMP-04 | لم تبدأ |

## المرحلة 9: بوابة العملاء وقوالب الطباعة وPDF

| # | المعرف | الشاشة | القسم | مسار URL | Controller · View | الأولوية | يعتمد على | الحالة |
|---|---|---|---|---|---|---|---|---|
| 245 | PRT-01 | PDF مستندات البيع (فاتورة، ضريبية، عرض سعر، أمر عمل، إشعار دائن) وإرسالها بالبريد | مشترك | `sales/pdf/{id}/{type}`، `sendPdf/{id}/{type}` | `Sales::getPdf` · `documents/pdf.php`، `document.php`، `einvoice.php`، `qr.php` | P2 | SAL-05 | لم تبدأ |
| 246 | PRT-02 | PDF الإيصال والإيصال المرسل بالبريد | مشترك | `sales/sendReceipt/{id}` | `documents/slip_pdf.php`، `slip.php`، `sales/receipt_email.php` | P2 | SAL-04 | لم تبدأ |
| 247 | PRT-03 | PDF السندات (قبض وصرف) | مشترك | `receipt_vouchers/pdf/{id}`، `expenses/voucherPdf/{id}`، `sales/paymentReceiptPdf/{id}`، `receivings/paymentReceiptPdf/{id}` | `documents/voucher_pdf.php`، `voucher.php` | P2 | CMP-05 | لم تبدأ |
| 248 | PRT-04 | PDF فاتورة الشراء | مشترك | `receivings/pdf/{id}` | `documents/purchase_invoice_pdf.php` | P3 | PUR-04 | لم تبدأ |
| 249 | PRT-05 | PDF القيد | مشترك | `accounting_journals/pdf/{id}` | `documents/journal_pdf.php` | P3 | FIN-31 | لم تبدأ |
| 250 | PRT-06 | قالب PDF للتقارير المصدّرة (XLSX/PDF) | مشترك | `{report}/pdf`، `{report}/xlsx` | فلتر `ReportExport` · `documents/report.php` | P3 | CMP-04 | لم تبدأ |
| 251 | PRT-07 | PDF تصدير الجداول | مشترك | `POST exports/pdf` | `Exports::postPdf` · `documents/table.php` | P3 | CMP-01 | لم تبدأ |
| 252 | PRTL-01 | بوابة العملاء: الإطار وتسجيل الدخول | مشترك | `portal/login` | `Portal::getLogin` · `portal/layout.php`، `login.php` | P3 | AUTH-01 | لم تبدأ |
| 253 | PRTL-02 | بوابة العملاء: تغيير كلمة المرور | مشترك | `portal/password` | `portal/password.php` | P3 | PRTL-01 | لم تبدأ |
| 254 | PRTL-03 | بوابة العملاء: كشف الحساب (الرئيسية) | مشترك | `portal`، `portal/statement` | `portal/statement.php` | P3 | PRTL-01 | لم تبدأ |
| 255 | PRTL-04 | بوابة العملاء: الفواتير والإشعارات الدائنة والمدفوعات (قوائم) | مشترك | `portal/invoices`، `credit_notes`، `payments` | `portal/invoices.php`، `credit_notes.php`، `payments.php`، `pager.php` | P3 | PRTL-03 | لم تبدأ |
| 256 | PRTL-05 | بوابة العملاء: عرض الفاتورة أو الإشعار أو سند الدفع، وPDF | مشترك | `portal/invoice/{id}`، `credit_note/{id}`، `payment/{id}` (+`/pdf`) | `portal/document.php`، `document_body.php`، `receipt.php` | P3 | PRT-01، PRT-03 | لم تبدأ |

---

## مراجع التصاميم المعتمدة

يُسجل هنا كل تصميم يعتمده المالك، وهو المرجع البصري الوحيد عند التنفيذ.

| المعرف | الإصدار | تاريخ الاعتماد | المرجع (رابط أو ملف) | المكونات المشتركة التي ثبّتها | ملاحظات |
|---|---|---|---|---|---|
| DASH-01، DASH-02، SHL-01..04 | `1795426370-9bb6` | 2026-11-23 | https://claude.ai/artifact/J7bAniTTj82EwWsfNbr83M (لوحة «EXPRO — إطار التطبيق ولوحة التحكم»، 5 صفحات و38 لوحة) | AppSidebar، AppTopbar، AppFooter، SearchPalette، ShortcutTile، CustomizeShortcuts، رموز التصميم `ds/alawal-pro/tokens.json` | محتوى القائمة في التصميم توضيحي؛ يُعتمد محتوى `SidebarMenu` الفعلي |
| DASH-03 | `1795429422-2e2c` | 2026-11-23 | https://claude.ai/artifact/18CC6pTUEBkNkNiviHjyrb (لوحة «EXPRO — الإشعارات»، 5 صفحات و27 لوحة) | يعيد استخدام AppSidebar وAppTopbar وAppFooter دون تغيير | ستة مقترحات وظيفية معروضة منفصلة ولم تُعتمد |
| AUTH-01 | `1795449448-18f5` | 2026-11-23 | https://claude.ai/artifact/CVfeUeLntJ9dbjAzCuV372 (لوحة «EXPRO — تسجيل الدخول»، 4 صفحات و33 لوحة) | صفحة مستقلة بلا إطار؛ تعيد استخدام أنماط الحقول والتنبيهات | ثلاثة مقترحات وظيفية معروضة منفصلة ولم تُعتمد |

## ما لم يُدرج عن قصد

- `taxes/view_tax_codes|categories|jurisdictions` ومتحكمات `Tax_codes` و`Tax_categories` و`Tax_jurisdictions`: تشير إلى قوالب غير موجودة، فهي غير مستخدمة. يكفي تبويبات صفحة الضرائب في الصف 48.
- `office`: تحويل إلى الرئيسية، وليست شاشة.
- `api/v1`: واجهة بيانات بلا شاشة.
- لا توجد شاشة «استعادة كلمة مرور» للموظفين؛ الموجود فقط التغيير الإجباري، وتعديل المدير للموظف، وكلمة المرور المؤقتة لبوابة العملاء.
