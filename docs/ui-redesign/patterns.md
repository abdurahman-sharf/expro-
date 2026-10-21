# أنماط الشاشات

هياكل جاهزة لما يتكرر في النظام. الوحدة أو الشاشة الجديدة تبدأ من نمط هنا، وتُراجع على [معيار التصميم](design-system.md). المكوّنات الحية في صفحة `/styleguide` (للمدير)، والتنفيذ المرجعي مذكور بجانب كل نمط. المقاطع أدناه مختصرة من الشيفرة الفعلية.

| أحتاج | النمط | مرجعه في الشيفرة |
|---|---|---|
| قائمة سجلات مع بحث وتصدير وإضافة وتعديل | [قائمة وحدة](#1-قائمة-وحدة) | `expenses_categories/manage.php` |
| نموذج إضافة وتعديل في نافذة | [نموذج منبثق](#2-نموذج-منبثق) | `expenses_categories/form.php` |
| تقرير بجدول أو رسم | [تقرير](#3-تقرير) | `reports/tabular.php` و`graphical.php` |
| إعدادات | [قسم إعدادات](#4-قسم-إعدادات) | `configs/stock_config.php` |
| مؤشرات في الرئيسية أو التقارير | [بطاقات مؤشرات](#5-بطاقات-مؤشرات) | `home/home.php` و`reports/_summary.php` |

## 1. قائمة وحدة

المتحكم (مرجعه `Expenses_categories`): `getIndex` يرسل ترويسات الجدول، و`getSearch` يعيد `{total, rows}` بـAJAX، و`getRow` صفًا واحدًا بعد الحفظ، و`getView` نموذج الإضافة والتعديل، و`postSave` و`postDelete` يعيدان JSON. صفوف الجدول وترويساته دالتان في `tabular_helper.php` (`get_x_manage_table_headers` و`get_x_data_row`). عمود التعديل رابط `modal-dlg` بأيقونة `bi-pencil-square` وعنوان.

القالب `manage.php`:

```php
<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'expense_category_id',
            cardTitle: ['category_name']
        });
    });
</script>

<div id="title_bar" class="btn-toolbar">
    <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc("$controller_name/view") ?>" title="<?= lang(ucfirst($controller_name) . '.new') ?>">
        <span class="bi bi-plus-lg" aria-hidden="true"></span> <?= lang(ucfirst($controller_name) . '.new') ?>
    </button>
</div>

<div id="toolbar">
    <div class="float-start d-flex flex-wrap align-items-center gap-2" role="toolbar">
        <button id="delete" class="btn btn-outline-secondary btn-sm print_hide">
            <span class="bi bi-trash" aria-hidden="true"></span> <?= lang('Common.delete') ?>
        </button>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
```

- `cardTitle` يحدد حقل عنوان البطاقة على الجوال. الجدول الذي يزيد على 8 أعمدة يصير بطاقات حتى 1100px.
- الأرقام والتواريخ في الخلايا تُعزل LTR تلقائيًا. لا تُكتب `style` ولا فئة لون في الخلايا.
- المرشحات (قائمة أو نطاق تاريخ) في `#toolbar` بأصناف `bootstrap-select` أو `daterangepicker` كما في `items/manage.php` و`expenses/manage.php`.

## 2. نموذج منبثق

يُفتح من رابط `modal-dlg` ويُحفظ بـAJAX. الهيكل (من `expenses_categories/form.php`):

```php
<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("module/save/$info->id", ['id' => 'module_edit_form', 'class' => 'app-form']) ?>
    <fieldset>
        <div class="field">
            <?= form_label(lang('Module.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input(['name' => 'name', 'id' => 'name', 'class' => 'form-control form-control-sm', 'value' => $info->name]) ?>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#module_edit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('<?= esc($controller_name) ?>', response);
                    },
                    dataType: 'json'
                });
            },
            errorLabelContainer: '#error_message_box',
            rules: {name: 'required'},
            messages: {name: '<?= lang('Module.name_required') ?>'}
        }, form_support.error));
    });
</script>
```

- الحقل الإلزامي: `text-danger required` على الوسم (النجمة تأتي من CSS).
- النماذج الطويلة: `.form-grid` (عمودان على الحاسوب وعمود على الجوال) و`.form-section` بعنوان `.form-section-title`، و`.span-all` لحقل بعرض كامل. خانة الاختيار: `.field.field-check`. مجموعة اختيار: `role="radiogroup"` بوسم يسميها و`.choice` لكل خيار.
- التبويبات داخل النموذج `nav nav-tabs` بـ`role="tablist"`، والتبويب المفرد يُخفى تلقائيًا.
- الحذف وأي إجراء مدمّر: `confirm` من `table_support` ولا يُنفَّذ بلا تأكيد.

## 3. تقرير

المتحكم يبني الرؤوس والبيانات ويستدعي `reports/tabular` (ملخص) أو `reports/tabular_details` (قابل للتوسعة) أو `reports/graphical` (رسم). شاشة الإدخال (نطاق التاريخ والتصفية) من `reports/date_input` أو `specific_input` على نموذج `report-form`. التقرير الجديد يُضاف إلى `reportCatalog()` في `report_helper.php` بصلاحيته، فيظهر في الفهرس والبحث السريع.

```php
<?= view('partial/header') ?>

<?= view('reports/_head', ['title' => $title, 'subtitle' => $subtitle, 'sensitiveToggle' => false]) ?>
<?= view('reports/_summary', ['summary_data' => $summary_data]) ?>

<div id="table_holder"><table id="table"></table></div>

<script type="text/javascript">
    $(document).ready(function () {
        <?= view('partial/bootstrap_tables_locale') ?>

        const columns = <?= transform_headers(esc($headers), true, false) ?>;

        $('#table').addClass('table-striped').bootstrapTable($.extend(table_support.report_options(columns), {
            pageSize: <?= $config['lines_per_page'] ?>,
            sortable: true,
            pagination: true,
            search: true,
            escape: true,
            data: <?= json_encode($data) ?>
        }));
    });
</script>

<?= view('partial/footer') ?>
```

- الرسم: `<canvas data-chart>` بمواصفة JSON `{kind: bar|hbar|line|pie, labels, values, name, xTitle, yTitle, currency}` كما في `reports/graphical.php`، مع جدول بياناته داخل `<details class="chart-data">`.
- التكلفة والربح بطاقات `is-sensitive` مخفية افتراضيًا، والزر في `reports/_head`.
- لا رسم بلا جدول مقابل، ولا فترة بلا رسالة «لا بيانات» (`Reports.no_data_for_period`).

## 4. قسم إعدادات

الأقسام الاثنا عشر معرّفة في `settingsSections()` (`navigation_helper.php`) وتُعرض من `configs/manage.php`. قسم جديد: يُضاف إلى `settingsSections()` وإلى مصفوفة `$panes` في `manage.php`، وملف `configs/<name>_config.php`، ونقطة `postSave<Name>` في `Config.php`. الصف:

```php
<?= form_open('config/saveExample/', ['id' => 'example_config_form', 'class' => 'app-form']) ?>
    <fieldset>
        <div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
        <ul id="example_error_message_box" class="error_message_box"></ul>

        <div class="row mb-3">
            <?= form_label(lang('Config.name'), 'name', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
            <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                <?= form_input(['name' => 'name', 'id' => 'name', 'class' => 'form-control form-control-sm', 'value' => $config['name']]) ?>
            </div>
        </div>

        <div class="settings-actions">
            <?= form_submit(['name' => 'submit_example', 'id' => 'submit_example', 'value' => lang('Common.submit'), 'class' => 'btn btn-primary']) ?>
        </div>
    </fieldset>
<?= form_close() ?>
```

- الوسم والحقل داخل صف واحد، والتخطيط (وسم فوق الحقل على الجوال وبجانبه من 768px) من CSS فلا يُكتب `col-*` إضافي لإصلاحه.
- كل `select` له `id` يطابق `for` وسمه. الحقول المتعددة في صف تأخذ اسمها من وسم الصف تلقائيًا (`66-settings.js`).
- صفوف قابلة للتكرار (إضافة وحذف): أزرار `repeat-action` بـ`aria-label`، كما في `partial/stock_locations.php`.

## 5. بطاقات مؤشرات

```php
<div class="report-kpis dash-kpis">
    <div class="report-kpi">
        <span class="report-kpi-label"><?= lang('Common.dashboard_today') ?></span>
        <bdi dir="ltr" class="report-kpi-value"><?= to_currency($total) ?></bdi>
        <span class="kpi-delta is-up"><span class="bi bi-arrow-up-short" aria-hidden="true"></span><bdi dir="ltr">12.5%</bdi></span>
    </div>
</div>
```

- المبلغ والنسبة دائمًا داخل `<bdi dir="ltr">`، والتغير يحمل سهمًا وإشارة لا لونًا فقط.
- القسم الذي قد يفشل (مصدره استعلام) يُخفى عند الفشل ولا يُعطّل الصفحة (انظر `Dashboard_lib`).

## أضف وحدة جديدة: قائمة التحقق

1. **قاعدة البيانات:** migration جديدة (لا تُعدَّل القديمة). تسجيل الوحدة في جدول `modules` بمفتاحي لغة واسمها وترتيبها، وصلاحياتها.
2. **الشيفرة:** متحكم يمتد `Secure_Controller('<module_id>')` ونموذج وملف لغة `app/Language/*/<Module>.php`، وصفوف الجدول في `tabular_helper.php`.
3. **الواجهة:** `manage.php` و`form.php` من النمطين 1 و2 بلا تصميم خاص.
4. **التنقل:** المعرّف في `NAVIGATION_GROUPS` و`NAVIGATION_ICONS` (أيقونة `bi-*`)، ومفتاحا `Module.<id>` و`Module.<id>_desc` في كل ملفات اللغة.
5. **اللغات:** كل مفتاح في كل `app/Language/*` بترتيب أبجدي، والعربية (`ar-EG` و`ar-LB`) مترجمة.
6. **الفحص الآلي:** شاشة القائمة ونافذة الإضافة في `tools/ui-smoke/screens.json`، ثم `node tools/ui-smoke/gates.mjs`، ثم `cd tools/ui-smoke && npm run smoke -- --only <ids>`.
7. **الاختبارات:** PHPUnit للمتحكم والنموذج (اسم الاختبار `testMethod_Behavior`).
8. **اليدوي:** بالعربية (RTL) والإنجليزية على 390 و768 و1440، وبلوحة المفاتيح، ومن حساب بلا صلاحية الوحدة (يُحوَّل إلى `no_access`).
9. **البناء:** `npm run build` ثم التزام `public/assets` مع التغيير.
